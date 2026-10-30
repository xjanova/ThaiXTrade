<?php

namespace App\Services\Bridge;

use App\Models\BridgeWithdrawal;
use App\Models\SystemAlert;
use App\Support\Wei;
use Illuminate\Database\Eloquent\Builder;
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
 *
 * ธุรกรรมที่ไม่ลงบล็อกนานเกิน stuck_after_minutes → needs_review (stuck_pending) + แจ้งเตือน critical
 * แต่ยังตามผลต่อ (ลงบล็อกเมื่อไหร่ก็ปิดเป็น paid เอง) และคิวของเชนนั้นยังหยุดรอ — เซ็นตัวใหม่ซ้อน nonce
 * ที่ยังค้างไม่ช่วยอะไร (ตัวใหม่ต่อคิวหลัง nonce เดิม) ทางออกคือเจ้าของส่งธุรกรรมแทนที่ด้วย nonce เดิม
 */
class BridgePayouter
{
    public const MAX_SIGN_ATTEMPTS = 5;

    public const REBROADCAST_AFTER_SECONDS = 180;

    public const REASON_STUCK = 'stuck_pending';

    public const ALERT_STUCK = 'bridge_payout_stuck';

    public const ALERT_LIQUIDITY = 'bridge_payout_liquidity';

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

        // กระเป๋าจ่ายกลับมามีเงินพอแล้ว (ไม่มีรายการรอสภาพคล่อง) — ปิดคาดแดงเอง
        if (! BridgeWithdrawal::where('status', BridgeWithdrawal::STATUS_AWAITING_LIQUIDITY)->exists()) {
            SystemAlert::resolveKeys(BridgeReconciler::ALERT_NODE, [self::ALERT_LIQUIDITY]);
        }

        return $summary;
    }

    /** รายการที่มีธุรกรรมจ่ายค้างอยู่บนเชน (กำลังส่ง หรือค้างจนส่งให้คนดูแล้วแต่ยังลงบล็อกได้) */
    private function unresolvedSends(): Builder
    {
        return BridgeWithdrawal::query()
            ->whereNotNull('payout_tx_hash')
            ->where(fn ($q) => $q->where('status', BridgeWithdrawal::STATUS_SENDING)
                ->orWhere(fn ($q) => $q->where('status', BridgeWithdrawal::STATUS_NEEDS_REVIEW)->where('review_reason', self::REASON_STUCK)));
    }

    /** คิวของเชนเดียว — คืนเหตุที่หยุด (null = คิวหมด) */
    private function payChain(int $chainId, int $limit, array &$summary): ?string
    {
        if (! $this->config->chainEnabled($chainId)) {
            return 'chain_disabled';
        }

        if ($this->unresolvedSends()->where('target_chain_id', $chainId)->exists()) {
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
            $note = 'USDT ในกระเป๋าจ่ายไม่พอ (มี '.Wei::format($balance, (int) $chain['usdt']['decimals']).')';
            $withdrawal->update(['status' => BridgeWithdrawal::STATUS_AWAITING_LIQUIDITY, 'last_error' => $note]);
            $this->alertLiquidity($chain, $withdrawal, $note);

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
                $note = mb_substr('ค่าแก๊สในกระเป๋าจ่ายไม่พอ: '.($signed['error'] ?? ''), 0, 300);
                $withdrawal->update(['status' => BridgeWithdrawal::STATUS_AWAITING_LIQUIDITY, 'last_error' => $note]);
                $this->alertLiquidity($chain, $withdrawal, $note);

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

    /** กระเป๋าจ่ายมีเหรียญ/แก๊สไม่พอ — คิวของเชนนั้นหยุดจนกว่าจะเติม จึงต้องมีคนรู้ ไม่ใช่รอเงียบ ๆ */
    private function alertLiquidity(array $chain, BridgeWithdrawal $withdrawal, string $note): void
    {
        SystemAlert::raise(
            BridgeReconciler::ALERT_NODE,
            self::ALERT_LIQUIDITY,
            'warning',
            "สะพาน USDT: กระเป๋าจ่ายบน {$chain['name']} ต้องเติมเงิน — รายการถอน #{$withdrawal->id} รอสภาพคล่อง ({$note})",
            ['withdrawal' => $withdrawal->id, 'chain_id' => $withdrawal->target_chain_id, 'payout_units' => (string) $withdrawal->payout_units],
        );
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

    /** ตามผลทุกรายการที่ส่งไปแล้ว (รวมรายการที่ค้างจนส่งให้คนดูแล้ว) — คืนจำนวนที่ได้ข้อสรุป */
    public function trackSending(): int
    {
        $settled = 0;

        $rows = $this->unresolvedSends()->orderBy('target_chain_id')->orderBy('payout_nonce')->get();

        foreach ($rows as $withdrawal) {
            $rpc = $this->config->rpc((int) $withdrawal->target_chain_id);

            if ($rpc && $this->trackOne($rpc, $withdrawal)) {
                $settled++;
            }
        }

        if (! $this->unresolvedSends()->where('review_reason', self::REASON_STUCK)->exists()) {
            SystemAlert::resolveKeys(BridgeReconciler::ALERT_NODE, [self::ALERT_STUCK]);
        }

        return $settled;
    }

    /**
     * จ่ายรายการที่เคยมี payout_tx_hash ใหม่ได้อย่างปลอดภัยหรือยัง (ใช้ตอนแอดมินอนุมัติ/ปฏิเสธ).
     *
     * ═══ "ไม่เจอใบเสร็จ" ไม่ใช่หลักฐานว่าเงินไม่ได้ออก ═══
     * RPC ล่ม / ติด rate limit / โหนดตามหัวไม่ทัน / BSC ลบ tx index เก่า (~2.35M บล็อก ≈ 20 วัน) ตอบ null ได้
     * ทั้งที่ธุรกรรมลงบล็อกและจ่ายเงินไปแล้ว — เดิมสรุปว่า "ไม่มีใบเสร็จ + nonce ถูกใช้ = ปลอดภัย" จึงจ่ายซ้ำได้
     *
     * ปลอดภัยเฉพาะเมื่อมี "หลักฐานเชิงบวก" ข้อใดข้อหนึ่ง:
     *   (ก) ใบเสร็จของ hash เดิมบอกว่า revert (เงินไม่ได้ออก)
     *   (ข) nonce เดิมถูกใช้โดย "ธุรกรรมอื่นที่ลงบล็อกแล้วจริง" (อ่านเจอทั้งตัวธุรกรรมและใบเสร็จ
     *       from = กระเป๋าจ่าย · nonce ตรง · hash ไม่ใช่ของเดิม) — nonce หนึ่งลงบล็อกได้ธุรกรรมเดียว
     *       จึงพิสูจน์ได้ว่า hash เดิมไม่มีวันลงบล็อก หาจากรายการถอนอื่นที่ใช้ nonce เดียวกัน
     *       หรือ hash ที่แอดมินกรอก (เช่นธุรกรรมยกเลิกที่เจ้าของส่งเองด้วย nonce เดิม)
     *       และธุรกรรมนั้นต้องไม่ได้โอน USDT ให้ผู้รับรายการนี้ — โอนครบยอด = จ่ายแล้ว ไม่ใช่ปลอดภัย (ดู judgeNonceTx)
     * พิสูจน์ไม่ได้ = ไม่ปลอดภัย เสมอ
     *
     * @return array{safe: bool, paid: bool, reason: string, paid_tx?: string, paid_block?: ?int}
     */
    public function retryCheck(BridgeWithdrawal $withdrawal, ?string $nonceTxHash = null): array
    {
        $hash = strtolower((string) $withdrawal->payout_tx_hash);
        if ($hash === '') {
            return ['safe' => true, 'paid' => false, 'reason' => 'ยังไม่เคยส่ง'];
        }

        $rpc = $this->config->rpc((int) $withdrawal->target_chain_id);
        if (! $rpc) {
            return ['safe' => false, 'paid' => false, 'reason' => 'เชนปลายทางไม่อยู่ในค่าตั้งแล้ว — ตรวจด้วยมือบน explorer'];
        }

        $receipt = $rpc->receiptAny($hash)['receipt'];

        if ($receipt !== null) {
            return strtolower((string) ($receipt['status'] ?? '')) === '0x1'
                ? ['safe' => false, 'paid' => true, 'reason' => 'ธุรกรรมเดิมจ่ายสำเร็จแล้วบนเชน']
                : ['safe' => true, 'paid' => false, 'reason' => 'ธุรกรรมเดิม revert — เงินไม่ได้ออก'];
        }

        // ตัวธุรกรรมบอกว่าลงบล็อกแล้ว (มี blockNumber) แต่อ่านใบเสร็จไม่ได้ — อาจจ่ายไปแล้ว ห้ามเดา
        $own = $rpc->transactionAny($hash);
        if ($own !== null && ! empty($own['blockNumber'])) {
            return ['safe' => false, 'paid' => false, 'reason' => 'ธุรกรรมเดิมลงบล็อกแล้วแต่อ่านใบเสร็จไม่ได้ — ลองใหม่ภายหลัง ห้ามจ่ายใหม่'];
        }

        if ($withdrawal->payout_nonce === null) {
            return ['safe' => false, 'paid' => false, 'reason' => 'รายการนี้ไม่มี nonce ของธุรกรรมเดิม — พิสูจน์บนเชนไม่ได้ ตรวจด้วยมือบน explorer'];
        }

        $from = strtolower((string) ($withdrawal->payout_from ?: $this->config->signerAddress('payout')));
        $nonce = (int) $withdrawal->payout_nonce;
        $count = $from !== '' ? $rpc->nonceLatest($from) : null;

        if ($count === null) {
            return ['safe' => false, 'paid' => false, 'reason' => 'อ่าน nonce ของกระเป๋าจ่ายไม่ได้ — ลองใหม่ภายหลัง'];
        }

        if ($count <= $nonce) {
            return ['safe' => false, 'paid' => false, 'reason' => "nonce {$nonce} ยังว่าง ธุรกรรมเดิมยังลงบล็อกได้ — รอให้ลงบล็อก หรือให้เจ้าของส่งธุรกรรมแทนที่ด้วย nonce {$nonce} จากกระเป๋าจ่าย ห้ามจ่ายใหม่"];
        }

        // nonce ถูกใช้แล้ว — ต้องเจอตัวที่ใช้มันจริง
        $otherPayouts = BridgeWithdrawal::query()
            ->where('target_chain_id', $withdrawal->target_chain_id)
            ->where('payout_nonce', $nonce)
            ->whereNotNull('payout_tx_hash')
            ->whereKeyNot($withdrawal->id)
            ->pluck('payout_tx_hash')
            ->map(fn ($h) => strtolower((string) $h))
            ->all();

        $candidates = $otherPayouts;

        if ($nonceTxHash !== null && trim($nonceTxHash) !== '') {
            $candidates[] = strtolower(trim($nonceTxHash));
        }

        foreach (array_unique($candidates) as $candidate) {
            if ($candidate === $hash) {
                continue; // hash เดิมเองพิสูจน์ "ไม่ได้จ่าย" ไม่ได้
            }

            $receipt = $this->minedReceipt($rpc, $candidate, $from, $nonce);

            // nonce หนึ่งลงบล็อกได้ธุรกรรมเดียว — เจอตัวจริงแล้ว คำตอบอยู่ที่ตัวนี้ ไม่ต้องดูตัวอื่น
            if ($receipt !== null) {
                return $this->judgeNonceTx($withdrawal, $candidate, $nonce, $receipt, in_array($candidate, $otherPayouts, true));
            }
        }

        return ['safe' => false, 'paid' => false, 'reason' => "พิสูจน์ไม่ได้ว่าเงินไม่ได้ออก: ไม่เจอใบเสร็จของธุรกรรมเดิม (อาจเป็นเพราะ RPC) และไม่รู้ว่าธุรกรรมใดใช้ nonce {$nonce}"
            ." — เปิดกระเป๋าจ่าย {$from} บน explorer หา nonce {$nonce} ถ้าเป็น {$hash} แปลว่าจ่ายไปแล้ว ถ้าเป็นธุรกรรมอื่นให้กรอก hash นั้นแล้วอนุมัติอีกครั้ง"];
    }

    /** ใบเสร็จของธุรกรรมนี้ เมื่อลงบล็อกแล้วจริงและเป็นของกระเป๋านี้ที่ nonce นี้ — เชื่อเฉพาะสิ่งที่อ่านเจอ ไม่เชื่อ "ไม่เจอ" */
    private function minedReceipt(EvmRpc $rpc, string $txHash, string $from, int $nonce): ?array
    {
        if (! preg_match('/^0x[0-9a-f]{64}$/', $txHash)) {
            return null;
        }

        $tx = $rpc->transactionAny($txHash);

        if ($tx === null
            || strtolower((string) ($tx['hash'] ?? $txHash)) !== $txHash
            || strtolower((string) ($tx['from'] ?? '')) !== $from
            || ! isset($tx['nonce'])
            || (int) Wei::hexToInt((string) $tx['nonce']) !== $nonce) {
            return null;
        }

        // ยังอยู่ใน mempool = ยังถูกแทนที่ได้ — ต้องมีใบเสร็จ (ลงบล็อกแล้ว) เท่านั้น
        return $rpc->receiptAny($txHash)['receipt'];
    }

    /**
     * ธุรกรรมนี้ใช้ nonce ของรายการไปแล้วจริง = hash เดิมตาย แต่เงินของรายการนี้อาจออกไปกับตัวนี้เอง
     * (เจ้าของกด speed up หรือส่งโอนเดิมซ้ำตามที่ flagIfStuck แนะนำ) จึงต้องดู Transfer ในใบเสร็จก่อนสรุปว่า "ไม่ได้จ่าย"
     *
     * นับทุก Transfer ของ USDT ที่เข้าผู้รับ ไม่กรองยอดและไม่กรองผู้โอน
     * (กรองยอด = โอนไม่ตรงยอดแล้วจ่ายทับได้ · กรองผู้โอน = โอนผ่านสัญญาแล้วรอด):
     *   ไม่มีเลย                             → ปลอดภัย
     *   ครบยอดพอดี + สำเร็จ + ไม่ใช่ของรายการอื่น → จ่ายแล้ว (ต้องปิดได้ ไม่งั้นแถว stuck_pending หยุดคิวของเชนตลอดไป)
     *   นอกนั้น หรืออ่าน log ไม่ได้              → ไม่ปลอดภัย ให้คนตัดสิน
     *
     * @return array{safe: bool, paid: bool, reason: string, paid_tx?: string, paid_block?: ?int}
     */
    private function judgeNonceTx(BridgeWithdrawal $withdrawal, string $txHash, int $nonce, array $receipt, bool $ofOtherWithdrawal): array
    {
        $received = $this->usdtReceived($receipt, $withdrawal);
        $amountRaw = (string) $withdrawal->payout_amount_raw;

        if ($received === null) {
            return ['safe' => false, 'paid' => false, 'reason' => "อ่าน log ของธุรกรรม {$txHash} ที่ใช้ nonce {$nonce} ไม่ได้ — ลองใหม่ภายหลัง ห้ามจ่ายใหม่"];
        }

        if (bccomp($received, '0', 0) === 0) {
            return ['safe' => true, 'paid' => false, 'reason' => "nonce {$nonce} ถูกใช้โดยธุรกรรม {$txHash} ที่ลงบล็อกแล้ว และไม่ได้โอน USDT ให้ผู้รับรายการนี้ — ธุรกรรมเดิมไม่มีทางลงบล็อก เงินไม่ได้ออก"];
        }

        // เงินก้อนนี้เป็นของรายการอื่น นับเป็นของรายการนี้ไม่ได้ (ผู้รับจะได้ไม่ครบ) แต่ก็ยืนยันไม่ได้ว่ารายการนี้ไม่ได้จ่าย
        if ($ofOtherWithdrawal) {
            return ['safe' => false, 'paid' => false, 'reason' => "ธุรกรรม {$txHash} ที่ใช้ nonce {$nonce} เป็นของรายการถอนอื่น และโอน USDT เข้าผู้รับคนเดียวกัน — แยกเองไม่ได้ว่าเป็นเงินของรายการไหน ตรวจด้วยมือบน explorer ห้ามจ่ายใหม่"];
        }

        if (strtolower((string) ($receipt['status'] ?? '')) === '0x1' && bccomp($received, $amountRaw, 0) === 0) {
            $block = isset($receipt['blockNumber']) ? (int) Wei::hexToInt((string) $receipt['blockNumber']) : null;

            return [
                'safe' => false,
                'paid' => true,
                'reason' => "จ่ายแล้วด้วยธุรกรรมแทนที่ {$txHash} (nonce {$nonce}) ที่โอน USDT ครบยอดให้ผู้รับ — ธุรกรรมเดิม {$withdrawal->payout_tx_hash} ไม่มีทางลงบล็อก",
                'paid_tx' => $txHash,
                'paid_block' => $block,
            ];
        }

        return ['safe' => false, 'paid' => false, 'reason' => "ธุรกรรม {$txHash} ที่ใช้ nonce {$nonce} โอน USDT เข้าผู้รับ {$received} หน่วยดิบ ไม่ตรงกับยอดจ่าย {$amountRaw} — มีเงินถึงผู้รับแล้ว ตรวจด้วยมือบน explorer ห้ามจ่ายใหม่"];
    }

    /** ยอด USDT รวมที่ใบเสร็จนี้โอนเข้าผู้รับของรายการ — null = อ่าน log ไม่ครบ ("อ่านไม่ได้" ต้องไม่กลายเป็น "ไม่มีการโอน") */
    private function usdtReceived(array $receipt, BridgeWithdrawal $withdrawal): ?string
    {
        $chain = $this->config->chain((int) $withdrawal->target_chain_id);
        $recipient = strtolower((string) $withdrawal->target_address);

        // ตัวจ่ายเซ็นตาม config ตอนจ่าย ส่วน payout_token จดไว้ตอนสแกน — ถ้าเปลี่ยนระหว่างทางต้องนับทั้งคู่
        $tokens = array_filter([strtolower((string) $withdrawal->payout_token), strtolower((string) ($chain['usdt']['address'] ?? ''))]);

        if ($tokens === [] || ! is_array($receipt['logs'] ?? null)) {
            return null;
        }

        $total = '0';

        foreach ($receipt['logs'] as $log) {
            if (! is_array($log) || ! isset($log['address']) || ! is_array($log['topics'] ?? null)) {
                return null;
            }

            if (! in_array(strtolower((string) $log['address']), $tokens, true)
                || strtolower((string) ($log['topics'][0] ?? '')) !== Abi::topic(Abi::TRANSFER_EVENT)) {
                continue;
            }

            $to = Abi::topicAddress($log['topics'][2] ?? null);
            $data = (string) ($log['data'] ?? '');

            if ($to === null || ! preg_match('/^0x[0-9a-fA-F]{64}$/', $data)) {
                return null;
            }

            if ($to === $recipient) {
                $total = bcadd($total, Wei::hexToInt($data), 0);
            }
        }

        return $total;
    }

    private function trackOne(EvmRpc $rpc, BridgeWithdrawal $withdrawal): bool
    {
        $hash = (string) $withdrawal->payout_tx_hash;
        if ($hash === '') {
            return false;
        }

        // ถามทุก RPC — โหนดที่ตามหัวไม่ทันตอบ null ได้ทั้งที่ลงบล็อกแล้ว
        $receipt = $rpc->receiptAny($hash)['receipt'];

        if ($receipt !== null) {
            return $this->settleReceipt($withdrawal, $receipt, $hash);
        }

        $known = $rpc->knowsTransaction($hash);
        if ($known === null) {
            return false;
        }

        if (! $known) {
            // ระยะห่างการส่งซ้ำนับจากการเปลี่ยนแปลงล่าสุด (payout_broadcast_at = ครั้งแรกที่ส่ง ใช้วัด "ค้าง")
            $sinceTouch = $withdrawal->updated_at ? now()->diffInSeconds($withdrawal->updated_at, true) : PHP_INT_MAX;

            if ($sinceTouch >= self::REBROADCAST_AFTER_SECONDS && $withdrawal->payout_raw_tx && $this->signer->isAvailable()) {
                $sent = $this->signer->send($rpc, (string) $withdrawal->payout_raw_tx);

                if (($sent['code'] ?? null) === 'nonce_used') {
                    return $this->settleNonceUsed($rpc, $withdrawal, $hash);
                }

                $this->updateIfSameTx($withdrawal, $hash, [
                    'last_error' => ($sent['ok'] ?? false) ? 'ส่ง raw เดิมซ้ำแล้ว' : mb_substr('ส่งซ้ำไม่ผ่าน: '.($sent['error'] ?? ''), 0, 300),
                ]);
            }
        }

        return $this->flagIfStuck($withdrawal, $hash, (bool) $known);
    }

    /**
     * ส่ง raw เดิมแล้วเชนบอกว่า nonce ถูกใช้ไปแล้ว — อาจเป็นธุรกรรมของเราเองที่เพิ่งลงบล็อก (RPC ที่ถามใบเสร็จช้ากว่า)
     * ถามใบเสร็จจากทุก RPC อีกรอบก่อนตัดสิน ไม่งั้นรายการที่จ่ายแล้วจะไปโผล่ในคิวรออนุมัติ.
     */
    private function settleNonceUsed(EvmRpc $rpc, BridgeWithdrawal $withdrawal, string $hash): bool
    {
        $again = $rpc->receiptAny($hash)['receipt'];
        if ($again !== null) {
            return $this->settleReceipt($withdrawal, $again, $hash);
        }

        // ยังไม่เจอใบเสร็จ — ไม่ได้แปลว่าเงินไม่ได้ออก ห้ามเซ็นใหม่เอง ให้คนตัดสินด้วยหลักฐาน (ดู retryCheck)
        $this->updateIfSameTx($withdrawal, $hash, [
            'status' => BridgeWithdrawal::STATUS_NEEDS_REVIEW,
            'review_reason' => 'nonce_consumed',
            'last_error' => "nonce {$withdrawal->payout_nonce} ถูกใช้ไปแล้วแต่ยังไม่เจอใบเสร็จของ {$hash} — ตรวจกระเป๋าจ่ายก่อนอนุมัติจ่ายใหม่",
        ]);

        return true;
    }

    /**
     * ส่งไปนานเกิน stuck_after_minutes แล้วยังไม่ลงบล็อก → needs_review (stuck_pending) + แจ้งเตือน critical.
     *
     * ไม่เปลี่ยน hash/raw — ยังตามผลต่อทุกรอบ ลงบล็อกเมื่อไหร่ก็ปิดเป็น paid เอง (trackSending รวมรายการนี้ด้วย)
     */
    private function flagIfStuck(BridgeWithdrawal $withdrawal, string $hash, bool $known): bool
    {
        if ($withdrawal->status !== BridgeWithdrawal::STATUS_SENDING) {
            return false; // ถูกส่งให้คนดูไปแล้ว — ไม่แจ้งซ้ำทุกนาที
        }

        $age = $withdrawal->payout_broadcast_at ? now()->diffInSeconds($withdrawal->payout_broadcast_at, true) : 0;
        if ($age < $this->config->stuckAfterSeconds()) {
            return false;
        }

        $minutes = (int) floor($age / 60);
        $note = "ส่งไปแล้ว {$minutes} นาทีแต่ยังไม่ลงบล็อก (".($known ? 'ค้างใน mempool' : 'เชนไม่รู้จัก ส่งซ้ำแล้วก็ไม่ลง').') — '
            ."คิวของเชนนี้หยุดรอ · ทางออก: รอให้ลงบล็อก หรือให้เจ้าของส่งธุรกรรมแทนที่ด้วย nonce {$withdrawal->payout_nonce} จากกระเป๋าจ่าย";

        if (! $this->updateIfSameTx($withdrawal, $hash, [
            'status' => BridgeWithdrawal::STATUS_NEEDS_REVIEW,
            'review_reason' => self::REASON_STUCK,
            'last_error' => mb_substr($note, 0, 300),
        ])) {
            return false;
        }

        Log::critical('bridge: ธุรกรรมจ่ายค้าง', ['withdrawal' => $withdrawal->id, 'tx' => $hash, 'nonce' => $withdrawal->payout_nonce]);
        SystemAlert::raise(
            BridgeReconciler::ALERT_NODE,
            self::ALERT_STUCK,
            'critical',
            "สะพาน USDT: ธุรกรรมจ่ายของรายการถอน #{$withdrawal->id} ค้าง {$minutes} นาที (nonce {$withdrawal->payout_nonce}) — คิวจ่ายของเชนนี้หยุดรอ",
            ['withdrawal' => $withdrawal->id, 'tx' => $hash, 'chain_id' => $withdrawal->target_chain_id, 'nonce' => $withdrawal->payout_nonce]
        );

        return true;
    }

    /** ได้ใบเสร็จแล้ว — สำเร็จ = paid · revert = needs_review (เงินไม่ได้ออก) */
    private function settleReceipt(BridgeWithdrawal $withdrawal, array $receipt, string $hash): bool
    {
        $block = isset($receipt['blockNumber']) ? (int) Wei::hexToInt((string) $receipt['blockNumber']) : null;

        if (strtolower((string) ($receipt['status'] ?? '')) === '0x1') {
            $done = $this->updateIfSameTx($withdrawal, $hash, [
                'status' => BridgeWithdrawal::STATUS_PAID,
                'payout_block_number' => $block,
                'paid_at' => now(),
                'last_error' => null,
            ]);
            Log::info('bridge: จ่ายเงินถอนสำเร็จ', ['withdrawal' => $withdrawal->id, 'tx' => $hash]);

            return $done;
        }

        // revert บนเชน (เช่น Tether blacklist ปลายทาง) — เงินไม่ได้ออก แต่ต้องให้คนตัดสินว่าจะทำอย่างไรต่อ
        return $this->updateIfSameTx($withdrawal, $hash, [
            'status' => BridgeWithdrawal::STATUS_NEEDS_REVIEW,
            'review_reason' => 'payout_reverted',
            'payout_block_number' => $block,
            'last_error' => "ธุรกรรม {$hash} revert บนเชน — เงินไม่ได้ออกจากกระเป๋าจ่าย",
        ]);
    }

    /**
     * อัปเดตเฉพาะเมื่อแถวยังผูกกับธุรกรรมเดิม — แอดมินอาจอนุมัติ (ล้าง hash) ไปพร้อมกันพอดี
     * ผลของ hash เก่าต้องไม่ไปเขียนทับรายการที่เข้าคิวใหม่แล้ว.
     */
    private function updateIfSameTx(BridgeWithdrawal $withdrawal, string $hash, array $values): bool
    {
        $changed = BridgeWithdrawal::whereKey($withdrawal->id)
            ->where('payout_tx_hash', $hash)
            ->update($values + ['updated_at' => now()]);

        $withdrawal->refresh();

        return $changed === 1;
    }
}
