<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\StabilityDecision;
use App\Services\Stabilizer\StabilizerService;
use App\Services\Stabilizer\StabilizerSettings;
use App\Services\TpixDexService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * หลังบ้าน TPIX Stability Reserve (/admin/stabilizer) — เฉพาะ super_admin.
 *
 * หน้าเว็บทำได้แค่: ปรับพารามิเตอร์ (ภายในกรอบแข็งของ config) · เปิด/ปิดสวิตช์ ·
 * บันทึกเงินฝากจาก tx hash · ดูตัวอย่างการตัดสินใจ — **เซ็น/ส่งธุรกรรมไม่ได้**
 * (php-fpm ปิด proc_open; การเซ็นเกิดใน scheduler ฝั่ง CLI เท่านั้น)
 *
 * ทุกการเปลี่ยนแปลงลง AuditLog — เป็นเรื่องเงินของกองทุนที่ประกาศต่อสาธารณะ
 *
 * Developed by Xman Studio
 */
class StabilizerController extends Controller
{
    public function __construct(
        private StabilizerService $service,
        private StabilizerSettings $settings,
        private TpixDexService $dex,
    ) {}

    public function index(): InertiaResponse
    {
        try {
            $state = $this->service->buildState();
        } catch (\Throwable $e) {
            // อ่านเชนไม่ได้ต้องไม่ทำให้ทั้งหน้าเป็น 500 — แอดมินยังต้องกดปิดสวิตช์ได้
            Log::warning('stabilizer admin: state unavailable', ['error' => $e->getMessage()]);
            $state = null;
        }

        $keystore = $this->settings->inspectKeystore();

        return Inertia::render('Admin/Stabilizer/Index', [
            'state' => $state,
            'readiness' => $this->settings->readiness($this->dex),
            'params' => $this->settings->params(),
            'bounds' => $this->settings->bounds(),
            'keystore' => [
                'configured' => $keystore['ok'],
                'address' => $keystore['address'],
                'passphrase_set' => $this->settings->hasPassphrase(),
            ],
            'decisionsPage' => StabilityDecision::latest('id')->paginate(25)->through(fn (StabilityDecision $d) => [
                'id' => $d->id,
                'at' => $d->decided_at?->toIso8601String(),
                'action' => $d->action,
                'reason_code' => $d->reason_code,
                'pool_price' => $d->pool_price,
                'reference_price' => $d->reference_price,
                'band_low' => $d->band_low,
                'band_high' => $d->band_high,
                'details' => array_diff_key((array) $d->details, ['params' => true]),
            ]),
        ]);
    }

    public function updateParams(Request $request): RedirectResponse
    {
        $rules = [];
        foreach ($this->settings->bounds() as $name => $bound) {
            $rules[$name] = ['required', 'numeric', 'min:'.$bound['min'], 'max:'.$bound['max']];
        }
        $validated = $request->validate($rules);

        $change = $this->settings->saveParams($validated);
        AuditLog::log('stabilizer.params', null, $change['old'], $change['new']);
        Cache::forget(StabilizerService::PUBLIC_CACHE_KEY);

        return back()->with('success', 'บันทึกพารามิเตอร์ของกองทุนพยุงราคาแล้ว (มีผลรอบถัดไป และแสดงบนหน้า /stability ทันที)');
    }

    /**
     * เปิด/ปิดสวิตช์ — เปิดต้องพิมพ์ยืนยัน ENABLE และระบบต้องพร้อมครบทุกข้อ.
     */
    public function toggle(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'confirm' => ['nullable', 'string', 'max:20'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $enable = (bool) $validated['enabled'];
        $before = $this->settings->switchOn();

        if ($enable) {
            if (($validated['confirm'] ?? '') !== 'ENABLE') {
                return back()->withErrors(['confirm' => 'พิมพ์ ENABLE เพื่อยืนยันการเปิดกองทุนพยุงราคา']);
            }

            $readiness = $this->settings->readiness($this->dex);
            if (! $readiness['ready']) {
                $missing = array_column(array_filter($readiness['checks'], fn ($c) => ! $c['ok']), 'label');

                return back()->withErrors(['enabled' => 'ยังเปิดไม่ได้ — ขาด: '.implode(' · ', $missing)]);
            }
        }

        $this->settings->setSwitch($enable, $enable ? null : ($validated['reason'] ?? 'manual_pause'));
        AuditLog::log($enable ? 'stabilizer.enable' : 'stabilizer.pause', null, ['switch_on' => $before], [
            'switch_on' => $enable,
            'reason' => $validated['reason'] ?? null,
        ]);
        Cache::forget(StabilizerService::PUBLIC_CACHE_KEY);

        return back()->with('success', $enable
            ? 'เปิดกองทุนพยุงราคาแล้ว — เริ่มทำงานในรอบถัดไปของ scheduler (ทุก 5 นาที)'
            : 'หยุดกองทุนพยุงราคาแล้ว — ไม่มีการเซ็นธุรกรรมใหม่จนกว่าจะเปิดอีกครั้ง');
    }

    public function storeDeposit(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', Rule::in(['usdt', 'tpix'])],
            'tx_hash' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{64}$/'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->service->recordDeposit(
            $validated['token'],
            $validated['tx_hash'],
            $validated['note'] ?? null,
            $request->user('admin')?->id,
        );

        if (! $result['ok']) {
            return back()->withErrors(['tx_hash' => 'บันทึกเงินฝากไม่ได้: '.$result['error']]);
        }

        AuditLog::log('stabilizer.deposit', $result['deposit'], null, $result['deposit']->only(['token', 'amount_wei', 'tx_hash', 'from_address']));

        return back()->with('success', 'บันทึกเงินฝากจากเชนแล้ว');
    }

    /** ดูว่ากฎจะตัดสินใจอย่างไรตอนนี้ — ไม่บันทึก ไม่เซ็น */
    public function preview(): JsonResponse
    {
        try {
            return response()->json(['success' => true, 'data' => $this->service->preview()]);
        } catch (\Throwable $e) {
            Log::warning('stabilizer admin: preview failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => ['code' => 'PREVIEW_FAILED', 'message' => 'Could not read the chain right now'],
            ], 503);
        }
    }
}
