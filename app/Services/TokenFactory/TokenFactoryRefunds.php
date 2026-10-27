<?php

namespace App\Services\TokenFactory;

use App\Models\FactoryToken;
use App\Models\TokenFactoryFeeQuote;
use App\Models\TreasuryPayout;
use App\Services\TreasuryService;
use App\Support\TreasuryWallet;
use App\Support\Wei;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * คืนค่าธรรมเนียมสร้างเหรียญเมื่อแอดมินปฏิเสธคำขอ — คืนเต็มจำนวนที่โอนมาจริง.
 *
 * เจ้าของสั่ง: "reject แล้วคืนเหรียญ TPIX ด้วย" และให้แอดมินเลือกวิธีได้ (นโยบาย refund_mode)
 *
 *   manual — ระบบเปิดรายการ "รอคืน" ไว้ แอดมินโอนจากกระเป๋าของตัวเอง (กระเป๋ารับค่าธรรมเนียม
 *            หรือกระเป๋าร้อน) แล้ววาง tx hash ให้ระบบตรวจบนเชนก่อนปิดรายการ
 *            เซิร์ฟเวอร์ไม่มีกุญแจของกระเป๋ารับค่าธรรมเนียม จึงจ่ายเองไม่ได้
 *   auto   — ระบบสร้างรายการจ่ายในคิว treasury_payouts (กระเป๋าร้อนของคลัง) ซึ่งมีครบแล้ว:
 *            วงเงินต่อครั้ง/ต่อวัน, เซ็นฝั่ง CLI, บันทึก hash ก่อนส่ง (กันจ่ายซ้ำ)
 *            ผลของคิวไหลกลับมาที่แถวเหรียญผ่าน syncFromPayout() (TreasuryPayout::updated)
 *
 * แถวที่ไม่ได้จ่ายอะไร (ฟรี / คำขอระบบเก่า) ไม่มีรายการคืนเงิน — refund_status = null
 *
 * Developed by Xman Studio
 */
class TokenFactoryRefunds
{
    public const PAYOUT_PURPOSE = 'token_factory_refund';

    public function __construct(
        private readonly TokenFactoryPolicy $policy,
        private readonly TpixRpcClient $rpc,
        private readonly TreasuryService $treasury,
    ) {}

    /**
     * ยอดที่ต้องคืน (wei) — null = คำขอนี้ไม่ได้จ่ายเงิน.
     *
     * ใช้ยอดที่โอนมาจริง (fee_value_wei) ถ้ามี — ผู้ใช้โอนเกินก็คืนให้ครบ
     * คำขอก่อนมีช่องนี้ใช้ยอดในใบเสนอราคา
     */
    public function refundableWei(FactoryToken $token): ?string
    {
        if (! $token->fee_tx_hash) {
            return null;
        }

        $paid = $token->metadata['fee_value_wei'] ?? null;
        if (is_string($paid) && preg_match('/^[0-9]+$/', $paid) && bccomp($paid, '0', 0) > 0) {
            return $paid;
        }

        $quote = $token->fee_quote_id ? TokenFactoryFeeQuote::find($token->fee_quote_id) : null;
        if ($quote && bccomp((string) $quote->amount_wei, '0', 0) > 0) {
            return (string) $quote->amount_wei;
        }

        return null;
    }

    /**
     * หลังปฏิเสธ — เปิดรายการคืนเงิน (และสั่งจ่ายทันทีถ้านโยบายเป็น auto).
     *
     * จ่ายอัตโนมัติไม่ผ่าน (วงเงิน/เงินไม่พอ) ไม่ทำให้การปฏิเสธล้ม — รายการกลายเป็น failed
     * ให้แอดมินสั่งใหม่หรือโอนเองจากหน้าเดิม
     */
    public function openForRejected(FactoryToken $token): void
    {
        if ($token->status !== 'rejected' || $token->refund_status !== null) {
            return;
        }

        $amount = $this->refundableWei($token);
        if ($amount === null) {
            return;
        }

        $mode = $this->policy->refundMode();

        FactoryToken::whereKey($token->id)->whereNull('refund_status')->update([
            'refund_status' => FactoryToken::REFUND_PENDING,
            'refund_mode' => $mode,
            'refund_amount_wei' => $amount,
            'updated_at' => now(),
        ]);

        if ($mode !== TokenFactoryPolicy::REFUND_AUTO) {
            return;
        }

        try {
            $this->queueAuto($token->fresh());
        } catch (TokenFactoryException $e) {
            $this->markFailed($token->id, $e->getMessage());
            Log::warning("Token factory refund #{$token->id} auto payout not queued", ['reason' => $e->getMessage()]);
        }
    }

    /**
     * สั่งจ่ายคืนจากกระเป๋าร้อน (ครั้งแรก หรือแอดมินกดสั่งใหม่หลังล้มเหลว).
     *
     * @throws TokenFactoryException
     */
    public function queueAuto(FactoryToken $token): TreasuryPayout
    {
        return DB::transaction(function () use ($token) {
            $row = FactoryToken::whereKey($token->id)->lockForUpdate()->first();
            $this->assertRefundOpen($row);

            // มีรายการจ่ายที่ยังไม่จบอยู่แล้ว = ห้ามสร้างซ้ำ (กดสองครั้ง / สองแอดมินพร้อมกัน)
            $open = $this->payoutsFor($row->id)
                ->whereNotIn('status', [TreasuryPayout::STATUS_FAILED, TreasuryPayout::STATUS_REJECTED])
                ->first();
            if ($open) {
                $this->linkPayout($row, $open);

                return $open;
            }

            $amount = (string) $row->refund_amount_wei;
            $check = $this->treasury->validatePayout($row->creator_address, $amount, null, self::PAYOUT_PURPOSE);
            if (! $check['ok']) {
                throw new TokenFactoryException('REFUND_AUTO_BLOCKED', 'จ่ายคืนอัตโนมัติไม่ได้: '.implode(' / ', $check['errors']));
            }

            $attempt = $this->payoutsFor($row->id)->count() + 1;
            $payout = TreasuryPayout::create([
                'idempotency_key' => self::PAYOUT_PURPOSE.":{$row->id}:{$attempt}",
                'to_address' => $row->creator_address,
                'amount_wei' => $amount,
                'purpose' => self::PAYOUT_PURPOSE,
                'memo' => "คืนค่าธรรมเนียมสร้างเหรียญ {$row->symbol} (คำขอ #{$row->id} ถูกปฏิเสธ)",
                // อนุมัติแล้วโดยนโยบาย (refund_mode = auto) — คิวเซ็นและส่งในรอบ tpix:treasury-payouts ถัดไป
                'status' => TreasuryPayout::STATUS_BROADCASTING,
                'approved_at' => now(),
                'hot_balance_at_approval_wei' => $this->treasury->hotWallet()['balance_wei'],
            ]);

            $this->linkPayout($row, $payout);

            return $payout;
        });
    }

    /**
     * แอดมินโอนคืนเองแล้ว — ตรวจธุรกรรมบนเชนก่อนปิดรายการ ไม่เชื่อ hash ที่วางมาเฉย ๆ.
     *
     * @throws TokenFactoryException
     */
    public function confirmManual(FactoryToken $token, string $txHash): FactoryToken
    {
        $txHash = strtolower(trim($txHash));
        if (! preg_match('/^0x[a-f0-9]{64}$/', $txHash)) {
            throw new TokenFactoryException('REFUND_TX_INVALID', 'รูปแบบ tx hash ไม่ถูกต้อง');
        }

        $this->assertRefundOpen($token);

        $sending = $token->refund_payout_id ? TreasuryPayout::find($token->refund_payout_id) : null;
        if ($sending && ! in_array($sending->status, [TreasuryPayout::STATUS_FAILED, TreasuryPayout::STATUS_REJECTED], true)) {
            throw new TokenFactoryException('REFUND_AUTO_IN_PROGRESS', "ระบบกำลังจ่ายคืนอัตโนมัติอยู่ (คิว #{$sending->id}) — รอผลก่อน ไม่งั้นจะคืนซ้ำ", 409);
        }

        if (FactoryToken::where('refund_tx_hash', $txHash)->exists() || TokenFactoryFeeVerifier::txAlreadyUsed($txHash)) {
            throw new TokenFactoryException('REFUND_TX_USED', 'ธุรกรรมนี้ถูกใช้ยืนยันรายการอื่นไปแล้ว', 409);
        }

        $tx = $this->ask('eth_getTransactionByHash', [$txHash]);
        if (! is_array($tx)) {
            throw new TokenFactoryException('REFUND_TX_NOT_FOUND', 'ยังไม่พบธุรกรรมนี้บนเชน TPIX — รอสักครู่แล้วลองใหม่');
        }

        if (strtolower((string) ($tx['to'] ?? '')) !== strtolower((string) $token->creator_address)) {
            throw new TokenFactoryException('REFUND_WRONG_RECIPIENT', "ธุรกรรมนี้ไม่ได้โอนไปที่กระเป๋าผู้ขอ ({$token->creator_address})");
        }

        $from = strtolower((string) ($tx['from'] ?? ''));
        if (! in_array($from, $this->allowedSenders($token), true)) {
            throw new TokenFactoryException('REFUND_WRONG_SENDER', 'ต้องโอนคืนจากกระเป๋ารับค่าธรรมเนียมหรือกระเป๋าร้อนของระบบเท่านั้น (ธุรกรรมนี้มาจาก '.$from.')');
        }

        $chainId = TpixRpcClient::hexToInt($tx['chainId'] ?? null);
        if ($chainId !== null && $chainId !== $this->policy->chainId()) {
            throw new TokenFactoryException('REFUND_WRONG_CHAIN', 'ต้องโอนคืนบนเชน TPIX ('.$this->policy->chainId().') เท่านั้น');
        }

        $value = Wei::hexToInt(is_string($tx['value'] ?? null) ? $tx['value'] : '0x0');
        if (bccomp($value, (string) $token->refund_amount_wei, 0) < 0) {
            throw new TokenFactoryException(
                'REFUND_TOO_LOW',
                'ยอดที่โอนคืน ('.Wei::format($value).' TPIX) น้อยกว่ายอดที่ต้องคืน ('.Wei::format((string) $token->refund_amount_wei).' TPIX)'
            );
        }

        $receipt = $this->ask('eth_getTransactionReceipt', [$txHash]);
        $block = is_array($receipt) ? TpixRpcClient::hexToInt($receipt['blockNumber'] ?? null) : null;
        if ($block === null) {
            throw new TokenFactoryException('REFUND_TX_NOT_FOUND', 'ธุรกรรมยังไม่ถูกบรรจุลงบล็อก — รอสักครู่แล้วลองใหม่');
        }
        if (($receipt['status'] ?? null) !== '0x1') {
            throw new TokenFactoryException('REFUND_TX_FAILED', 'ธุรกรรมนี้ล้มเหลวบนเชน (revert) — ไม่มีเงินถึงผู้ขอ');
        }

        // ต้องเกิดหลังผู้ใช้จ่ายค่าธรรมเนียม — กันเอาธุรกรรมเก่าที่เคยโอนให้กระเป๋านี้มานับเป็นการคืนเงิน
        $feeBlock = (int) ($token->metadata['fee_block'] ?? 0);
        if ($feeBlock > 0 && $block <= $feeBlock) {
            throw new TokenFactoryException('REFUND_TX_TOO_OLD', 'ธุรกรรมนี้เกิดก่อนหรือพร้อมการจ่ายค่าธรรมเนียม จึงนับเป็นการคืนเงินไม่ได้');
        }

        $latest = TpixRpcClient::hexToInt($this->ask('eth_blockNumber', []));
        $required = (int) $this->policy->effective()['required_confirmations'];
        $confirmations = $latest === null ? 0 : max(0, $latest - $block + 1);
        if ($confirmations < $required) {
            throw new TokenFactoryException('REFUND_TX_PENDING', "รอการยืนยันบนเชน ({$confirmations}/{$required} บล็อก) แล้วกดยืนยันอีกครั้ง", 202);
        }

        try {
            $updated = FactoryToken::whereKey($token->id)
                ->where('status', 'rejected')
                ->whereIn('refund_status', [FactoryToken::REFUND_PENDING, FactoryToken::REFUND_FAILED])
                ->update([
                    'refund_status' => FactoryToken::REFUND_REFUNDED,
                    'refund_mode' => TokenFactoryPolicy::REFUND_MANUAL,
                    'refund_tx_hash' => $txHash,
                    'refunded_at' => now(),
                    'updated_at' => now(),
                ]);
        } catch (QueryException) {
            throw new TokenFactoryException('REFUND_TX_USED', 'ธุรกรรมนี้ถูกใช้ยืนยันรายการอื่นไปแล้ว', 409);
        }

        if ($updated !== 1) {
            throw new TokenFactoryException('REFUND_NOT_OPEN', 'รายการคืนเงินนี้ถูกปิดไปแล้ว', 409);
        }

        Log::info("Token factory refund #{$token->id} confirmed (manual)", ['tx' => $txHash, 'amount_wei' => $token->refund_amount_wei]);

        return $token->fresh();
    }

    /**
     * ผลของคิวจ่ายเงิน → สถานะคืนเงินของเหรียญ (เรียกจาก TreasuryPayout::updated).
     */
    public function syncFromPayout(TreasuryPayout $payout): void
    {
        if ($payout->purpose !== self::PAYOUT_PURPOSE) {
            return;
        }

        $token = FactoryToken::where('refund_payout_id', $payout->id)->first();
        if (! $token || $token->refund_status === FactoryToken::REFUND_REFUNDED) {
            return;
        }

        if ($payout->status === TreasuryPayout::STATUS_CONFIRMED) {
            FactoryToken::whereKey($token->id)->update([
                'refund_status' => FactoryToken::REFUND_REFUNDED,
                'refund_tx_hash' => strtolower((string) $payout->tx_hash),
                'refunded_at' => $payout->confirmed_at ?? now(),
                'updated_at' => now(),
            ]);

            return;
        }

        if (in_array($payout->status, [TreasuryPayout::STATUS_FAILED, TreasuryPayout::STATUS_REJECTED], true)) {
            $this->markFailed($token->id, (string) ($payout->failure_reason ?: 'คิวจ่ายเงินถูกยกเลิก'));
        }
    }

    /** กระเป๋าที่ยอมรับเป็นผู้โอนคืน — กระเป๋ารับค่าธรรมเนียม (ตอนจ่าย + ตอนนี้) และกระเป๋าร้อนของคลัง */
    private function allowedSenders(FactoryToken $token): array
    {
        $wallets = [
            $token->metadata['fee_wallet'] ?? null,
            $this->policy->feeWallet(),
            TreasuryWallet::address(),
        ];

        return array_values(array_unique(array_filter(
            array_map(fn ($w) => strtolower(trim((string) $w)), $wallets),
            fn ($w) => (bool) preg_match('/^0x[a-f0-9]{40}$/', $w)
        )));
    }

    /** @throws TokenFactoryException */
    private function assertRefundOpen(?FactoryToken $token): void
    {
        if (! $token || $token->status !== 'rejected') {
            throw new TokenFactoryException('REFUND_NOT_OPEN', 'คืนเงินได้เฉพาะคำขอที่ถูกปฏิเสธ', 409);
        }

        if ($token->refund_status === FactoryToken::REFUND_REFUNDED) {
            throw new TokenFactoryException('REFUND_ALREADY_DONE', 'คืนค่าธรรมเนียมรายการนี้ไปแล้ว', 409);
        }

        if (! in_array($token->refund_status, [FactoryToken::REFUND_PENDING, FactoryToken::REFUND_FAILED, FactoryToken::REFUND_SENDING], true)
            || bccomp((string) ($token->refund_amount_wei ?? '0'), '0', 0) <= 0) {
            throw new TokenFactoryException('REFUND_NOT_OPEN', 'คำขอนี้ไม่มียอดที่ต้องคืน (ไม่ได้จ่ายค่าธรรมเนียม)', 409);
        }
    }

    private function payoutsFor(int $tokenId)
    {
        return TreasuryPayout::where('purpose', self::PAYOUT_PURPOSE)
            ->where('idempotency_key', 'like', self::PAYOUT_PURPOSE.":{$tokenId}:%");
    }

    private function linkPayout(FactoryToken $token, TreasuryPayout $payout): void
    {
        FactoryToken::whereKey($token->id)->update([
            'refund_status' => FactoryToken::REFUND_SENDING,
            'refund_mode' => TokenFactoryPolicy::REFUND_AUTO,
            'refund_payout_id' => $payout->id,
            'updated_at' => now(),
        ]);
    }

    private function markFailed(int $tokenId, string $reason): void
    {
        $token = FactoryToken::find($tokenId);
        if (! $token || $token->refund_status === FactoryToken::REFUND_REFUNDED) {
            return;
        }

        $metadata = $token->metadata ?? [];
        $metadata['refund_error'] = mb_substr($reason, 0, 300);

        FactoryToken::whereKey($tokenId)->update([
            'refund_status' => FactoryToken::REFUND_FAILED,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }

    private function ask(string $method, array $params): mixed
    {
        try {
            return $this->rpc->call($method, $params);
        } catch (RuntimeException) {
            throw new TokenFactoryException('CHAIN_UNAVAILABLE', 'ติดต่อเชน TPIX ไม่ได้ชั่วคราว กรุณาลองใหม่อีกครั้ง', 503);
        }
    }
}
