<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SiteSetting;
use App\Models\TradingFeeTier;
use App\Models\TradingOrderTicket;
use App\Services\Trading\TradingFeeQuoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * TPIX TRADE — ตั้งค่าบริการวางไม้ในหลังบ้าน.
 *
 * แยกเป็นหน้าของตัวเองไม่ยัดรวมกับ /admin/settings เพราะขั้นบันไดเป็นรายการ
 * ที่เพิ่ม/ลบได้ ไม่ใช่ฟอร์มค่าคงที่ — และแอดมินต้องเห็นตารางทั้งหมดพร้อมกัน
 * ถึงจะรู้ว่าช่วงไหนขาดหรือทับกัน
 *
 * Developed by Xman Studio.
 */
class TradingFeeTierController extends Controller
{
    public function index(TradingFeeQuoteService $quotes): InertiaResponse
    {
        $tiers = TradingFeeTier::orderBy('min_order_usd')->orderBy('sort_order')->get();

        return Inertia::render('Admin/TradingFees/Index', [
            'tiers' => $tiers->map(fn (TradingFeeTier $tier) => [
                'id' => $tier->id,
                'label' => $tier->label,
                'min_order_usd' => (float) $tier->min_order_usd,
                'max_order_usd' => $tier->max_order_usd === null ? null : (float) $tier->max_order_usd,
                'fee_tpix' => (float) $tier->fee_tpix,
                'sort_order' => $tier->sort_order,
                'is_active' => $tier->is_active,
                'range' => $tier->rangeLabel(),
            ]),
            'settings' => [
                'tpix_fee_enabled' => $quotes->enabled(),
                'tpix_topup_wallet' => (string) SiteSetting::get('trading', 'tpix_topup_wallet', ''),
                'tpix_topup_chain_id' => (int) SiteSetting::get('trading', 'tpix_topup_chain_id', 4289),
                'tpix_min_topup' => (float) SiteSetting::get('trading', 'tpix_min_topup', 10),
                'refund_gas_fee' => (float) SiteSetting::get('trading', 'refund_gas_fee', 0),
                'ticket_ttl_minutes' => (int) SiteSetting::get('trading', 'ticket_ttl_minutes', 15),
            ],
            // ช่วงที่ไม่มีขั้นไหนครอบคลุม — ไม้ขนาดนั้นจะตกไปจ่ายแบบเดิมเงียบๆ
            'coverageGaps' => $this->coverageGaps($tiers),
            'stats' => [
                'issued' => TradingOrderTicket::issued()->count(),
                'consumed' => TradingOrderTicket::where('status', TradingOrderTicket::STATUS_CONSUMED)->count(),
                'refunded' => TradingOrderTicket::where('status', TradingOrderTicket::STATUS_REFUNDED)->count(),
            ],
        ]);
    }

    /**
     * ช่องโหว่ของขั้นบันได.
     *
     * แอดมินที่ตั้งขั้น 0-100 กับ 500-1000 จะไม่รู้เลยว่าไม้ขนาด 300 ไม่มีขั้นรองรับ
     * และตกไปจ่ายแบบเดิมทั้งหมดโดยไม่มีอะไรฟ้อง — ต้องบอกให้เห็นตั้งแต่หน้าตั้งค่า
     */
    private function coverageGaps($tiers): array
    {
        $active = $tiers->where('is_active', true)->sortBy('min_order_usd')->values();

        if ($active->isEmpty()) {
            return [['from' => 0, 'to' => null]];
        }

        $gaps = [];
        $cursor = 0.0;

        foreach ($active as $tier) {
            $min = (float) $tier->min_order_usd;

            if ($min > $cursor) {
                $gaps[] = ['from' => $cursor, 'to' => $min];
            }

            if ($tier->max_order_usd === null) {
                return $gaps;   // ขั้นบนสุดไม่มีเพดาน = ครอบคลุมถึงอนันต์
            }

            $cursor = max($cursor, (float) $tier->max_order_usd);
        }

        $gaps[] = ['from' => $cursor, 'to' => null];

        return $gaps;
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateTier($request);

        TradingFeeTier::create($validated);
        AuditLog::log('trading_fee_tier.create', null, null, $validated);

        return back()->with('success', 'เพิ่มขั้นค่าบริการแล้ว');
    }

    public function update(Request $request, TradingFeeTier $tier): RedirectResponse
    {
        $validated = $this->validateTier($request, $tier->id);
        $before = $tier->only(['min_order_usd', 'max_order_usd', 'fee_tpix', 'is_active']);

        $tier->update($validated);
        AuditLog::log('trading_fee_tier.update', null, $before, $validated);

        return back()->with('success', 'บันทึกขั้นค่าบริการแล้ว');
    }

    public function destroy(TradingFeeTier $tier): RedirectResponse
    {
        AuditLog::log('trading_fee_tier.delete', null, $tier->toArray(), null);
        $tier->delete();

        return back()->with('success', 'ลบขั้นค่าบริการแล้ว');
    }

    /** ค่าตั้งรวมของระบบค่าบริการ */
    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'tpix_fee_enabled' => ['required', 'boolean'],
            // กระเป๋ารับเงิน — ว่างได้ (= ยังไม่เปิดให้เติม) แต่ถ้าใส่ต้องเป็นที่อยู่จริง
            'tpix_topup_wallet' => ['nullable', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'tpix_topup_chain_id' => ['required', 'integer', 'min:1'],
            'tpix_min_topup' => ['required', 'numeric', 'gte:0'],
            'refund_gas_fee' => ['required', 'numeric', 'gte:0'],
            'ticket_ttl_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);

        SiteSetting::set('trading', 'tpix_fee_enabled', $validated['tpix_fee_enabled'] ? '1' : '0', 'boolean');
        SiteSetting::set('trading', 'tpix_topup_wallet', strtolower((string) ($validated['tpix_topup_wallet'] ?? '')));
        SiteSetting::set('trading', 'tpix_topup_chain_id', (string) $validated['tpix_topup_chain_id'], 'integer');
        SiteSetting::set('trading', 'tpix_min_topup', (string) $validated['tpix_min_topup']);
        SiteSetting::set('trading', 'refund_gas_fee', (string) $validated['refund_gas_fee']);
        SiteSetting::set('trading', 'ticket_ttl_minutes', (string) $validated['ticket_ttl_minutes'], 'integer');

        SiteSetting::clearCache();
        AuditLog::log('trading_fee.settings.update', null, null, $validated);

        return back()->with('success', 'บันทึกการตั้งค่าค่าบริการแล้ว');
    }

    private function validateTier(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'label' => ['nullable', 'string', 'max:60'],
            'min_order_usd' => ['required', 'numeric', 'gte:0'],
            // null = ขั้นบนสุดไม่มีเพดาน · ต้องมากกว่าขอบล่างเสมอ ไม่งั้นเป็นขั้นที่
            // ไม่มีวันถูกเลือก และแอดมินจะงงว่าตั้งแล้วทำไมไม่มีผล
            'max_order_usd' => ['nullable', 'numeric', 'gt:min_order_usd'],
            'fee_tpix' => ['required', 'numeric', 'gte:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}
