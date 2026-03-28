<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Chain;
use App\Models\Order;
use App\Models\SiteSetting;
use App\Models\SupportTicket;
use App\Models\Trade;
use App\Models\TradingPair;
use App\Models\Transaction;
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

        // Fee & Trading stats
        $feeCollector = SiteSetting::get('trading', 'fee_collector_wallet', '');
        $totalFeeCollected = Transaction::where('status', 'completed')
            ->where('fee_amount', '>', 0)
            ->sum('fee_amount');
        $totalInternalTrades = Trade::count();
        $totalInternalVolume = Trade::sum('total');
        $totalInternalFees = Trade::selectRaw('COALESCE(SUM(maker_fee), 0) + COALESCE(SUM(taker_fee), 0) as total_fees')
            ->value('total_fees');
        $openOrders = Order::whereIn('status', ['open', 'partially_filled'])->count();

        // 24h stats
        $since24h = now()->subHours(24);
        $volume24h = Trade::where('created_at', '>=', $since24h)->sum('total');
        $trades24h = Trade::where('created_at', '>=', $since24h)->count();
        $swaps24h = Transaction::whereIn('type', ['swap'])
            ->where('status', 'completed')
            ->where('created_at', '>=', $since24h)
            ->count();

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
