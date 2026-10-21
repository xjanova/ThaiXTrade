<?php

namespace App\Services\Bridge\Wtpix;

use App\Models\User;
use App\Models\WtpixBridgeTransfer;
use App\Services\Bridge\EvmRpc;
use App\Services\Kyc\KycGate;

/**
 * กฎของสะพาน TPIX ⇄ wTPIX — ตัดสินว่ารายการหนึ่ง "ทำต่อเองได้" หรือ "ต้องให้คนดู".
 *
 * หลักการเดียวกับสะพาน USDT: สงสัยเมื่อไหร่ → needs_review ห้าม mint/ปล่อยเอง
 * (wTPIX ที่ mint ผิดถูกเทขายใส่พูล PancakeSwap ได้ในบล็อกเดียว ส่วนการรอแอดมินแค่ช้า)
 *
 * ใช้กฎชุดเดียวกันทั้งสองทิศ ต่างกันแค่คีย์วงเงิน (lock/burn) — ตัวสแกนเรียกตอนบันทึก
 * หลังบ้านเรียกซ้ำตอนอนุมัติ
 */
class WtpixBridgePolicy
{
    public function __construct(
        private WtpixBridgeConfig $config,
        private KycGate $kyc,
    ) {}

    /**
     * @param  array{from_address: string, amount_wei: string, payout_wei: string, sender_is_contract: bool, sender_mismatch: bool}  $facts
     * @param  bool  $ignoreMinimum  แอดมินอนุมัติรายการที่ต่ำกว่าขั้นต่ำ — ข้ามเฉพาะกฎขั้นต่ำข้อเดียว กฎอื่นตรวจครบ
     * @return array{status: string, reason: ?string}
     */
    public function evaluate(string $direction, array $facts, ?int $ignoreId = null, bool $ignoreMinimum = false): array
    {
        $from = strtolower($facts['from_address']);
        $amount = $facts['amount_wei'];

        if (bccomp($amount, '0', 0) <= 0) {
            return ['status' => WtpixBridgeTransfer::STATUS_IGNORED, 'reason' => 'dust'];
        }

        if (in_array($from, $this->config->internalSenders(), true)) {
            return ['status' => WtpixBridgeTransfer::STATUS_IGNORED, 'reason' => 'internal_sender'];
        }

        // หักค่าธรรมเนียมแล้วไม่เหลือ — ไม่มีอะไรให้ทำ ไม่เข้าคิวตรวจ (กันคนถมคิวแอดมินด้วยยอดเศษ)
        if (bccomp($facts['payout_wei'], '0', 0) <= 0) {
            return ['status' => WtpixBridgeTransfer::STATUS_IGNORED, 'reason' => 'below_fee'];
        }

        // ต่ำกว่าขั้นต่ำแต่ยังทำได้ = เก็บไว้ ให้แอดมินอนุมัติได้ภายหลัง
        if (! $ignoreMinimum && bccomp($amount, $this->config->limitWei($this->config->limitKey($direction, 'min')), 0) < 0) {
            return ['status' => WtpixBridgeTransfer::STATUS_IGNORED, 'reason' => 'below_minimum'];
        }

        // ผู้ส่งเป็นสัญญา (multisig / router / กระดานเทรด) — ที่อยู่เดียวกันบนอีกเชนอาจไม่มีใครคุม
        if ($facts['sender_is_contract']) {
            return ['status' => WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, 'reason' => 'sender_is_contract'];
        }

        // คนกดส่งกับเจ้าของเหรียญเป็นคนละคน (burnFrom / bridgeBurn โดยผู้ได้รับอนุญาต) — ผู้รับอาจไม่ใช่คนที่ตั้งใจ
        if ($facts['sender_mismatch']) {
            return ['status' => WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, 'reason' => 'sender_mismatch'];
        }

        if (! $this->kycPasses($from)) {
            return ['status' => WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, 'reason' => 'kyc_required'];
        }

        if (bccomp($amount, $this->config->limitWei($this->config->limitKey($direction, 'max_tx')), 0) > 0) {
            return ['status' => WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, 'reason' => 'over_tx_limit'];
        }

        $used = $this->usedLast24hWei($direction, $from, $ignoreId);
        if (bccomp(bcadd($used, $amount, 0), $this->config->limitWei($this->config->limitKey($direction, 'daily')), 0) > 0) {
            return ['status' => WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, 'reason' => 'over_daily_address_limit'];
        }

        return ['status' => WtpixBridgeTransfer::STATUS_CONFIRMED, 'reason' => null];
    }

    /**
     * ข้อมูลประกอบจากเชนต้นทาง — ผู้ส่งเป็นสัญญาไหม · คนกดส่งธุรกรรมเป็นเจ้าของเหรียญคนเดียวกันไหม.
     *
     * ที่เดียวที่คำนวณสองข้อนี้ — ตัวสแกนใช้ตอนบันทึก หลังบ้านใช้ตอนอนุมัติรายการที่ตัวสแกนไม่ได้ถาม
     * (รายการ ignored ไม่ถูกถามเชนเพื่อประหยัด RPC จึงยังไม่มีสองข้อนี้)
     *
     * @return array{sender_is_contract: bool, sender_mismatch: bool}|null null = อ่านเชนไม่ได้ (ห้ามเดา)
     */
    public function senderFacts(EvmRpc $rpc, string $from, string $txHash): ?array
    {
        $code = $rpc->code($from);
        $tx = $rpc->transaction($txHash);

        if ($code === null || $tx === null) {
            return null;
        }

        return [
            // EIP-7702: EOA ที่มอบสิทธิ์ให้โค้ด (0xef0100…) ยังเป็นกระเป๋าที่เจ้าของถือคีย์
            'sender_is_contract' => $code !== '0x' && ! str_starts_with($code, '0xef0100'),
            'sender_mismatch' => strtolower((string) ($tx['from'] ?? '')) !== strtolower($from),
        ];
    }

    /** ยอดที่ผ่านด่านแล้วของกระเป๋านี้ในทิศเดียวกันใน 24 ชม. ล่าสุด (wei) */
    public function usedLast24hWei(string $direction, string $from, ?int $ignoreId = null): string
    {
        $values = WtpixBridgeTransfer::query()
            ->where('direction', $direction)
            ->where('from_address', strtolower($from))
            ->whereIn('status', WtpixBridgeTransfer::COUNTS_TOWARD_LIMIT)
            ->where('created_at', '>=', now()->subDay())
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->pluck('amount_wei');

        return array_reduce($values->all(), fn ($sum, $v) => bcadd($sum, (string) $v, 0), '0');
    }

    /**
     * ด่าน KYC ของฟีเจอร์ "bridge" (ตัวเดียวกับสะพาน USDT) — เปิดเมื่อแอดมินเปิดที่ /admin/kyc เท่านั้น.
     * ไม่เจอบัญชีที่ผูกกระเป๋า = ไม่ผ่าน → needs_review (เหรียญถูกล็อก/เผาไปแล้ว ปฏิเสธทิ้งไม่ได้).
     */
    private function kycPasses(string $address): bool
    {
        if (! $this->kyc->requires('bridge')) {
            return true;
        }

        $user = User::where('wallet_address', strtolower($address))->first();

        return $this->kyc->passes($user, 'bridge');
    }
}
