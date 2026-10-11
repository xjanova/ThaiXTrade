<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BridgeDeposit;
use App\Models\BridgeReserveSnapshot;
use App\Models\BridgeWithdrawal;
use App\Models\SiteSetting;
use App\Services\Bridge\Abi;
use App\Services\Bridge\BridgeConfig;
use App\Services\Bridge\BridgePayouter;
use App\Services\Bridge\BridgePolicy;
use App\Services\Bridge\BridgePublicInfo;
use App\Services\Bridge\BridgeReconciler;
use App\Services\Bridge\BridgeRelayer;
use App\Support\Wei;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * หลังบ้านสะพาน USDT — แดชบอร์ด · รายการฝาก/ถอน · อนุมัติ/ปฏิเสธรายการที่ต้องให้คนดู · สวิตช์ · วงเงิน.
 *
 * หน้าเว็บไม่เคยเซ็นธุรกรรมเอง (php-fpm ปิด proc_open) — อนุมัติแล้วแค่เปลี่ยนสถานะกลับเข้าคิว
 * ให้ bridge:relay / bridge:payout ฝั่ง CLI เป็นคนเซ็น ซึ่งตรวจกฎซ้ำอีกรอบก่อนปล่อยเงินจริง
 *
 * ทุกปุ่มที่เปลี่ยนสถานะล็อกแถว (lockForUpdate) แล้วเช็คสถานะก่อนทำ — แอดมินสองคนกดพร้อมกัน
 * หรือกดเบิ้ล คนที่สองได้ 409 ไม่ใช่ทำซ้ำ
 *
 * Developed by Xman Studio.
 */
class BridgeAdminController extends Controller
{
    public function __construct(
        private BridgeConfig $config,
        private BridgeRelayer $relayer,
        private BridgePayouter $payouter,
        private BridgePolicy $policy,
        private BridgeReconciler $reconciler,
        private BridgePublicInfo $publicInfo,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $tab = in_array($request->query('tab'), ['overview', 'deposits', 'withdrawals', 'settings'], true)
            ? $request->query('tab') : 'overview';
        $status = $request->query('status');

        $deposits = BridgeDeposit::query()
            ->when($status && $tab === 'deposits', fn ($q) => $q->where('status', $status))
            ->latest('id')->paginate(25, ['*'], 'dpage')->withQueryString()
            ->through(fn (BridgeDeposit $d) => $this->depositRow($d));

        $withdrawals = BridgeWithdrawal::query()
            ->when($status && $tab === 'withdrawals', fn ($q) => $q->where('status', $status))
            ->latest('id')->paginate(25, ['*'], 'wpage')->withQueryString()
            ->through(fn (BridgeWithdrawal $w) => $this->withdrawalRow($w));

        $snapshot = BridgeReserveSnapshot::latest('id')->first();

        return Inertia::render('Admin/Bridge/Index', [
            'tab' => $tab,
            'filters' => ['status' => $status],
            'state' => $this->state(),
            'snapshot' => $snapshot ? $snapshot->toPublicArray() + [
                'unexplained_mint' => Wei::format((string) $snapshot->unexplained_mint_units, 6),
                'note' => $snapshot->note,
            ] : null,
            'deposits' => $deposits,
            'withdrawals' => $withdrawals,
            'counts' => [
                'deposits' => BridgeDeposit::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
                'withdrawals' => BridgeWithdrawal::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            ],
            'settings' => $this->settingsPayload(),
            'explorers' => collect($this->config->chains())->mapWithKeys(fn ($c, $id) => [$id => $this->config->explorer($id)])
                ->put($this->config->tpixChainId(), $this->config->explorer($this->config->tpixChainId())),
        ]);
    }

    /** สถานะรวม + ด่านความพร้อม (ยิง RPC เชน TPIX ไม่กี่ครั้ง แคช 20 วิ) */
    private function state(): array
    {
        return Cache::remember('bridge:admin:state', 20, function () {
            $readiness = $this->relayer->readiness();
            $onchain = $readiness['onchain'] ?? [];
            $fmt = fn ($v) => $v === null ? null : Wei::format((string) $v, 6);

            return [
                'installed' => $this->config->installed(),
                'site_switch' => $this->config->siteSwitchOn(),
                'switch_reason' => $this->config->switchReason(),
                'operational' => $this->config->operational(),
                'readiness' => $readiness['checks'],
                'mint_ready' => $readiness['ready'],
                'addresses' => [
                    'vault' => $this->config->vaultAddress(),
                    'usdt_tpix' => $this->config->usdtTpixAddress(),
                    'minter' => $this->config->minterAddress(),
                ],
                'signers' => collect(['relayer', 'payout', 'guardian'])->mapWithKeys(fn ($role) => [$role => [
                    'address' => $this->config->signerAddress($role),
                    'keystore_set' => $this->config->keystorePath($role) !== '',
                    'passphrase_set' => $this->config->hasPassphrase($role),
                    'ready' => $this->config->signerReady($role),
                ]]),
                'minter' => [
                    'paused' => $onchain['minter_paused'] ?? null,
                    'max_per_tx' => $fmt($onchain['max_per_tx'] ?? null),
                    'daily_cap' => $fmt($onchain['daily_cap'] ?? null),
                    'remaining_today' => $fmt($onchain['remaining_today'] ?? null),
                    'total_minted' => $fmt($onchain['total_minted'] ?? null),
                ],
                'paid_today' => Wei::format($this->policy->paidTodayUnits(), 6),
                'cursors' => collect(array_keys($this->config->chains()))
                    ->push($this->config->tpixChainId())
                    ->mapWithKeys(fn ($id) => [$id => [
                        'cursor' => $this->config->cursor($id === $this->config->tpixChainId() ? "burn_{$id}" : "deposit_{$id}"),
                        'scan_ok_at' => SiteSetting::get(BridgeConfig::CURSOR_GROUP, "scan_ok_at_{$id}"),
                    ]]),
            ];
        });
    }

    /** ยอดกระเป๋าทุกเชนแบบสด (ปุ่มรีเฟรช) — วัดอย่างเดียว ไม่สั่งหยุดอะไร */
    public function measure(): JsonResponse
    {
        $m = $this->reconciler->measure();
        $fmt = fn ($v) => $v === null ? null : Wei::format((string) $v, 6);

        return response()->json(['success' => true, 'data' => [
            'status' => $m['status'],
            'supply' => $fmt($m['supply_units']),
            'vault' => $fmt($m['vault_units']),
            'payout_wallets' => $fmt($m['payout_units']),
            'owed' => $fmt($m['owed_units']),
            'backing' => $fmt($m['backing_units']),
            'deficit' => $fmt($m['deficit_units']),
            'unexplained_mint' => $fmt($m['unexplained_mint_units']),
            'minter_paused' => $m['minter_paused'],
            'chains' => $m['chains'],
        ]]);
    }

    /**
     * เปิด/ปิดสวิตช์หลังบ้าน — ปิด = หยุด mint และจ่ายทันที (การสแกนยังเดิน รายการไม่หาย).
     */
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
        Cache::forget('bridge:admin:state');

        Log::warning('bridge: แอดมินเปลี่ยนสวิตช์', ['enabled' => $on, 'by' => $request->user()?->id, 'reason' => $reason]);

        $warnings = [];
        if ($on) {
            $latest = BridgeReserveSnapshot::latest('id')->first();
            if ($latest && $latest->status === BridgeReserveSnapshot::STATUS_DEFICIT) {
                $warnings[] = 'ผลกระทบยอดล่าสุดยังขาดเงินหนุน — ระบบจะปิดเองอีกถ้ารอบหน้ายังขาด';
            }
            if (! $this->config->installed()) {
                $warnings[] = 'BRIDGE_ENABLED ใน .env ยังเป็น false — สวิตช์นี้ยังไม่มีผลจนกว่าจะตั้ง';
            }
        }

        return response()->json(['success' => true, 'data' => ['enabled' => $on, 'warnings' => $warnings]]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $amount = ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'];
        $rules = [];
        foreach (BridgeConfig::LIMIT_KEYS as $key) {
            $rules["limits.{$key}"] = $amount;
        }
        foreach (array_keys($this->config->chains()) as $chainId) {
            $rules["fees.{$chainId}"] = $amount;
            $rules["chains.{$chainId}"] = ['nullable', 'boolean'];
        }

        $validated = $request->validate($rules);
        $limits = array_filter((array) ($validated['limits'] ?? []), fn ($v) => $v !== null && $v !== '');

        // ความสัมพันธ์ระหว่างวงเงิน — ตั้งกลับหัวแล้วระบบจะปัดทุกรายการไป needs_review หรือ mint เกินที่ตั้งใจ
        $merged = array_merge($this->config->limits(), $limits);
        $errors = [];
        if (bccomp(Wei::toWei($merged['max_deposit_per_tx'], 6), Wei::toWei($merged['min_deposit'], 6), 0) < 0) {
            $errors[] = 'วงเงินฝากต่อครั้งต้องไม่น้อยกว่าขั้นต่ำ';
        }
        if (bccomp(Wei::toWei($merged['max_deposit_per_address_daily'], 6), Wei::toWei($merged['max_deposit_per_tx'], 6), 0) < 0) {
            $errors[] = 'วงเงินต่อกระเป๋าต่อวันต้องไม่น้อยกว่าวงเงินต่อครั้ง';
        }
        if (bccomp(Wei::toWei($merged['max_withdraw_per_tx'], 6), Wei::toWei($merged['min_withdraw'], 6), 0) < 0) {
            $errors[] = 'วงเงินถอนต่อครั้งต้องไม่น้อยกว่าขั้นต่ำ';
        }
        foreach ((array) ($validated['fees'] ?? []) as $chainId => $fee) {
            if ($fee !== null && $fee !== '' && bccomp(Wei::toWei($fee, 6), Wei::toWei($merged['min_withdraw'], 6), 0) >= 0) {
                $errors[] = "ค่าธรรมเนียมถอนของเชน {$chainId} ต้องน้อยกว่าขั้นต่ำการถอน ไม่งั้นผู้ใช้ได้ศูนย์";
            }
        }

        if ($errors !== []) {
            return response()->json(['success' => false, 'message' => 'บันทึกไม่ได้', 'errors' => $errors], 422);
        }

        foreach ($limits as $key => $value) {
            SiteSetting::set(BridgeConfig::SETTINGS_GROUP, "limit_{$key}", $value, 'string');
        }
        foreach ((array) ($validated['fees'] ?? []) as $chainId => $fee) {
            if ($fee !== null && $fee !== '') {
                SiteSetting::set(BridgeConfig::SETTINGS_GROUP, "withdraw_fee_{$chainId}", $fee, 'string');
            }
        }
        foreach ((array) ($validated['chains'] ?? []) as $chainId => $enabled) {
            if ($enabled !== null) {
                SiteSetting::set(BridgeConfig::SETTINGS_GROUP, "chain_enabled_{$chainId}", $enabled ? '1' : '0', 'boolean');
            }
        }

        Cache::forget('bridge:info:public');
        Cache::forget('bridge:admin:state');

        return response()->json(['success' => true, 'data' => $this->settingsPayload()]);
    }

    // ── อนุมัติ / ปฏิเสธ ─────────────────────────────────────────────────────

    /**
     * อนุมัติเงินฝากที่ต้องให้คนดู → กลับเข้าคิว mint.
     *
     * อนุมัติ = แอดมินรับรองข้อสงสัยของรายการนี้ (เกินวงเงิน / ผู้ส่งเป็นสัญญา / KYC) แต่ด่านความปลอดภัย
     * ยังตรวจซ้ำทุกข้อ: depositId ต้องคำนวณได้ตรง · ยังไม่เคย mint บนสัญญา · ไม่เกิน maxPerTx บนสัญญา
     */
    public function approveDeposit(Request $request, int $id): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $id) {
            $deposit = BridgeDeposit::lockForUpdate()->find($id);

            if (! $deposit) {
                return [404, ['success' => false, 'message' => 'ไม่พบรายการ']];
            }

            $approvable = $deposit->status === BridgeDeposit::STATUS_NEEDS_REVIEW
                || ($deposit->status === BridgeDeposit::STATUS_IGNORED && $deposit->review_reason === 'below_minimum');

            if (! $approvable) {
                return [409, ['success' => false, 'message' => "รายการนี้อนุมัติไม่ได้ (สถานะ: {$deposit->status})"]];
            }

            $errors = [];
            if ($this->config->chain((int) $deposit->source_chain_id) === null) {
                $errors[] = 'เชนต้นทางไม่อยู่ในรายการที่รองรับแล้ว';
            }
            if (! hash_equals(Abi::depositId((int) $deposit->source_chain_id, $deposit->tx_hash, (int) $deposit->log_index), $deposit->deposit_id)) {
                $errors[] = 'depositId ไม่ตรงกับ (เชน, tx, log) — ข้อมูลรายการผิดปกติ';
            }
            if (bccomp((string) $deposit->mint_units, '0', 0) <= 0) {
                $errors[] = 'ยอดที่จะ mint เป็นศูนย์';
            }
            if (! Abi::isValidAddress($deposit->from_address)) {
                $errors[] = 'ที่อยู่ผู้รับไม่ถูกต้อง';
            }

            if ($errors !== []) {
                return [422, ['success' => false, 'message' => 'อนุมัติไม่ได้', 'errors' => $errors]];
            }

            $rpc = $this->config->tpixRpc();
            $processed = $this->relayer->processedOnChain($rpc, $deposit->deposit_id);
            $minter = $this->config->minterAddress();
            $maxPerTx = $minter ? $rpc->callUint($minter, 'maxPerTx()') : null;

            if ($processed === null || $maxPerTx === null) {
                return [503, ['success' => false, 'message' => 'อ่านสถานะจากเชน TPIX ไม่ได้ตอนนี้ — ลองใหม่อีกครั้ง']];
            }

            if ($processed) {
                $deposit->update([
                    'status' => BridgeDeposit::STATUS_MINTED,
                    'minted_at' => now(),
                    'reviewed_by' => $request->user()?->id,
                    'reviewed_at' => now(),
                    'last_error' => 'สัญญาบันทึกว่า mint ไปแล้ว — ไม่ต้อง mint ซ้ำ',
                ]);

                return [200, ['success' => true, 'message' => 'รายการนี้ถูก mint บนเชนไปแล้ว ปรับสถานะเป็นสำเร็จ', 'data' => $this->depositRow($deposit->fresh())]];
            }

            if (bccomp((string) $deposit->mint_units, $maxPerTx, 0) > 0) {
                return [422, ['success' => false, 'message' => 'อนุมัติไม่ได้', 'errors' => [
                    'ยอด '.Wei::format((string) $deposit->mint_units, 6).' เกินเพดานต่อครั้งบนสัญญา ('.Wei::format($maxPerTx, 6)
                    .' USDT) — เจ้าของต้อง setMaxPerTx ก่อน หรือปฏิเสธแล้วคืนเงินจาก vault',
                ]]];
            }

            $note = trim(($deposit->review_note ? $deposit->review_note."\n" : '')
                .now()->toDateTimeString().' อนุมัติโดย '.($request->user()?->name ?? 'admin')
                .' (เหตุเดิม: '.($deposit->review_reason ?? '-').($deposit->mint_tx_hash ? ', tx เดิม '.$deposit->mint_tx_hash : '').')');

            // ธุรกรรมเดิม (ถ้ามี) revert หรือ nonce ถูกใช้ไปแล้ว — mint ใหม่ปลอดภัยเพราะสัญญากัน depositId ซ้ำอยู่
            $deposit->update([
                'status' => BridgeDeposit::STATUS_CONFIRMED,
                'limits_overridden' => true,
                'reviewed_by' => $request->user()?->id,
                'reviewed_at' => now(),
                'review_note' => $note,
                'mint_tx_hash' => null,
                'mint_raw_tx' => null,
                'mint_nonce' => null,
                'mint_attempts' => 0,
                'last_error' => null,
            ]);

            return [200, ['success' => true, 'message' => 'อนุมัติแล้ว — ระบบจะ mint ภายในรอบถัดไป', 'data' => $this->depositRow($deposit->fresh())]];
        });

        return response()->json($result[1], $result[0]);
    }

    public function rejectDeposit(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $result = DB::transaction(function () use ($request, $id, $validated) {
            $deposit = BridgeDeposit::lockForUpdate()->find($id);

            if (! $deposit) {
                return [404, ['success' => false, 'message' => 'ไม่พบรายการ']];
            }

            if (! in_array($deposit->status, [BridgeDeposit::STATUS_NEEDS_REVIEW, BridgeDeposit::STATUS_CONFIRMED], true) || $deposit->mint_tx_hash) {
                return [409, ['success' => false, 'message' => "รายการนี้ปฏิเสธไม่ได้ (สถานะ: {$deposit->status})"]];
            }

            $deposit->update([
                'status' => BridgeDeposit::STATUS_REJECTED,
                'reviewed_by' => $request->user()?->id,
                'reviewed_at' => now(),
                'review_note' => trim(($deposit->review_note ? $deposit->review_note."\n" : '')
                    .now()->toDateTimeString().' ปฏิเสธโดย '.($request->user()?->name ?? 'admin').': '.$validated['reason']),
            ]);

            return [200, ['success' => true, 'data' => $this->depositRow($deposit->fresh())]];
        });

        return response()->json($result[1], $result[0]);
    }

    /**
     * อนุมัติคำขอถอนที่ต้องให้คนดู → กลับเข้าคิวจ่าย.
     *
     * ปลายทางผิดรูปแบบ/เชนไม่รองรับ อนุมัติไม่ได้ (จ่ายไม่ได้จริง) — ทางเดียวคือปฏิเสธแล้วคืนเงินเอง
     * รายการที่เคยส่งธุรกรรมไปแล้ว ต้องพิสูจน์ก่อนว่าธุรกรรมเดิมไม่มีทางลงบล็อก ไม่งั้นคือจ่ายสองรอบ
     */
    public function approveWithdrawal(Request $request, int $id): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $id) {
            $withdrawal = BridgeWithdrawal::lockForUpdate()->find($id);

            if (! $withdrawal) {
                return [404, ['success' => false, 'message' => 'ไม่พบรายการ']];
            }

            if ($withdrawal->status !== BridgeWithdrawal::STATUS_NEEDS_REVIEW) {
                return [409, ['success' => false, 'message' => "รายการนี้อนุมัติไม่ได้ (สถานะ: {$withdrawal->status})"]];
            }

            $check = $this->policy->evaluateWithdrawal([
                'target_chain_id' => $withdrawal->target_chain_id,
                'target_address' => $withdrawal->target_address,
                'amount_units' => (string) $withdrawal->amount_units,
                'payout_units' => (string) $withdrawal->payout_units,
                'from_address' => $withdrawal->from_address,
            ], true);

            // ตรวจแบบ "แอดมินรับรองแล้ว" (ข้าม KYC/วงเงิน) — ที่ยังไม่ผ่านคือจ่ายไม่ได้จริง (ปลายทางผิด/เชนปิด/ต่ำกว่าค่าธรรมเนียม)
            if ($check['status'] !== BridgeWithdrawal::STATUS_PENDING) {
                return [422, ['success' => false, 'message' => 'อนุมัติไม่ได้', 'errors' => ["ไม่ผ่านกฎ: {$check['reason']} — ปฏิเสธแล้วคืนเงินด้วยมือแทน"]]];
            }

            if ($withdrawal->payout_tx_hash) {
                $retry = $this->payouter->retryCheck($withdrawal);

                if ($retry['paid']) {
                    $withdrawal->update([
                        'status' => BridgeWithdrawal::STATUS_PAID,
                        'paid_at' => now(),
                        'reviewed_by' => $request->user()?->id,
                        'reviewed_at' => now(),
                        'last_error' => $retry['reason'],
                    ]);

                    return [200, ['success' => true, 'message' => $retry['reason'].' — ปรับสถานะเป็นจ่ายแล้ว', 'data' => $this->withdrawalRow($withdrawal->fresh())]];
                }

                if (! $retry['safe']) {
                    return [409, ['success' => false, 'message' => $retry['reason']]];
                }
            }

            $withdrawal->update([
                'status' => BridgeWithdrawal::STATUS_PENDING,
                'limits_overridden' => true,
                'reviewed_by' => $request->user()?->id,
                'reviewed_at' => now(),
                'review_note' => trim(($withdrawal->review_note ? $withdrawal->review_note."\n" : '')
                    .now()->toDateTimeString().' อนุมัติโดย '.($request->user()?->name ?? 'admin')
                    .' (เหตุเดิม: '.($withdrawal->review_reason ?? '-').($withdrawal->payout_tx_hash ? ', tx เดิม '.$withdrawal->payout_tx_hash : '').')'),
                'payout_tx_hash' => null,
                'payout_raw_tx' => null,
                'payout_nonce' => null,
                'payout_attempts' => 0,
                'last_error' => null,
            ]);

            return [200, ['success' => true, 'message' => 'อนุมัติแล้ว — ระบบจะจ่ายภายในรอบถัดไป', 'data' => $this->withdrawalRow($withdrawal->fresh())]];
        });

        return response()->json($result[1], $result[0]);
    }

    public function rejectWithdrawal(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $result = DB::transaction(function () use ($request, $id, $validated) {
            $withdrawal = BridgeWithdrawal::lockForUpdate()->find($id);

            if (! $withdrawal) {
                return [404, ['success' => false, 'message' => 'ไม่พบรายการ']];
            }

            $open = in_array($withdrawal->status, [BridgeWithdrawal::STATUS_NEEDS_REVIEW, BridgeWithdrawal::STATUS_PENDING, BridgeWithdrawal::STATUS_AWAITING_LIQUIDITY], true);

            if (! $open) {
                return [409, ['success' => false, 'message' => "รายการนี้ปฏิเสธไม่ได้ (สถานะ: {$withdrawal->status})"]];
            }

            // เคยส่งธุรกรรมแล้ว: ต้องแน่ใจว่าเงินไม่ได้ออก ก่อนจะปิดรายการว่า "ไม่จ่าย"
            if ($withdrawal->payout_tx_hash) {
                $retry = $this->payouter->retryCheck($withdrawal);
                if ($retry['paid'] || ! $retry['safe']) {
                    return [409, ['success' => false, 'message' => $retry['reason']]];
                }
            }

            $withdrawal->update([
                'status' => BridgeWithdrawal::STATUS_REJECTED,
                'reviewed_by' => $request->user()?->id,
                'reviewed_at' => now(),
                'review_note' => trim(($withdrawal->review_note ? $withdrawal->review_note."\n" : '')
                    .now()->toDateTimeString().' ปฏิเสธโดย '.($request->user()?->name ?? 'admin').': '.$validated['reason']),
            ]);

            return [200, ['success' => true, 'data' => $this->withdrawalRow($withdrawal->fresh())]];
        });

        return response()->json($result[1], $result[0]);
    }

    // ── รูปแบบข้อมูลของหน้า ───────────────────────────────────────────────

    private function depositRow(BridgeDeposit $d): array
    {
        return $d->toPublicArray() + [
            'deposit_id' => $d->deposit_id,
            'mint_tx_hash' => $d->mint_tx_hash,
            'mint_attempts' => $d->mint_attempts,
            'limits_overridden' => $d->limits_overridden,
            'review_note' => $d->review_note,
            'last_error' => $d->last_error,
            'token_decimals' => $d->token_decimals,
            'dust_raw' => (string) $d->dust_raw,
        ];
    }

    private function withdrawalRow(BridgeWithdrawal $w): array
    {
        return $w->toPublicArray() + [
            'target_chain_raw' => $w->target_chain_raw,
            'payout_tx_hash' => $w->payout_tx_hash,
            'payout_attempts' => $w->payout_attempts,
            'limits_overridden' => $w->limits_overridden,
            'review_note' => $w->review_note,
            'last_error' => $w->last_error,
        ];
    }

    private function settingsPayload(): array
    {
        return [
            'limits' => $this->config->limits(),
            'defaults' => (array) config('bridge.limits', []),
            'chains' => collect($this->config->chains())->map(fn ($c, $id) => [
                'chain_id' => $id,
                'name' => $c['name'],
                'short' => $c['short'] ?? $c['name'],
                'configured' => (bool) ($c['enabled'] ?? false),
                'enabled' => $this->config->chainEnabled($id),
                'withdraw_fee' => $this->config->withdrawFee($id),
                'usdt' => $c['usdt'],
            ])->values(),
            'tolerance' => (string) config('bridge.reconcile.tolerance', '1'),
            'public' => $this->publicInfo->info()['enabled'],
        ];
    }
}
