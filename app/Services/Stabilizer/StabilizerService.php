<?php

namespace App\Services\Stabilizer;

use App\Models\StabilityDecision;
use App\Models\StabilityDeposit;
use App\Models\StabilityReport;
use App\Models\StabilityTransaction;
use App\Services\TpixDexService;
use App\Support\Wei;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TPIX Stability Reserve — ตัวประสานงาน: ตามผลธุรกรรมเดิม → ตัดสินใจตามกฎ → เซ็น/ส่ง → บันทึก.
 *
 * ═══ ลำดับที่กันส่งซ้ำ (never-double-send) ═══
 *   1. ล็อกทั้งรอบ (Cache::lock + withoutOverlapping ของ scheduler) — สองรอบซ้อนกัน = แย่ง nonce
 *   2. ตามผลธุรกรรมที่ยังไม่จบ "ก่อน" เสมอ และถ้ายังมีตัวค้าง ห้ามเซ็นตัวใหม่เด็ดขาด
 *   3. nonce มาจากเชนแบบ 'latest' (ที่ลงบล็อกแล้ว) — ไม่ใช่ 'pending'
 *      ถ้าธุรกรรมเก่าที่เราคิดว่าหายไปยังลอยอยู่ใน mempool ตัวใหม่จะได้ nonce เดียวกัน
 *      → ลงบล็อกได้แค่ตัวเดียว ไม่มีทางเกิดสองไม้
 *   4. บันทึก tx hash + raw ลงฐานข้อมูล "ก่อน" ส่งขึ้นเชน — ดับกลางคันแล้วรอบหน้ายังรู้
 *   5. ส่งไม่สำเร็จ = ยังไม่ถือว่าล้ม (โหนดอาจรับไปแล้วแต่ตอบไม่ทัน) ปล่อยให้รอบหน้าตรวจ receipt
 *
 * ═══ ความเงียบไม่ใช่ความสำเร็จ ═══
 *   ธุรกรรม revert / เซ็นไม่ผ่าน / ค้างนานผิดปกติ → หยุดตัวเองอัตโนมัติ (auto-pause)
 *   และต้องให้แอดมินตรวจแล้วเปิดเองเท่านั้น
 *
 * Developed by Xman Studio
 */
class StabilizerService
{
    public const LOCK_KEY = 'stabilizer:run';

    public const PUBLIC_CACHE_KEY = 'stabilizer:public';

    public function __construct(
        private StabilizerSettings $settings,
        private StabilizerMarket $market,
        private StabilizerRules $rules,
        private StabilizerTxSigner $signer,
        private TpixDexService $dex,
    ) {}

    // =========================================================================
    // รอบการทำงาน
    // =========================================================================

    /**
     * หนึ่งรอบของ stabilizer:run.
     *
     * @return array{status: string, decision?: array, tx_hash?: string}
     */
    public function run(bool $dryRun = false): array
    {
        if (! $this->settings->envEnabled()) {
            // สวิตช์หลักปิด = ระบบยังไม่ได้เปิดใช้ ไม่บันทึกอะไร (ไม่มีการตัดสินใจเกิดขึ้น)
            return ['status' => 'disabled_env'];
        }

        if ($dryRun) {
            return ['status' => 'dry_run', 'decision' => $this->preview()];
        }

        $lock = Cache::lock(self::LOCK_KEY, 600);
        if (! $lock->get()) {
            return ['status' => 'locked'];
        }

        try {
            $tracked = $this->trackOpen();

            if (! $this->settings->switchOn()) {
                return ['status' => 'paused', 'tracked' => $tracked];
            }

            $result = $this->decideAndAct();
            Cache::forget(self::PUBLIC_CACHE_KEY);

            return $result + ['tracked' => $tracked];
        } finally {
            $lock->release();
        }
    }

    /**
     * ดูว่ากฎจะตัดสินใจอย่างไร "ตอนนี้" — ไม่บันทึก ไม่เซ็น ไม่ส่ง.
     */
    public function preview(): array
    {
        $params = $this->settings->params();
        $snapshot = $this->market->snapshot($params);
        $eval = $this->rules->evaluate($snapshot, $params);
        unset($eval['order']);

        return $eval;
    }

    private function decideAndAct(): array
    {
        $params = $this->settings->params();

        $readiness = $this->settings->readiness($this->dex);
        if (! $readiness['ready']) {
            $missing = array_values(array_map(fn ($c) => $c['key'], array_filter($readiness['checks'], fn ($c) => ! $c['ok'])));
            $row = $this->record(['action' => 'none', 'reason_code' => 'not_configured', 'details' => ['missing' => $missing]], $params);

            return ['status' => 'not_configured', 'decision' => $row->only(['action', 'reason_code', 'details'])];
        }

        $open = StabilityTransaction::open()->orderBy('nonce')->first();
        if ($open !== null) {
            $row = $this->record([
                'action' => StabilityDecision::ACTION_WAIT,
                'reason_code' => 'pending_tx',
                'details' => ['tx_hash' => $open->tx_hash, 'status' => $open->status],
            ], $params);

            return ['status' => 'pending_tx', 'decision' => $row->only(['action', 'reason_code', 'details'])];
        }

        $snapshot = $this->market->snapshot($params);
        $eval = $this->rules->evaluate($snapshot, $params);

        if ($eval['order'] === null) {
            $row = $this->record($eval, $params);

            return ['status' => 'no_action', 'decision' => $row->only(['action', 'reason_code', 'details'])];
        }

        return $this->execute($eval, $snapshot, $params);
    }

    /**
     * เซ็น → บันทึก → ส่ง (ตามลำดับนี้เท่านั้น).
     */
    private function execute(array $eval, array $snapshot, array $params): array
    {
        $wallet = (string) $this->settings->walletAddress();

        if (! $this->signer->isAvailable()) {
            $row = $this->record($this->downgrade($eval, 'signer_unavailable'), $params);

            return ['status' => 'no_action', 'decision' => $row->only(['action', 'reason_code', 'details'])];
        }

        // nonce จากสถานะที่ลงบล็อกแล้วเท่านั้น (ดูหัวไฟล์ข้อ 3)
        $nonce = $this->dex->transactionCount($wallet, 'latest');
        if ($nonce === null) {
            $row = $this->record($this->downgrade($eval, 'nonce_unreadable'), $params);

            return ['status' => 'no_action', 'decision' => $row->only(['action', 'reason_code', 'details'])];
        }

        // ธุรกรรมของเราที่ลงบล็อกแล้วต้องถูกนับใน nonce ของเชนแล้ว — ไม่งั้นเชน/RPC ตามไม่ทัน
        $lastMined = StabilityTransaction::whereNotNull('block_number')->max('nonce');
        if ($lastMined !== null && $nonce <= (int) $lastMined) {
            $row = $this->record($this->downgrade($eval, 'nonce_behind', ['chain_nonce' => $nonce, 'last_mined_nonce' => (int) $lastMined]), $params);

            return ['status' => 'no_action', 'decision' => $row->only(['action', 'reason_code', 'details'])];
        }

        $tx = $this->buildTx($eval, $snapshot['contracts'], $wallet);
        $decision = $this->record($eval, $params);

        $signed = $this->signer->sign($tx['to'], $tx['data'], $tx['value'], $tx['gas'], $nonce);
        if (! ($signed['ok'] ?? false) || empty($signed['raw']) || empty($signed['txHash'])) {
            $decision->update([
                'action' => StabilityDecision::ACTION_NONE,
                'reason_code' => 'sign_failed',
                'details' => array_merge($decision->details ?? [], ['sign_error' => $signed['code'] ?? 'unknown']),
            ]);
            // เซ็นไม่ผ่านแทบทุกครั้งคือปัญหาการตั้งค่า (passphrase/keystore ผิด) — หยุดก่อน
            // ไม่งั้นจะถอด keystore (scrypt หนัก) ซ้ำทุก 5 นาทีโดยไม่มีใครรู้
            $this->settings->autoPause('sign_failed');

            return ['status' => 'sign_failed', 'decision' => $decision->only(['action', 'reason_code', 'details'])];
        }

        $order = $eval['order'];
        $isApprove = $eval['action'] === 'approve';

        // ⚠️ บันทึก hash + raw ก่อนส่ง — ห้ามสลับลำดับ
        $record = DB::transaction(function () use ($decision, $signed, $order, $isApprove, $nonce, $tx, $snapshot) {
            $record = StabilityTransaction::create([
                'decision_id' => $decision->id,
                'purpose' => $isApprove ? StabilityTransaction::PURPOSE_APPROVE : StabilityTransaction::PURPOSE_SWAP,
                'side' => $isApprove ? null : $order['side'],
                'token_in' => $isApprove ? 'usdt' : ($order['side'] === 'buy' ? 'usdt' : 'tpix'),
                'amount_in_wei' => $isApprove ? (string) $order['approve_wei'] : (string) $order['amount_in_wei'],
                'expected_out_wei' => $isApprove ? null : (string) $order['expected_out_wei'],
                'min_out_wei' => $isApprove ? null : (string) $order['min_out_wei'],
                'price_before' => $snapshot['pool']['price'],
                'price_after_expected' => $isApprove ? null : $order['price_after'],
                'nonce' => $nonce,
                'tx_hash' => strtolower((string) $signed['txHash']),
                'raw_tx' => (string) $signed['raw'],
                'deadline_at' => $tx['deadline'],
                'status' => StabilityTransaction::STATUS_SIGNED,
                'signed_at' => now(),
            ]);
            $decision->update(['transaction_id' => $record->id]);

            return $record;
        });

        $this->broadcast($record);

        Log::info('stabilizer: transaction signed and sent', [
            'tx_hash' => $record->tx_hash,
            'purpose' => $record->purpose,
            'side' => $record->side,
            'nonce' => $nonce,
            'status' => $record->status,
        ]);

        return [
            'status' => $isApprove ? 'approve_sent' : 'trade_sent',
            'tx_hash' => $record->tx_hash,
            'decision' => $decision->only(['action', 'reason_code', 'details']),
        ];
    }

    /** ส่ง raw เดิม — ใช้ทั้งครั้งแรกและตอนส่งซ้ำ (raw เดิม = nonce เดิม เชนรับได้ครั้งเดียว) */
    private function broadcast(StabilityTransaction $record): void
    {
        $sent = $this->signer->send((string) $record->raw_tx);

        if ($sent['ok'] ?? false) {
            $record->update(['status' => StabilityTransaction::STATUS_BROADCAST, 'broadcast_at' => now(), 'failure_reason' => null]);

            return;
        }

        // ไม่เปลี่ยนเป็น failed: โหนดอาจรับไปแล้วแต่ตอบไม่ทัน — ปล่อยสถานะ signed ไว้
        // ให้ trackOpen() ตรวจ receipt/mempool ในรอบถัดไป (ถ้าตีว่าล้มแล้วเซ็นใหม่ = เสี่ยงสองไม้)
        $record->update(['failure_reason' => 'broadcast: '.mb_substr((string) ($sent['code'] ?? 'unknown'), 0, 60)]);
    }

    /**
     * @return array{to: string, data: string, value: string, gas: int, deadline: ?int}
     */
    private function buildTx(array $eval, array $contracts, string $wallet): array
    {
        $order = $eval['order'];

        if ($eval['action'] === 'approve') {
            return [
                'to' => $contracts['usdt'],
                'data' => SwapCalldata::approve($contracts['router'], (string) $order['approve_wei']),
                'value' => '0',
                'gas' => (int) config('stabilizer.gas.approve', 120000),
                'deadline' => null,
            ];
        }

        $deadline = now()->getTimestamp() + (int) config('stabilizer.swap_deadline_seconds', 600);

        if ($order['side'] === 'buy') {
            return [
                'to' => $contracts['router'],
                'data' => SwapCalldata::buy((string) $order['amount_in_wei'], (string) $order['min_out_wei'], $contracts['usdt'], $contracts['wtpix'], $wallet, $deadline),
                'value' => '0',
                'gas' => (int) config('stabilizer.gas.swap', 400000),
                'deadline' => $deadline,
            ];
        }

        return [
            'to' => $contracts['router'],
            'data' => SwapCalldata::sell((string) $order['min_out_wei'], $contracts['wtpix'], $contracts['usdt'], $wallet, $deadline),
            'value' => (string) $order['amount_in_wei'],
            'gas' => (int) config('stabilizer.gas.swap', 400000),
            'deadline' => $deadline,
        ];
    }

    /** การตัดสินใจที่ตั้งใจจะเทรดแต่ไปต่อไม่ได้ → บันทึกเป็น none พร้อมเหตุผลจริง */
    private function downgrade(array $eval, string $reason, array $extra = []): array
    {
        return [
            'action' => 'none',
            'reason_code' => $reason,
            'details' => array_merge($eval['details'] ?? [], ['intended' => $eval['action']], $extra),
        ];
    }

    private function record(array $eval, array $params): StabilityDecision
    {
        $d = $eval['details'] ?? [];

        return StabilityDecision::create([
            'decided_at' => now(),
            'action' => $eval['action'],
            'reason_code' => $eval['reason_code'],
            'pool_price' => $d['pool_price'] ?? null,
            'reference_price' => $d['reference_price'] ?? null,
            'band_low' => $d['band_low'] ?? null,
            'band_high' => $d['band_high'] ?? null,
            'reserve_tpix_wei' => $d['reserve_tpix'] ?? null,
            'reserve_usdt_wei' => $d['reserve_usdt'] ?? null,
            // เก็บพารามิเตอร์ที่ใช้จริงในรอบนั้นไว้ด้วย — แอดมินเปลี่ยนค่าทีหลังก็ยังตรวจย้อนได้
            'details' => array_diff_key($d, array_flip(['reserve_tpix', 'reserve_usdt'])) + ['params' => $params],
        ]);
    }

    // =========================================================================
    // ตามผลธุรกรรม
    // =========================================================================

    /**
     * ตามผลธุรกรรมที่ยังไม่จบ (และตัวที่เพิ่งถูกตีว่าหายไปใน 24 ชม. เผื่อมันลงบล็อกทีหลัง).
     *
     * @return array<int, array{tx_hash: string, status: string}>
     */
    public function trackOpen(): array
    {
        $rows = StabilityTransaction::query()
            ->where(function ($q) {
                $q->whereIn('status', StabilityTransaction::OPEN_STATUSES)
                    ->orWhere(fn ($q) => $q->where('status', StabilityTransaction::STATUS_DROPPED)->where('signed_at', '>=', now()->subDay()));
            })
            ->orderBy('nonce')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // RPC ล่ม = แยกไม่ออกว่า "ไม่มี receipt" หรือ "อ่านไม่ได้" → ไม่ตัดสินอะไรทั้งนั้น
        if ($this->dex->blockNumber() === null) {
            return [];
        }

        $stuckAfter = (int) config('stabilizer.stuck_after_minutes', 30);
        $out = [];

        foreach ($rows as $row) {
            $receipt = $this->dex->receipt($row->tx_hash);

            if ($receipt !== null) {
                $this->finalize($row, $receipt);
                $out[] = ['tx_hash' => $row->tx_hash, 'status' => $row->status];

                continue;
            }

            if ($row->status === StabilityTransaction::STATUS_DROPPED) {
                continue;
            }

            $ageMinutes = (int) abs(($row->signed_at ?? $row->created_at)->diffInMinutes(now()));
            $known = $this->dex->transaction($row->tx_hash) !== null;

            if ($known) {
                if ($row->status === StabilityTransaction::STATUS_SIGNED) {
                    $row->update(['status' => StabilityTransaction::STATUS_BROADCAST, 'broadcast_at' => now()]);
                }
                // โหนดรู้จักแต่ไม่ลงบล็อกนานผิดปกติ — หยุดให้คนมาดู (ไม่เซ็นตัวใหม่อยู่แล้ว)
                if ($ageMinutes >= $stuckAfter * 3) {
                    $this->settings->autoPause('stuck_tx');
                }
                $out[] = ['tx_hash' => $row->tx_hash, 'status' => $row->status];

                continue;
            }

            if ($ageMinutes >= $stuckAfter) {
                // ไม่มีใครรู้จัก tx นี้แล้ว — ปล่อย nonce ให้ใช้ต่อได้
                // ปลอดภัยเพราะ nonce ถัดไปมาจาก 'latest' ถ้า tx เก่าโผล่มาลงบล็อกทีหลัง
                // ตัวใหม่ที่ใช้ nonce เดียวกันจะตกไปเอง (ลงได้แค่ตัวเดียว) และรอบนี้ยังไล่ receipt ต่อ 24 ชม.
                $row->update([
                    'status' => StabilityTransaction::STATUS_DROPPED,
                    'failure_reason' => "not seen on chain after {$stuckAfter} minutes",
                    'finalized_at' => now(),
                ]);
                $out[] = ['tx_hash' => $row->tx_hash, 'status' => $row->status];

                continue;
            }

            // ส่งซ้ำตัวเดิมเฉพาะตอนสวิตช์ยังเปิด และสวอปยังไม่หมดอายุ — ปิดสวิตช์แล้วต้องไม่มีไม้ใหม่ขึ้นเชน
            $deadlineOk = $row->purpose === StabilityTransaction::PURPOSE_APPROVE
                || ($row->deadline_at !== null && $row->deadline_at > now()->getTimestamp() + 30);
            if ($this->settings->active() && $deadlineOk && $this->signer->isAvailable()) {
                $this->broadcast($row);
            }
            $out[] = ['tx_hash' => $row->tx_hash, 'status' => $row->fresh()->status];
        }

        return $out;
    }

    private function finalize(StabilityTransaction $row, array $receipt): void
    {
        $block = isset($receipt['blockNumber']) ? (int) Wei::hexToInt((string) $receipt['blockNumber']) : null;
        $success = ($receipt['status'] ?? '0x0') === '0x1';

        if (! $success) {
            $row->update([
                'status' => StabilityTransaction::STATUS_FAILED,
                'block_number' => $block,
                'failure_reason' => 'reverted on-chain (status 0x0)',
                'finalized_at' => now(),
            ]);
            $this->settings->autoPause('tx_reverted');

            return;
        }

        $row->update([
            'status' => StabilityTransaction::STATUS_CONFIRMED,
            'block_number' => $block,
            'actual_out_wei' => $row->purpose === StabilityTransaction::PURPOSE_SWAP ? $this->actualOut($row, $receipt) : null,
            'failure_reason' => null,
            'finalized_at' => now(),
        ]);
    }

    /** จำนวนที่ได้จริงจาก event Swap ของพูลในใบเสร็จ — null ถ้าถอดไม่ได้ */
    private function actualOut(StabilityTransaction $row, array $receipt): ?string
    {
        $contracts = $this->market->contracts();
        if ($contracts === null) {
            return null;
        }

        $info = $this->dex->pairInfo($contracts['pair']);
        $tpixIsToken0 = $info !== null && strtolower($info['token0']) === $contracts['wtpix'];
        $topic = $this->dex->eventTopic('Swap(address,uint256,uint256,uint256,uint256,address)');

        foreach ((array) ($receipt['logs'] ?? []) as $log) {
            if (strtolower((string) ($log['address'] ?? '')) !== $contracts['pair'] || strtolower((string) ($log['topics'][0] ?? '')) !== $topic) {
                continue;
            }
            $words = TpixDexService::words((string) ($log['data'] ?? ''));
            if (count($words) < 4) {
                return null;
            }
            $amount0Out = Wei::hexToInt('0x'.$words[2]);
            $amount1Out = Wei::hexToInt('0x'.$words[3]);
            // ซื้อ = ได้ TPIX ออกมา · ขาย = ได้ USDT ออกมา
            $wantTpix = $row->side === StabilityTransaction::SIDE_BUY;

            return ($wantTpix === $tpixIsToken0) ? $amount0Out : $amount1Out;
        }

        return null;
    }

    // =========================================================================
    // เงินฝาก (งบ USDT ที่มีที่มาชัดเจน)
    // =========================================================================

    /**
     * บันทึกเงินฝากของเจ้าของจาก tx hash — ยอดอ่านจากเชนเท่านั้น ไม่เชื่อตัวเลขที่กรอกมา.
     *
     * @return array{ok: bool, deposit?: StabilityDeposit, error?: string}
     */
    public function recordDeposit(string $token, string $txHash, ?string $note, ?int $adminId): array
    {
        $txHash = strtolower(trim($txHash));
        $wallet = $this->settings->walletAddress();
        $contracts = $this->market->contracts();

        if ($wallet === null || $contracts === null) {
            return ['ok' => false, 'error' => 'wallet or DEX not configured'];
        }
        if (StabilityDeposit::where('tx_hash', $txHash)->exists()) {
            return ['ok' => false, 'error' => 'this transaction is already recorded'];
        }

        /*
         * ธุรกรรมของกองทุนเอง (ซื้อ/ขาย/approve ที่บอทเซ็น) ไม่ใช่เงินฝาก
         *
         * ⚠️ ไม้ขายมี Transfer USDT จากพูลเข้ากระเป๋ากองทุนจริงในใบเสร็จ — ถ้านับเป็นเงินฝาก
         *    "งบที่มีที่มาชัด" จะพองขึ้นด้วย USDT_TPIX จากพูลที่ไม่มีใครพิสูจน์ว่ามีของหนุน
         *    ซึ่งเป็นสิ่งที่งบเงินฝากตั้งใจกันไว้ตั้งแต่แรก (โหมด deposits_only)
         */
        if (StabilityTransaction::where('tx_hash', $txHash)->exists()) {
            return ['ok' => false, 'error' => 'this is a transaction signed by the stabilizer itself, not a deposit'];
        }

        $receipt = $this->dex->receipt($txHash);
        if ($receipt === null || ($receipt['status'] ?? '') !== '0x1') {
            return ['ok' => false, 'error' => 'transaction not found or not successful on chain'];
        }

        $block = isset($receipt['blockNumber']) ? (int) Wei::hexToInt((string) $receipt['blockNumber']) : null;
        $amount = '0';
        $from = null;
        $wallet = strtolower($wallet);

        /*
         * ผู้ส่งที่ไม่ใช่ "เงินใหม่จากข้างนอก" — กระเป๋ากองทุนเอง (โอนหาตัวเอง) · พูล (ผลของไม้ขาย
         * ที่เซ็นนอกระบบ) · router (เงินทอน/ถอนสภาพคล่อง) — ข้ามทุก log ของผู้ส่งเหล่านี้
         */
        $notExternal = array_values(array_filter([$wallet, $contracts['pair'] ?? null, $contracts['router'] ?? null]));

        if ($token === StabilityDeposit::TOKEN_USDT) {
            $transferTopic = $this->dex->eventTopic('Transfer(address,address,uint256)');
            foreach ((array) ($receipt['logs'] ?? []) as $log) {
                $topics = (array) ($log['topics'] ?? []);
                if (strtolower((string) ($log['address'] ?? '')) !== $contracts['usdt'] || strtolower((string) ($topics[0] ?? '')) !== $transferTopic || count($topics) < 3) {
                    continue;
                }
                $to = '0x'.substr(strtolower((string) $topics[2]), 26);
                $sender = '0x'.substr(strtolower((string) $topics[1]), 26);
                if ($to !== $wallet || in_array($sender, $notExternal, true)) {
                    continue;
                }
                $amount = bcadd($amount, Wei::hexToInt((string) ($log['data'] ?? '0x0')), 0);
                $from = $sender;
            }
        } else {
            $tx = $this->dex->transaction($txHash);
            $sender = is_array($tx) ? strtolower((string) ($tx['from'] ?? '')) : '';
            if (is_array($tx) && strtolower((string) ($tx['to'] ?? '')) === $wallet && ! in_array($sender, $notExternal, true)) {
                $amount = Wei::hexToInt((string) ($tx['value'] ?? '0x0'));
                $from = $sender ?: null;
            }
        }

        if (bccomp($amount, '0', 0) <= 0) {
            return ['ok' => false, 'error' => 'no transfer of this token into the reserve wallet found in that transaction'];
        }

        try {
            $deposit = StabilityDeposit::create([
                'token' => $token,
                'amount_wei' => $amount,
                'tx_hash' => $txHash,
                'from_address' => $from,
                'block_number' => $block,
                'note' => $note !== null ? mb_substr($note, 0, 255) : null,
                'recorded_by' => $adminId,
            ]);
        } catch (UniqueConstraintViolationException) {
            // กดซ้ำพร้อมกันสองแท็บ — unique index กันไว้แล้ว ตอบเหมือนกรณีบันทึกซ้ำปกติ
            return ['ok' => false, 'error' => 'this transaction is already recorded'];
        }
        Cache::forget(self::PUBLIC_CACHE_KEY);

        return ['ok' => true, 'deposit' => $deposit];
    }

    // =========================================================================
    // สถานะสำหรับหน้าเว็บ
    // =========================================================================

    /**
     * ข้อมูลทั้งหมดของหน้า /stability — อ่านอย่างเดียว ไม่มีความลับ.
     */
    public function publicState(): array
    {
        return Cache::remember(self::PUBLIC_CACHE_KEY, 30, fn () => $this->buildState());
    }

    public function buildState(): array
    {
        $params = $this->settings->params();
        $explorer = rtrim((string) config('chains.chains.4289.explorer', 'https://explorer.tpix.online'), '/');
        $wallet = $this->settings->walletAddress();
        $contracts = $this->market->contracts();
        $decimals = $contracts ? $this->market->usdtDecimals($contracts) : (int) config('stabilizer.usdt_decimals', 6);

        $market = null;
        $balances = null;
        if ($contracts !== null) {
            $pool = $this->market->pool($contracts, $decimals);
            $ref = $this->market->reference($contracts['pair'], (int) $params['lookback_days']);
            if ($pool !== null && $pool['price'] !== null) {
                $low = $ref['price'] ? PoolMath::shift($ref['price'], -(float) $params['band_pct']) : null;
                $high = $ref['price'] ? PoolMath::shift($ref['price'], (float) $params['band_pct']) : null;
                $market = [
                    'pool_price' => $pool['price'],
                    'reference_price' => $ref['price'],
                    'band_low' => $low,
                    'band_high' => $high,
                    'position' => ($low === null || $high === null) ? null
                        : (bccomp($pool['price'], $low, 18) < 0 ? 'below' : (bccomp($pool['price'], $high, 18) > 0 ? 'above' : 'inside')),
                    'pool_tpix' => Wei::format($pool['reserve_tpix']),
                    'pool_usdt' => Wei::format($pool['reserve_usdt'], $decimals),
                    'history_coverage_pct' => $ref['coverage_pct'],
                ];
            }
            if ($wallet !== null) {
                $w = $this->market->wallet($contracts);
                $balances = [
                    'tpix' => $w['tpix'] !== null ? Wei::format($w['tpix']) : null,
                    'usdt' => $w['usdt'] !== null ? Wei::format($w['usdt'], $decimals) : null,
                ];
            }
        }

        $ledger = $this->market->ledger();
        $bridge = $this->market->bridge();
        $depositsLeft = bcsub($ledger['usdt_deposits'], $ledger['usdt_spent_total'], 0);

        return [
            'status' => [
                'active' => $this->settings->active(),
                'env_enabled' => $this->settings->envEnabled(),
                'switch_on' => $this->settings->switchOn(),
                'state' => $this->settings->active() ? 'active' : ($this->settings->envEnabled() ? 'paused' : 'off'),
                'pause_reason' => $this->settings->pauseReason(),
                'paused_at' => $this->settings->pausedAt(),
                // ISO 8601 พร้อมเขตเวลา — สตริงดิบจาก DB ไม่มีโซน เบราว์เซอร์จะตีเป็นเวลาท้องถิ่นของคนดูผิด
                'last_run_at' => ($last = StabilityDecision::max('decided_at')) ? Carbon::parse($last)->toIso8601String() : null,
            ],
            'rules' => $params + [
                'schedule_minutes' => 5,
                'timezone' => (string) config('app.timezone'),
                'usdt_decimals' => $decimals,
            ],
            'wallet' => [
                'address' => $wallet,
                'explorer_url' => $wallet ? $explorer.'/address/'.$wallet : null,
                'balances' => $balances,
            ],
            'contracts' => $contracts,
            'market' => $market,
            'budget_today' => [
                'usdt_spent' => Wei::format($ledger['usdt_spent_today'], $decimals),
                'usdt_limit' => PoolMath::dec($params['daily_budget_usdt']),
                'tpix_sold' => Wei::format($ledger['tpix_sold_today']),
                'tpix_limit' => PoolMath::dec($params['daily_budget_tpix']),
                'day_start' => $ledger['day_start']->toIso8601String(),
            ],
            'usdt_backing' => [
                'mode' => $bridge['available'] ? 'bridge_proof' : 'deposits_only',
                'deposits_total' => Wei::format($ledger['usdt_deposits'], $decimals),
                'spent_total' => Wei::format($ledger['usdt_spent_total'], $decimals),
                'deposits_remaining' => Wei::format(bccomp($depositsLeft, '0', 0) > 0 ? $depositsLeft : '0', $decimals),
                'bridge' => $bridge,
            ],
            'deposits' => StabilityDeposit::latest('id')->limit(50)->get()->map(fn (StabilityDeposit $d) => [
                'token' => $d->token,
                'amount' => Wei::format((string) $d->amount_wei, $d->token === 'usdt' ? $decimals : 18),
                'tx_hash' => $d->tx_hash,
                'tx_url' => $explorer.'/tx/'.$d->tx_hash,
                'from' => $d->from_address,
                'at' => $d->created_at?->toIso8601String(),
            ])->all(),
            'chart' => $this->chart(),
            'transactions' => StabilityTransaction::latest('id')->limit(50)->get()->map(fn (StabilityTransaction $t) => $this->presentTx($t, $decimals, $explorer))->all(),
            'decisions' => StabilityDecision::latest('id')->limit(30)->get()->map(fn (StabilityDecision $d) => [
                'at' => $d->decided_at?->toIso8601String(),
                'action' => $d->action,
                'reason_code' => $d->reason_code,
                'pool_price' => $d->pool_price,
                'band_low' => $d->band_low,
                'band_high' => $d->band_high,
                'details' => array_diff_key((array) $d->details, ['params' => true]),
            ])->all(),
            'reports' => StabilityReport::orderByDesc('report_date')->limit(14)->get()->map(fn (StabilityReport $r) => [
                'date' => $r->report_date?->format('Y-m-d'),
                'facts_th' => $r->facts_th,
                'facts_en' => $r->facts_en,
                'summary_th' => $r->summary_th,
                'summary_en' => $r->summary_en,
                'ai_written' => $r->summary_th !== null || $r->summary_en !== null,
                'stats' => $r->stats,
            ])->all(),
            'explorer' => $explorer,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function presentTx(StabilityTransaction $t, int $decimals, string $explorer): array
    {
        $inDecimals = $t->token_in === 'usdt' ? $decimals : 18;
        $outDecimals = $t->side === StabilityTransaction::SIDE_BUY ? 18 : $decimals;

        return [
            'at' => $t->signed_at?->toIso8601String(),
            'purpose' => $t->purpose,
            'side' => $t->side,
            'token_in' => $t->token_in,
            'amount_in' => Wei::format((string) $t->amount_in_wei, $inDecimals),
            'expected_out' => $t->expected_out_wei !== null ? Wei::format((string) $t->expected_out_wei, $outDecimals) : null,
            'actual_out' => $t->actual_out_wei !== null ? Wei::format((string) $t->actual_out_wei, $outDecimals) : null,
            'price_before' => $t->price_before,
            'price_after_expected' => $t->price_after_expected,
            'status' => $t->status,
            'tx_hash' => $t->tx_hash,
            'tx_url' => $explorer.'/tx/'.$t->tx_hash,
            'block_number' => $t->block_number,
        ];
    }

    /**
     * กราฟราคา/ราคาอ้างอิง/กรอบ รายชั่วโมงย้อนหลัง 7 วัน — จากบันทึกการตัดสินใจจริง
     * (ค่าสุดท้ายของแต่ละชั่วโมง) ไม่ใช่เส้นที่วาดขึ้นมาใหม่.
     *
     * @return array<int, array{t: string, price: ?string, reference: ?string, low: ?string, high: ?string}>
     */
    public function chart(int $days = 7): array
    {
        $points = [];

        StabilityDecision::where('decided_at', '>=', now()->subDays($days))
            ->whereNotNull('pool_price')
            ->orderBy('decided_at')
            ->select(['id', 'decided_at', 'pool_price', 'reference_price', 'band_low', 'band_high'])
            ->chunk(2000, function ($rows) use (&$points) {
                foreach ($rows as $row) {
                    $hour = Carbon::parse($row->decided_at)->startOfHour()->toIso8601String();
                    $points[$hour] = [
                        't' => $hour,
                        'price' => $row->pool_price,
                        'reference' => $row->reference_price,
                        'low' => $row->band_low,
                        'high' => $row->band_high,
                    ];
                }
            });

        return array_values($points);
    }
}
