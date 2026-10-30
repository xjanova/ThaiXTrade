<?php

namespace App\Services\TokenFactory;

use App\Models\FactoryToken;
use App\Models\TokenFactoryFeeQuote;
use App\Support\Wei;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * ตรวจการจ่ายค่าธรรมเนียมสร้างเหรียญบนเชน TPIX (4289) — ไม่เชื่ออะไรที่ client บอกเลย.
 *
 * เดิม fee_tx_hash เป็นช่องว่างได้ และไม่เคยถูกตรวจ — ส่ง hash มั่ว ๆ หรือ hash เดิมซ้ำ
 * ก็ได้เหรียญบน mainnet ฟรีไม่จำกัด (เปิด auto_approve ไว้ด้วยก็ได้ทันที)
 *
 * เงื่อนไขที่ต้องผ่านครบ:
 *   - hash นี้ไม่เคยใช้ชำระค่าธรรมเนียมมาก่อน — ทั้งค่าสร้างเหรียญ (fee_tx_hash) และค่ายืนยันซอร์ส
 *     (source_verify_tx_hash) ฐานข้อมูลมี unique index กันซ้ำอีกชั้นทั้งสองคอลัมน์
 *   - to = กระเป๋ารับค่าธรรมเนียมในใบเสนอราคา · from = กระเป๋าผู้สร้าง · chainId = 4289
 *   - value ≥ ยอดในใบเสนอราคา (เทียบเป็น wei ด้วย bcmath ไม่ผ่าน float)
 *   - receipt.status = 1 · ยืนยันแล้วอย่างน้อย N บล็อก (ตั้งในนโยบาย)
 *   - เวลาของบล็อกอยู่หลังเวลาออกใบเสนอราคา และไม่เกินเวลาหมดอายุ
 *
 * ยังไม่ครบ N บล็อก / ยังไม่ขึ้นเชน → FEE_PENDING (202) ให้หน้าเว็บถามซ้ำ ไม่ใช่ปฏิเสธ
 *
 * Developed by Xman Studio
 */
class TokenFactoryFeeVerifier
{
    /** ยอมให้นาฬิกาเซิร์ฟเวอร์กับเวลาบล็อกคลาดกันได้เท่านี้ (วินาที) */
    private const CLOCK_SKEW = 30;

    public function __construct(
        private readonly TpixRpcClient $rpc,
        private readonly TokenFactoryPolicy $policy,
    ) {}

    /**
     * @return array{confirmations:int, required:int, block:int, value_wei:string}
     *
     * @throws TokenFactoryException
     */
    public function verify(TokenFactoryFeeQuote $quote, string $txHash, string $creator): array
    {
        $txHash = strtolower(trim($txHash));
        $creator = strtolower(trim($creator));
        $feeWallet = strtolower((string) $quote->fee_wallet);
        $required = (int) $this->policy->effective()['required_confirmations'];

        if (! preg_match('/^0x[a-f0-9]{64}$/', $txHash)) {
            throw new TokenFactoryException('FEE_TX_INVALID', 'รูปแบบ tx hash ของค่าธรรมเนียมไม่ถูกต้อง');
        }

        if (self::txAlreadyUsed($txHash)) {
            throw new TokenFactoryException('FEE_TX_USED', 'ธุรกรรมนี้ถูกใช้ชำระค่าธรรมเนียมไปแล้ว', 409);
        }

        if ($feeWallet === '') {
            throw new TokenFactoryException('FEE_WALLET_MISSING', 'ระบบยังไม่ได้ตั้งกระเป๋ารับค่าธรรมเนียม กรุณาติดต่อทีมงาน', 503);
        }

        $tx = $this->ask('eth_getTransactionByHash', [$txHash]);

        if (! is_array($tx)) {
            if ($quote->isExpired()) {
                throw new TokenFactoryException('QUOTE_EXPIRED', 'ใบเสนอราคาหมดอายุแล้ว และยังไม่พบธุรกรรมค่าธรรมเนียมบนเชน');
            }

            throw TokenFactoryException::feePending(
                'ยังไม่พบธุรกรรมค่าธรรมเนียมบนเชน — รอสักครู่ ระบบจะตรวจอีกครั้ง',
                ['confirmations' => 0, 'required' => $required]
            );
        }

        if (strtolower((string) ($tx['to'] ?? '')) !== $feeWallet) {
            throw new TokenFactoryException('FEE_WRONG_RECIPIENT', 'ธุรกรรมนี้ไม่ได้โอนเข้ากระเป๋ารับค่าธรรมเนียมของระบบ');
        }

        if (strtolower((string) ($tx['from'] ?? '')) !== $creator) {
            throw new TokenFactoryException('FEE_WRONG_SENDER', 'ค่าธรรมเนียมต้องโอนจากกระเป๋าเดียวกับผู้สร้างเหรียญ');
        }

        if ($this->chainIdOf($tx) !== $this->policy->chainId()) {
            throw new TokenFactoryException('FEE_WRONG_CHAIN', 'ค่าธรรมเนียมต้องจ่ายบนเชน TPIX ('.$this->policy->chainId().') เท่านั้น');
        }

        $valueWei = Wei::hexToInt(is_string($tx['value'] ?? null) ? $tx['value'] : '0x0');
        if (bccomp($valueWei, (string) $quote->amount_wei, 0) < 0) {
            throw new TokenFactoryException(
                'FEE_TOO_LOW',
                'ยอดที่โอน ('.Wei::format($valueWei).' TPIX) น้อยกว่าค่าธรรมเนียม ('.Wei::format((string) $quote->amount_wei).' TPIX)'
            );
        }

        $receipt = $this->ask('eth_getTransactionReceipt', [$txHash]);
        $block = is_array($receipt) ? TpixRpcClient::hexToInt($receipt['blockNumber'] ?? null) : null;

        if ($block === null) {
            throw TokenFactoryException::feePending(
                'ธุรกรรมค่าธรรมเนียมยังไม่ถูกบรรจุลงบล็อก — รอสักครู่',
                ['confirmations' => 0, 'required' => $required]
            );
        }

        if (($receipt['status'] ?? null) !== '0x1') {
            throw new TokenFactoryException('FEE_TX_FAILED', 'ธุรกรรมค่าธรรมเนียมล้มเหลวบนเชน (revert) — ไม่มีการโอนเงินเกิดขึ้น');
        }

        $latest = TpixRpcClient::hexToInt($this->ask('eth_blockNumber', []));
        $blockData = $this->ask('eth_getBlockByNumber', [$receipt['blockNumber'], false]);
        $timestamp = is_array($blockData) ? TpixRpcClient::hexToInt($blockData['timestamp'] ?? null) : null;

        if ($latest === null || $timestamp === null) {
            throw new TokenFactoryException('CHAIN_UNAVAILABLE', 'อ่านข้อมูลบล็อกจากเชน TPIX ไม่ได้ชั่วคราว กรุณาลองใหม่', 503);
        }

        if ($timestamp < $quote->created_at->getTimestamp() - self::CLOCK_SKEW) {
            throw new TokenFactoryException('FEE_TX_BEFORE_QUOTE', 'ธุรกรรมนี้เกิดก่อนออกใบเสนอราคา จึงใช้จ่ายค่าธรรมเนียมรอบนี้ไม่ได้');
        }

        if ($timestamp > $quote->expires_at->getTimestamp() + self::CLOCK_SKEW) {
            throw new TokenFactoryException('QUOTE_EXPIRED', 'ชำระหลังใบเสนอราคาหมดอายุ — กรุณาติดต่อทีมงานพร้อม tx hash');
        }

        $confirmations = max(0, $latest - $block + 1);
        if ($confirmations < $required) {
            throw TokenFactoryException::feePending(
                "รอการยืนยันบนเชน ({$confirmations}/{$required} บล็อก)",
                ['confirmations' => $confirmations, 'required' => $required]
            );
        }

        return [
            'confirmations' => $confirmations,
            'required' => $required,
            'block' => $block,
            'value_wei' => $valueWei,
        ];
    }

    /** ธุรกรรมนี้เคยใช้ชำระค่าธรรมเนียมใด ๆ ของโรงงานไปแล้วหรือยัง */
    public static function txAlreadyUsed(string $txHash): bool
    {
        $txHash = strtolower(trim($txHash));

        return FactoryToken::where('fee_tx_hash', $txHash)
            ->orWhere('source_verify_tx_hash', $txHash)
            ->exists();
    }

    /**
     * บันทึกการใช้ธุรกรรมแบบกันชนกัน — hash เดียวห้ามจ่ายได้ทั้งค่าสร้างและค่ายืนยันซอร์ส.
     *
     * unique index กันได้ทีละคอลัมน์ (fee_tx_hash / source_verify_tx_hash) แต่ไม่กันข้ามคอลัมน์
     * สองคำขอที่มาพร้อมกันจึงต้องล็อกที่ hash แล้วถามซ้ำข้างในก่อนเขียน
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     *
     * @throws TokenFactoryException
     */
    public static function claimTx(string $txHash, callable $write): mixed
    {
        $txHash = strtolower(trim($txHash));

        try {
            return Cache::lock('token_factory:fee_tx:'.$txHash, 30)->block(10, function () use ($txHash, $write) {
                if (self::txAlreadyUsed($txHash)) {
                    throw new TokenFactoryException('FEE_TX_USED', 'ธุรกรรมนี้ถูกใช้ชำระค่าธรรมเนียมไปแล้ว', 409);
                }

                return $write();
            });
        } catch (LockTimeoutException) {
            throw new TokenFactoryException('FEE_TX_BUSY', 'ธุรกรรมนี้กำลังถูกตรวจอยู่ กรุณาลองใหม่อีกครั้ง', 409);
        }
    }

    private function ask(string $method, array $params): mixed
    {
        try {
            return $this->rpc->call($method, $params);
        } catch (RuntimeException) {
            throw new TokenFactoryException('CHAIN_UNAVAILABLE', 'ติดต่อเชน TPIX ไม่ได้ชั่วคราว กรุณาลองใหม่อีกครั้ง', 503);
        }
    }

    /**
     * chainId ของธุรกรรม — ช่อง chainId ถ้ามี ไม่งั้นถอดจาก v ของ EIP-155 (v = chainId*2 + 35/36).
     */
    private function chainIdOf(array $tx): ?int
    {
        $chainId = TpixRpcClient::hexToInt($tx['chainId'] ?? null);
        if ($chainId !== null) {
            return $chainId;
        }

        $v = TpixRpcClient::hexToInt($tx['v'] ?? null);

        return ($v !== null && $v >= 35) ? intdiv($v - 35, 2) : null;
    }
}
