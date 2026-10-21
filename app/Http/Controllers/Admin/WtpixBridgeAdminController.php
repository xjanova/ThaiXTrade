<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Models\WtpixBridgeSnapshot;
use App\Models\WtpixBridgeTransfer;
use App\Services\Bridge\Abi;
use App\Services\Bridge\BridgeConfig;
use App\Services\Bridge\Wtpix\WtpixBridgeConfig;
use App\Services\Bridge\Wtpix\WtpixBridgePolicy;
use App\Services\Bridge\Wtpix\WtpixBridgeReconciler;
use App\Services\Bridge\Wtpix\WtpixBridgeRelayer;
use App\Support\Wei;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * หลังบ้านสะพาน TPIX ⇄ wTPIX — ด่านความพร้อม · บทบาทบนสัญญา · แก๊ส BNB · คิว · อนุมัติ/ปฏิเสธ · เพดาน · สวิตช์.
 *
 * หน้าเว็บไม่เคยเซ็นธุรกรรมเอง (php-fpm ปิด proc_open) — อนุมัติแล้วแค่ส่งกลับเข้าคิว
 * ให้ bridge:wtpix-relay ฝั่ง CLI เซ็น ซึ่งตรวจบนเชนซ้ำอีกรอบก่อนส่งจริง
 *
 * ทุกปุ่มที่เปลี่ยนสถานะล็อกแถว (lockForUpdate) แล้วเช็คสถานะก่อนทำ — กดเบิ้ล/สองคนกดพร้อมกัน
 * คนที่สองได้ 409 ไม่ใช่ทำซ้ำ
 *
 * Developed by Xman Studio.
 */
class WtpixBridgeAdminController extends Controller
{
    private const CACHE_STATE = 'wtpix-bridge:admin:state';

    public function __construct(
        private WtpixBridgeConfig $config,
        private WtpixBridgeRelayer $relayer,
        private WtpixBridgeReconciler $reconciler,
        private BridgeConfig $usdtConfig,
        private WtpixBridgePolicy $policy,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $tab = in_array($request->query('tab'), ['overview', 'transfers', 'settings'], true) ? $request->query('tab') : 'overview';
        $status = is_string($request->query('status')) ? $request->query('status') : null;
        $direction = in_array($request->query('direction'), WtpixBridgeTransfer::DIRECTIONS, true) ? $request->query('direction') : null;

        $transfers = WtpixBridgeTransfer::query()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($direction, fn ($q) => $q->where('direction', $direction))
            ->latest('id')->paginate(25, ['*'], 'page')->withQueryString()
            ->through(fn (WtpixBridgeTransfer $t) => $this->row($t));

        $snapshot = WtpixBridgeSnapshot::latest('id')->first();

        return Inertia::render('Admin/Bridge/Wtpix', [
            'tab' => $tab,
            'filters' => ['status' => $status, 'direction' => $direction],
            'state' => $this->state(),
            'snapshot' => $snapshot ? $snapshot->toPublicArray() + [
                'unexplained_mint' => Wei::format((string) $snapshot->unexplained_mint_wei, 18),
                'unexplained_release' => Wei::format((string) $snapshot->unexplained_release_wei, 18),
                'foreign_supply' => Wei::format((string) $snapshot->foreign_supply_wei, 18),
                'action' => $snapshot->action,
                'note' => $snapshot->note,
            ] : null,
            'transfers' => $transfers,
            'counts' => WtpixBridgeTransfer::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'settings' => $this->settingsPayload(),
            'explorers' => [
                $this->config->tpixChainId() => $this->config->explorer($this->config->tpixChainId()),
                $this->config->bscChainId() => $this->config->explorer($this->config->bscChainId()),
            ],
            'chains' => [
                'tpix' => ['chain_id' => $this->config->tpixChainId(), 'name' => 'TPIX Chain'],
                'bsc' => ['chain_id' => $this->config->bscChainId(), 'name' => $this->config->bscName()],
            ],
        ]);
    }

    /**
     * สถานะรวม + ด่านความพร้อมทั้งสองทิศ + บทบาท/แก๊ส/เพดานบนสัญญา (ยิง RPC สองเชน แคช 20 วิ).
     */
    private function state(): array
    {
        return Cache::remember(self::CACHE_STATE, 20, function () {
            $vault = $this->config->vaultAddress();
            $wtpix = $this->config->wtpixAddress();
            $minter = $this->config->minterAddress();
            $relayer = $this->config->signerAddress('relayer');
            $guardian = $this->config->signerAddress('guardian');
            $tpix = $this->config->tpixRpc();
            $bsc = $this->config->bscRpc();
            $fmt = fn (?string $v) => $v === null ? null : Wei::format($v, 18);
            $role = fn ($rpc, ?string $contract, string $fn, ?string $who) => $contract && $who ? $rpc->callBool($contract, "{$fn}(address)", Abi::encAddress($who)) : null;

            $mint = $this->relayer->readiness(WtpixBridgeTransfer::TO_BSC, $bsc);
            $release = $this->relayer->readiness(WtpixBridgeTransfer::TO_TPIX, $tpix);

            $chainHex = $bsc->result('eth_chainId');
            $bscChainOk = is_string($chainHex) && str_starts_with($chainHex, '0x') && (int) Wei::hexToInt($chainHex) === $this->config->bscChainId();

            $vaultState = $vault ? [
                'code' => ($tpix->code($vault) ?? '0x') !== '0x',
                'paused' => $tpix->callBool($vault, 'paused()'),
                'balance' => $fmt($tpix->nativeBalance($vault)),
                'min_lock' => $fmt($tpix->callUint($vault, 'minLock()')),
                'max_lock' => $fmt($tpix->callUint($vault, 'maxLock()')),
                'max_per_tx' => $fmt($tpix->callUint($vault, 'maxPerTx()')),
                'daily_cap' => $fmt($tpix->callUint($vault, 'dailyCap()')),
                'remaining_today' => $fmt($tpix->callUint($vault, 'remainingToday()')),
                'total_locked' => $fmt($tpix->callUint($vault, 'totalLocked()')),
                'total_released' => $fmt($tpix->callUint($vault, 'totalReleased()')),
                'max_lock_wei' => $tpix->callUint($vault, 'maxLock()'),
            ] : null;

            $minterState = $minter ? [
                'code' => ($bsc->code($minter) ?? '0x') !== '0x',
                'paused' => $bsc->callBool($minter, 'paused()'),
                'max_per_tx' => $fmt($bsc->callUint($minter, 'maxPerTx()')),
                'daily_cap' => $fmt($bsc->callUint($minter, 'dailyCap()')),
                'remaining_today' => $fmt($bsc->callUint($minter, 'remainingToday()')),
                'total_minted' => $fmt($bsc->callUint($minter, 'totalMinted()')),
                'max_per_tx_wei' => $bsc->callUint($minter, 'maxPerTx()'),
                'total_minted_wei' => $bsc->callUint($minter, 'totalMinted()'),
            ] : null;

            $tokenState = $wtpix ? [
                'code' => ($bsc->code($wtpix) ?? '0x') !== '0x',
                'paused' => $bsc->callBool($wtpix, 'paused()'),
                'total_supply' => $fmt($bsc->callUint($wtpix, 'totalSupply()')),
                'total_supply_wei' => $bsc->callUint($wtpix, 'totalSupply()'),
                'initial_minter_set' => $bsc->callBool($wtpix, 'initialMinterSet()'),
                'minter_is_minter' => $role($bsc, $wtpix, 'minters', $minter),
            ] : null;

            $relayerBnb = $relayer ? $bsc->nativeBalance($relayer) : null;
            $guardianBnb = $guardian ? $bsc->nativeBalance($guardian) : null;
            $minGas = $this->config->minGasBalanceWei();
            $enough = fn (?string $bal) => $bal !== null && bccomp($bal, $minGas, 0) >= 0;

            $capsAligned = ($vaultState['max_lock_wei'] ?? null) !== null && ($minterState['max_per_tx_wei'] ?? null) !== null
                && bccomp($vaultState['max_lock_wei'], $minterState['max_per_tx_wei'], 0) <= 0;
            $noForeign = ($tokenState['total_supply_wei'] ?? null) !== null && ($minterState['total_minted_wei'] ?? null) !== null
                && bccomp($tokenState['total_supply_wei'], $minterState['total_minted_wei'], 0) <= 0;

            // รายการตรวจสำหรับคน — optional = ไม่บล็อกการทำงาน แต่ควรตั้งก่อนเปิดจริง
            $checks = [
                ['key' => 'installed', 'ok' => $this->config->installed(), 'hint' => 'WTPIX_BRIDGE_ENABLED=true ใน .env'],
                ['key' => 'site_switch', 'ok' => $this->config->siteSwitchOn(), 'hint' => 'เปิดสวิตช์ด้านซ้าย'],
                ['key' => 'vault_address', 'ok' => $vault !== null && ! $this->config->vaultRegistryMismatch(),
                    'hint' => $this->config->vaultRegistryMismatch() ? 'ทะเบียนสัญญา ('.$this->config->vaultRegistryAddress().') ไม่ตรงกับ WTPIX_BRIDGE_VAULT_ADDRESS' : ($vault ?? 'ตั้ง WTPIX_BRIDGE_VAULT_ADDRESS')],
                ['key' => 'vault_code', 'ok' => (bool) ($vaultState['code'] ?? false), 'hint' => 'ไม่พบสัญญาที่ที่อยู่นี้บนเชน 4289'],
                ['key' => 'wtpix_address', 'ok' => (bool) ($tokenState['code'] ?? false), 'hint' => $wtpix ?? 'ตั้ง WTPIX_BSC_ADDRESS'],
                ['key' => 'minter_address', 'ok' => (bool) ($minterState['code'] ?? false), 'hint' => $minter ?? 'ตั้ง WTPIX_BSC_MINTER_ADDRESS'],
                ['key' => 'bsc_chain', 'ok' => $bscChainOk, 'hint' => 'RPC ของ BSC ต้องตอบ chainId '.$this->config->bscChainId()],
                ['key' => 'minter_token', 'ok' => ($mint['onchain']['minter_token'] ?? null) === $wtpix && $wtpix !== null, 'hint' => 'WTPIXBridgeMinter.token() ต้องเป็น wTPIX ตัวนี้'],
                ['key' => 'initial_minter_set', 'ok' => ($tokenState['initial_minter_set'] ?? null) === true, 'hint' => 'wTPIX.setInitialMinter(minter) ยังไม่ถูกเรียก'],
                ['key' => 'minter_is_minter', 'ok' => ($tokenState['minter_is_minter'] ?? null) === true, 'hint' => 'wTPIX.minters(WTPIXBridgeMinter) ต้องเป็น true'],
                ['key' => 'relayer_keystore', 'ok' => $relayer !== null && $this->config->hasPassphrase('relayer'), 'hint' => 'WTPIX_BRIDGE_RELAYER_KEYSTORE + WTPIX_BRIDGE_RELAYER_PASS'],
                ['key' => 'minter_relayer_role', 'ok' => $role($bsc, $minter, 'relayers', $relayer) === true, 'hint' => 'WTPIXBridgeMinter.setRelayer(relayer, true)'],
                ['key' => 'vault_relayer_role', 'ok' => $role($tpix, $vault, 'relayers', $relayer) === true, 'hint' => 'TPIXBridgeVault.setRelayer(relayer, true)'],
                ['key' => 'relayer_bnb', 'ok' => $enough($relayerBnb), 'hint' => 'เติม BNB ให้ relayer (ขั้นต่ำ '.Wei::format($minGas, 18).')'],
                ['key' => 'vault_not_paused', 'ok' => ($vaultState['paused'] ?? null) === false, 'hint' => 'เจ้าของรัน unpause-bridge-vault.js'],
                ['key' => 'minter_not_paused', 'ok' => ($minterState['paused'] ?? null) === false, 'hint' => 'เจ้าของเรียก WTPIXBridgeMinter.unpause()'],
                ['key' => 'token_not_paused', 'ok' => ($tokenState['paused'] ?? null) === false, 'hint' => 'wTPIX ถูก pause โดยเจ้าของ'],
                ['key' => 'caps_aligned', 'ok' => $capsAligned, 'hint' => 'vault.maxLock ต้อง ≤ WTPIXBridgeMinter.maxPerTx ไม่งั้นล็อกได้แต่ mint ไม่ได้'],
                ['key' => 'no_foreign_supply', 'ok' => $noForeign, 'hint' => 'wTPIX.totalSupply > minter.totalMinted = มี wTPIX ที่ไม่ได้มาจากสะพาน (ใช้ wTPIX ตัวใหม่ที่ supply เป็นศูนย์)'],
                ['key' => 'guardian_keystore', 'ok' => $this->config->signerReady('guardian'), 'optional' => true, 'hint' => 'WTPIX_BRIDGE_GUARDIAN_KEYSTORE + _PASS — ไม่มี = เกิดเหตุแล้ว pause บนเชนเองไม่ได้'],
                ['key' => 'minter_guardian_role', 'ok' => $role($bsc, $minter, 'guardians', $guardian) === true, 'optional' => true, 'hint' => 'WTPIXBridgeMinter.setGuardian(guardian, true)'],
                ['key' => 'vault_guardian_role', 'ok' => $role($tpix, $vault, 'guardians', $guardian) === true, 'optional' => true, 'hint' => 'TPIXBridgeVault.setGuardian(guardian, true)'],
                ['key' => 'guardian_bnb', 'ok' => $enough($guardianBnb), 'optional' => true, 'hint' => 'guardian ต้องมี BNB ไว้ pause บน BSC'],
            ];

            $usdtRelayer = $this->usdtConfig->signerAddress('relayer');
            $usdtPayout = $this->usdtConfig->signerAddress('payout');
            $sharedKey = $relayer !== null && in_array($relayer, array_filter([$usdtRelayer, $usdtPayout, $this->usdtConfig->signerAddress('guardian')]), true);

            unset($vaultState['max_lock_wei'], $minterState['max_per_tx_wei'], $minterState['total_minted_wei'], $tokenState['total_supply_wei']);

            return [
                'installed' => $this->config->installed(),
                'site_switch' => $this->config->siteSwitchOn(),
                'switch_reason' => $this->config->switchReason(),
                'operational' => $this->config->operational(),
                'mint_ready' => $mint['ready'],
                'release_ready' => $release['ready'],
                'checks' => $checks,
                'addresses' => ['vault' => $vault, 'vault_registry' => $this->config->vaultRegistryAddress(), 'wtpix' => $wtpix, 'minter' => $minter],
                'signers' => [
                    'relayer' => ['address' => $relayer, 'keystore_set' => $this->config->keystorePath('relayer') !== '',
                        'passphrase_set' => $this->config->hasPassphrase('relayer'), 'ready' => $this->config->signerReady('relayer'), 'bnb' => $fmt($relayerBnb)],
                    'guardian' => ['address' => $guardian, 'keystore_set' => $this->config->keystorePath('guardian') !== '',
                        'passphrase_set' => $this->config->hasPassphrase('guardian'), 'ready' => $this->config->signerReady('guardian'), 'bnb' => $fmt($guardianBnb)],
                ],
                'shared_key_with_usdt' => $sharedKey,
                'vault' => $vaultState,
                'minter' => $minterState,
                'token' => $tokenState,
                'cursors' => [
                    'tpix' => ['cursor' => $this->config->cursor($this->config->lockCursorKey()), 'scan_ok_at' => SiteSetting::get(WtpixBridgeConfig::CURSOR_GROUP, 'scan_ok_at_'.$this->config->tpixChainId())],
                    'bsc' => ['cursor' => $this->config->cursor($this->config->burnCursorKey()), 'scan_ok_at' => SiteSetting::get(WtpixBridgeConfig::CURSOR_GROUP, 'scan_ok_at_'.$this->config->bscChainId())],
                ],
            ];
        });
    }

    /** ยอดสด (ปุ่มรีเฟรช) — วัดอย่างเดียว ไม่สั่งหยุดอะไร */
    public function measure(): JsonResponse
    {
        $m = $this->reconciler->measure();
        $fmt = fn ($v) => $v === null ? null : Wei::format((string) $v, 18);

        return response()->json(['success' => true, 'data' => [
            'status' => $m['status'],
            'vault' => $fmt($m['vault_wei']),
            'supply' => $fmt($m['supply_wei']),
            'owed' => $fmt($m['owed_wei']),
            'backing' => $fmt($m['backing_wei']),
            'deficit' => $fmt($m['deficit_wei']),
            'unexplained_mint' => $fmt($m['unexplained_mint_wei']),
            'unexplained_release' => $fmt($m['unexplained_release_wei']),
            'foreign_supply' => $fmt($m['foreign_supply_wei']),
            'details' => [
                'vault_paused' => $m['details']['vault_paused'],
                'minter_paused' => $m['details']['minter_paused'],
                'vault_url' => $m['details']['vault_url'],
                'token_url' => $m['details']['token_url'],
            ],
        ]]);
    }

    /** เปิด/ปิดสวิตช์ — ปิด = หยุด mint และปล่อยทันที (การสแกน/ตัวเฝ้าเหตุยังเดิน) */
    public function toggle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        $on = (bool) $validated['enabled'];
        $reason = $validated['reason'] ?? null;
        $actor = $request->user()?->name ?? 'admin';

        $this->config->setSiteSwitch($on, $on ? null : trim("ปิดโดย {$actor}".($reason ? ": {$reason}" : '')));
        Cache::forget(self::CACHE_STATE);
        Log::warning('wtpix-bridge: แอดมินเปลี่ยนสวิตช์', ['enabled' => $on, 'by' => $request->user()?->id, 'reason' => $reason]);

        $warnings = [];
        if ($on) {
            $latest = WtpixBridgeSnapshot::latest('id')->first();
            if ($latest && $latest->status === WtpixBridgeSnapshot::STATUS_DEFICIT) {
                $warnings[] = 'ผลกระทบยอดล่าสุดยังขาด — ระบบจะปิดเองอีกถ้ารอบหน้ายังขาด';
            }
            if ($latest && (bccomp((string) $latest->unexplained_mint_wei, '0', 0) > 0 || bccomp((string) $latest->unexplained_release_wei, '0', 0) > 0
                || bccomp((string) $latest->foreign_supply_wei, '0', 0) > 0)) {
                $warnings[] = 'ยังมี mint/ปล่อยที่ระบบไม่ได้สั่งค้างอยู่ — ตัวกระทบยอดจะหยุดสะพานอีกในรอบหน้า';
            }
            if (! $this->config->installed()) {
                $warnings[] = 'WTPIX_BRIDGE_ENABLED ใน .env ยังเป็น false — สวิตช์นี้ยังไม่มีผลจนกว่าจะตั้ง';
            }
        }

        return response()->json(['success' => true, 'data' => ['enabled' => $on, 'warnings' => $warnings]]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $rules = [];
        foreach (WtpixBridgeConfig::LIMIT_KEYS as $key) {
            $rules["limits.{$key}"] = ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,18})?$/'];
        }

        $validated = $request->validate($rules);
        $limits = array_filter((array) ($validated['limits'] ?? []), fn ($v) => $v !== null && $v !== '');
        $merged = array_merge($this->config->limits(), $limits);
        $wei = fn (string $key) => Wei::toWei($merged[$key], 18);
        $errors = [];

        // ความสัมพันธ์ระหว่างวงเงิน — ตั้งกลับหัวแล้วทุกรายการจะไปรอตรวจ หรือผู้ใช้ได้ศูนย์
        foreach (['lock' => 'ล็อก (TPIX → BSC)', 'burn' => 'เผา (BSC → TPIX)'] as $side => $label) {
            if (bccomp($wei("max_{$side}_per_tx"), $wei("min_{$side}"), 0) < 0) {
                $errors[] = "{$label}: วงเงินต่อครั้งต้องไม่น้อยกว่าขั้นต่ำ";
            }
            if (bccomp($wei("max_{$side}_per_address_daily"), $wei("max_{$side}_per_tx"), 0) < 0) {
                $errors[] = "{$label}: วงเงินต่อกระเป๋าต่อวันต้องไม่น้อยกว่าวงเงินต่อครั้ง";
            }
            if (bccomp($wei("{$side}_fee"), $wei("min_{$side}"), 0) >= 0) {
                $errors[] = "{$label}: ค่าธรรมเนียมต้องน้อยกว่าขั้นต่ำ ไม่งั้นผู้ใช้ได้ศูนย์";
            }
        }

        if ($errors !== []) {
            return response()->json(['success' => false, 'message' => 'บันทึกไม่ได้', 'errors' => $errors], 422);
        }

        foreach ($limits as $key => $value) {
            SiteSetting::set(WtpixBridgeConfig::SETTINGS_GROUP, "limit_{$key}", $value, 'string');
        }

        Cache::forget(WtpixBridgeConfig::PUBLIC_CACHE_KEY);
        Cache::forget(self::CACHE_STATE);

        return response()->json(['success' => true, 'data' => $this->settingsPayload()]);
    }

    // ── อนุมัติ / ปฏิเสธ ─────────────────────────────────────────────────────

    /**
     * อนุมัติรายการที่ต้องให้คนดู → กลับเข้าคิว.
     *
     * อนุมัติ = รับรองข้อสงสัยของรายการนี้ (เกินวงเงิน / ผู้ส่งเป็นสัญญา / KYC) แต่ด่านความปลอดภัยยังตรวจทุกข้อ:
     * depositId ต้องตรงสูตร · สัญญาต้นทางยังเป็นตัวที่ตั้งไว้ · ยังไม่เคยทำบนสัญญาปลายทาง · ไม่เกิน maxPerTx บนสัญญา
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $id) {
            $row = WtpixBridgeTransfer::lockForUpdate()->find($id);

            if (! $row) {
                return [404, ['success' => false, 'message' => 'ไม่พบรายการ']];
            }

            $approvable = $row->status === WtpixBridgeTransfer::STATUS_NEEDS_REVIEW
                || ($row->status === WtpixBridgeTransfer::STATUS_IGNORED && $row->review_reason === 'below_minimum');

            if (! $approvable) {
                return [409, ['success' => false, 'message' => "รายการนี้อนุมัติไม่ได้ (สถานะ: {$row->status})"]];
            }

            $route = $this->config->route($row->direction);
            $errors = [];
            if ($row->source_chain_id !== $route['source_chain'] || $row->source_contract !== $route['source_contract']) {
                $errors[] = 'รายการนี้มาจากสัญญา/เชนที่ไม่ได้ตั้งไว้แล้ว (เปลี่ยนค่าตั้งหลังบันทึก) — ปฏิเสธแล้วจัดการด้วยมือ';
            }
            if (! hash_equals(Abi::depositId((int) $row->source_chain_id, $row->tx_hash, (int) $row->log_index), $row->deposit_id)) {
                $errors[] = 'depositId ไม่ตรงกับ (เชน, tx, log) — ข้อมูลรายการผิดปกติ';
            }
            if (bccomp((string) $row->payout_wei, '0', 0) <= 0) {
                $errors[] = 'ยอดที่จะส่งเป็นศูนย์';
            }
            if (! Abi::isValidAddress($row->recipient)) {
                $errors[] = 'ที่อยู่ผู้รับไม่ถูกต้อง';
            }
            if ($route['dest_contract'] === null) {
                $errors[] = 'ยังไม่ได้ตั้งสัญญาปลายทาง';
            }

            if ($errors !== []) {
                return [422, ['success' => false, 'message' => 'อนุมัติไม่ได้', 'errors' => $errors]];
            }

            /*
             * ต่ำกว่าขั้นต่ำ (ignored) = ตัวสแกนไม่เคยถามเชนว่าผู้ส่งเป็นสัญญา หรือคนกดส่งเป็นเจ้าของเหรียญไหม
             * (รายการ ignored ไม่ยิง RPC เพิ่มเพื่อประหยัด) — ป้ายที่แอดมินเห็นจึงมีแค่ "below_minimum"
             *
             * ⚠️ อนุมัติตรงนี้ = ยอมข้าม "กฎขั้นต่ำ" ข้อเดียว ไม่ใช่ทุกข้อ
             *    multisig/กระดานเทรดที่ล็อก 5 TPIX จะได้ wTPIX ที่ที่อยู่เดียวกันบน BSC ซึ่งอาจไม่มีใครคุม
             *    → ถามเชนต้นทางเอง แล้วรันกฎครบชุด เจอข้อสงสัยอื่น = ย้ายไปรอตรวจด้วยเหตุผลจริง
             *      ให้แอดมินรับรองข้อนั้นอย่างตั้งใจอีกครั้ง (อนุมัติแถว needs_review = รับรองข้อสงสัยของแถวนั้น)
             */
            if ($row->status === WtpixBridgeTransfer::STATUS_IGNORED) {
                $sourceRpc = $this->config->rpc((int) $row->source_chain_id);
                $sender = $sourceRpc ? $this->policy->senderFacts($sourceRpc, (string) $row->from_address, (string) $row->tx_hash) : null;

                if ($sender === null) {
                    return [503, ['success' => false, 'message' => 'อ่านข้อมูลผู้ส่งจากเชนต้นทางไม่ได้ตอนนี้ — ลองใหม่อีกครั้ง']];
                }

                $decision = $this->policy->evaluate($row->direction, [
                    'from_address' => (string) $row->from_address,
                    'amount_wei' => (string) $row->amount_wei,
                    'payout_wei' => (string) $row->payout_wei,
                ] + $sender, $row->id, ignoreMinimum: true);

                if ($decision['status'] === WtpixBridgeTransfer::STATUS_IGNORED) {
                    return [422, ['success' => false, 'message' => 'อนุมัติไม่ได้', 'errors' => ["รายการนี้ทำต่อไม่ได้ ({$decision['reason']})"]]];
                }

                if ($decision['status'] === WtpixBridgeTransfer::STATUS_NEEDS_REVIEW) {
                    $row->update([
                        'status' => WtpixBridgeTransfer::STATUS_NEEDS_REVIEW,
                        'review_reason' => $decision['reason'],
                        'last_error' => "ตรวจซ้ำตอนอนุมัติรายการต่ำกว่าขั้นต่ำ พบ {$decision['reason']} — ตรวจข้อนี้แล้วกดอนุมัติอีกครั้ง",
                        'review_note' => $this->appendNote($row, 'ตรวจซ้ำตอนอนุมัติ (เหตุเดิม: below_minimum) พบ '.$decision['reason']),
                    ]);

                    return [409, [
                        'success' => false,
                        'message' => "ตรวจกับเชนแล้วพบข้อสงสัยอื่น ({$decision['reason']}) — ย้ายไปรอตรวจแล้ว ตรวจข้อนี้แล้วกดอนุมัติอีกครั้ง",
                        'data' => $this->row($row->fresh()),
                    ]];
                }
            }

            $rpc = $this->relayer->destRpc($row->direction);
            $processed = $this->relayer->processedOnChain($rpc, $route['dest_contract'], $row->deposit_id);
            $maxPerTx = $rpc->callUint($route['dest_contract'], 'maxPerTx()');

            if ($processed === null || $maxPerTx === null) {
                return [503, ['success' => false, 'message' => 'อ่านสถานะจากเชนปลายทางไม่ได้ตอนนี้ — ลองใหม่อีกครั้ง']];
            }

            $actor = $request->user()?->name ?? 'admin';
            $elsewhere = in_array($row->review_reason, ['minted_elsewhere', 'released_elsewhere'], true);

            if ($processed && ! $elsewhere) {
                // ทำบนเชนแล้วทั้งที่ระบบไม่เคยส่ง — อย่าปิดเงียบ ๆ (จะกลบรอยคีย์หลุดจากตัวกระทบยอด)
                $row->update([
                    // แถว ignored ต้องย้ายมารอตรวจด้วย ไม่งั้นป้ายเปลี่ยนแล้วกดอนุมัติรอบสองไม่ได้อีกเลย
                    'status' => WtpixBridgeTransfer::STATUS_NEEDS_REVIEW,
                    'review_reason' => $row->direction === WtpixBridgeTransfer::TO_BSC ? 'minted_elsewhere' : 'released_elsewhere',
                    'last_error' => 'สัญญาปลายทางบันทึกว่าทำไปแล้วโดยธุรกรรมที่ระบบไม่ได้ส่ง — ตรวจผู้รับบน explorer แล้วกดอนุมัติอีกครั้ง',
                ]);

                return [409, ['success' => false, 'message' => 'รายการนี้ถูกทำบนเชนไปแล้วโดยธุรกรรมที่ระบบไม่ได้ส่ง — ตรวจ event บน explorer ว่าผู้รับ/จำนวนถูกต้องก่อนอนุมัติอีกครั้ง']];
            }

            if ($processed) {
                // แอดมินตรวจ event บน explorer แล้วว่าถึงผู้ใช้ถูกคน — นับเป็นของระบบ (ตัวกระทบยอดเลิกนับเป็น "ไม่ได้สั่ง")
                $row->update([
                    'status' => WtpixBridgeTransfer::STATUS_COMPLETED,
                    'dest_contract' => $route['dest_contract'],
                    'dest_chain_id' => $rpc->chainId,
                    'completed_at' => now(),
                    'reviewed_by' => $request->user()?->id,
                    'reviewed_at' => now(),
                    'review_note' => $this->appendNote($row, "ยืนยันว่าทำบนเชนแล้วโดย {$actor} (เหตุเดิม: {$row->review_reason})"),
                ]);

                return [200, ['success' => true, 'message' => 'บันทึกว่าทำบนเชนแล้ว (ตามที่แอดมินตรวจ)', 'data' => $this->row($row->fresh())]];
            }

            if (bccomp((string) $row->payout_wei, $maxPerTx, 0) > 0) {
                return [422, ['success' => false, 'message' => 'อนุมัติไม่ได้', 'errors' => [
                    'ยอด '.Wei::format((string) $row->payout_wei, 18).' เกินเพดานต่อครั้งบนสัญญา ('.Wei::format($maxPerTx, 18)
                    .') — เจ้าของต้อง setMaxPerTx ก่อน (depositId ใช้ได้ครั้งเดียว แบ่งจ่ายไม่ได้)',
                ]]];
            }

            // ธุรกรรมเดิม (ถ้ามี) revert หรือ nonce ถูกใช้ไปแล้ว — ส่งใหม่ปลอดภัยเพราะสัญญากัน depositId ซ้ำอยู่
            $row->update([
                'status' => WtpixBridgeTransfer::STATUS_CONFIRMED,
                'limits_overridden' => true,
                'reviewed_by' => $request->user()?->id,
                'reviewed_at' => now(),
                'review_note' => $this->appendNote($row, "อนุมัติโดย {$actor} (เหตุเดิม: ".($row->review_reason ?? '-')
                    .($row->dest_tx_hash ? ", tx เดิม {$row->dest_tx_hash}" : '').')'),
                'dest_tx_hash' => null,
                'dest_raw_tx' => null,
                'dest_nonce' => null,
                'dest_from' => null,
                'broadcast_at' => null,
                'attempts' => 0,
                'last_error' => null,
            ]);

            return [200, ['success' => true, 'message' => 'อนุมัติแล้ว — ระบบจะทำรายการภายในรอบถัดไป', 'data' => $this->row($row->fresh())]];
        });

        return response()->json($result[1], $result[0]);
    }

    /** ปฏิเสธ — ต้องมีเหตุผล และต้องยืนยันบนเชนว่ายังไม่เคยทำ (ไม่งั้นปิดรายการทั้งที่เงินออกไปแล้ว) */
    public function reject(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $result = DB::transaction(function () use ($request, $id, $validated) {
            $row = WtpixBridgeTransfer::lockForUpdate()->find($id);

            if (! $row) {
                return [404, ['success' => false, 'message' => 'ไม่พบรายการ']];
            }

            $open = in_array($row->status, [WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, WtpixBridgeTransfer::STATUS_CONFIRMED, WtpixBridgeTransfer::STATUS_AWAITING_LIQUIDITY], true);
            if (! $open) {
                return [409, ['success' => false, 'message' => "รายการนี้ปฏิเสธไม่ได้ (สถานะ: {$row->status})"]];
            }

            $contract = $row->dest_contract ?: $this->config->route($row->direction)['dest_contract'];
            if ($contract !== null) {
                $processed = $this->relayer->processedOnChain($this->relayer->destRpc($row->direction), $contract, $row->deposit_id);
                if ($processed === null) {
                    return [503, ['success' => false, 'message' => 'อ่านสถานะจากเชนปลายทางไม่ได้ตอนนี้ — ลองใหม่อีกครั้ง']];
                }
                if ($processed) {
                    return [409, ['success' => false, 'message' => 'รายการนี้ถูกทำบนเชนไปแล้ว — ปฏิเสธไม่ได้ ตรวจ event บน explorer']];
                }
            }

            $row->update([
                'status' => WtpixBridgeTransfer::STATUS_REJECTED,
                'reviewed_by' => $request->user()?->id,
                'reviewed_at' => now(),
                'review_note' => $this->appendNote($row, 'ปฏิเสธโดย '.($request->user()?->name ?? 'admin').': '.$validated['reason']),
            ]);

            return [200, ['success' => true, 'data' => $this->row($row->fresh())]];
        });

        return response()->json($result[1], $result[0]);
    }

    // ── รูปแบบข้อมูลของหน้า ───────────────────────────────────────────────

    private function appendNote(WtpixBridgeTransfer $row, string $line): string
    {
        return trim(($row->review_note ? $row->review_note."\n" : '').now()->toDateTimeString().' '.$line);
    }

    private function row(WtpixBridgeTransfer $t): array
    {
        return $t->toPublicArray() + [
            'deposit_id' => $t->deposit_id,
            'source_contract' => $t->source_contract,
            'dest_contract' => $t->dest_contract,
            'dest_tx_hash' => $t->dest_tx_hash,
            'attempts' => $t->attempts,
            'limits_overridden' => $t->limits_overridden,
            'review_note' => $t->review_note,
            'last_error' => $t->last_error,
        ];
    }

    private function settingsPayload(): array
    {
        return [
            'limits' => $this->config->limits(),
            'defaults' => (array) config('wtpix_bridge.limits', []),
            'tolerance' => (string) config('wtpix_bridge.reconcile.tolerance', '1'),
            'min_gas_balance' => (string) config('wtpix_bridge.bsc.min_gas_balance', '0.002'),
        ];
    }
}
