<?php

namespace App\Services\Trading;

use App\Services\Bridge\Abi;
use App\Services\Bridge\EvmRpc;
use App\Support\Wei;

/**
 * TPIX TRADE — อ่านไม้สวอปและธุรกรรมค่าธรรมเนียมบน BSC จากใบเสร็จบนเชนเอง (อ่านอย่างเดียว ไม่มีคีย์).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ⚠️ เดิม /swap/execute เชื่อทุกตัวเลขที่หน้าเว็บส่งมา แล้วบันทึก confirmed ทันที (ออดิท 2026-10-28 C2)
 * ═══════════════════════════════════════════════════════════════════════════
 *   - tx hash มั่ว 64 ตัว = ไม้ "สำเร็จ" ในประวัติ
 *   - แจ้ง hash ของคนอื่นก่อนเจ้าของ = แถวของเจ้าของจริงชน unique แล้วหายจากประวัติ
 *   - "เก็บค่าธรรมเนียมแล้ว" ตามที่ client บอก ไม่มี hash ให้ตรวจ แต่ถูกนับเป็นรายได้
 *   ตอนนี้ผู้ส่ง ปลายทาง และยอด มาจากใบเสร็จทั้งหมด
 *
 * ผลลัพธ์แยก "ยังไม่รู้" ออกจาก "ไม่ใช่" เสมอ — ยังไม่ลงบล็อก / RPC ล่ม ต้องรอ ห้ามสรุปว่าไม้ล้ม
 *
 * Developed by Xman Studio.
 */
class BscSwapVerifier
{
    public const CHAIN_ID = 56;

    /** ลงบล็อกแล้วและตรงทุกข้อ */
    public const CONFIRMED = 'confirmed';

    /** เชนรู้จักธุรกรรมแล้ว ผู้ส่งถูกต้อง แต่ยังไม่ลงบล็อก */
    public const PENDING = 'pending';

    /** RPC ที่ตอบได้ไม่มีตัวไหนเห็นธุรกรรมนี้ — ยังกระจายไม่ถึง / hash มั่ว / ถูกแทนที่ด้วย speed up */
    public const UNKNOWN = 'unknown';

    /** RPC ล่มทุกตัว — ไม่รู้อะไรเลย */
    public const UNAVAILABLE = 'unavailable';

    /** revert บนเชน */
    public const FAILED = 'failed';

    /** ผู้ส่งไม่ใช่กระเป๋านี้ */
    public const WALLET_MISMATCH = 'wallet_mismatch';

    /** สวอป: ไม่ได้ส่งเข้า router ที่หน้าเว็บใช้ · ค่าธรรมเนียม BNB: ไม่ได้โอนเข้ากระเป๋าค่าธรรมเนียม */
    public const WRONG_TARGET = 'wrong_target';

    /** ส่งถูกที่ แต่ไม่มีเหรียญที่อ้างเคลื่อนออก/เข้ากระเป๋านี้ */
    public const NO_TRANSFER = 'no_transfer';

    private const PANCAKE_V2_ROUTER = '0x10ed43c718714eb63d5aa57b78b54704e256024e';

    private const WBNB = '0xbb4cdb9cbd36b01bd1cbaebf2de08d9173bc095c';

    // Transfer(address,address,uint256) · Deposit(address,uint256) · Withdrawal(address,uint256)
    private const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    private const DEPOSIT_TOPIC = '0xe1fffcc4923d04b559f4d29a8bfc6cda04eb5b0d3c460751c2402c5c5cc9109c';

    private const WITHDRAWAL_TOPIC = '0x7fcf532c15f0a6db0bd6d0e038bea71d30d808c7d98cb3bf7268a95bf5081b65';

    private const NATIVE = ['0x0000000000000000000000000000000000000000', '0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'];

    public function __construct(private ?EvmRpc $rpc = null) {}

    /**
     * ไม้สวอปของกระเป๋านี้: ยอดที่ router ดึงออกจากกระเป๋า และยอดที่เข้ากระเป๋า (หน่วยเล็กสุด).
     *
     * เหรียญ = ผลรวม Transfer ของเหรียญนั้นที่ออกจาก/เข้ากระเป๋า
     * BNB ขาเข้า = WBNB Deposit ที่ router ห่อ · BNB ขาออก = WBNB Withdrawal ที่ router ถอนส่งให้
     *
     * @return array{status: string, amount_in?: string, amount_out?: string, block_number?: int}
     */
    public function swap(string $txHash, string $wallet, string $fromToken, string $toToken): array
    {
        $wallet = $this->lower($wallet);
        [$receipt, $status] = $this->receiptFor($txHash, $wallet);
        if ($receipt === null) {
            return ['status' => $status];
        }

        if (! $this->succeeded($receipt)) {
            return ['status' => self::FAILED];
        }

        $router = $this->router();
        if ($this->lower($receipt['to'] ?? null) !== $router) {
            return ['status' => self::WRONG_TARGET];
        }

        $in = $this->isNative($fromToken)
            ? $this->sumLogs($receipt, $this->wrappedNative(), self::DEPOSIT_TOPIC, [1 => $router])
            : $this->sumLogs($receipt, $fromToken, self::TRANSFER_TOPIC, [1 => $wallet]);
        $out = $this->isNative($toToken)
            ? $this->sumLogs($receipt, $this->wrappedNative(), self::WITHDRAWAL_TOPIC, [1 => $router])
            : $this->sumLogs($receipt, $toToken, self::TRANSFER_TOPIC, [2 => $wallet]);

        if ($in === '0' || $out === '0') {
            return ['status' => self::NO_TRANSFER];
        }

        return [
            'status' => self::CONFIRMED,
            'amount_in' => $in,
            'amount_out' => $out,
            'block_number' => (int) $this->hexAmount($receipt['blockNumber'] ?? null),
        ];
    }

    /**
     * ผลของธุรกรรมไม้ (ไม่ดูเหรียญ) — ใช้ตัดสินใบอนุญาตวางไม้ที่ผูก tx ไว้แล้ว.
     *
     * CONFIRMED = ลงเชนสำเร็จ ส่งโดยกระเป๋านี้ เข้า router ที่หน้าเว็บใช้
     */
    public function outcome(string $txHash, string $wallet): string
    {
        [$receipt, $status] = $this->receiptFor($txHash, $this->lower($wallet));
        if ($receipt === null) {
            return $status;
        }

        if (! $this->succeeded($receipt)) {
            return self::FAILED;
        }

        return $this->lower($receipt['to'] ?? null) === $this->router() ? self::CONFIRMED : self::WRONG_TARGET;
    }

    /**
     * ธุรกรรมค่าธรรมเนียม: กระเป๋านี้โอน $token เข้า $recipient เท่าไร (หน่วยเล็กสุด).
     *
     * เหรียญ = ผลรวม Transfer(จากกระเป๋านี้ → ผู้รับ) ในใบเสร็จ
     * BNB = ธุรกรรมต้องส่งตรงเข้าผู้รับ ยอดคือ value ของธุรกรรม (การโอน BNB ไม่มี log)
     *
     * @return array{status: string, amount?: string}
     */
    public function transfer(string $txHash, string $wallet, string $recipient, string $token): array
    {
        $wallet = $this->lower($wallet);
        $recipient = $this->lower($recipient);
        [$receipt, $status] = $this->receiptFor($txHash, $wallet);
        if ($receipt === null) {
            return ['status' => $status];
        }

        if (! $this->succeeded($receipt)) {
            return ['status' => self::FAILED];
        }

        if ($this->isNative($token)) {
            if ($this->lower($receipt['to'] ?? null) !== $recipient) {
                return ['status' => self::WRONG_TARGET];
            }
            $tx = $this->rpc()->transactionAny($txHash);
            if ($tx === null) {
                return ['status' => self::UNAVAILABLE];
            }
            $amount = $this->hexAmount($tx['value'] ?? null);
        } else {
            $amount = $this->sumLogs($receipt, $token, self::TRANSFER_TOPIC, [1 => $wallet, 2 => $recipient]);
        }

        return $amount === '0'
            ? ['status' => self::NO_TRANSFER]
            : ['status' => self::CONFIRMED, 'amount' => $amount];
    }

    /** decimals ของเหรียญบน BSC — null = อ่านไม่ได้ */
    public function decimals(string $token): ?int
    {
        if ($this->isNative($token)) {
            return 18;
        }

        $value = $this->rpc()->callUint($this->lower($token), 'decimals()');

        return $value !== null && ctype_digit($value) && (int) $value <= 36 ? (int) $value : null;
    }

    public function isNative(string $token): bool
    {
        return in_array($this->lower($token), self::NATIVE, true);
    }

    // ── ภายใน ────────────────────────────────────────────────────────────────

    /**
     * ใบเสร็จที่ส่งโดยกระเป๋านี้ — หรือเหตุที่ยังไม่มี.
     *
     * ยังไม่ลงบล็อกก็ยังตรวจผู้ส่งได้จากธุรกรรมใน mempool: ห้ามรับ hash ของคนอื่นไว้รอ
     * ไม่งั้นแถว "รอยืนยัน" ของคนแจ้งปลอมไปขวางแถวของเจ้าของจริง
     *
     * @return array{0: ?array, 1: ?string}
     */
    private function receiptFor(string $txHash, string $wallet): array
    {
        $found = $this->rpc()->receiptAny($txHash);
        $receipt = $found['receipt'];

        if ($receipt === null) {
            $tx = $this->rpc()->transactionAny($txHash);
            if ($tx === null) {
                return [null, $found['nulls'] > 0 ? self::UNKNOWN : self::UNAVAILABLE];
            }

            return [null, $this->lower($tx['from'] ?? null) === $wallet ? self::PENDING : self::WALLET_MISMATCH];
        }

        if ($this->lower($receipt['from'] ?? null) !== $wallet) {
            return [null, self::WALLET_MISMATCH];
        }

        return [$receipt, null];
    }

    private function succeeded(array $receipt): bool
    {
        return $this->lower($receipt['status'] ?? null) === '0x1';
    }

    /**
     * ผลรวมยอดของ log ที่ตรงเงื่อนไข — $match = [ตำแหน่ง topic => ที่อยู่ที่ต้องตรง].
     *
     * @param  array<int, string>  $match
     */
    private function sumLogs(array $receipt, string $address, string $topic, array $match): string
    {
        $address = $this->lower($address);
        $total = '0';

        foreach ((array) ($receipt['logs'] ?? []) as $log) {
            $topics = (array) ($log['topics'] ?? []);
            if ($this->lower($log['address'] ?? null) !== $address || $this->lower($topics[0] ?? null) !== $topic) {
                continue;
            }

            foreach ($match as $index => $expected) {
                if (Abi::topicAddress($topics[$index] ?? null) !== $expected) {
                    continue 2;
                }
            }

            // ยอดอยู่ใน word แรกของ data (เหรียญที่ไม่ตรงมาตรฐานบางตัวต่อข้อมูลอื่นไว้ข้างหลัง)
            $total = bcadd($total, $this->hexAmount(substr((string) ($log['data'] ?? ''), 0, 66)), 0);
        }

        return $total;
    }

    /** เลขฐานสิบหกจากเชน → จำนวนเต็มฐานสิบ (อ่านไม่ได้ = 0 ไม่ใช่ error) */
    private function hexAmount(mixed $hex): string
    {
        try {
            return Wei::hexToInt(is_string($hex) ? $hex : null);
        } catch (\InvalidArgumentException) {
            return '0';
        }
    }

    private function router(): string
    {
        return $this->lower((string) config('chains.chains.56.swap_router', self::PANCAKE_V2_ROUTER));
    }

    private function wrappedNative(): string
    {
        return $this->lower((string) config('chains.chains.56.wrapped_native', self::WBNB));
    }

    private function lower(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }

    private function rpc(): EvmRpc
    {
        return $this->rpc ??= new EvmRpc(
            self::CHAIN_ID,
            (array) config('chains.chains.56.rpc', []),
            (string) config('bridge.user_agent', 'TPIX-Bridge/1.0 (+https://tpix.online)'),
            8,
        );
    }
}
