<?php

namespace App\Services\Bridge;

use App\Models\BridgeDeposit;
use App\Support\Wei;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BridgeRelayer — mint USDT_TPIX ให้เงินฝากที่ยืนยันแล้ว ผ่าน TPIXBridgeMinter บนเชน 4289.
 *
 * ═══ ลำดับที่กัน mint ซ้ำ (ห้ามสลับ) ═══
 *
 *   ยังไม่มี mint_tx_hash → ถาม USDT_TPIX.processedTxHashes(depositId) ก่อน (เคย mint ไปแล้ว = จบ)
 *                         → เซ็น → **บันทึก hash + raw ลงฐานข้อมูล** → ค่อย broadcast
 *   มี mint_tx_hash แล้ว  → ไม่เซ็นใหม่เด็ดขาด ไปตามผลของ hash เดิม (ไม่เจอบนเชน = ส่ง raw เดิมซ้ำ)
 *
 * ต่อให้ทุกอย่างข้างบนพลาดหมด สัญญายังกันชั้นสุดท้าย: depositId ซ้ำ = revert "txhash replayed"
 *
 * ทำทีละรายการแบบเรียงลำดับ และไม่เซ็นรายการใหม่ระหว่างที่ยังมีรายการ minting ค้างไม่รู้ผล
 * เพราะ nonce ถูกถามแบบ pending — ถ้าธุรกรรมก่อนหน้าหลุดจาก mempool แล้วเราเซ็นต่อ
 * ตัวใหม่จะได้ nonce เดียวกันไปทับ แล้วตัวเก่าไม่มีวันลงบล็อก
 */
class BridgeRelayer
{
    /** เซ็นไม่ผ่าน (estimateGas ว่าจะ revert) ติดกันเท่านี้ครั้ง → ส่งให้คนดู */
    public const MAX_SIGN_ATTEMPTS = 5;

    /** broadcast แล้วเชนยังไม่รู้จักนานเกินนี้ → ส่ง raw เดิมซ้ำ */
    public const REBROADCAST_AFTER_SECONDS = 90;

    public function __construct(
        private BridgeConfig $config,
        private BridgeSigner $signer,
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
        $checks[] = ['key' => 'usdt_tpix', 'ok' => $usdt !== null, 'hint' => $usdt ?? 'ยังไม่ได้ลงทะเบียน usdt_tpix'];
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

        if (BridgeDeposit::where('status', BridgeDeposit::STATUS_MINTING)->exists()) {
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

            if ($outcome === 'minted' || $outcome === 'already') {
                $summary['minted']++;
                if ($outcome === 'minted') {
                    $summary['signed']++;
                    $remaining = bcsub($remaining, (string) $deposit->mint_units, 0);
                }

                continue;
            }

            if ($outcome === 'review') {
                continue;
            }

            // ส่งแล้วแต่ยังไม่ลงบล็อก / ติดเพดานวัน / เซ็นไม่ได้ — หยุดรอบนี้ รอบหน้าตามผลต่อ
            if ($outcome === 'sent') {
                $summary['signed']++;
            }
            $summary['blocked'] = $outcome;
            break;
        }

        return $summary;
    }

    /**
     * mint หนึ่งรายการ — คืน minted | already | sent | review | daily_cap | sign_failed | busy.
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

        // สัญญาบอกว่า depositId นี้ mint ไปแล้ว (เช่นธุรกรรมเก่าที่แอดมินสั่งลองใหม่ลงบล็อกทีหลัง
        // หรือเจ้าของ mint ให้ด้วยมือ) — จบ ไม่เซ็นซ้ำ (ต่อให้เซ็น สัญญาก็ revert "txhash replayed" อยู่ดี)
        $processed = $this->processedOnChain($rpc, $deposit->deposit_id);
        if ($processed === null) {
            return 'sign_failed';
        }
        if ($processed) {
            $deposit->update([
                'status' => BridgeDeposit::STATUS_MINTED,
                'minted_at' => now(),
                'last_error' => 'mint พบบนสัญญาแล้ว (processedTxHashes = true) — ไม่ได้เซ็นซ้ำ',
            ]);

            return 'already';
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

    /** ตามผลทุกรายการที่อยู่ระหว่าง minting — คืนจำนวนที่ได้ข้อสรุป */
    public function trackMinting(EvmRpc $rpc): int
    {
        $settled = 0;

        foreach (BridgeDeposit::where('status', BridgeDeposit::STATUS_MINTING)->orderBy('mint_nonce')->get() as $deposit) {
            if ($this->trackOne($rpc, $deposit)) {
                $settled++;
            }
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

        $receipt = $rpc->receipt($hash);

        if ($receipt !== null) {
            $block = isset($receipt['blockNumber']) ? (int) Wei::hexToInt((string) $receipt['blockNumber']) : null;

            if (strtolower((string) ($receipt['status'] ?? '')) === '0x1') {
                $deposit->update([
                    'status' => BridgeDeposit::STATUS_MINTED,
                    'mint_block_number' => $block,
                    'minted_at' => now(),
                    'last_error' => null,
                ]);
                Log::info('bridge: mint สำเร็จ', ['deposit' => $deposit->id, 'tx' => $hash, 'block' => $block]);

                return true;
            }

            // revert — ดูที่สัญญาว่า depositId นี้ถูก mint ไปแล้วด้วยธุรกรรมอื่นหรือเปล่า
            return $this->settleFailed($rpc, $deposit, 'mint_reverted', "ธุรกรรม {$hash} revert บนเชน");
        }

        $known = $rpc->knowsTransaction($hash);
        if ($known === null) {
            return false; // อ่านไม่ได้ — รอรอบหน้า ห้ามตัดสินอะไร
        }

        if ($known) {
            return false; // อยู่ใน mempool รอลงบล็อก
        }

        // เชนไม่รู้จัก — ถ้าส่งไปนานพอแล้วให้ยิง raw เดิมซ้ำ (nonce เดิม รับได้ครั้งเดียว)
        $age = $deposit->mint_broadcast_at ? now()->diffInSeconds($deposit->mint_broadcast_at, true) : PHP_INT_MAX;
        if ($age < self::REBROADCAST_AFTER_SECONDS || ! $deposit->mint_raw_tx) {
            return false;
        }

        if (! $this->signer->isAvailable()) {
            return false;
        }

        $sent = $this->signer->send($rpc, (string) $deposit->mint_raw_tx);

        if (($sent['code'] ?? null) === 'nonce_used') {
            // nonce ถูกธุรกรรมอื่นใช้ไปแล้ว ธุรกรรมของเราจะไม่มีวันลงบล็อก — ดูที่สัญญาเป็นตัวตัดสิน
            return $this->settleFailed($rpc, $deposit, 'nonce_consumed', "nonce {$deposit->mint_nonce} ถูกใช้โดยธุรกรรมอื่น");
        }

        $deposit->update([
            'mint_broadcast_at' => now(),
            'last_error' => ($sent['ok'] ?? false) ? 'ส่ง raw เดิมซ้ำแล้ว' : mb_substr('ส่งซ้ำไม่ผ่าน: '.($sent['error'] ?? ''), 0, 300),
        ]);

        return false;
    }

    /** ธุรกรรมของเราไม่สำเร็จ — ถ้าสัญญาบอกว่า mint แล้ว (ทางอื่น) ถือว่าสำเร็จ ไม่งั้นส่งให้คนดู */
    private function settleFailed(EvmRpc $rpc, BridgeDeposit $deposit, string $reason, string $note): bool
    {
        $processed = $this->processedOnChain($rpc, $deposit->deposit_id);

        if ($processed === null) {
            return false;
        }

        if ($processed) {
            $deposit->update([
                'status' => BridgeDeposit::STATUS_MINTED,
                'minted_at' => now(),
                'last_error' => "{$note} แต่สัญญาบันทึกว่า mint แล้ว",
            ]);

            return true;
        }

        $deposit->update([
            'status' => BridgeDeposit::STATUS_NEEDS_REVIEW,
            'review_reason' => $reason,
            'last_error' => mb_substr($note, 0, 300),
        ]);
        Log::warning('bridge: mint ไม่สำเร็จ — ส่งให้แอดมินดู', ['deposit' => $deposit->id, 'reason' => $reason]);

        return true;
    }
}
