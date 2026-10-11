<?php

namespace App\Services\Bridge;

use App\Models\BridgeWithdrawal;
use App\Support\Wei;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BridgePayouter — จ่าย USDT จริงบนเชนปลายทางให้คำขอถอนที่ผ่านกฎแล้ว จากกระเป๋าจ่าย (payout).
 *
 * ═══ ลำดับที่กันจ่ายซ้ำ (แบบเดียวกับ mint) ═══
 *   ยังไม่มี payout_tx_hash → เช็คเงิน+แก๊สพอ → เซ็น → **บันทึก hash + raw** → ค่อย broadcast
 *   มี payout_tx_hash แล้ว  → ไม่เซ็นใหม่เด็ดขาด ไปตามผลของ hash เดิม (ไม่เจอ = ส่ง raw เดิมซ้ำ)
 *
 * ไม่จ่ายบางส่วนเด็ดขาด: เหรียญหรือแก๊สในกระเป๋าจ่ายไม่พอ → awaiting_liquidity ทั้งรายการ
 * แล้วหยุดคิวของเชนนั้น (ไม่ข้ามไปจ่ายรายการเล็กกว่าที่มาทีหลัง — คนที่ขอก่อนต้องได้ก่อน)
 *
 * ทำทีละเชน ทีละรายการ และไม่เซ็นรายการใหม่ของเชนไหนระหว่างที่ยังมีรายการ sending ค้างอยู่
 * บนเชนนั้น (nonce ถาม pending — ตัวที่หลุดจาก mempool จะถูกตัวใหม่ทับ nonce)
 */
class BridgePayouter
{
    public const MAX_SIGN_ATTEMPTS = 5;

    public const REBROADCAST_AFTER_SECONDS = 180;

    public function __construct(
        private BridgeConfig $config,
        private BridgeSigner $signer,
        private BridgePolicy $policy,
    ) {}

    /**
     * @return array{tracked: int, paid: int, signed: int, chains: array<int, ?string>, blocked: ?string}
     */
    public function run(int $limitPerChain = 3): array
    {
        $summary = ['tracked' => 0, 'paid' => 0, 'signed' => 0, 'chains' => [], 'blocked' => null];

        // ── ระยะที่ 1: ตามผลก่อนเสมอ (ทำแม้สวิตช์ปิด) ─────────────────────────
        $summary['tracked'] = $this->trackSending();

        // ── ระยะที่ 2: จ่ายใหม่ ────────────────────────────────────────────────
        if (! $this->config->operational()) {
            $summary['blocked'] = 'switched_off';

            return $summary;
        }

        if (! $this->config->signerReady('payout')) {
            $summary['blocked'] = 'payout_signer_not_configured';

            return $summary;
        }

        if (! $this->signer->isAvailable()) {
            $summary['blocked'] = 'not_cli';

            return $summary;
        }

        $chainIds = BridgeWithdrawal::whereIn('status', [BridgeWithdrawal::STATUS_PENDING, BridgeWithdrawal::STATUS_AWAITING_LIQUIDITY])
            ->whereNotNull('target_chain_id')
            ->distinct()
            ->pluck('target_chain_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($chainIds as $chainId) {
            $summary['chains'][$chainId] = $this->payChain($chainId, $limitPerChain, $summary);
        }

        return $summary;
    }

    /** คิวของเชนเดียว — คืนเหตุที่หยุด (null = คิวหมด) */
    private function payChain(int $chainId, int $limit, array &$summary): ?string
    {
        if (! $this->config->chainEnabled($chainId)) {
            return 'chain_disabled';
        }

        if (BridgeWithdrawal::where('target_chain_id', $chainId)->where('status', BridgeWithdrawal::STATUS_SENDING)->exists()) {
            return 'awaiting_previous_payout';
        }

        $rpc = $this->config->rpc($chainId);
        $chain = $this->config->chain($chainId);
        $payoutWallet = $this->config->signerAddress('payout');

        for ($i = 0; $i < max(1, $limit); $i++) {
            $candidate = BridgeWithdrawal::where('target_chain_id', $chainId)
                ->whereIn('status', [BridgeWithdrawal::STATUS_PENDING, BridgeWithdrawal::STATUS_AWAITING_LIQUIDITY])
                ->whereNull('payout_tx_hash')
                ->orderBy('id')
                ->first();

            if (! $candidate) {
                return null;
            }

            $outcome = $this->payOne($rpc, $chain, (string) $payoutWallet, $candidate);

            if ($outcome === 'paid') {
                $summary['paid']++;
                $summary['signed']++;

                continue;
            }

            if ($outcome === 'review') {
                continue;
            }

            if ($outcome === 'sent') {
                $summary['signed']++;
            }

            return $outcome;
        }

        return null;
    }

    /**
     * จ่ายหนึ่งรายการ — คืน paid | sent | review | daily_cap | no_liquidity | sign_failed | busy | rpc_unavailable.
     */
    private function payOne(EvmRpc $rpc, array $chain, string $payoutWallet, BridgeWithdrawal $candidate): string
    {
        $withdrawal = DB::transaction(function () use ($candidate) {
            $row = BridgeWithdrawal::whereKey($candidate->id)->lockForUpdate()->first();

            return $row
                && in_array($row->status, [BridgeWithdrawal::STATUS_PENDING, BridgeWithdrawal::STATUS_AWAITING_LIQUIDITY], true)
                && $row->payout_tx_hash === null ? $row : null;
        });

        if (! $withdrawal) {
            return 'busy';
        }

        // ตรวจกฎซ้ำก่อนปล่อยเงินจริง — เชนอาจถูกปิด หรือวงเงินถูกลดระหว่างรอคิว
        $check = $this->policy->evaluateWithdrawal([
            'target_chain_id' => $withdrawal->target_chain_id,
            'target_address' => $withdrawal->target_address,
            'amount_units' => (string) $withdrawal->amount_units,
            'payout_units' => (string) $withdrawal->payout_units,
            'from_address' => $withdrawal->from_address,
        ], (bool) $withdrawal->limits_overridden);

        if ($check['status'] !== BridgeWithdrawal::STATUS_PENDING) {
            $withdrawal->update(['status' => $check['status'], 'review_reason' => $check['reason']]);

            return 'review';
        }

        // เพดานจ่ายต่อวัน (UTC) — แอดมินอนุมัติเป็นรายรายการแล้วถือว่ายกเว้น
        if (! $withdrawal->limits_overridden) {
            $after = bcadd($this->policy->paidTodayUnits(), (string) $withdrawal->payout_units, 0);
            if (bccomp($after, $this->config->limitUnits('max_payout_daily'), 0) > 0) {
                $withdrawal->update(['last_error' => 'รอโควตาจ่ายของวันถัดไป (UTC)']);

                return 'daily_cap';
            }
        }

        $token = strtolower((string) $chain['usdt']['address']);
        $amountRaw = (string) $withdrawal->payout_amount_raw;

        // เงินในกระเป๋าจ่ายต้องพอทั้งก้อน — ไม่จ่ายบางส่วน
        $balance = $rpc?->tokenBalance($token, $payoutWallet);
        if ($balance === null) {
            return 'rpc_unavailable';
        }

        if (bccomp($balance, $amountRaw, 0) < 0) {
            $withdrawal->update([
                'status' => BridgeWithdrawal::STATUS_AWAITING_LIQUIDITY,
                'last_error' => 'USDT ในกระเป๋าจ่ายไม่พอ (มี '.Wei::format($balance, (int) $chain['usdt']['decimals']).')',
            ]);

            return 'no_liquidity';
        }

        $maxGwei = (string) ($chain['max_gas_price_gwei'] ?? '');
        $signed = $this->signer->sign('payout', $rpc, $token, Abi::transferCalldata($withdrawal->target_address, $amountRaw), [
            'gas_multiplier_bps' => (int) ($chain['gas_price_multiplier_bps'] ?? 12500),
            'max_gas_price_wei' => $maxGwei !== '' ? Wei::toWei($maxGwei, 9) : null,
        ]);

        if (! ($signed['ok'] ?? false)) {
            $code = (string) ($signed['code'] ?? 'error');

            if ($code === 'insufficient_gas') {
                $withdrawal->update([
                    'status' => BridgeWithdrawal::STATUS_AWAITING_LIQUIDITY,
                    'last_error' => mb_substr('ค่าแก๊สในกระเป๋าจ่ายไม่พอ: '.($signed['error'] ?? ''), 0, 300),
                ]);

                return 'no_liquidity';
            }

            $attempts = $withdrawal->payout_attempts + 1;
            $toReview = $code === 'estimate_failed' && $attempts >= self::MAX_SIGN_ATTEMPTS;

            $withdrawal->update([
                'payout_attempts' => $attempts,
                'last_error' => mb_substr("เซ็นไม่ผ่าน ({$code}): ".($signed['error'] ?? ''), 0, 300),
                'status' => $toReview ? BridgeWithdrawal::STATUS_NEEDS_REVIEW : $withdrawal->status,
                'review_reason' => $toReview ? 'payout_estimate_failed' : $withdrawal->review_reason,
            ]);

            return $toReview ? 'review' : 'sign_failed';
        }

        // ⚠️ บันทึก hash + raw ก่อนส่ง — ห้ามสลับลำดับเด็ดขาด
        // compare-and-set: แอดมินปฏิเสธระหว่างเซ็น = ทิ้งธุรกรรมที่เซ็นไว้ (ยังไม่ส่ง ไม่มีเงินออก)
        $claimed = BridgeWithdrawal::whereKey($withdrawal->id)
            ->whereIn('status', [BridgeWithdrawal::STATUS_PENDING, BridgeWithdrawal::STATUS_AWAITING_LIQUIDITY])
            ->whereNull('payout_tx_hash')
            ->update([
                'status' => BridgeWithdrawal::STATUS_SENDING,
                'payout_tx_hash' => strtolower((string) $signed['txHash']),
                'payout_raw_tx' => (string) $signed['raw'],
                'payout_nonce' => (int) ($signed['nonce'] ?? 0),
                'payout_from' => strtolower($payoutWallet),
                'payout_attempts' => $withdrawal->payout_attempts + 1,
                'payout_broadcast_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            Log::warning('bridge: รายการถอนเปลี่ยนสถานะระหว่างเซ็น — ทิ้งธุรกรรมที่เซ็นไว้ ไม่ส่ง', ['withdrawal' => $withdrawal->id]);

            return 'busy';
        }

        $withdrawal->refresh();

        $sent = $this->signer->send($rpc, (string) $signed['raw']);
        if (! ($sent['ok'] ?? false)) {
            $withdrawal->update(['last_error' => mb_substr('broadcast ไม่ผ่าน: '.($sent['error'] ?? ''), 0, 300)]);
        }

        Log::info('bridge: ส่งเงินถอนขึ้นเชนแล้ว', [
            'withdrawal' => $withdrawal->id,
            'chain' => $withdrawal->target_chain_id,
            'to' => $withdrawal->target_address,
            'raw_amount' => $amountRaw,
            'tx' => $signed['txHash'],
        ]);

        return $this->waitForReceipt($rpc, $withdrawal->fresh()) ? 'paid' : 'sent';
    }

    private function waitForReceipt(EvmRpc $rpc, BridgeWithdrawal $withdrawal): bool
    {
        $deadline = microtime(true) + max(0, (int) config('bridge.receipt_wait_seconds', 30));

        do {
            $this->trackOne($rpc, $withdrawal);
            $withdrawal->refresh();

            if ($withdrawal->status !== BridgeWithdrawal::STATUS_SENDING) {
                return $withdrawal->status === BridgeWithdrawal::STATUS_PAID;
            }

            if (microtime(true) >= $deadline) {
                return false;
            }

            usleep(3_000_000);
        } while (true);
    }

    /** ตามผลทุกรายการที่ส่งไปแล้ว — คืนจำนวนที่ได้ข้อสรุป */
    public function trackSending(): int
    {
        $settled = 0;

        $rows = BridgeWithdrawal::where('status', BridgeWithdrawal::STATUS_SENDING)
            ->orderBy('target_chain_id')->orderBy('payout_nonce')->get();

        foreach ($rows as $withdrawal) {
            $rpc = $this->config->rpc((int) $withdrawal->target_chain_id);

            if ($rpc && $this->trackOne($rpc, $withdrawal)) {
                $settled++;
            }
        }

        return $settled;
    }

    /**
     * จ่ายรายการที่เคยมี payout_tx_hash ใหม่ได้อย่างปลอดภัยหรือยัง (ใช้ตอนแอดมินอนุมัติ).
     *
     * ปลอดภัยเมื่อ: ไม่มีใบเสร็จสำเร็จของ hash เดิม **และ** nonce เดิมถูกใช้ไปแล้ว
     * (= raw เดิมไม่มีทางลงบล็อกได้อีก) — ถ้า nonce ยังว่าง raw เดิมยังลงบล็อกได้ทุกเมื่อ
     * อนุมัติจ่ายใหม่ตอนนั้นคือจ่ายสองรอบ
     *
     * @return array{safe: bool, paid: bool, reason: string}
     */
    public function retryCheck(BridgeWithdrawal $withdrawal): array
    {
        $hash = (string) $withdrawal->payout_tx_hash;
        if ($hash === '') {
            return ['safe' => true, 'paid' => false, 'reason' => 'ยังไม่เคยส่ง'];
        }

        $rpc = $this->config->rpc((int) $withdrawal->target_chain_id);
        $receipt = $rpc?->receipt($hash);

        if ($receipt !== null && strtolower((string) ($receipt['status'] ?? '')) === '0x1') {
            return ['safe' => false, 'paid' => true, 'reason' => 'ธุรกรรมเดิมจ่ายสำเร็จแล้วบนเชน'];
        }

        if ($receipt !== null) {
            return ['safe' => true, 'paid' => false, 'reason' => 'ธุรกรรมเดิม revert — เงินไม่ได้ออก'];
        }

        $from = (string) ($withdrawal->payout_from ?: $this->config->signerAddress('payout'));
        $count = $rpc?->result('eth_getTransactionCount', [$from, 'latest']);
        if (! is_string($count) || ! str_starts_with($count, '0x')) {
            return ['safe' => false, 'paid' => false, 'reason' => 'อ่าน nonce ของกระเป๋าจ่ายไม่ได้ — ลองใหม่ภายหลัง'];
        }

        if ((int) Wei::hexToInt($count) <= (int) $withdrawal->payout_nonce) {
            return ['safe' => false, 'paid' => false, 'reason' => 'nonce เดิมยังว่าง ธุรกรรมเดิมยังลงบล็อกได้ — รอให้ระบบส่งซ้ำก่อน ห้ามจ่ายใหม่'];
        }

        return ['safe' => true, 'paid' => false, 'reason' => 'nonce เดิมถูกใช้ไปแล้วและไม่มีใบเสร็จของธุรกรรมเดิม'];
    }

    private function trackOne(EvmRpc $rpc, BridgeWithdrawal $withdrawal): bool
    {
        $hash = (string) $withdrawal->payout_tx_hash;
        if ($hash === '') {
            return false;
        }

        $receipt = $rpc->receipt($hash);

        if ($receipt !== null) {
            return $this->settleReceipt($withdrawal, $receipt, $hash);
        }

        $known = $rpc->knowsTransaction($hash);
        if ($known === null || $known) {
            return false;
        }

        $age = $withdrawal->payout_broadcast_at ? now()->diffInSeconds($withdrawal->payout_broadcast_at, true) : PHP_INT_MAX;
        if ($age < self::REBROADCAST_AFTER_SECONDS || ! $withdrawal->payout_raw_tx || ! $this->signer->isAvailable()) {
            return false;
        }

        $sent = $this->signer->send($rpc, (string) $withdrawal->payout_raw_tx);

        if (($sent['code'] ?? null) === 'nonce_used') {
            // nonce ถูกใช้แล้ว — อาจเป็นธุรกรรมของเราเองที่เพิ่งลงบล็อก (RPC ตัวที่ถามใบเสร็จช้ากว่า)
            // ถามใบเสร็จอีกรอบก่อนตัดสิน ไม่งั้นรายการที่จ่ายแล้วจะไปโผล่ในคิวรออนุมัติจ่ายซ้ำ
            $again = $rpc->receipt($hash);
            if ($again !== null) {
                return $this->settleReceipt($withdrawal, $again, $hash);
            }

            // ไม่มีใบเสร็จของ hash เรา = เงินไม่ได้ออก ห้ามเซ็นใหม่เอง ให้คนตัดสิน
            $withdrawal->update([
                'status' => BridgeWithdrawal::STATUS_NEEDS_REVIEW,
                'review_reason' => 'nonce_consumed',
                'last_error' => "nonce {$withdrawal->payout_nonce} ถูกใช้โดยธุรกรรมอื่น — ตรวจกระเป๋าจ่ายก่อนอนุมัติจ่ายใหม่",
            ]);

            return true;
        }

        $withdrawal->update([
            'payout_broadcast_at' => now(),
            'last_error' => ($sent['ok'] ?? false) ? 'ส่ง raw เดิมซ้ำแล้ว' : mb_substr('ส่งซ้ำไม่ผ่าน: '.($sent['error'] ?? ''), 0, 300),
        ]);

        return false;
    }

    /** ได้ใบเสร็จแล้ว — สำเร็จ = paid · revert = needs_review (เงินไม่ได้ออก) */
    private function settleReceipt(BridgeWithdrawal $withdrawal, array $receipt, string $hash): bool
    {
        $block = isset($receipt['blockNumber']) ? (int) Wei::hexToInt((string) $receipt['blockNumber']) : null;

        if (strtolower((string) ($receipt['status'] ?? '')) === '0x1') {
            $withdrawal->update([
                'status' => BridgeWithdrawal::STATUS_PAID,
                'payout_block_number' => $block,
                'paid_at' => now(),
                'last_error' => null,
            ]);
            Log::info('bridge: จ่ายเงินถอนสำเร็จ', ['withdrawal' => $withdrawal->id, 'tx' => $hash]);

            return true;
        }

        // revert บนเชน (เช่น Tether blacklist ปลายทาง) — เงินไม่ได้ออก แต่ต้องให้คนตัดสินว่าจะทำอย่างไรต่อ
        $withdrawal->update([
            'status' => BridgeWithdrawal::STATUS_NEEDS_REVIEW,
            'review_reason' => 'payout_reverted',
            'payout_block_number' => $block,
            'last_error' => "ธุรกรรม {$hash} revert บนเชน — เงินไม่ได้ออกจากกระเป๋าจ่าย",
        ]);

        return true;
    }
}
