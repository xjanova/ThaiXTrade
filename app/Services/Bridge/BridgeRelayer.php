<?php

namespace App\Services\Bridge;

use App\Models\BridgeDeposit;
use App\Models\SystemAlert;
use App\Support\Wei;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BridgeRelayer — mint USDT_TPIX ให้เงินฝากที่ยืนยันแล้ว ผ่าน TPIXBridgeMinter บนเชน 4289.
 *
 * ═══ ลำดับที่กัน mint ซ้ำ (ห้ามสลับ) ═══
 *
 *   ยังไม่มี mint_tx_hash → ถาม USDT_TPIX.processedTxHashes(depositId) ก่อน
 *                         (mint ไปแล้วโดยธุรกรรมที่เราไม่ได้ส่ง = needs_review + ปิดสวิตช์ ไม่นับเป็น minted)
 *                         → เซ็น → **บันทึก hash + raw ลงฐานข้อมูล** → ค่อย broadcast
 *   มี mint_tx_hash แล้ว  → ไม่เซ็นใหม่เด็ดขาด ไปตามผลของ hash เดิม (ไม่เจอบนเชน = ส่ง raw เดิมซ้ำ)
 *
 * ต่อให้ทุกอย่างข้างบนพลาดหมด สัญญายังกันชั้นสุดท้าย: depositId ซ้ำ = revert "txhash replayed"
 *
 * ทำทีละรายการแบบเรียงลำดับ และไม่เซ็นรายการใหม่ระหว่างที่ยังมีรายการ minting ค้างไม่รู้ผล
 * เพราะ nonce ถูกถามแบบ pending — ถ้าธุรกรรมก่อนหน้าหลุดจาก mempool แล้วเราเซ็นต่อ
 * ตัวใหม่จะได้ nonce เดียวกันไปทับ แล้วตัวเก่าไม่มีวันลงบล็อก
 *
 * "สัญญาบอกว่า mint แล้ว" ไม่ได้แปลว่า "คนอื่น mint" เสมอไป — อาจเป็นธุรกรรมของเราเองที่ RPC ยังไม่เห็นใบเสร็จ
 * หรือธุรกรรมเดิมก่อนแอดมินอนุมัติใหม่ ก่อนสั่งหยุดสะพานจึงอ่าน Minted(to, amount, depositId) ของ USDT_TPIX
 * ว่าเหรียญไปถึงผู้ฝากตามจำนวนจริงหรือไม่ (ตรง = ปิดเป็น minted · ไม่ตรง/พิสูจน์ไม่ได้ = หยุดสะพาน)
 */
class BridgeRelayer
{
    /** เซ็นไม่ผ่าน (estimateGas ว่าจะ revert) ติดกันเท่านี้ครั้ง → ส่งให้คนดู */
    public const MAX_SIGN_ATTEMPTS = 5;

    /** broadcast แล้วเชนยังไม่รู้จักนานเกินนี้ → ส่ง raw เดิมซ้ำ */
    public const REBROADCAST_AFTER_SECONDS = 90;

    public const REASON_STUCK = 'stuck_pending';

    public const ALERT_STUCK = 'bridge_mint_stuck';

    /** ขอบล่างของการไล่หา Minted เผื่อเวลาเครื่องคลาดกัน / RPC ต้นทางอ่านเวลาบล็อกไม่ได้ */
    private const MINT_SEARCH_MARGIN_SECONDS = 600;

    private const MINT_SEARCH_FALLBACK_SECONDS = 86400;

    public function __construct(
        private BridgeConfig $config,
        private BridgeSigner $signer,
        private BridgeDepositCrossCheck $crossCheck,
    ) {}

    /**
     * ด่านความพร้อมของฝั่ง mint — ทุกข้อต้องผ่านก่อนเซ็นอะไรทั้งนั้น.
     *
     * @return array{ready: bool, checks: array<int, array{key: string, ok: bool, hint: string}>}
     */
    public function readiness(?EvmRpc $rpc = null): array
    {
        $rpc ??= $this->config->tpixRpc();
        $minter = $this->config->minterAddress();
        $usdt = $this->config->usdtTpixAddress();
        $relayer = $this->config->signerAddress('relayer');
        $checks = [];

        $checks[] = ['key' => 'installed', 'ok' => $this->config->installed(), 'hint' => 'BRIDGE_ENABLED=true ใน .env'];
        $checks[] = ['key' => 'site_switch', 'ok' => $this->config->siteSwitchOn(), 'hint' => 'เปิดสวิตช์ที่ /admin/bridge'];
        $checks[] = ['key' => 'usdt_tpix', 'ok' => $usdt !== null && ! $this->config->usdtTpixRegistryMismatch(),
            'hint' => $usdt === null ? 'ตั้ง BRIDGE_USDT_TPIX_ADDRESS'
                : ($this->config->usdtTpixRegistryMismatch() ? 'ทะเบียนสัญญาชี้ usdt_tpix ไปที่อื่น — ตรวจก่อนเปิด' : $usdt)];
        $checks[] = ['key' => 'minter', 'ok' => $minter !== null, 'hint' => $minter ?? 'ยังไม่ได้ลงทะเบียน bridge_minter'];
        $checks[] = ['key' => 'relayer_keystore', 'ok' => $relayer !== null, 'hint' => $relayer ?? 'วางไฟล์ BRIDGE_RELAYER_KEYSTORE'];
        $checks[] = ['key' => 'relayer_passphrase', 'ok' => $this->config->hasPassphrase('relayer'), 'hint' => 'BRIDGE_RELAYER_PASS ใน .env'];

        $onchain = $minter && $usdt && $relayer ? $this->onchainState($rpc, $minter, $usdt, $relayer) : null;

        $checks[] = ['key' => 'minter_is_bridge', 'ok' => ($onchain['minter_is_bridge'] ?? null) === true,
            'hint' => 'USDT_TPIX.setBridge(minter, true) โดยเจ้าของ'];
        $checks[] = ['key' => 'relayer_role', 'ok' => ($onchain['relayer_role'] ?? null) === true,
            'hint' => 'TPIXBridgeMinter.setRelayer(relayer, true) โดยเจ้าของ'];
        $checks[] = ['key' => 'minter_not_paused', 'ok' => ($onchain['minter_paused'] ?? null) === false,
            'hint' => ($onchain['minter_paused'] ?? null) === true ? 'minter ถูก pause อยู่ — เจ้าของต้อง unpause' : 'อ่านสถานะจากเชนไม่ได้'];
        $checks[] = ['key' => 'token_not_paused', 'ok' => ($onchain['token_paused'] ?? null) === false,
            'hint' => 'USDT_TPIX ต้องไม่ถูก pause'];

        return [
            'ready' => ! in_array(false, array_column($checks, 'ok'), true),
            'checks' => $checks,
            'onchain' => $onchain,
        ];
    }

    /**
     * สถานะบนเชนที่ minter ต้องการ — ค่า null = อ่านไม่ได้.
     */
    public function onchainState(EvmRpc $rpc, string $minter, string $usdt, ?string $relayer): array
    {
        return [
            'minter_is_bridge' => $rpc->callBool($usdt, 'bridges(address)', Abi::encAddress($minter)),
            'relayer_role' => $relayer ? $rpc->callBool($minter, 'relayers(address)', Abi::encAddress($relayer)) : null,
            'minter_paused' => $rpc->callBool($minter, 'paused()'),
            'token_paused' => $rpc->callBool($usdt, 'paused()'),
            'max_per_tx' => $rpc->callUint($minter, 'maxPerTx()'),
            'daily_cap' => $rpc->callUint($minter, 'dailyCap()'),
            'remaining_today' => $rpc->callUint($minter, 'remainingToday()'),
            'total_minted' => $rpc->callUint($minter, 'totalMinted()'),
        ];
    }

    /** depositId นี้ถูก mint บนสัญญาไปแล้วหรือยัง — null = อ่านไม่ได้ */
    public function processedOnChain(EvmRpc $rpc, string $depositId): ?bool
    {
        $usdt = $this->config->usdtTpixAddress();

        return $usdt ? $rpc->callBool($usdt, 'processedTxHashes(bytes32)', Abi::encBytes32($depositId)) : null;
    }

    /**
     * หนึ่งรอบ: ตามผลรายการที่ส่งไปแล้ว → mint รายการใหม่ (ถ้าพร้อม).
     *
     * @return array{tracked: int, minted: int, signed: int, blocked: ?string}
     */
    public function run(int $limit = 5): array
    {
        $rpc = $this->config->tpixRpc();
        $summary = ['tracked' => 0, 'minted' => 0, 'signed' => 0, 'blocked' => null];

        // ── ระยะที่ 1: ตามผลก่อนเสมอ (ทำแม้สวิตช์ปิด — ของที่ส่งไปแล้วต้องรู้ผล) ─────────
        $summary['tracked'] = $this->trackMinting($rpc);

        // รวมรายการที่ค้างจนส่งให้คนดูแล้ว — ธุรกรรมเดิมยังลงบล็อกได้ เซ็นตัวใหม่ก็ต่อคิวหลัง nonce เดิมอยู่ดี
        if ($this->unresolvedMints()->exists()) {
            $summary['blocked'] = 'awaiting_previous_mint';

            return $summary;
        }

        // ── ระยะที่ 2: mint ใหม่ ─────────────────────────────────────────────
        // ไม่มีงาน = ไม่ถามเชน (rpc.tpix.online อยู่หลัง Cloudflare ที่ตอบ 429 เมื่อยิงถี่ — เก็บโควตาไว้ให้ตอนมีงานจริง)
        if (! BridgeDeposit::where('status', BridgeDeposit::STATUS_CONFIRMED)->whereNull('mint_tx_hash')->exists()) {
            return $summary;
        }

        $ready = $this->readiness($rpc);
        if (! $ready['ready']) {
            $summary['blocked'] = 'not_ready:'.implode(',', array_column(array_filter($ready['checks'], fn ($c) => ! $c['ok']), 'key'));

            return $summary;
        }

        if (! $this->signer->isAvailable()) {
            $summary['blocked'] = 'not_cli';

            return $summary;
        }

        $onchain = $ready['onchain'];
        $remaining = $onchain['remaining_today'] ?? null;
        $maxPerTx = $onchain['max_per_tx'] ?? null;

        if ($remaining === null || $maxPerTx === null) {
            $summary['blocked'] = 'minter_unreadable';

            return $summary;
        }

        for ($i = 0; $i < max(1, $limit); $i++) {
            $deposit = BridgeDeposit::where('status', BridgeDeposit::STATUS_CONFIRMED)
                ->whereNull('mint_tx_hash')
                ->orderBy('id')
                ->first();

            if (! $deposit) {
                break;
            }

            $outcome = $this->mintOne($rpc, $deposit, $remaining, $maxPerTx);

            if ($outcome === 'minted') {
                $summary['minted']++;
                $summary['signed']++;
                $remaining = bcsub($remaining, (string) $deposit->mint_units, 0);

                continue;
            }

            // review = ส่งให้คนดูแล้ว · already_minted = สัญญาบอกว่า mint แล้วและ Minted ตรงกับผู้ฝาก
            // (โควตาวันนี้ที่อ่านมาหักยอดนั้นไปแล้ว ไม่ต้องหักซ้ำ)
            if ($outcome === 'review' || $outcome === 'already_minted') {
                continue;
            }

            // ส่งแล้วแต่ยังไม่ลงบล็อก / ติดเพดานวัน / เซ็นไม่ได้ / ถูก mint ตัดหน้า — หยุดรอบนี้ รอบหน้าตามผลต่อ
            if ($outcome === 'sent') {
                $summary['signed']++;
            }
            $summary['blocked'] = $outcome;
            break;
        }

        return $summary;
    }

    /**
     * ก่อนเซ็นต้องมี provider อื่นเห็นเงินฝากเดียวกัน — คืน outcome เมื่อยังเซ็นไม่ได้.
     *
     * แอดมินอนุมัติแล้ว (limits_overridden) = ตรวจบน explorer แล้ว ไม่ถามซ้ำ
     * ไม่งั้นรายการที่ provider ตัวหนึ่งไม่เคยเห็นจะวนกลับมารอตรวจไม่รู้จบ
     */
    private function crossCheckHold(BridgeDeposit $deposit): ?string
    {
        if ($deposit->limits_overridden) {
            return null;
        }

        $verdict = $this->crossCheck->check($deposit);

        if ($verdict === BridgeDepositCrossCheck::MISMATCH) {
            $deposit->update([
                'status' => BridgeDeposit::STATUS_NEEDS_REVIEW,
                'review_reason' => 'rpc_disagreement',
                'last_error' => 'RPC ของเชนต้นทางเห็นเงินฝากนี้ไม่ตรงกัน — ตรวจบน explorer ก่อนอนุมัติ',
            ]);
            SystemAlert::raise(
                BridgeReconciler::ALERT_NODE,
                'bridge_rpc_disagreement',
                'critical',
                "สะพาน USDT: RPC ของเชน {$deposit->source_chain_id} เห็นเงินฝาก #{$deposit->id} ไม่ตรงกัน — หยุด mint รายการนี้แล้ว RPC อาจถูกเจาะ",
                ['deposit' => $deposit->id, 'tx' => $deposit->tx_hash],
            );

            return 'review';
        }

        if ($verdict !== BridgeDepositCrossCheck::PENDING) {
            return null;
        }

        if ($deposit->created_at->diffInSeconds(now()) < $this->config->stuckAfterSeconds()) {
            $deposit->update(['last_error' => 'รอ RPC อีกตัวยืนยันเงินฝาก']);

            return 'awaiting_source_confirmation';
        }

        $deposit->update([
            'status' => BridgeDeposit::STATUS_NEEDS_REVIEW,
            'review_reason' => 'source_unconfirmed',
            'last_error' => 'ไม่มี RPC ตัวที่สองยืนยันเงินฝากนี้ — ตรวจบน explorer ก่อนอนุมัติ',
        ]);

        return 'review';
    }

    /**
     * mint หนึ่งรายการ — คืน minted | minted_elsewhere | sent | review | daily_cap | sign_failed | busy | awaiting_source_confirmation.
     */
    private function mintOne(EvmRpc $rpc, BridgeDeposit $candidate, string $remaining, string $maxPerTx): string
    {
        // ล็อกแถว + เช็คซ้ำ — กันสองโปรเซส (หรือแอดมินกดพร้อมกัน) หยิบรายการเดียวกัน
        $deposit = DB::transaction(function () use ($candidate) {
            $row = BridgeDeposit::whereKey($candidate->id)->lockForUpdate()->first();

            return $row && $row->status === BridgeDeposit::STATUS_CONFIRMED && $row->mint_tx_hash === null ? $row : null;
        });

        if (! $deposit) {
            return 'busy';
        }

        $units = (string) $deposit->mint_units;

        // สัญญาบอกว่า depositId นี้ mint ไปแล้วทั้งที่รายการยังไม่มีธุรกรรมของเรา — ไม่เซ็นซ้ำ (สัญญา revert อยู่ดี)
        // และห้ามนับว่าส่งถึงผู้ฝากจนกว่าจะเห็น Minted ที่ตรงกับผู้ฝาก: อาจเป็นคีย์ relayer ที่หลุด mint ตัดหน้าไปที่อื่น
        $processed = $this->processedOnChain($rpc, $deposit->deposit_id);
        if ($processed === null) {
            return 'sign_failed';
        }
        if ($processed) {
            return match ($this->resolveProcessed($rpc, $deposit, 'ก่อนเซ็น')) {
                'minted' => 'already_minted',
                'held' => 'minted_elsewhere',
                default => 'sign_failed', // อ่าน log ไม่ได้ตอนนี้ — รอบหน้าลองใหม่
            };
        }

        if (bccomp($units, $maxPerTx, 0) > 0) {
            $deposit->update(['status' => BridgeDeposit::STATUS_NEEDS_REVIEW, 'review_reason' => 'exceeds_onchain_max']);

            return 'review';
        }

        if (bccomp($units, $remaining, 0) > 0) {
            // ไม่ใช่ความผิดของผู้ฝาก — รอวันใหม่ (UTC) ตามเพดานบนสัญญา ไม่เปลี่ยนสถานะ
            $deposit->update(['last_error' => 'รอโควตา dailyCap ของวันถัดไป']);

            return 'daily_cap';
        }

        if (($held = $this->crossCheckHold($deposit)) !== null) {
            return $held;
        }

        $signed = $this->signer->sign(
            'relayer',
            $rpc,
            (string) $this->config->minterAddress(),
            Abi::mintCalldata($deposit->from_address, $units, $deposit->deposit_id),
            ['gas_multiplier_bps' => 10000],
        );

        if (! ($signed['ok'] ?? false)) {
            $attempts = $deposit->mint_attempts + 1;
            $code = (string) ($signed['code'] ?? 'error');
            $toReview = $code === 'estimate_failed' && $attempts >= self::MAX_SIGN_ATTEMPTS;

            $deposit->update([
                'mint_attempts' => $attempts,
                'last_error' => mb_substr("เซ็นไม่ผ่าน ({$code}): ".($signed['error'] ?? ''), 0, 300),
                'status' => $toReview ? BridgeDeposit::STATUS_NEEDS_REVIEW : $deposit->status,
                'review_reason' => $toReview ? 'mint_estimate_failed' : $deposit->review_reason,
            ]);

            return $toReview ? 'review' : 'sign_failed';
        }

        // ⚠️ บันทึก hash + raw ก่อนส่ง — ห้ามสลับลำดับเด็ดขาด
        // อัปเดตแบบมีเงื่อนไข (compare-and-set): ถ้าระหว่างเซ็นแอดมินเพิ่งปฏิเสธรายการนี้ แถวจะไม่ตรงเงื่อนไข
        // แล้วเราทิ้งธุรกรรมที่เซ็นไว้ไปเฉย ๆ (ยังไม่เคยส่ง nonce จึงไม่ถูกใช้ ไม่มีอะไรเสีย)
        $claimed = BridgeDeposit::whereKey($deposit->id)
            ->where('status', BridgeDeposit::STATUS_CONFIRMED)
            ->whereNull('mint_tx_hash')
            ->update([
                'status' => BridgeDeposit::STATUS_MINTING,
                'mint_tx_hash' => strtolower((string) $signed['txHash']),
                'mint_raw_tx' => (string) $signed['raw'],
                'mint_nonce' => (int) ($signed['nonce'] ?? 0),
                'mint_attempts' => $deposit->mint_attempts + 1,
                'mint_broadcast_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            Log::warning('bridge: รายการเปลี่ยนสถานะระหว่างเซ็น — ทิ้งธุรกรรมที่เซ็นไว้ ไม่ส่ง', ['deposit' => $deposit->id]);

            return 'busy';
        }

        $deposit->refresh();

        $sent = $this->signer->send($rpc, (string) $signed['raw']);
        if (! ($sent['ok'] ?? false)) {
            // ส่งไม่ผ่าน (RPC ล่ม / nonce ถูกใช้) — ไม่เซ็นใหม่ ให้รอบตามผลไปตรวจ/ส่ง raw เดิมซ้ำ
            $deposit->update(['last_error' => mb_substr('broadcast ไม่ผ่าน: '.($sent['error'] ?? ''), 0, 300)]);
        }

        Log::info('bridge: ส่ง mint ขึ้นเชนแล้ว', [
            'deposit' => $deposit->id,
            'to' => $deposit->from_address,
            'units' => $units,
            'tx' => $signed['txHash'],
            'nonce' => $signed['nonce'] ?? null,
        ]);

        return $this->waitForReceipt($rpc, $deposit->fresh()) ? 'minted' : 'sent';
    }

    /** รอใบเสร็จสั้น ๆ ในรอบเดียว — ได้ = ทำรายการถัดไปต่อได้เลย ไม่ต้องรอนาทีหน้า */
    private function waitForReceipt(EvmRpc $rpc, BridgeDeposit $deposit): bool
    {
        $deadline = microtime(true) + max(0, (int) config('bridge.receipt_wait_seconds', 30));

        do {
            $this->trackOne($rpc, $deposit);
            $deposit->refresh();

            if ($deposit->status !== BridgeDeposit::STATUS_MINTING) {
                return $deposit->status === BridgeDeposit::STATUS_MINTED;
            }

            if (microtime(true) >= $deadline) {
                return false;
            }

            usleep(2_000_000);
        } while (true);
    }

    /** รายการที่มีธุรกรรม mint ค้างอยู่บนเชน (กำลัง mint หรือค้างจนส่งให้คนดูแล้วแต่ยังลงบล็อกได้) */
    private function unresolvedMints(): Builder
    {
        return BridgeDeposit::query()
            ->whereNotNull('mint_tx_hash')
            ->where(fn ($q) => $q->where('status', BridgeDeposit::STATUS_MINTING)
                ->orWhere(fn ($q) => $q->where('status', BridgeDeposit::STATUS_NEEDS_REVIEW)->where('review_reason', self::REASON_STUCK)));
    }

    /** ตามผลทุกรายการที่อยู่ระหว่าง minting (รวมที่ค้างจนส่งให้คนดูแล้ว) — คืนจำนวนที่ได้ข้อสรุป */
    public function trackMinting(EvmRpc $rpc): int
    {
        $settled = 0;

        foreach ($this->unresolvedMints()->orderBy('mint_nonce')->get() as $deposit) {
            if ($this->trackOne($rpc, $deposit)) {
                $settled++;
            }
        }

        if (! $this->unresolvedMints()->where('review_reason', self::REASON_STUCK)->exists()) {
            SystemAlert::resolveKeys(BridgeReconciler::ALERT_NODE, [self::ALERT_STUCK]);
        }

        return $settled;
    }

    /**
     * ตามผลรายการเดียว — true = ได้ข้อสรุปแล้ว (minted หรือ needs_review).
     */
    private function trackOne(EvmRpc $rpc, BridgeDeposit $deposit): bool
    {
        $hash = (string) $deposit->mint_tx_hash;
        if ($hash === '') {
            return false;
        }

        // ถามทุก RPC — โหนดที่ตามหัวไม่ทันตอบ null ได้ทั้งที่ลงบล็อกแล้ว
        $receipt = $rpc->receiptAny($hash)['receipt'];

        if ($receipt !== null) {
            return $this->settleReceipt($rpc, $deposit, $receipt, $hash);
        }

        $known = $rpc->knowsTransaction($hash);
        if ($known === null) {
            return false; // อ่านไม่ได้ — รอรอบหน้า ห้ามตัดสินอะไร
        }

        if (! $known) {
            // เชนไม่รู้จัก — ถ้าเงียบไปนานพอแล้วให้ยิง raw เดิมซ้ำ (nonce เดิม รับได้ครั้งเดียว)
            // ระยะห่างนับจากการเปลี่ยนแปลงล่าสุด · mint_broadcast_at = ครั้งแรกที่ส่ง ใช้วัด "ค้าง"
            $sinceTouch = $deposit->updated_at ? now()->diffInSeconds($deposit->updated_at, true) : PHP_INT_MAX;

            if ($sinceTouch >= self::REBROADCAST_AFTER_SECONDS && $deposit->mint_raw_tx && $this->signer->isAvailable()) {
                $sent = $this->signer->send($rpc, (string) $deposit->mint_raw_tx);

                if (($sent['code'] ?? null) === 'nonce_used') {
                    // nonce ถูกใช้แล้ว — อาจเป็นธุรกรรมของเราเองที่เพิ่งลงบล็อก (RPC ที่ถามใบเสร็จช้ากว่า)
                    // ถามใบเสร็จจากทุก RPC อีกรอบก่อน ไม่งั้นจะไปตัดสินว่า "คนอื่น mint" แล้วหยุดสะพานเปล่า ๆ
                    $again = $rpc->receiptAny($hash)['receipt'];

                    return $again !== null
                        ? $this->settleReceipt($rpc, $deposit, $again, $hash)
                        : $this->settleFailed($rpc, $deposit, 'nonce_consumed', "nonce {$deposit->mint_nonce} ถูกใช้ไปแล้วแต่ไม่เจอใบเสร็จของ {$hash}");
                }

                $this->updateIfUnchanged($deposit, [
                    'last_error' => ($sent['ok'] ?? false) ? 'ส่ง raw เดิมซ้ำแล้ว' : mb_substr('ส่งซ้ำไม่ผ่าน: '.($sent['error'] ?? ''), 0, 300),
                ]);
            }
        }

        return $this->flagIfStuck($deposit, $hash, (bool) $known);
    }

    private function settleReceipt(EvmRpc $rpc, BridgeDeposit $deposit, array $receipt, string $hash): bool
    {
        $block = isset($receipt['blockNumber']) ? (int) Wei::hexToInt((string) $receipt['blockNumber']) : null;

        if (strtolower((string) ($receipt['status'] ?? '')) === '0x1') {
            $done = $this->updateIfUnchanged($deposit, [
                'status' => BridgeDeposit::STATUS_MINTED,
                'mint_block_number' => $block,
                'minted_at' => now(),
                'last_error' => null,
            ]);
            Log::info('bridge: mint สำเร็จ', ['deposit' => $deposit->id, 'tx' => $hash, 'block' => $block]);

            return $done;
        }

        // revert — ดูที่สัญญาว่า depositId นี้ถูก mint ไปแล้วด้วยธุรกรรมอื่นหรือเปล่า
        return $this->settleFailed($rpc, $deposit, 'mint_reverted', "ธุรกรรม {$hash} revert บนเชน");
    }

    /**
     * ส่งไปนานเกิน stuck_after_minutes แล้วยังไม่ลงบล็อก → needs_review (stuck_pending) + แจ้งเตือน critical.
     *
     * ไม่เปลี่ยน hash/raw — ยังตามผลต่อทุกรอบ ลงบล็อกเมื่อไหร่ก็ปิดเป็น minted เอง และคิว mint ยังหยุดรอ
     */
    private function flagIfStuck(BridgeDeposit $deposit, string $hash, bool $known): bool
    {
        if ($deposit->status !== BridgeDeposit::STATUS_MINTING) {
            return false; // ส่งให้คนดูไปแล้ว — ไม่แจ้งซ้ำทุกนาที
        }

        $age = $deposit->mint_broadcast_at ? now()->diffInSeconds($deposit->mint_broadcast_at, true) : 0;
        if ($age < $this->config->stuckAfterSeconds()) {
            return false;
        }

        $minutes = (int) floor($age / 60);
        $note = "ส่งไปแล้ว {$minutes} นาทีแต่ยังไม่ลงบล็อก (".($known ? 'ค้างใน mempool' : 'เชนไม่รู้จัก ส่งซ้ำแล้วก็ไม่ลง').') — '
            ."คิว mint หยุดรอ · ทางออก: รอให้ลงบล็อก หรือให้เจ้าของส่งธุรกรรมแทนที่ด้วย nonce {$deposit->mint_nonce} จากกระเป๋า relayer";

        if (! $this->updateIfUnchanged($deposit, [
            'status' => BridgeDeposit::STATUS_NEEDS_REVIEW,
            'review_reason' => self::REASON_STUCK,
            'last_error' => mb_substr($note, 0, 300),
        ])) {
            return false;
        }

        Log::critical('bridge: ธุรกรรม mint ค้าง', ['deposit' => $deposit->id, 'tx' => $hash, 'nonce' => $deposit->mint_nonce]);
        SystemAlert::raise(
            BridgeReconciler::ALERT_NODE,
            self::ALERT_STUCK,
            'critical',
            "สะพาน USDT: ธุรกรรม mint ของเงินฝาก #{$deposit->id} ค้าง {$minutes} นาที (nonce {$deposit->mint_nonce}) — คิว mint หยุดรอ",
            ['deposit' => $deposit->id, 'tx' => $hash, 'nonce' => $deposit->mint_nonce]
        );

        return true;
    }

    /** ธุรกรรมของเราไม่สำเร็จ — ถ้าสัญญาบอกว่า mint แล้ว ต้องพิสูจน์ด้วย Minted ก่อนว่าไปถึงผู้ฝาก */
    private function settleFailed(EvmRpc $rpc, BridgeDeposit $deposit, string $reason, string $note): bool
    {
        $processed = $this->processedOnChain($rpc, $deposit->deposit_id);

        if ($processed === null) {
            return false;
        }

        if ($processed) {
            return $this->resolveProcessed($rpc, $deposit, $note) !== 'unreadable';
        }

        $done = $this->updateIfUnchanged($deposit, [
            'status' => BridgeDeposit::STATUS_NEEDS_REVIEW,
            'review_reason' => $reason,
            'last_error' => mb_substr($note, 0, 300),
        ]);
        Log::warning('bridge: mint ไม่สำเร็จ — ส่งให้แอดมินดู', ['deposit' => $deposit->id, 'reason' => $reason]);

        return $done;
    }

    /**
     * สัญญาบอกว่า depositId นี้ mint แล้ว — ดู Minted ว่าไปถึงใคร เท่าไร แล้วตัดสิน.
     *
     *   ตรงกับผู้ฝาก (to = from_address · amount = mint_units) → minted (เช่นธุรกรรมของเราเองที่ RPC ยังไม่เห็นใบเสร็จ
     *                                                            หรือธุรกรรมเดิมก่อนแอดมินอนุมัติใหม่)
     *   ไม่ตรง / หาไม่เจอทั้งช่วง                              → held (minted_elsewhere + หยุดสะพาน)
     *   อ่าน log ไม่ได้                                        → unreadable (ไม่ตัดสิน รอบหน้าลองใหม่)
     *
     * @return 'minted'|'held'|'unreadable'
     */
    private function resolveProcessed(EvmRpc $rpc, BridgeDeposit $deposit, string $context): string
    {
        $mint = $this->findMint($rpc, $deposit);

        if ($mint['state'] === 'unreadable') {
            return 'unreadable';
        }

        if ($mint['state'] === 'found' && $this->mintMatches($deposit, $mint)) {
            $previous = $deposit->mint_tx_hash && strtolower((string) $deposit->mint_tx_hash) !== $mint['tx_hash']
                ? " (ธุรกรรมที่ระบบส่ง {$deposit->mint_tx_hash} ไม่ได้เป็นตัวที่ลง)" : '';

            $done = $this->updateIfUnchanged($deposit, [
                'status' => BridgeDeposit::STATUS_MINTED,
                'mint_tx_hash' => $mint['tx_hash'],
                'mint_block_number' => $mint['block'],
                'minted_at' => now(),
                'last_error' => mb_substr("{$context}: ยืนยันจาก Minted บนเชน — ไปถึงผู้ฝากครบ{$previous}", 0, 300),
            ]);
            Log::info('bridge: ยืนยัน mint จาก Minted บนเชน', ['deposit' => $deposit->id, 'tx' => $mint['tx_hash']]);

            return $done ? 'minted' : 'unreadable';
        }

        $detail = $mint['state'] === 'found'
            ? 'Minted ไปที่ '.$mint['to'].' จำนวน '.Wei::format($mint['units'], 6).' USDT (tx '.$mint['tx_hash'].') ไม่ตรงกับผู้ฝาก'
            : ($mint['state'] === 'too_far' ? 'ช่วงบล็อกไกลเกินกว่าจะไล่หา Minted อัตโนมัติ' : 'ไม่เจอ Minted ของ depositId นี้');

        $this->holdMintedElsewhere($deposit, "{$context}: {$detail}");

        return 'held';
    }

    public function mintMatches(BridgeDeposit $deposit, array $mint): bool
    {
        return ($mint['state'] ?? null) === 'found'
            && strtolower((string) $mint['to']) === strtolower((string) $deposit->from_address)
            && bccomp((string) $mint['units'], (string) $deposit->mint_units, 0) === 0;
    }

    /**
     * หา Minted(to, amount, sourceTxHash = depositId) ของ USDT_TPIX — หลักฐานว่า depositId นี้ไปถึงใคร เท่าไร.
     *
     * ไล่ไปข้างหน้าจากเวลาที่ธุรกรรมฝากเกิดบนเชนต้นทาง (ก่อนหน้านั้นไม่มีใคร mint ได้ เพราะ depositId ยังไม่มี)
     * เผื่อเวลาเครื่องคลาด 10 นาที · อ่านเวลาบล็อกต้นทางไม่ได้ = ถอยจากเวลาบันทึกรายการไป 1 วัน
     * เจอแล้วหยุดทันที (ปกติอยู่ใกล้จุดเริ่ม) · ไกลเกิน verify_max_blocks = too_far (ไม่เดา)
     *
     * @return array{state: 'found', to: string, units: string, tx_hash: string, block: int}|array{state: 'not_found'|'unreadable'|'too_far'}
     */
    public function findMint(EvmRpc $rpc, BridgeDeposit $deposit): array
    {
        $token = $this->config->usdtTpixAddress();
        $depositId = strtolower((string) $deposit->deposit_id);

        if ($token === null || ! preg_match('/^0x[0-9a-f]{64}$/', $depositId)) {
            return ['state' => 'unreadable'];
        }

        $created = $deposit->created_at?->getTimestamp() ?? now()->getTimestamp();
        $sourceTs = $this->config->rpc((int) $deposit->source_chain_id)?->blockTimestamp((int) $deposit->block_number);
        $since = $sourceTs !== null
            ? min($sourceTs, $created) - self::MINT_SEARCH_MARGIN_SECONDS
            : $created - self::MINT_SEARCH_FALLBACK_SECONDS;

        $latest = $rpc->blockNumber();
        $start = $rpc->blockAtOrBefore($since, (float) config('bridge.tpix.block_seconds', 2));

        if ($latest === null || $start === null) {
            return ['state' => 'unreadable'];
        }

        if ($latest - $start > max(1000, (int) config('bridge.tpix.verify_max_blocks', 200000))) {
            return ['state' => 'too_far'];
        }

        $topic = Abi::topic(Abi::MINTED_EVENT);
        $matches = fn (array $log) => strtolower((string) ($log['address'] ?? '')) === $token
            && ($log['removed'] ?? false) !== true
            && strtolower((string) ($log['topics'][0] ?? '')) === $topic
            && strtolower((string) ($log['topics'][2] ?? '')) === $depositId;

        $logs = $rpc->logsBetween(
            $token,
            [$topic, null, $depositId],
            $start,
            $latest,
            min(900, max(1, (int) config('bridge.tpix.log_chunk', 900))),
            $matches
        );

        if ($logs === null) {
            return ['state' => 'unreadable'];
        }

        foreach ($logs as $log) {
            if ($matches($log)) {
                $to = Abi::topicAddress($log['topics'][1] ?? null);

                return $to === null ? ['state' => 'unreadable'] : [
                    'state' => 'found',
                    'to' => $to,
                    'units' => Wei::hexToInt((string) ($log['data'] ?? '0x0')),
                    'tx_hash' => strtolower((string) ($log['transactionHash'] ?? '')),
                    'block' => (int) Wei::hexToInt((string) ($log['blockNumber'] ?? '0x0')),
                ];
            }
        }

        return ['state' => 'not_found'];
    }

    /**
     * ธุรกรรม mint ที่รายการนี้ถืออยู่ "ตายแล้ว" (ไม่มีทางลงบล็อกได้อีก) ไหม — ใช้ก่อนแอดมินอนุมัติใหม่/ปฏิเสธ.
     *
     * ตาย = ลงบล็อกแล้ว (มีใบเสร็จ / มี blockNumber) หรือ nonce ของ relayer ถูกใช้ไปแล้ว
     * ยังไม่ตาย = ยังอยู่ใน mempool ลงบล็อกได้ทุกเมื่อ (ปฏิเสธแล้วคืนเงินด้วยมือตอนนั้น = ผู้ใช้ได้สองต่อ)
     * null = อ่านไม่ได้
     */
    public function mintTxDead(EvmRpc $rpc, BridgeDeposit $deposit): ?bool
    {
        $hash = strtolower((string) $deposit->mint_tx_hash);
        if ($hash === '') {
            return true;
        }

        if ($rpc->receiptAny($hash)['receipt'] !== null) {
            return true;
        }

        $tx = $rpc->transactionAny($hash);
        if ($tx !== null && ! empty($tx['blockNumber'])) {
            return true;
        }

        $relayer = $this->config->signerAddress('relayer');
        $count = $relayer ? $rpc->nonceLatest($relayer) : null;

        if ($count === null || $deposit->mint_nonce === null) {
            return null;
        }

        return $count > (int) $deposit->mint_nonce;
    }

    /**
     * depositId นี้ถูก mint บนสัญญาแล้ว แต่ไม่ตรงกับผู้ฝาก (หรือพิสูจน์ไม่ได้) — ผู้รับและจำนวนอาจไม่ใช่ของผู้ฝาก.
     *
     * depositId = keccak(chainId, txHash, logIndex) คำนวณได้จาก log สาธารณะ คีย์ relayer ที่หลุดจึง mint
     * ตัดหน้าเข้ากระเป๋าตัวเองได้ และยอดรวมยังตรงกับเงินหนุน — ถ้านับรายการนี้เป็น minted
     * ตัวกระทบยอดจะไม่มีวันเห็น จึงไม่นับ (minter.totalMinted จะเกินยอดที่ระบบรับรู้ → guardian pause รอบถัดไป)
     * ปิดสวิตช์ทันที — แอดมินอนุมัติได้เฉพาะเมื่อ Minted บนเชนตรงกับผู้ฝาก (ระบบตรวจให้ตอนกดอนุมัติ)
     */
    private function holdMintedElsewhere(BridgeDeposit $deposit, string $context): void
    {
        $this->updateIfUnchanged($deposit, [
            'status' => BridgeDeposit::STATUS_NEEDS_REVIEW,
            'review_reason' => 'minted_elsewhere',
            'last_error' => mb_substr("{$context} — depositId ถูก mint บนสัญญาโดยที่ระบบยืนยันไม่ได้ว่าไปถึงผู้ฝาก", 0, 300),
        ]);

        $message = "สะพาน USDT ถูกหยุดอัตโนมัติ: deposit #{$deposit->id} ถูก mint โดยที่ Minted บนเชนไม่ตรงกับผู้ฝาก — คีย์ relayer อาจหลุด";
        $this->config->setSiteSwitch(false, "หยุดอัตโนมัติ (minted_elsewhere): deposit #{$deposit->id}", 'auto');

        Log::critical('bridge: '.$message, ['deposit' => $deposit->id, 'deposit_id' => $deposit->deposit_id, 'context' => $context]);
        SystemAlert::raise(BridgeReconciler::ALERT_NODE, 'bridge_minted_elsewhere', 'critical', $message, [
            'deposit' => $deposit->id,
            'deposit_id' => $deposit->deposit_id,
            'mint_units' => (string) $deposit->mint_units,
            'context' => mb_substr($context, 0, 300),
        ]);
    }

    /**
     * อัปเดตเฉพาะเมื่อแถวยังอยู่ในสถานะและธุรกรรมเดียวกับที่อ่านมา — แอดมินอาจอนุมัติ/ปฏิเสธไปพร้อมกันพอดี
     * ผลของธุรกรรมเก่าต้องไม่ไปเขียนทับรายการที่ถูกเปลี่ยนแล้ว.
     */
    private function updateIfUnchanged(BridgeDeposit $deposit, array $values): bool
    {
        $query = BridgeDeposit::whereKey($deposit->id)->where('status', $deposit->status);
        $deposit->mint_tx_hash === null
            ? $query->whereNull('mint_tx_hash')
            : $query->where('mint_tx_hash', $deposit->mint_tx_hash);

        $changed = $query->update($values + ['updated_at' => now()]);
        $deposit->refresh();

        return $changed === 1;
    }
}
