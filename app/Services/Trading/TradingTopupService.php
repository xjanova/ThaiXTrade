<?php

namespace App\Services\Trading;

use App\Models\SiteSetting;
use App\Models\TradingCredit;
use App\Services\Wei;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * TPIX TRADE — เติมคลัง TPIX ด้วยการโอนจริงบนเชน 4289.
 *
 * ผู้ใช้โอน TPIX ไปที่กระเป๋ารับเงินของแพลตฟอร์ม แล้วส่ง tx hash มาให้ยืนยัน
 * เรายืนยันบนเชนเองทุกครั้ง ไม่เชื่อตัวเลขที่ client ส่งมา
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ⚠️ ห้ามเชื่อจำนวนเงินที่ client บอก — อ่านจากเชนอย่างเดียว
 * ═══════════════════════════════════════════════════════════════════════════
 * ถ้ารับตัวเลขจาก client ใครก็โอน 1 TPIX แล้วบอกว่าโอน 10000 ได้ทันที
 * ที่นี่จึงใช้ยอดจาก eth_getTransactionByHash เป็นตัวจริงเสมอ
 *
 * ทำไมต้องเป็นเชน 4289: TPIX เป็นเหรียญหลักของเชนนั้น ส่วนการเทรดอยู่บน BSC
 * ผู้ใช้จึงสลับเชนมาเติมครั้งเดียว แล้วเทรดต่อบน BSC ได้เรื่อยๆ โดยไม่ต้องสลับอีก
 *
 * Developed by Xman Studio.
 */
class TradingTopupService
{
    public const ERR_NO_TREASURY = 'TOPUP_WALLET_NOT_SET';

    public const ERR_TX_NOT_FOUND = 'TX_NOT_FOUND';

    public const ERR_TX_FAILED = 'TX_FAILED';

    public const ERR_WRONG_RECIPIENT = 'WRONG_RECIPIENT';

    public const ERR_WRONG_SENDER = 'WRONG_SENDER';

    public const ERR_BELOW_MINIMUM = 'BELOW_MINIMUM';

    public const ERR_ALREADY_CREDITED = 'ALREADY_CREDITED';

    public function __construct(private readonly TradingCreditService $credits) {}

    /** กระเป๋าที่ผู้ใช้โอน TPIX เข้ามา — ว่าง = ยังเปิดให้เติมไม่ได้ */
    public function treasuryWallet(): string
    {
        return strtolower(trim((string) SiteSetting::get('trading', 'tpix_topup_wallet', '')));
    }

    public function isConfigured(): bool
    {
        return $this->treasuryWallet() !== '';
    }

    /** ข้อมูลที่หน้าเว็บต้องใช้แสดงวิธีเติม */
    public function info(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'wallet' => $this->treasuryWallet(),
            'chain_id' => $this->chainId(),
            'minimum' => $this->credits->minimumTopup(),
            'symbol' => 'TPIX',
        ];
    }

    public function chainId(): int
    {
        return (int) SiteSetting::get('trading', 'tpix_topup_chain_id', 4289);
    }

    /**
     * ยืนยันการโอนแล้วลงเครดิต.
     *
     * @return array{credited: float, balance: float, tx_hash: string}
     *
     * @throws RuntimeException
     */
    public function credit(string $wallet, string $txHash): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(self::ERR_NO_TREASURY);
        }

        $wallet = $this->credits->normalize($wallet);
        $txHash = strtolower(trim($txHash));

        // ลงไปแล้วก็คืนผลเดิม ไม่ใช่ error — client ยิงซ้ำได้เป็นเรื่องปกติ
        $already = TradingCredit::where('wallet_address', $wallet)
            ->where('reference', 'topup:'.$txHash)
            ->first();

        if ($already) {
            return [
                'credited' => (float) $already->amount,
                'balance' => $this->credits->balanceFor($wallet),
                'tx_hash' => $txHash,
            ];
        }

        $tx = $this->readTransaction($txHash);

        if (($tx['from'] ?? '') !== $wallet) {
            throw new RuntimeException(self::ERR_WRONG_SENDER);
        }

        if (($tx['to'] ?? '') !== $this->treasuryWallet()) {
            throw new RuntimeException(self::ERR_WRONG_RECIPIENT);
        }

        $amount = (float) $tx['value'];
        $minimum = $this->credits->minimumTopup();

        if ($amount < $minimum) {
            throw new RuntimeException(self::ERR_BELOW_MINIMUM);
        }

        $this->credits->topup($wallet, $amount, $txHash, [
            'block' => $tx['block'] ?? null,
            'chain_id' => $this->chainId(),
        ]);

        return [
            'credited' => $amount,
            'balance' => $this->credits->balanceFor($wallet),
            'tx_hash' => $txHash,
        ];
    }

    /**
     * อ่านธุรกรรมจากเชน.
     *
     * @return array{from: string, to: string, value: float, block: ?int}
     */
    private function readTransaction(string $txHash): array
    {
        $rpc = $this->rpcUrl();

        try {
            $receipt = Http::timeout(12)->post($rpc, [
                'jsonrpc' => '2.0', 'id' => 1,
                'method' => 'eth_getTransactionReceipt',
                'params' => [$txHash],
            ])->json('result');

            if (! $receipt) {
                throw new RuntimeException(self::ERR_TX_NOT_FOUND);
            }

            // ธุรกรรมที่ revert ไม่ได้โอนอะไรเลย — ลงเครดิตให้ไม่ได้
            if (($receipt['status'] ?? '') !== '0x1') {
                throw new RuntimeException(self::ERR_TX_FAILED);
            }

            $tx = Http::timeout(12)->post($rpc, [
                'jsonrpc' => '2.0', 'id' => 2,
                'method' => 'eth_getTransactionByHash',
                'params' => [$txHash],
            ])->json('result');

            if (! $tx) {
                throw new RuntimeException(self::ERR_TX_NOT_FOUND);
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('อ่านธุรกรรมเติมเครดิตจากเชนไม่ได้', ['tx' => $txHash, 'error' => $e->getMessage()]);

            throw new RuntimeException(self::ERR_TX_NOT_FOUND);
        }

        // แปลงแบบ arbitrary precision — ยอดสูงๆ ทำให้ int ธรรมดา overflow
        // (เซิร์ฟเวอร์ไม่มี ext-gmp จึงใช้ Wei::hexToInt + bcdiv เหมือนฝั่ง token sale)
        $valueWei = Wei::hexToInt($tx['value'] ?? '0x0');

        return [
            'from' => strtolower($tx['from'] ?? ''),
            'to' => strtolower($tx['to'] ?? ''),
            'value' => (float) bcdiv($valueWei, '1000000000000000000', 18),
            'block' => isset($receipt['blockNumber']) ? hexdec($receipt['blockNumber']) : null,
        ];
    }

    private function rpcUrl(): string
    {
        return (string) (config('chains.chains.'.$this->chainId().'.rpc.0')
            ?? env('WEB3_RPC_TPIX', 'https://rpc.tpix.online'));
    }
}
