<?php

namespace App\Http\Controllers;

use App\Models\LaunchpadSale;
use App\Services\Bridge\BridgeConfig;
use App\Services\Launchpad\LaunchpadDepositMatcher;
use Inertia\Inertia;
use Inertia\Response;

/**
 * หน้า Launchpad — /launchpad (รวมทุกหน้าขาย) และ /launchpad/{symbol} (หน้าขายของเหรียญหนึ่ง).
 *
 * เฉพาะหน้าขายที่แอดมินอนุมัติแล้วเท่านั้น — pending/hidden/rejected = 404 (ไม่บอกว่ามีอยู่)
 * ตัวเลขทั้งหมดมาจากเชน (ลงทะเบียนด้วย launchpad:register) ป้ายความเสี่ยงหน้าเว็บอ่านสดผ่าน /api/v1/launchpad/sales/{id}/risk
 *
 * Developed by Xman Studio.
 */
class LaunchpadPageController extends Controller
{
    public function __construct(
        private BridgeConfig $bridge,
        private LaunchpadDepositMatcher $matcher,
    ) {}

    public function index(): Response
    {
        $sales = LaunchpadSale::where('status', LaunchpadSale::STATUS_APPROVED)
            ->orderByDesc('starts_at')
            ->limit(100)
            ->get()
            ->map->toPublicArray()
            ->values();

        return Inertia::render('Launchpad/Index', [
            'sales' => $sales,
            'launchpad' => $this->settings(),
        ]);
    }

    public function show(string $symbol): Response
    {
        // สัญลักษณ์ของเหรียญที่อนุมัติแล้วซ้ำกันไม่ได้ (LaunchpadRegistrar::approve) — เลือกตัวล่าสุดเผื่อข้อมูลเก่า
        abort_unless(preg_match('/^[A-Za-z0-9._-]{1,32}$/', $symbol) === 1, 404);

        $sale = LaunchpadSale::where('status', LaunchpadSale::STATUS_APPROVED)
            ->whereRaw('LOWER(token_symbol) = ?', [mb_strtolower($symbol)])
            ->orderByDesc('id')
            ->first();

        abort_unless($sale !== null, 404);

        return Inertia::render('Launchpad/Show', [
            'sale' => $sale->toPublicArray(),
            'launchpad' => $this->settings(),
        ]);
    }

    /** ค่าที่หน้าเว็บต้องใช้ก่อนเรียก prepare — เชนที่จ่ายได้ (USDT ตัวไหน) และเวลาของคำสั่งซื้อ */
    private function settings(): array
    {
        $chains = [];
        foreach ((array) config('launchpad.source_chains', []) as $chainId) {
            $chainId = (int) $chainId;
            $chain = $this->bridge->chain($chainId);
            if ($chain === null) {
                continue;
            }
            $chains[] = [
                'chain_id' => $chainId,
                'name' => $chain['name'],
                'enabled' => $this->matcher->acceptsChain($chainId),
                'usdt_address' => strtolower((string) $chain['usdt']['address']),
                'usdt_decimals' => (int) $chain['usdt']['decimals'],
                'explorer' => $this->bridge->explorer($chainId),
            ];
        }

        return [
            'enabled' => (bool) config('launchpad.enabled'),
            'chains' => $chains,
            'order_ttl_minutes' => (int) config('launchpad.order_ttl_minutes', 30),
            'tpix_chain_id' => $this->bridge->tpixChainId(),
            'tpix_explorer' => $this->bridge->explorer($this->bridge->tpixChainId()),
        ];
    }
}
