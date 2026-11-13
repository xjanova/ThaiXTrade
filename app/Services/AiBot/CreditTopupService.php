<?php

namespace App\Services\AiBot;

use App\Models\AiBotCredit;
use App\Models\TradingCredit;
use App\Models\TradingOrderTicket;
use App\Services\AiBotService;
use App\Support\Wei;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * TPIX TRADE — เติมเครดิตบอท AI TRADE ด้วยการโอน TPIX จริงบนเชน 4289.
 *
 * ผู้ใช้เลือกแพ็ก → โอน TPIX เท่าราคาแพ็กเข้ากระเป๋ารับเงิน → ส่ง tx hash มาให้ยืนยัน
 * เราอ่านธุรกรรมจากเชนเองทุกครั้ง แล้วลงเครดิต (แพ็ก + โบนัส) ผ่าน ledger ครั้งเดียวต่อธุรกรรม
 * วิธีตรวจชุดเดียวกับ TradingTopupService (ใบเสร็จสำเร็จ · ผู้ส่ง · ผู้รับ · ยอด)
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ⚠️ ปิดไว้เป็นค่าปริยายทุกทาง — สงสัยเมื่อไหร่ = ไม่ลงเครดิต
 * ═══════════════════════════════════════════════════════════════════════════
 *  - ไม่เชื่ออะไรจาก client นอกจาก "รหัสแพ็ก" กับ "tx hash" — ยอด ผู้ส่ง ผู้รับ อ่านจากเชน
 *  - RPC ล่ม / ยังไม่มีใบเสร็จ / คำตอบผิดรูป = ตอบ "รอ" ให้ลองใหม่ ไม่ลงเครดิตและไม่ปฏิเสธถาวร
 *  - ธุรกรรมหนึ่งใบลงเครดิตได้ครั้งเดียวทั้งระบบ: reference ผูกกับ tx hash (unique ต่อกระเป๋า)
 *    + ต้องโอนจากกระเป๋าของผู้เรียกเอง + เช็คว่า hash นี้ไม่เคยถูกใช้ในบัญชีเครดิตบอทของ
 *    กระเป๋าอื่น หรือในบัญชีคลังค่าธรรมเนียมเทรด
 *
 * Developed by Xman Studio.
 */
class CreditTopupService
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CREDITED = 'credited';

    /** ธุรกรรม revert — ไม่มีเงินโอนจริง */
    public const ERR_TX_FAILED = 'TOPUP_TX_FAILED';

    public const ERR_WRONG_SENDER = 'TOPUP_WRONG_SENDER';

    public const ERR_WRONG_RECIPIENT = 'TOPUP_WRONG_RECIPIENT';

    /** ยอดที่โอนไม่ตรงกับราคาแพ็กที่เลือก */
    public const ERR_WRONG_AMOUNT = 'TOPUP_WRONG_AMOUNT';

    /** ธุรกรรมนี้ถูกใช้เติมเครดิตไปแล้ว (กระเป๋าอื่น หรือบัญชีคลังเทรด) */
    public const ERR_TX_USED = 'TOPUP_TX_ALREADY_USED';

    /** ให้ client รอกี่วินาทีก่อนถามซ้ำ ตอนเชนยังไม่ยืนยัน */
    private const RETRY_AFTER_SECONDS = 5;

    public function __construct(private readonly AiBotService $bots) {}

    /** reference ใน ledger ของการเติมด้วยธุรกรรมนี้ — รูปเดียวกับฝั่งคลังเทรด (topup:{hash}) */
    public static function reference(string $txHash): string
    {
        return 'topup:'.strtolower(trim($txHash));
    }

    /**
     * ยืนยันการโอนบนเชนแล้วลงเครดิตของแพ็ก.
     *
     * @return array{status: string, tx_hash: string, credited?: float, balance?: float, pack?: ?string, retry_after_seconds?: int}
     *
     * @throws RuntimeException รหัสที่ตั้งใจให้ผู้ใช้เห็น (controller แปลงเป็นข้อความ)
     */
    public function confirm(string $wallet, string $packCode, string $txHash): array
    {
        // ปิดรับเติม (ธง · กระเป๋ารับเงิน · ยังไม่เปิดขาย) — ธุรกรรมไม่ถูกใช้ ยืนยันใหม่ได้เมื่อเปิด
        if (! $this->bots->topupAvailableFor($wallet)) {
            throw new RuntimeException(AiBotService::ERR_TOPUP_CLOSED);
        }

        $pack = $this->bots->creditPack($packCode);

        if (! $pack) {
            throw new RuntimeException('INVALID_PACK');
        }

        // แพ็กที่ราคาไม่เป็นบวก (ตั้งค่าพลาด) ห้ามใช้ยืนยัน — ธุรกรรมโอน 0 TPIX จะกลายเป็นเครดิตฟรี
        if ((float) ($pack['price_tpix'] ?? 0) <= 0) {
            throw new RuntimeException('INVALID_PACK');
        }

        $wallet = $this->bots->normalize($wallet);
        $txHash = strtolower(trim($txHash));
        $reference = self::reference($txHash);

        // ลงไปแล้วก็คืนผลเดิม ไม่ใช่ error — client ยิงซ้ำได้เป็นเรื่องปกติ (เน็ตหลุดหลังจ่าย)
        $already = AiBotCredit::where('wallet_address', $wallet)->where('reference', $reference)->first();

        if ($already) {
            return $this->credited($already, $txHash);
        }

        if ($this->usedElsewhere($wallet, $txHash, $reference)) {
            throw new RuntimeException(self::ERR_TX_USED);
        }

        $tx = $this->readTransaction($txHash);

        // เชนยังไม่ยืนยัน หรือถามเชนไม่ได้ — ยังไม่รู้ผล ห้ามลงเครดิต และห้ามปฏิเสธถาวร
        if ($tx === null) {
            return [
                'status' => self::STATUS_PENDING,
                'tx_hash' => $txHash,
                'retry_after_seconds' => self::RETRY_AFTER_SECONDS,
            ];
        }

        // ธุรกรรมที่ revert ไม่ได้โอนอะไรเลย
        if ($tx['status'] !== '0x1') {
            throw new RuntimeException(self::ERR_TX_FAILED);
        }

        // ต้องโอนจากกระเป๋าของผู้เรียกเอง (ที่เซ็นยืนยันแล้ว) — เอา hash ของคนอื่นมาขอเครดิตไม่ได้
        if ($tx['from'] !== $wallet) {
            throw new RuntimeException(self::ERR_WRONG_SENDER);
        }

        if ($tx['to'] !== $this->bots->topupWallet()) {
            throw new RuntimeException(self::ERR_WRONG_RECIPIENT);
        }

        /*
         * ยอดต้องตรงราคาแพ็กทุกหลัก (เทียบเป็น wei แบบข้อความ ไม่ผ่าน float)
         *
         * ไม่ตรง = ไม่ลงเครดิตของแพ็กนี้ แต่ธุรกรรมยังไม่ถูกใช้ — ผู้ใช้ส่ง hash เดิมมาใหม่
         * พร้อมแพ็กที่ราคาตรงกับยอดที่โอนได้ (เช่นเลือกแพ็กผิดบนหน้าจอ)
         */
        if (Wei::compare($tx['value_wei'], Wei::toWei($this->bots->packPriceTpix($pack))) !== 0) {
            throw new RuntimeException(self::ERR_WRONG_AMOUNT);
        }

        $row = $this->bots->record($wallet, 'topup', $this->bots->packCredits($pack), $reference, [
            'pack' => $pack['code'],
            'credits' => (float) ($pack['credits'] ?? 0),
            'bonus' => (float) ($pack['bonus'] ?? 0),
            'price_tpix' => $this->bots->packPriceTpix($pack),
            'tx_hash' => $txHash,
            'block' => $tx['block'],
            'chain_id' => $this->bots->topupChainId(),
        ]);

        if ($row->wasRecentlyCreated) {
            // ไม่มีข้อมูลลับ — ที่อยู่กระเป๋ากับ tx hash เป็นข้อมูลสาธารณะบนเชนอยู่แล้ว
            Log::info('aibot topup: ลงเครดิตจากการโอนบนเชน', [
                'wallet' => $wallet, 'tx' => $txHash, 'pack' => $pack['code'], 'credits' => (float) $row->amount,
            ]);
        }

        return $this->credited($row, $txHash);
    }

    /** @return array{status: string, tx_hash: string, credited: float, balance: float, pack: ?string} */
    private function credited(AiBotCredit $row, string $txHash): array
    {
        return [
            'status' => self::STATUS_CREDITED,
            'tx_hash' => $txHash,
            'credited' => (float) $row->amount,
            'balance' => $this->bots->balanceFor($row->wallet_address),
            'pack' => $row->meta['pack'] ?? null,
        ];
    }

    /**
     * ธุรกรรมนี้เคยถูกใช้ขอเครดิตที่อื่นแล้วไหม.
     *
     * กระเป๋ารับเงินของสองระบบถูกบังคับให้เป็นคนละใบอยู่แล้ว (AiBotService::topupWallet)
     * ด่านนี้คือชั้นที่สอง เผื่อวันหนึ่งมีคนตั้งค่าพลาดหรือย้ายกระเป๋า
     */
    private function usedElsewhere(string $wallet, string $txHash, string $reference): bool
    {
        return AiBotCredit::where('reference', $reference)->where('wallet_address', '!=', $wallet)->exists()
            // บัญชีคลัง TPIX ของค่าธรรมเนียมเทรด (TradingTopupService ใช้ reference รูปเดียวกัน)
            || TradingCredit::where('reference', $reference)->exists()
            // ธุรกรรมที่ถูกใช้จ่ายค่าธรรมเนียมวางไม้โดยตรง
            || TradingOrderTicket::where('fee_tx_hash', $txHash)->exists();
    }

    /**
     * อ่านธุรกรรมจากเชน — null = ยังไม่รู้ผล (ยังไม่ถูกขุด · RPC ล่ม · คำตอบผิดรูป).
     *
     * @return array{status: string, from: string, to: string, value_wei: string, block: ?int}|null
     */
    private function readTransaction(string $txHash): ?array
    {
        try {
            $receipt = $this->rpc('eth_getTransactionReceipt', [$txHash]);

            if (! is_array($receipt) || ! isset($receipt['status'])) {
                return null;
            }

            $tx = $this->rpc('eth_getTransactionByHash', [$txHash]);

            if (! is_array($tx) || ! isset($tx['from'], $tx['value'])) {
                return null;
            }

            // คำตอบต้องเป็นของธุรกรรมที่ถามจริง — โหนดที่ตอบผิดใบต้องไม่ทำให้ใครได้เครดิต
            if (isset($tx['hash']) && strtolower((string) $tx['hash']) !== $txHash) {
                return null;
            }

            return [
                'status' => strtolower((string) $receipt['status']),
                'from' => strtolower((string) $tx['from']),
                // สร้างสัญญา (to = null) ไม่ใช่การโอนเข้ากระเป๋ารับเงิน — ปล่อยให้ด่านผู้รับปฏิเสธ
                'to' => strtolower((string) ($tx['to'] ?? '')),
                // arbitrary precision — ยอดสูงๆ ทำให้ int ธรรมดา overflow
                'value_wei' => Wei::hexToInt((string) $tx['value']),
                'block' => isset($receipt['blockNumber']) ? (int) hexdec((string) $receipt['blockNumber']) : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('aibot topup: อ่านธุรกรรมจากเชนไม่ได้ ตอบให้รอ', ['tx' => $txHash, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** เรียก JSON-RPC หนึ่งครั้ง — คืน result (null เมื่อโหนดตอบ error หรือไม่มีผล) */
    private function rpc(string $method, array $params): mixed
    {
        $response = Http::timeout(12)->post($this->rpcUrl(), [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params,
        ]);

        if ($response->failed() || $response->json('error') !== null) {
            return null;
        }

        return $response->json('result');
    }

    /** RPC ของเชนที่รับชำระ — ไม่มีในตารางเชน = ถามไม่ได้ (ห้ามตกไปถามเชนอื่นแทน) */
    private function rpcUrl(): string
    {
        $url = config('chains.chains.'.$this->bots->topupChainId().'.rpc.0');

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('ไม่มี RPC ของเชนที่รับชำระค่าเครดิต');
        }

        return $url;
    }
}
