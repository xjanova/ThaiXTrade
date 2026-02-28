<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Chain;
use App\Models\SupportTicket;
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

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'total_transactions' => $totalTransactions,
                'total_volume' => $totalVolume,
                'active_chains' => $activeChains,
                'active_pairs' => $activePairs,
                'open_tickets' => $openTickets,
            ],
            'recent_transactions' => $recentTransactions,
        ]);
    }
}
