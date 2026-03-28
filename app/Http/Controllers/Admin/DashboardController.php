<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Chain;
use App\Models\SiteSetting;
use App\Models\SupportTicket;
use App\Models\TradingPair;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * DashboardController.
 *
 * Serves the admin dashboard with aggregated platform statistics
 * including transaction volumes, active chains/pairs, and open tickets.
 */
class DashboardController extends Controller
{
    /**
     * Display the admin dashboard with platform statistics.
     */
    public function index(): InertiaResponse
    {
        try {
            $totalTransactions = Transaction::count();
            $totalVolume = Transaction::where('status', 'completed')
                ->sum('from_amount');
            $activeChains = Chain::where('is_active', true)->count();
            $activePairs = TradingPair::where('is_active', true)->count();
            $openTickets = SupportTicket::open()->count();
            $recentTransactions = Transaction::with('chain')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get();
        } catch (\Exception $e) {
            Log::warning('Dashboard core stats error', ['error' => $e->getMessage()]);
            $totalTransactions = 0;
            $totalVolume = 0;
            $activeChains = 0;
            $activePairs = 0;
            $openTickets = 0;
            $recentTransactions = collect();
        }

        // Fee & Trading stats — ใช้ raw DB query ที่ safe กับทุก MySQL mode
        $feeCollector = '';
        $totalFeeCollected = 0;
        $totalInternalTrades = 0;
        $totalInternalVolume = 0;
        $totalInternalFees = 0;
        $openOrders = 0;
        $volume24h = 0;
        $trades24h = 0;
        $swaps24h = 0;

        try {
            $feeCollector = SiteSetting::get('trading', 'fee_collector_wallet', '');
            $totalFeeCollected = Transaction::where('status', 'completed')
                ->where('fee_amount', '>', 0)
                ->sum('fee_amount');

            $since24h = now()->subHours(24);

            // ตรวจ table ด้วย raw query ที่ไม่ crash
            $hasTrades = DB::select("SHOW TABLES LIKE 'trades'");
            if (! empty($hasTrades)) {
                $totalInternalTrades = DB::table('trades')->count();
                $totalInternalVolume = (float) DB::table('trades')->sum('total');
                $makerFees = (float) DB::table('trades')->sum('maker_fee');
                $takerFees = (float) DB::table('trades')->sum('taker_fee');
                $totalInternalFees = $makerFees + $takerFees;
                $volume24h = (float) DB::table('trades')->where('created_at', '>=', $since24h)->sum('total');
                $trades24h = DB::table('trades')->where('created_at', '>=', $since24h)->count();
            }

            $hasOrders = DB::select("SHOW TABLES LIKE 'orders'");
            if (! empty($hasOrders)) {
                $openOrders = DB::table('orders')->whereIn('status', ['open', 'partially_filled'])->count();
            }

            $swaps24h = Transaction::whereIn('type', ['swap'])
                ->where('status', 'completed')
                ->where('created_at', '>=', $since24h)
                ->count();
        } catch (\Exception $e) {
            Log::warning('Dashboard trading stats error', ['error' => $e->getMessage()]);
        }

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'totalTransactions' => $totalTransactions,
                'totalVolume' => '$'.number_format((float) $totalVolume, 2),
                'activeChains' => $activeChains,
                'activePairs' => $activePairs,
                'openTickets' => $openTickets,
                'feeCollectorWallet' => $feeCollector,
                'feeCollectorConfigured' => ! empty($feeCollector),
                'totalFeeCollected' => number_format((float) $totalFeeCollected, 4),
                'totalInternalTrades' => $totalInternalTrades,
                'totalInternalVolume' => '$'.number_format((float) $totalInternalVolume, 2),
                'totalInternalFees' => number_format((float) $totalInternalFees, 4),
                'openOrders' => $openOrders,
                'volume24h' => '$'.number_format((float) $volume24h, 2),
                'trades24h' => $trades24h,
                'swaps24h' => $swaps24h,
            ],
            'recentTransactions' => $recentTransactions,
        ]);
    }
}
