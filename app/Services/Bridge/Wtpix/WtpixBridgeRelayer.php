<?php

namespace App\Services\Bridge\Wtpix;

use App\Models\SystemAlert;
use App\Models\WtpixBridgeTransfer;
use App\Services\Bridge\Abi;
use App\Services\Bridge\BridgeSigner;
use App\Services\Bridge\EvmRpc;
use App\Support\Wei;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ตัวสั่งงานปลายทางของสะพาน TPIX ⇄ wTPIX (กุญแจ relayer ใบเดียว ใช้สองเชน).
 *
 *   to_bsc  → WTPIXBridgeMinter.mint(to, amount, depositId) บน BSC (ต้องมี BNB ค่าแก๊ส)
 *   to_tpix → TPIXBridgeVault.release(to, amount, depositId) บนเชน 4289 (legacy · gasPrice 0)
 *
 * ═══ ลำดับที่กันจ่ายซ้ำ (เหมือนสะพาน USDT — ห้ามสลับ) ═══
 *   ยังไม่มี dest_tx_hash → ถาม processed(depositId) บนสัญญาปลายทางก่อน
 *                          (true ทั้งที่ระบบไม่เคยส่ง = มีคนทำแทน → needs_review + หยุดสะพาน)
 *                          → ตรวจเพดาน/โควตา/BNB/TPIX ในคลัง → เซ็น → **บันทึก hash + raw** → ค่อย broadcast
 *   มี dest_tx_hash แล้ว  → ไม่เซ็นใหม่ ไปตามผลของ hash เดิม (เชนไม่รู้จัก = ส่ง raw เดิมซ้ำ nonce เดิม)
 *   ไม่แน่ใจเมื่อไหร่       → ถาม processed(depositId) บนสัญญาเป็นตัวตัดสิน
 *
 * ต่อให้ทุกอย่างข้างบนพลาด สัญญาทั้งสองตัวยังกันชั้นสุดท้าย: depositId ซ้ำ = revert AlreadyProcessed
 *
 * ทำทีละทิศ ทีละรายการ เรียงตามลำดับที่เข้าคิว และไม่เซ็นใหม่ระหว่างที่ยังมีรายการ sending ค้าง
 * (nonce ถามแบบ pending — ตัวที่หลุดจาก mempool จะถูกตัวใหม่ทับ nonce แล้วไม่มีวันลงบล็อก)
 * ไม่ทำบางส่วนเด็ดขาด: BNB/TPIX ไม่พอ = awaiting_liquidity ทั้งรายการ แล้วหยุดคิวของทิศนั้น
 */
class WtpixBridgeRelayer
{
    public const MAX_SIGN_ATTEMPTS = 5;

    public const REBROADCAST_AFTER_SECONDS = 90;

    /** ขอบล่างของการไล่หา event เผื่อเวลาเครื่องคลาดกัน / RPC ต้นทางอ่านเวลาบล็อกไม่ได้ */
    private const SETTLEMENT_SEARCH_MARGIN_SECONDS = 600;

    private const SETTLEMENT_SEARCH_FALLBACK_SECONDS = 86400;

    public function __construct(
        private WtpixBridgeConfig $config,
        private BridgeSigner $signer,
        private WtpixBridgeGuard $guard,
    ) {}

    public function destRpc(string $direction): EvmRpc
    {
        return $direction === WtpixBridgeTransfer::TO_BSC ? $this->config->bscRpc() : $this->config->tpixRpc();
    }

    // =========================================================================
    // ด่านความพร้อม
    // =========================================================================

    /**
     * ทุกข้อต้องผ่านก่อนเซ็นอะไรทั้งนั้น — คืน onchain ไว้ใช้ต่อ (เพดาน/โควตา).
     *
     * @return array{ready: bool, checks: array<int, array{key: string, ok: bool, hint: string}>, onchain: ?array}
     */
    public function readiness(string $direction, ?EvmRpc $rpc = null): array
    {
        $rpc ??= $this->destRpc($direction);
        $vault = $this->config->vaultAddress();
        $wtpix = $this->config->wtpixAddress();
        $minter = $this->config->minterAddress();
        $relayer = $this->config->signerAddress('relayer');
        $mismatch = $this->config->vaultRegistryMismatch();

        $checks = [
            ['key' => 'installed', 'ok' => $this->config->installed(), 'hint' => 'WTPIX_BRIDGE_ENABLED=true ใน .env'],
            ['key' => 'site_switch', 'ok' => $this->config->siteSwitchOn(), 'hint' => 'เปิดสวิตช์ที่ /admin/bridge/wtpix'],
            ['key' => 'vault', 'ok' => $vault !== null && ! $mismatch,
                'hint' => $vault === null ? 'ตั้ง WTPIX_BRIDGE_VAULT_ADDRESS'
                    : ($mismatch ? 'ทะเบียนสัญญาชี้ wtpix_bridge_vault ไปที่อื่น ('.$this->config->vaultRegistryAddress().') — ตรวจก่อนเปิด' : $vault)],
            ['key' => 'wtpix', 'ok' => $wtpix !== null, 'hint' => $wtpix ?? 'ตั้ง WTPIX_BSC_ADDRESS'],
            ['key' => 'minter', 'ok' => $minter !== null, 'hint' => $minter ?? 'ตั้ง WTPIX_BSC_MINTER_ADDRESS'],
            ['key' => 'relayer_keystore', 'ok' => $relayer !== null, 'hint' => $relayer ?? 'วางไฟล์ WTPIX_BRIDGE_RELAYER_KEYSTORE'],
            ['key' => 'relayer_passphrase', 'ok' => $this->config->hasPassphrase('relayer'), 'hint' => 'WTPIX_BRIDGE_RELAYER_PASS ใน .env'],
        ];

        $onchain = $vault && $wtpix && $minter && $relayer ? $this->onchain($direction, $rpc, $relayer) : null;

        if ($direction === WtpixBridgeTransfer::TO_BSC) {
            $checks[] = ['key' => 'relayer_role', 'ok' => ($onchain['relayer_role'] ?? null) === true, 'hint' => 'WTPIXBridgeMinter.setRelayer(relayer, true) โดยเจ้าของ'];
            $checks[] = ['key' => 'minter_not_paused', 'ok' => ($onchain['paused'] ?? null) === false,
                'hint' => ($onchain['paused'] ?? null) === true ? 'minter ถูก pause — เจ้าของต้อง unpause' : 'อ่านสถานะจาก BSC ไม่ได้'];
            $checks[] = ['key' => 'token_not_paused', 'ok' => ($onchain['token_paused'] ?? null) === false, 'hint' => 'wTPIX ต้องไม่ถูก pause'];
            $checks[] = ['key' => 'minter_is_minter', 'ok' => ($onchain['minter_is_minter'] ?? null) === true,
                'hint' => 'wTPIX.minters(minter) ต้องเป็น true (setInitialMinter หรือ queueMinter → 2 วัน → executeMinter)'];
            $checks[] = ['key' => 'minter_token', 'ok' => ($onchain['minter_token'] ?? null) === $wtpix,
                'hint' => 'WTPIXBridgeMinter.token() ต้องเป็น WTPIX_BSC_ADDRESS'];
        } else {
            $checks[] = ['key' => 'relayer_role', 'ok' => ($onchain['relayer_role'] ?? null) === true, 'hint' => 'TPIXBridgeVault.setRelayer(relayer, true) โดยเจ้าของ'];
            $checks[] = ['key' => 'vault_not_paused', 'ok' => ($onchain['paused'] ?? null) === false,
                'hint' => ($onchain['paused'] ?? null) === true ? 'คลังถูก pause — เจ้าของต้องรัน unpause-bridge-vault.js' : 'อ่านสถานะจากเชน TPIX ไม่ได้'];
        }

        return [
            'ready' => ! in_array(false, array_column($checks, 'ok'), true),
            'checks' => $checks,
            'onchain' => $onchain,
        ];
    }

    /** สถานะบนสัญญาปลายทาง — ค่า null = อ่านไม่ได้ */
    public function onchain(string $direction, EvmRpc $rpc, ?string $relayer): array
    {
        if ($direction === WtpixBridgeTransfer::TO_BSC) {
            $minter = (string) $this->config->minterAddress();
            $wtpix = (string) $this->config->wtpixAddress();
            $token = Abi::decAddress($rpc->call($minter, Abi::selector('token()')));

            return [
                'relayer_role' => $relayer ? $rpc->callBool($minter, 'relayers(address)', Abi::encAddress($relayer)) : null,
                'paused' => $rpc->callBool($minter, 'paused()'),
                'token_paused' => $rpc->callBool($wtpix, 'paused()'),
                'minter_is_minter' => $rpc->callBool($wtpix, 'minters(address)', Abi::encAddress($minter)),
                'minter_token' => $token !== null ? strtolower($token) : null,
                'max_per_tx' => $rpc->callUint($minter, 'maxPerTx()'),
                'daily_cap' => $rpc->callUint($minter, 'dailyCap()'),
                'remaining_today' => $rpc->callUint($minter, 'remainingToday()'),
            ];
        }

        $vault = (string) $this->config->vaultAddress();

        return [
            'relayer_role' => $relayer ? $rpc->callBool($vault, 'relayers(address)', Abi::encAddress($relayer)) : null,
            'paused' => $rpc->callBool($vault, 'paused()'),
            'max_per_tx' => $rpc->callUint($vault, 'maxPerTx()'),
            'daily_cap' => $rpc->callUint($vault, 'dailyCap()'),
            'remaining_today' => $rpc->callUint($vault, 'remainingToday()'),
        ];
    }

    /** depositId นี้ถูกทำบนสัญญาปลายทางแล้วหรือยัง — null = อ่านไม่ได้ (ทั้ง minter และ vault ใช้ processed(bytes32)) */
    public function processedOnChain(EvmRpc $rpc, ?string $contract, string $depositId): ?bool
    {
        return $contract ? $rpc->callBool($contract, 'processed(bytes32)', Abi::encBytes32($depositId)) : null;
    }

    // =========================================================================
    // หนึ่งรอบ
    // =========================================================================

    /**
     * @return array{direction: string, tracked: int, completed: int, signed: int, blocked: ?string}
     */
    public function run(string $direction, int $limit = 5): array
    {
        $rpc = $this->destRpc($direction);
        $summary = ['direction' => $direction, 'tracked' => 0, 'completed' => 0, 'signed' => 0, 'blocked' => null];

        // ระยะที่ 1: ตามผลก่อนเสมอ (ทำแม้สวิตช์ปิด — ของที่ส่งไปแล้วต้องรู้ผล)
        $summary['tracked'] = $this->trackSending($direction, $rpc);

        if (WtpixBridgeTransfer::where('direction', $direction)->where('status', WtpixBridgeTransfer::STATUS_SENDING)->exists()) {
            $summary['blocked'] = 'awaiting_previous';

            return $summary;
        }

        $this->flagStale($direction);

        // ไม่มีงาน = ไม่ถามเชน (rpc.tpix.online อยู่หลัง Cloudflare ที่ตอบ 429 เมื่อยิงถี่)
        if (! $this->queue($direction)->exists()) {
            return $summary;
        }

        $ready = $this->readiness($direction, $rpc);
        if (! $ready['ready']) {
            $summary['blocked'] = 'not_ready:'.implode(',', array_column(array_filter($ready['checks'], fn ($c) => ! $c['ok']), 'key'));

            return $summary;
        }

        if (! $this->signer->isAvailable()) {
            $summary['blocked'] = 'not_cli';

            return $summary;
        }

        $remaining = $ready['onchain']['remaining_today'] ?? null;
        $maxPerTx = $ready['onchain']['max_per_tx'] ?? null;
        if ($remaining === null || $maxPerTx === null) {
            $summary['blocked'] = 'dest_unreadable';

            return $summary;
        }

        for ($i = 0; $i < max(1, $limit); $i++) {
            $candidate = $this->queue($direction)->orderBy('id')->first();
            if (! $candidate) {
                break;
            }

            $outcome = $this->processOne($direction, $rpc, $candidate, $remaining, $maxPerTx);

            if ($outcome === 'completed') {
                $summary['completed']++;
                $summary['signed']++;
                $remaining = bccomp($remaining, (string) $candidate->payout_wei, 0) > 0 ? bcsub($remaining, (string) $candidate->payout_wei, 0) : '0';

                continue;
            }

            if ($outcome === 'review') {
                continue;
            }

            if ($outcome === 'sent') {
                $summary['signed']++;
            }
            $summary['blocked'] = $outcome;
            break;
        }

        return $summary;
    }

    /** คิวของทิศนี้ — เฉพาะรายการจากสัญญาต้นทางที่ตั้งไว้ "ตอนนี้" */
    private function queue(string $direction)
    {
        $route = $this->config->route($direction);

        return WtpixBridgeTransfer::query()
            ->where('direction', $direction)
            ->whereIn('status', WtpixBridgeTransfer::QUEUED)
            ->whereNull('dest_tx_hash')
            ->where('source_chain_id', $route['source_chain'])
            ->where('source_contract', (string) $route['source_contract']);
    }

    /**
     * รายการที่มาจากสัญญา/เชนที่ไม่ได้ตั้งไว้แล้ว (เช่นเปลี่ยนจาก BSC testnet ไป mainnet ระหว่างที่ยังมีคิว)
     * — ห้ามทำเอง: wTPIX ปลอมของ testnet จะกลายเป็นใบเบิก TPIX จริง → ส่งให้คนดู.
     */
    private function flagStale(string $direction): void
    {
        $route = $this->config->route($direction);
        if ($route['source_contract'] === null) {
            return;
        }

        WtpixBridgeTransfer::query()
            ->where('direction', $direction)
            ->whereIn('status', WtpixBridgeTransfer::QUEUED)
            ->whereNull('dest_tx_hash')
            ->where(fn ($q) => $q->where('source_chain_id', '!=', $route['source_chain'])->orWhere('source_contract', '!=', $route['source_contract']))
            ->update(['status' => WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, 'review_reason' => 'config_changed', 'updated_at' => now()]);
    }

    /**
     * ทำหนึ่งรายการ — คืน completed | sent | review | daily_cap | no_liquidity | gas_price_too_high
     *                    | sign_failed | elsewhere | rpc_unavailable | busy.
     */
    private function processOne(string $direction, EvmRpc $rpc, WtpixBridgeTransfer $candidate, string $remaining, string $maxPerTx): string
    {
        // ล็อกแถว + เช็คซ้ำ — กันสองโปรเซส (หรือแอดมินกดพร้อมกัน) หยิบรายการเดียวกัน
        $row = DB::transaction(function () use ($candidate) {
            $row = WtpixBridgeTransfer::whereKey($candidate->id)->lockForUpdate()->first();

            return $row && in_array($row->status, WtpixBridgeTransfer::QUEUED, true) && $row->dest_tx_hash === null ? $row : null;
        });

        if (! $row) {
            return 'busy';
        }

        $toBsc = $direction === WtpixBridgeTransfer::TO_BSC;
        $route = $this->config->route($direction);
        $contract = (string) $route['dest_contract'];
        $payout = (string) $row->payout_wei;

        $processed = $this->processedOnChain($rpc, $contract, $row->deposit_id);
        if ($processed === null) {
            return 'rpc_unavailable';
        }
        if ($processed) {
            // ระบบไม่เคยส่งธุรกรรมของรายการนี้ แต่สัญญาบอกว่าทำแล้ว — ไม่รู้ว่าเงินไปถึงใคร
            $this->holdElsewhere($row, $contract, 'ก่อนเซ็น');

            return 'elsewhere';
        }

        if (bccomp($payout, $maxPerTx, 0) > 0) {
            $row->update(['status' => WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, 'review_reason' => 'exceeds_onchain_max',
                'last_error' => 'เกิน maxPerTx บนสัญญา ('.Wei::format($maxPerTx, 18).') — เจ้าของต้อง setMaxPerTx ก่อน (depositId ใช้ได้ครั้งเดียว แบ่งจ่ายไม่ได้)']);

            return 'review';
        }

        if (bccomp($payout, $remaining, 0) > 0) {
            // ไม่ใช่ความผิดของผู้ใช้ — รอวันใหม่ตามนาฬิกาของสัญญา (UTC) ไม่เปลี่ยนสถานะ ไม่ข้ามคิว
            $row->update(['last_error' => 'รอโควตา dailyCap ของวันถัดไป (UTC) — เหลือวันนี้ '.Wei::format($remaining, 18)]);

            return 'daily_cap';
        }

        // เงิน/แก๊สต้องพอทั้งก้อน — ไม่ทำบางส่วน
        if ($toBsc) {
            $relayer = (string) $this->config->signerAddress('relayer');
            $gas = $rpc->nativeBalance($relayer);
            if ($gas === null) {
                return 'rpc_unavailable';
            }
            if (bccomp($gas, $this->config->minGasBalanceWei(), 0) < 0) {
                $row->update(['status' => WtpixBridgeTransfer::STATUS_AWAITING_LIQUIDITY,
                    'last_error' => 'BNB ค่าแก๊สในกระเป๋า relayer ไม่พอ (มี '.Wei::format($gas, 18).' BNB)']);

                return 'no_liquidity';
            }
        } else {
            $vaultBalance = $rpc->nativeBalance($contract);
            if ($vaultBalance === null) {
                return 'rpc_unavailable';
            }
            if (bccomp($vaultBalance, $payout, 0) < 0) {
                $row->update(['status' => WtpixBridgeTransfer::STATUS_AWAITING_LIQUIDITY,
                    'last_error' => 'TPIX ในคลังไม่พอปล่อย (มี '.Wei::format($vaultBalance, 18).') — ตรวจกระทบยอดด่วน']);

                return 'no_liquidity';
            }
        }

        $calldata = $toBsc
            ? WtpixAbi::mintCalldata($row->recipient, $payout, $row->deposit_id)
            : WtpixAbi::releaseCalldata($row->recipient, $payout, $row->deposit_id);
        $bsc = $this->config->bsc();
        $maxGwei = (string) ($bsc['max_gas_price_gwei'] ?? '');
        $options = $toBsc
            ? ['gas_multiplier_bps' => (int) ($bsc['gas_price_multiplier_bps'] ?? 12000), 'max_gas_price_wei' => $maxGwei !== '' ? Wei::toWei($maxGwei, 9) : null]
            // เชน TPIX: legacy (type 0) ราคาแก๊ส 0 · chainId 4289 มาจาก EvmRpc ของเชนนี้
            : ['gas_price_wei' => '0'];

        $signed = $this->signer->signWithKey($this->config->signerKey('relayer'), $rpc, $contract, $calldata, $options);

        if (! ($signed['ok'] ?? false)) {
            return $this->signFailed($row, $toBsc, (string) ($signed['code'] ?? 'error'), (string) ($signed['error'] ?? ''));
        }

        // ⚠️ บันทึก hash + raw ก่อนส่ง — ห้ามสลับลำดับเด็ดขาด
        // compare-and-set: แอดมินปฏิเสธระหว่างเซ็น = ทิ้งธุรกรรมที่เซ็นไว้ (ยังไม่ส่ง nonce ไม่ถูกใช้ ไม่มีอะไรเสีย)
        $claimed = WtpixBridgeTransfer::whereKey($row->id)
            ->whereIn('status', WtpixBridgeTransfer::QUEUED)
            ->whereNull('dest_tx_hash')
            ->update([
                'status' => WtpixBridgeTransfer::STATUS_SENDING,
                'dest_chain_id' => $rpc->chainId,
                'dest_contract' => $contract,
                'dest_tx_hash' => strtolower((string) $signed['txHash']),
                'dest_raw_tx' => (string) $signed['raw'],
                'dest_nonce' => (int) ($signed['nonce'] ?? 0),
                'dest_from' => strtolower((string) ($signed['address'] ?? $this->config->signerAddress('relayer'))),
                'attempts' => $row->attempts + 1,
                'broadcast_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            Log::warning('wtpix-bridge: รายการเปลี่ยนสถานะระหว่างเซ็น — ทิ้งธุรกรรมที่เซ็นไว้ ไม่ส่ง', ['transfer' => $row->id]);

            return 'busy';
        }

        $sent = $this->signer->send($rpc, (string) $signed['raw']);
        if (! ($sent['ok'] ?? false)) {
            // ส่งไม่ผ่าน (RPC ล่ม) — ไม่เซ็นใหม่ ให้รอบตามผลไปตรวจ/ส่ง raw เดิมซ้ำ
            WtpixBridgeTransfer::whereKey($row->id)->update(['last_error' => mb_substr('broadcast ไม่ผ่าน: '.($sent['error'] ?? ''), 0, 300)]);
        }

        Log::info('wtpix-bridge: ส่งธุรกรรมปลายทางแล้ว', [
            'transfer' => $row->id, 'direction' => $direction, 'to' => $row->recipient,
            'wei' => $payout, 'tx' => $signed['txHash'], 'nonce' => $signed['nonce'] ?? null,
        ]);

        return $this->waitForReceipt($rpc, $row->fresh()) ? 'completed' : 'sent';
    }

    private function signFailed(WtpixBridgeTransfer $row, bool $toBsc, string $code, string $error): string
    {
        if ($code === 'insufficient_gas') {
            $row->update(['status' => WtpixBridgeTransfer::STATUS_AWAITING_LIQUIDITY,
                'last_error' => mb_substr('ค่าแก๊สในกระเป๋า relayer ไม่พอ: '.$error, 0, 300)]);

            return 'no_liquidity';
        }

        if ($code === 'gas_price_too_high') {
            $row->update(['last_error' => mb_substr('ค่าแก๊ส BSC แพงเกินเพดาน รอรอบหน้า: '.$error, 0, 300)]);

            return 'gas_price_too_high';
        }

        $attempts = $row->attempts + 1;
        $toReview = $code === 'estimate_failed' && $attempts >= self::MAX_SIGN_ATTEMPTS;

        $row->update([
            'attempts' => $attempts,
            'last_error' => mb_substr("เซ็นไม่ผ่าน ({$code}): {$error}", 0, 300),
            'status' => $toReview ? WtpixBridgeTransfer::STATUS_NEEDS_REVIEW : $row->status,
            'review_reason' => $toReview ? ($toBsc ? 'mint_estimate_failed' : 'release_estimate_failed') : $row->review_reason,
        ]);

        return $toReview ? 'review' : 'sign_failed';
    }

    /** รอใบเสร็จสั้น ๆ ในรอบเดียว — ได้ = ทำรายการถัดไปต่อได้เลย */
    private function waitForReceipt(EvmRpc $rpc, WtpixBridgeTransfer $row): bool
    {
        $deadline = microtime(true) + max(0, (int) config('wtpix_bridge.receipt_wait_seconds', 30));

        do {
            $this->trackOne($rpc, $row);
            $row->refresh();

            if ($row->status !== WtpixBridgeTransfer::STATUS_SENDING) {
                return $row->status === WtpixBridgeTransfer::STATUS_COMPLETED;
            }

            if (microtime(true) >= $deadline) {
                return false;
            }

            usleep(2_000_000);
        } while (true);
    }

    /** ตามผลทุกรายการ sending ของทิศนี้ — คืนจำนวนที่ได้ข้อสรุป */
    public function trackSending(string $direction, ?EvmRpc $rpc = null): int
    {
        $rpc ??= $this->destRpc($direction);
        $settled = 0;

        $rows = WtpixBridgeTransfer::where('direction', $direction)->where('status', WtpixBridgeTransfer::STATUS_SENDING)->orderBy('dest_nonce')->get();
        foreach ($rows as $row) {
            if ($this->trackOne($rpc, $row)) {
                $settled++;
            }
        }

        if (! WtpixBridgeTransfer::where('direction', $direction)->where('status', WtpixBridgeTransfer::STATUS_SENDING)->exists()) {
            SystemAlert::resolveKeys(WtpixBridgeGuard::ALERT_NODE, ["wtpix_bridge_tx_stuck_{$direction}"]);
        }

        return $settled;
    }

    /**
     * ธุรกรรมค้างใน mempool — ทิศนี้หยุดเซ็นรอ (nonce ทับกันไม่ได้) จึงต้องมีคนรู้ ไม่ใช่รอเงียบ ๆ.
     *
     * ยังเป็น sending ต่อไป เพราะลงบล็อกได้ทุกเมื่อ · เจ้าของแทนที่ nonce เดิมแล้ว รอบถัดไปจะเห็น nonce_used เอง
     */
    private function flagStuck(WtpixBridgeTransfer $row, string $hash): void
    {
        $age = $row->broadcast_at ? now()->diffInSeconds($row->broadcast_at, true) : 0;
        if ($age < $this->config->stuckAfterSeconds()) {
            return;
        }

        $minutes = (int) floor($age / 60);
        SystemAlert::raise(
            WtpixBridgeGuard::ALERT_NODE,
            "wtpix_bridge_tx_stuck_{$row->direction}",
            'critical',
            "สะพาน wTPIX: ธุรกรรมของรายการ #{$row->id} ค้าง {$minutes} นาที (nonce {$row->dest_nonce}) — ทิศนี้หยุดรอ ต้องรอให้ลงบล็อกหรือส่งธุรกรรมแทนที่ด้วย nonce เดิมจากกระเป๋า relayer",
            ['transfer' => $row->id, 'tx' => $hash, 'nonce' => $row->dest_nonce],
        );
    }

    /**
     * หา event ของ depositId บนสัญญาปลายทาง — หลักฐานว่ารายการนี้ไปถึงใคร เท่าไร.
     *
     * ไล่ไปข้างหน้าจากเวลาที่ธุรกรรมเกิดบนเชนต้นทาง (ก่อนหน้านั้น depositId ยังไม่มี) เจอแล้วหยุดทันที
     * ไล่ไม่เกิน verify_max_blocks — ไม่เจอในช่วงนั้นแต่เชนยังเดินต่อไปอีก = too_far ขยายค่านี้แล้วลองใหม่ได้
     *
     * @return array{state: 'found', to: string, wei: string, tx_hash: string, block: int}|array{state: 'not_found'|'unreadable'|'too_far'}
     */
    public function findSettlement(EvmRpc $rpc, WtpixBridgeTransfer $row, string $contract): array
    {
        $toBsc = $row->direction === WtpixBridgeTransfer::TO_BSC;
        $depositId = strtolower((string) $row->deposit_id);
        $created = $row->created_at?->getTimestamp() ?? now()->getTimestamp();
        $sourceTs = $this->destRpc($toBsc ? WtpixBridgeTransfer::TO_TPIX : WtpixBridgeTransfer::TO_BSC)
            ->blockTimestamp((int) $row->block_number);
        $since = $sourceTs !== null
            ? min($sourceTs, $created) - self::SETTLEMENT_SEARCH_MARGIN_SECONDS
            : $created - self::SETTLEMENT_SEARCH_FALLBACK_SECONDS;

        $latest = $rpc->blockNumber();
        $start = $rpc->blockAtOrBefore($since, $toBsc ? 0.45 : 2.0);

        if ($latest === null || $start === null) {
            return ['state' => 'unreadable'];
        }

        $chain = $toBsc ? 'bsc' : 'tpix';
        $end = min($latest, $start + max(1000, (int) config("wtpix_bridge.{$chain}.verify_max_blocks", 200000)));
        $topic = Abi::topic($toBsc ? WtpixAbi::BRIDGE_MINTED_EVENT : WtpixAbi::RELEASED_EVENT);
        $chunk = (int) config("wtpix_bridge.{$chain}.log_chunk", 900);
        $matches = fn (array $log) => strtolower((string) ($log['address'] ?? '')) === strtolower($contract)
            && ($log['removed'] ?? false) !== true
            && strtolower((string) ($log['topics'][0] ?? '')) === $topic
            && strtolower((string) ($log['topics'][3] ?? '')) === $depositId;

        $logs = $rpc->logsBetween($contract, [$topic, null, null, $depositId], $start, $end, $chunk, $matches);

        if ($logs === null) {
            return ['state' => 'unreadable'];
        }

        foreach ($logs as $log) {
            if ($matches($log)) {
                $to = Abi::topicAddress($log['topics'][2] ?? null);

                return $to === null ? ['state' => 'unreadable'] : [
                    'state' => 'found',
                    'to' => $to,
                    'wei' => Wei::hexToInt('0x'.substr((string) ($log['data'] ?? '0x'), 2, 64)),
                    'tx_hash' => strtolower((string) ($log['transactionHash'] ?? '')),
                    'block' => (int) Wei::hexToInt((string) ($log['blockNumber'] ?? '0x0')),
                ];
            }
        }

        return ['state' => $end < $latest ? 'too_far' : 'not_found'];
    }

    /** event ต้องไปถึงผู้รับของรายการนี้ ตามจำนวนจริง */
    public function settlementMatches(WtpixBridgeTransfer $row, array $settlement): bool
    {
        return ($settlement['state'] ?? null) === 'found'
            && strtolower((string) $settlement['to']) === strtolower((string) $row->recipient)
            && bccomp((string) $settlement['wei'], (string) $row->payout_wei, 0) === 0;
    }

    /** ตามผลรายการเดียว — true = ได้ข้อสรุปแล้ว (completed หรือ needs_review) */
    private function trackOne(EvmRpc $rpc, WtpixBridgeTransfer $row): bool
    {
        $hash = (string) $row->dest_tx_hash;
        if ($hash === '') {
            return false;
        }

        $receipt = $rpc->receipt($hash);
        if ($receipt !== null) {
            return $this->settleReceipt($rpc, $row, $receipt, $hash);
        }

        $known = $rpc->knowsTransaction($hash);
        if ($known === null) {
            return false; // อ่านไม่ได้ = รอ
        }

        if (! $known) {
            // เชนไม่รู้จัก — ส่งไปนานพอแล้วยิง raw เดิมซ้ำ (nonce เดิม รับได้ครั้งเดียว)
            // ระยะห่างนับจากการเปลี่ยนแปลงล่าสุด · broadcast_at = ครั้งแรกที่ส่ง ใช้วัด "ค้าง"
            $sinceTouch = $row->updated_at ? now()->diffInSeconds($row->updated_at, true) : PHP_INT_MAX;

            if ($sinceTouch >= self::REBROADCAST_AFTER_SECONDS && $row->dest_raw_tx && $this->signer->isAvailable()) {
                $sent = $this->signer->send($rpc, (string) $row->dest_raw_tx);

                if (($sent['code'] ?? null) === 'nonce_used') {
                    // nonce ถูกใช้แล้ว — อาจเป็นธุรกรรมของเราเองที่เพิ่งลงบล็อก (RPC ตัวที่ถามใบเสร็จช้ากว่า)
                    // ถ้าตัดสินจาก RPC ตัวเดียว ธุรกรรมของเราเองจะกลายเป็น "ทำโดยคนอื่น" แล้วสะพานหยุดทั้งเส้น
                    $again = $rpc->receiptAny($hash);
                    if ($again['receipt'] !== null) {
                        return $this->settleReceipt($rpc, $row, $again['receipt'], $hash);
                    }

                    // ยังยืนยันไม่ได้ว่าไม่ใช่ของเรา — รอรอบหน้า
                    if ($again['errors'] === 0 && $rpc->transactionAny($hash) === null) {
                        return $this->settleFailed($rpc, $row, 'nonce_consumed', "nonce {$row->dest_nonce} ถูกใช้โดยธุรกรรมอื่น");
                    }
                } else {
                    WtpixBridgeTransfer::whereKey($row->id)->update([
                        'last_error' => ($sent['ok'] ?? false) ? 'ส่ง raw เดิมซ้ำแล้ว' : mb_substr('ส่งซ้ำไม่ผ่าน: '.($sent['error'] ?? ''), 0, 300),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        $this->flagStuck($row, $hash); // ยังลงบล็อกได้ทุกเมื่อ แต่ทิศนี้หยุดรอ ต้องมีคนรู้ถ้ารอนานเกิน

        return false;
    }

    private function settleReceipt(EvmRpc $rpc, WtpixBridgeTransfer $row, array $receipt, string $hash): bool
    {
        $block = isset($receipt['blockNumber']) ? (int) Wei::hexToInt((string) $receipt['blockNumber']) : null;

        if (strtolower((string) ($receipt['status'] ?? '')) === '0x1') {
            $row->update([
                'status' => WtpixBridgeTransfer::STATUS_COMPLETED,
                'dest_block_number' => $block,
                'completed_at' => now(),
                'last_error' => null,
            ]);
            Log::info('wtpix-bridge: สำเร็จบนเชนปลายทาง', ['transfer' => $row->id, 'tx' => $hash, 'block' => $block]);

            return true;
        }

        $reason = $row->direction === WtpixBridgeTransfer::TO_BSC ? 'mint_reverted' : 'release_reverted';

        return $this->settleFailed($rpc, $row, $reason, "ธุรกรรม {$hash} revert บนเชน");
    }

    /** ธุรกรรมของเราไม่สำเร็จ — สัญญาบอกว่าทำแล้ว (ด้วยธุรกรรมอื่น) = ห้ามนับว่าถึงผู้ใช้ ให้คนดูเสมอ */
    private function settleFailed(EvmRpc $rpc, WtpixBridgeTransfer $row, string $reason, string $note): bool
    {
        $processed = $this->processedOnChain($rpc, $row->dest_contract, $row->deposit_id);

        if ($processed === null) {
            return false;
        }

        if ($processed) {
            $this->holdElsewhere($row, (string) $row->dest_contract, $note);

            return true;
        }

        $row->update([
            'status' => WtpixBridgeTransfer::STATUS_NEEDS_REVIEW,
            'review_reason' => $reason,
            'last_error' => mb_substr($note, 0, 300),
        ]);
        Log::warning('wtpix-bridge: ธุรกรรมปลายทางไม่สำเร็จ — ส่งให้แอดมินดู', ['transfer' => $row->id, 'reason' => $reason]);

        return true;
    }

    /**
     * depositId ถูกทำบนสัญญาปลายทางแล้ว แต่ไม่ใช่ด้วยธุรกรรมที่ระบบส่ง — ผู้รับ/จำนวนอาจไม่ใช่ของผู้ใช้.
     *
     * depositId คำนวณได้จาก log สาธารณะ คีย์ relayer ที่หลุดจึงทำรายการตัดหน้าเข้ากระเป๋าตัวเองได้
     * ถ้านับว่าเสร็จ ตัวกระทบยอดจะไม่มีวันเห็น → ไม่นับ หยุดสะพานทันที (guardian pause ทั้งสองฝั่ง)
     * แอดมินต้องตรวจ event บน explorer ก่อนอนุมัติ
     */
    private function holdElsewhere(WtpixBridgeTransfer $row, string $contract, string $context): void
    {
        $toBsc = $row->direction === WtpixBridgeTransfer::TO_BSC;
        $row->update([
            'status' => WtpixBridgeTransfer::STATUS_NEEDS_REVIEW,
            'review_reason' => $toBsc ? 'minted_elsewhere' : 'released_elsewhere',
            'dest_contract' => $contract,
            'last_error' => mb_substr("{$context}: depositId ถูกทำบนสัญญาแล้วโดยธุรกรรมที่ระบบไม่ได้ส่ง — ตรวจ "
                .($toBsc ? 'BridgeMinted' : 'Released').'(to, amount) บน explorer ก่อนอนุมัติ', 0, 300),
        ]);

        $this->guard->halt(
            $toBsc ? 'minted_elsewhere' : 'released_elsewhere',
            "รายการ #{$row->id} ถูก".($toBsc ? ' mint ' : 'ปล่อย').'โดยธุรกรรมที่ระบบไม่ได้ส่ง — คีย์ relayer อาจหลุด',
            ['transfer' => $row->id, 'deposit_id' => $row->deposit_id, 'payout_wei' => (string) $row->payout_wei]
        );
    }
}
