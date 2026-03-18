<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * TPIX TRADE — Public Banner API
 * ให้ frontend ดึง banners ตาม placement + นับ clicks
 * Developed by Xman Studio.
 */
class BannerController extends Controller
{
    /**
     * GET /api/v1/banners?placement=trade_top
     * ดึง banners ที่ active สำหรับ placement ที่ระบุ (cached 5 นาที)
     */
    public function index(Request $request): JsonResponse
    {
        $placement = $request->query('placement', 'all_pages_top');

        // Cache 5 นาทีต่อ placement
        $banners = Cache::remember("banners.{$placement}", 300, function () use ($placement) {
            return Banner::active()
                ->forPlacement($placement)
                ->scheduled()
                ->ordered()
                ->get(['id', 'title', 'type', 'image_url', 'link_url', 'target', 'ad_code', 'placement']);
        });

        return response()->json([
            'success' => true,
            'data' => $banners,
        ]);
    }

    /**
     * POST /api/v1/banners/{id}/click
     * นับ click สำหรับ banner
     */
    public function click(Banner $banner): JsonResponse
    {
        $banner->incrementClicks();

        return response()->json(['success' => true]);
    }
}
