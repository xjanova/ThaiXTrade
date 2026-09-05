<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chain;
use App\Models\Token;
use App\Services\ChainResolver;
use App\Services\Web3BalanceService;
use Illuminate\Http\JsonResponse;

/**
 * ChainController — รายการเชนที่ระบบรองรับ (สาธารณะ).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ★ อ่านจากฐานข้อมูล ไม่ใช่ config/chains.php อีกต่อไป
 * ═══════════════════════════════════════════════════════════════════════════
 * เดิมตัวนี้อ่าน config('chains.chains') ซึ่งเป็นไฟล์ PHP ในโค้ด ผลคือทุกอย่าง
 * ที่แอดมินกดแก้ในหน้า /admin/chains (เปิด/ปิดเชน, เปลี่ยน RPC, อัปโหลดไอคอน,
 * จัดลำดับ) ไม่มีผลกับเว็บและแอปมือถือเลยแม้แต่นิดเดียว — หน้าหลังบ้านทั้งหน้า
 * เป็นแค่ของประดับ
 *
 * ตอนนี้แหล่งความจริงคือตาราง chains ส่วน config/chains.php เหลือหน้าที่เป็น
 * "ค่าตั้งต้น" ที่ migration คัดลอกเข้าฐานข้อมูลไปแล้วครั้งเดียว
 *
 * รูปแบบ JSON ที่ส่งออกยังเหมือนเดิมทุกคีย์ (ดู Chain::toApiArray) เพราะมีคนอ่านอยู่
 * ทั้งเว็บ แอปมือถือ และตัวเพิ่มเครือข่ายเข้ากระเป๋า
 *
 * Developed by Xman Studio.
 */
class ChainController extends Controller
{
    public function __construct(
        private Web3BalanceService $balanceService,
        private ChainResolver $chains,
    ) {}

    /**
     * รายการเชนทั้งหมดที่เปิดใช้งาน.
     */
    public function index(): JsonResponse
    {
        $chains = Chain::query()
            ->where('is_active', true)
            ->ordered()
            ->get()
            ->map(fn (Chain $chain) => $chain->toApiArray())
            ->values();

        return response()->json([
            'success' => true,
            'data' => $chains,
            /*
             * เชนที่ผู้ใช้ควรอยู่เมื่อเปิดมาเฉย ๆ — เว็บและแอปต้องอ่านค่านี้
             * ห้าม hardcode 56 ฝั่ง client อีก (เจ้าของสั่งให้ปริยายเป็น TPIX เมื่อเชนเราพร้อม)
             */
            'meta' => [
                'default_chain_id' => $this->chains->defaultChainId(),
            ],
        ]);
    }

    /**
     * ข้อมูลเชนตัวเดียว.
     */
    public function show(int $chainId): JsonResponse
    {
        $chain = $this->chains->resolve($chainId);

        if (! $chain) {
            return $this->notFound($chainId);
        }

        return response()->json([
            'success' => true,
            'data' => $chain->toApiArray(),
        ]);
    }

    /**
     * โทเคนบนเชนนั้น.
     *
     * ★ เดิมอ่านคีย์ 'tokens' จาก config ซึ่งไม่มีเชนไหนมีเลยสักเชน
     *   จึงคืน [] เสมอพร้อม success:true — ผู้เรียกแยกไม่ออกระหว่าง
     *   "เชนนี้ไม่มีโทเคน" กับ "ปลายทางนี้ไม่เคยถูกต่อสาย"
     *   ทั้งที่รายการจริงอยู่ในตาราง tokens และแก้ได้จาก /admin/tokens อยู่แล้ว
     */
    public function tokens(int $chainId): JsonResponse
    {
        $chain = $this->chains->resolve($chainId);

        if (! $chain) {
            return $this->notFound($chainId);
        }

        $tokens = Token::query()
            ->where('chain_id', $chain->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Token $token) => [
                'symbol' => $token->symbol,
                'name' => $token->name,
                'address' => $token->contract_address,
                'decimals' => (int) $token->decimals,
                'logo' => $token->logo_url,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => $tokens,
        ]);
    }

    /**
     * ราคาแก๊สปัจจุบันจาก RPC ของเชนนั้น.
     */
    public function gasPrice(int $chainId): JsonResponse
    {
        $chain = $this->chains->resolve($chainId);

        if (! $chain) {
            return $this->notFound($chainId);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'chainId' => $chainId,
                'gasPrice' => $this->balanceService->getGasPrice($chainId),
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    private function notFound(int $chainId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'CHAIN_NOT_FOUND',
                'message' => "Chain with ID {$chainId} not found",
            ],
        ], 404);
    }
}
