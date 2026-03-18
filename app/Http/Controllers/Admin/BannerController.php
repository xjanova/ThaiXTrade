<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TPIX TRADE — Admin Banner Controller
 * จัดการป้ายโฆษณา: CRUD + toggle + สถิติ
 * รองรับ 3 ประเภท: รูปภาพ, Google AdSense, Custom HTML
 * Developed by Xman Studio.
 */
class BannerController extends Controller
{
    /**
     * แสดงรายการ banners ทั้งหมด.
     */
    public function index(): Response
    {
        $banners = Banner::orderBy('sort_order')
            ->orderByDesc('id')
            ->get();

        // สถิติรวม
        $stats = [
            'total' => Banner::count(),
            'active' => Banner::where('is_active', true)->count(),
            'total_views' => Banner::sum('view_count'),
            'total_clicks' => Banner::sum('click_count'),
        ];

        return Inertia::render('Admin/Banners/Index', [
            'banners' => $banners,
            'stats' => $stats,
            'types' => Banner::TYPES,
            'placements' => Banner::PLACEMENTS,
        ]);
    }

    /**
     * สร้าง banner ใหม่.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|in:image,google_adsense,html',
            'image_url' => 'nullable|string|max:500',
            'link_url' => 'nullable|url|max:500',
            'target' => 'required|in:_blank,_self',
            'ad_code' => 'nullable|string|max:5000',
            'placement' => 'required|in:'.implode(',', array_keys(Banner::PLACEMENTS)),
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date|after_or_equal:start_at',
        ]);

        Banner::create($validated);

        return back()->with('success', 'สร้าง banner สำเร็จ');
    }

    /**
     * อัปเดต banner.
     */
    public function update(Request $request, Banner $banner): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|in:image,google_adsense,html',
            'image_url' => 'nullable|string|max:500',
            'link_url' => 'nullable|url|max:500',
            'target' => 'required|in:_blank,_self',
            'ad_code' => 'nullable|string|max:5000',
            'placement' => 'required|in:'.implode(',', array_keys(Banner::PLACEMENTS)),
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date|after_or_equal:start_at',
        ]);

        $banner->update($validated);

        return back()->with('success', 'อัปเดต banner สำเร็จ');
    }

    /**
     * ลบ banner (soft delete).
     */
    public function destroy(Banner $banner): RedirectResponse
    {
        $banner->delete();

        return back()->with('success', 'ลบ banner สำเร็จ');
    }

    /**
     * เปิด/ปิด banner.
     */
    public function toggleActive(Banner $banner): RedirectResponse
    {
        $banner->update(['is_active' => ! $banner->is_active]);

        return back()->with('success', ($banner->is_active ? 'เปิด' : 'ปิด').' banner สำเร็จ');
    }
}
