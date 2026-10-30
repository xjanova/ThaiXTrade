<?php

namespace App\Services\Bridge;

use App\Models\BridgeDeposit;
use App\Models\BridgeWithdrawal;
use App\Models\User;
use App\Services\Kyc\KycGate;

/**
 * กฎของสะพาน — ตัดสินว่ารายการฝาก/ถอนหนึ่งรายการ "ทำต่อเองได้" หรือ "ต้องให้คนดู".
 *
 * หลักการ: สงสัยเมื่อไหร่ → needs_review เสมอ ห้าม mint/จ่ายเองเด็ดขาด
 * (เงินที่ mint ผิดเรียกคืนไม่ได้ ส่วนการรอแอดมินแค่ช้า)
 *
 * ตัวสแกนเรียกตอนบันทึกรายการ และหลังบ้านเรียกซ้ำตอนอนุมัติ — กฎชุดเดียวกัน ไม่แยกเขียนสองที่
 */
class BridgePolicy
{
    public function __construct(
        private BridgeConfig $config,
        private KycGate $kyc,
    ) {}

    /**
     * @param  array{from_address: string, amount_units: string, mint_units: string, sender_is_contract: bool, sender_mismatch: bool}  $facts
     * @return array{status: string, reason: ?string}
     */
    public function evaluateDeposit(array $facts, ?int $ignoreId = null, bool $ignoreMinimum = false): array
    {
        $from = strtolower($facts['from_address']);
        $amount = $facts['amount_units'];

        // ยอดศูนย์ / ฝุ่นต่ำกว่า 6 ทศนิยม — Transfer มูลค่า 0 ยิงใส่ vault ได้ฟรี (address poisoning)
        if (bccomp($amount, '0', 0) <= 0 || bccomp($facts['mint_units'], '0', 0) <= 0) {
            return ['status' => BridgeDeposit::STATUS_IGNORED, 'reason' => 'dust'];
        }

        // ย้ายเงินภายใน (เติมเงินหนุน / กระเป๋าจ่ายคืนเงินเข้า vault) — ไม่ใช่การฝาก
        if (in_array($from, $this->config->internalSenders(), true)) {
            return ['status' => BridgeDeposit::STATUS_IGNORED, 'reason' => 'internal_sender'];
        }

        if (! $ignoreMinimum && bccomp($amount, $this->config->limitUnits('min_deposit'), 0) < 0) {
            return ['status' => BridgeDeposit::STATUS_IGNORED, 'reason' => 'below_minimum'];
        }

        // ผู้ส่งเป็นสัญญา (multisig / router / กระดานเทรด) — ที่อยู่เดียวกันบนเชน TPIX อาจไม่มีใครคุม
        if ($facts['sender_is_contract']) {
            return ['status' => BridgeDeposit::STATUS_NEEDS_REVIEW, 'reason' => 'sender_is_contract'];
        }

        // คนกดส่งธุรกรรมกับเจ้าของเหรียญเป็นคนละคน (transferFrom ผ่านตัวกลาง/บริการโอนแทน)
        if ($facts['sender_mismatch']) {
            return ['status' => BridgeDeposit::STATUS_NEEDS_REVIEW, 'reason' => 'sender_mismatch'];
        }

        if (! $this->kycPasses($from)) {
            return ['status' => BridgeDeposit::STATUS_NEEDS_REVIEW, 'reason' => 'kyc_required'];
        }

        if (bccomp($amount, $this->config->limitUnits('max_deposit_per_tx'), 0) > 0) {
            return ['status' => BridgeDeposit::STATUS_NEEDS_REVIEW, 'reason' => 'over_tx_limit'];
        }

        $usedToday = $this->depositedLast24hUnits($from, $ignoreId);
        if (bccomp(bcadd($usedToday, $amount, 0), $this->config->limitUnits('max_deposit_per_address_daily'), 0) > 0) {
            return ['status' => BridgeDeposit::STATUS_NEEDS_REVIEW, 'reason' => 'over_daily_address_limit'];
        }

        return ['status' => BridgeDeposit::STATUS_CONFIRMED, 'reason' => null];
    }

    /**
     * ข้อมูลประกอบจากเชนต้นทาง — ผู้ส่งเป็นสัญญาไหม · คนกดส่งธุรกรรมเป็นเจ้าของเหรียญคนเดียวกันไหม.
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

    /** ยอดที่ผ่านด่านแล้วของกระเป๋านี้ใน 24 ชม. ล่าสุด (หน่วย 6 ทศนิยม) */
    public function depositedLast24hUnits(string $from, ?int $ignoreId = null): string
    {
        $values = BridgeDeposit::query()
            ->where('from_address', strtolower($from))
            ->whereIn('status', BridgeDeposit::COUNTS_TOWARD_LIMIT)
            ->where('created_at', '>=', now()->subDay())
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->pluck('amount_units');

        return array_reduce($values->all(), fn ($sum, $v) => bcadd($sum, (string) $v, 0), '0');
    }

    /**
     * @param  array{target_chain_id: ?int, target_address: string, amount_units: string, payout_units: string, from_address: string}  $facts
     * @return array{status: string, reason: ?string}
     */
    public function evaluateWithdrawal(array $facts, bool $limitsOverridden = false): array
    {
        $chainId = $facts['target_chain_id'];

        if ($chainId === null || ! $this->config->chainEnabled($chainId)) {
            return ['status' => BridgeWithdrawal::STATUS_NEEDS_REVIEW, 'reason' => 'unsupported_chain'];
        }

        $target = $facts['target_address'];
        if (! Abi::isValidAddress($target)) {
            return ['status' => BridgeWithdrawal::STATUS_NEEDS_REVIEW, 'reason' => 'invalid_target_address'];
        }

        // ส่งเข้าตัวสัญญาเหรียญ / vault / กระเป๋าจ่ายของเราเอง = พิมพ์ผิดแน่นอน เงินจะติดอยู่ตรงนั้น
        $token = strtolower((string) ($this->config->chain($chainId)['usdt']['address'] ?? ''));
        $ours = array_filter([$token, $this->config->vaultAddress(), $this->config->signerAddress('payout')]);
        if (in_array(strtolower($target), $ours, true)) {
            return ['status' => BridgeWithdrawal::STATUS_NEEDS_REVIEW, 'reason' => 'suspicious_target'];
        }

        // หักค่าธรรมเนียมแล้วเหลือศูนย์ = ไม่มีอะไรให้จ่าย (อนุมัติก็ไม่ช่วย) — ไม่เข้าคิวตรวจ
        // เผาเศษบนเชน TPIX แก๊สเป็นศูนย์ ถ้าเข้าคิวตรวจ คนเดียวก็ถมคิวแอดมินได้เป็นล้านแถว
        if (bccomp($facts['payout_units'], '0', 0) <= 0) {
            return ['status' => BridgeWithdrawal::STATUS_IGNORED, 'reason' => 'below_fee'];
        }

        // ต่ำกว่าขั้นต่ำแต่ยังจ่ายได้ = แอดมินรับรองได้
        if (! $limitsOverridden && bccomp($facts['amount_units'], $this->config->limitUnits('min_withdraw'), 0) < 0) {
            return ['status' => BridgeWithdrawal::STATUS_NEEDS_REVIEW, 'reason' => 'below_minimum'];
        }

        // แอดมินอนุมัติรายการนี้แล้ว = รับรองข้อสงสัยแบบ "อ่อน" (KYC / วงเงิน) แทน ส่วนข้อข้างบนยังบังคับเสมอ
        if (! $limitsOverridden && ! $this->kycPasses($facts['from_address'])) {
            return ['status' => BridgeWithdrawal::STATUS_NEEDS_REVIEW, 'reason' => 'kyc_required'];
        }

        if (! $limitsOverridden && bccomp($facts['amount_units'], $this->config->limitUnits('max_withdraw_per_tx'), 0) > 0) {
            return ['status' => BridgeWithdrawal::STATUS_NEEDS_REVIEW, 'reason' => 'over_tx_limit'];
        }

        return ['status' => BridgeWithdrawal::STATUS_PENDING, 'reason' => null];
    }

    /** ยอดจ่ายออกของวันนี้ (UTC — ตรงกับรอบวันของ minter บนเชน) รวมที่กำลังส่ง */
    public function paidTodayUnits(): string
    {
        $values = BridgeWithdrawal::query()
            ->whereIn('status', [BridgeWithdrawal::STATUS_SENDING, BridgeWithdrawal::STATUS_PAID])
            // เริ่มวันตาม UTC แล้วแปลงกลับเป็นเขตเวลาของแอป เพราะคอลัมน์ถูกเขียนด้วยเวลาของแอป
            ->where('payout_broadcast_at', '>=', now('UTC')->startOfDay()->setTimezone(config('app.timezone')))
            ->pluck('payout_units');

        return array_reduce($values->all(), fn ($sum, $v) => bcadd($sum, (string) $v, 0), '0');
    }

    /**
     * ด่าน KYC ของฟีเจอร์ "bridge" — เปิดอยู่เมื่อแอดมินเปิดที่ /admin/kyc เท่านั้น.
     *
     * สะพานไม่มีการล็อกอิน (ผู้ใช้แค่โอนบนเชน) จึงผูกกระเป๋ากับบัญชีที่ยืนยันตัวตนแล้ว
     * ไม่เจอบัญชี = ไม่ผ่าน → needs_review ให้แอดมินตัดสิน (ไม่ใช่ปฏิเสธทิ้ง เพราะเงินเข้ามาแล้ว)
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
