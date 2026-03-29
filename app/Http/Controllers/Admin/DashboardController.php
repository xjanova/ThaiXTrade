<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Chain;
use App\Models\FactoryToken;
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
 * Serves the admin dashboard with aggregated platform statistics,
 * unified revenue analytics, and daily revenue charts.
 *
 * Developed by Xman Studio
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

        // ─── Revenue Wallets (multi-token/chain) ───
        $tpixWallet = SiteSetting::get('revenue', 'tpix_wallet', '');
        if (empty($tpixWallet)) {
            $tpixWallet = SiteSetting::get('revenue', 'wallet_address', '');
        }
        if (empty($tpixWallet)) {
            $tpixWallet = SiteSetting::get('trading', 'fee_collector_wallet', '');
        }
        $revenueWallet = $tpixWallet; // ใช้ TPIX wallet เป็น primary

        // ─── Fee & Trading stats ───
        $totalFeeCollected = 0;
        $totalInternalTrades = 0;
        $totalInternalVolume = 0;
        $totalInternalFees = 0;
        $openOrders = 0;
        $volume24h = 0;
        $trades24h = 0;
        $swaps24h = 0;

        try {
            $totalFeeCollected = Transaction::where('status', 'completed')
                ->where('fee_amount', '>', 0)
                ->sum('fee_amount');

            $since24h = now()->subHours(24);

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

        // ─── Revenue Analytics — รายได้แยกตามแหล่ง ───
        $revenue = $this->getRevenueAnalytics();

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'totalTransactions' => $totalTransactions,
                'totalVolume' => '$'.number_format((float) $totalVolume, 2),
                'activeChains' => $activeChains,
                'activePairs' => $activePairs,
                'openTickets' => $openTickets,
                'feeCollectorWallet' => $revenueWallet,
                'feeCollectorConfigured' => ! empty($revenueWallet),
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
            'revenue' => $revenue,
        ]);
    }

    /**
     * คำนวณรายได้จากทุกแหล่ง + daily trend สำหรับกราฟ
     * แยกตาม token type (TPIX native / wTPIX wrapped) และ chain
     */
    private function getRevenueAnalytics(): array
    {
        // ─── Multi-wallet configuration ───
        $wallets = $this->getRevenueWallets();
        $walletConfigured = ! empty($wallets);

        // ─── Revenue by source ───
        $tradingFees = 0;
        $factoryFees = 0;
        $swapFees = 0;

        // แยกรายได้ตาม token type
        $revenueByToken = [
            'tpix' => ['trading' => 0, 'swap' => 0, 'factory' => 0, 'total' => 0],
            'wtpix' => ['trading' => 0, 'swap' => 0, 'factory' => 0, 'total' => 0],
        ];

        try {
            // Trading/Order book fees
            $hasTrades = DB::select("SHOW TABLES LIKE 'trades'");
            if (! empty($hasTrades)) {
                $tradingFees = (float) DB::table('trades')->sum(DB::raw('COALESCE(maker_fee, 0) + COALESCE(taker_fee, 0)'));

                // แยกตาม fee_currency ถ้ามี field — ไม่มีก็ default เป็น TPIX
                $hasFeeCurrency = DB::getSchemaBuilder()->hasColumn('trades', 'fee_currency');
                if ($hasFeeCurrency) {
                    $tpixTrading = (float) DB::table('trades')
                        ->where('fee_currency', 'TPIX')
                        ->sum(DB::raw('COALESCE(maker_fee, 0) + COALESCE(taker_fee, 0)'));
                    $wtpixTrading = (float) DB::table('trades')
                        ->where('fee_currency', 'wTPIX')
                        ->sum(DB::raw('COALESCE(maker_fee, 0) + COALESCE(taker_fee, 0)'));
                    $revenueByToken['tpix']['trading'] = $tpixTrading;
                    $revenueByToken['wtpix']['trading'] = $wtpixTrading;
                } else {
                    // ถ้าไม่มี fee_currency → ทั้งหมดเป็น TPIX
                    $revenueByToken['tpix']['trading'] = $tradingFees;
                }
            }

            // Swap fees จาก transactions
            $swapFees = (float) Transaction::where('status', 'completed')
                ->where('fee_amount', '>', 0)
                ->sum('fee_amount');

            // แยก swap fee ตาม fee_currency
            $hasFeeCurrencyTx = DB::getSchemaBuilder()->hasColumn('transactions', 'fee_currency');
            if ($hasFeeCurrencyTx) {
                $revenueByToken['tpix']['swap'] = (float) Transaction::where('status', 'completed')
                    ->where('fee_amount', '>', 0)
                    ->where('fee_currency', 'TPIX')
                    ->sum('fee_amount');
                $revenueByToken['wtpix']['swap'] = (float) Transaction::where('status', 'completed')
                    ->where('fee_amount', '>', 0)
                    ->where('fee_currency', 'wTPIX')
                    ->sum('fee_amount');
            } else {
                $revenueByToken['tpix']['swap'] = $swapFees;
            }

            // Token Factory fees — จาก metadata ของ deployed tokens
            $hasFactory = DB::select("SHOW TABLES LIKE 'factory_tokens'");
            if (! empty($hasFactory)) {
                $deployedTokens = FactoryToken::where('status', 'deployed')->get();
                foreach ($deployedTokens as $token) {
                    $meta = $token->metadata ?? [];
                    $feeAmount = (float) ($meta['fee_amount'] ?? 0);
                    $feeCurrency = $meta['fee_currency'] ?? 'TPIX';
                    $factoryFees += $feeAmount;

                    $tokenKey = strtolower($feeCurrency) === 'wtpix' ? 'wtpix' : 'tpix';
                    $revenueByToken[$tokenKey]['factory'] += $feeAmount;
                }
            }
        } catch (\Exception $e) {
            Log::warning('Revenue analytics error', ['error' => $e->getMessage()]);
        }

        // คำนวณ total ของแต่ละ token
        foreach ($revenueByToken as $tokenKey => &$data) {
            $data['total'] = round($data['trading'] + $data['swap'] + $data['factory'], 4);
        }
        unset($data);

        $totalRevenue = $tradingFees + $swapFees + $factoryFees;

        // ─── Revenue by source (สำหรับ donut chart) ───
        $sources = [];
        if ($tradingFees > 0) {
            $sources[] = [
                'name' => 'Trading Fees',
                'name_th' => 'ค่าธรรมเนียมเทรด',
                'amount' => round($tradingFees, 4),
                'color' => '#06b6d4', // cyan
                'percentage' => $totalRevenue > 0 ? round(($tradingFees / $totalRevenue) * 100, 1) : 0,
            ];
        }
        if ($swapFees > 0) {
            $sources[] = [
                'name' => 'Swap Fees',
                'name_th' => 'ค่าธรรมเนียม Swap',
                'amount' => round($swapFees, 4),
                'color' => '#8b5cf6', // violet
                'percentage' => $totalRevenue > 0 ? round(($swapFees / $totalRevenue) * 100, 1) : 0,
            ];
        }
        if ($factoryFees > 0) {
            $sources[] = [
                'name' => 'Token Factory',
                'name_th' => 'ค่าสร้างเหรียญ',
                'amount' => round($factoryFees, 4),
                'color' => '#f59e0b', // amber
                'percentage' => $totalRevenue > 0 ? round(($factoryFees / $totalRevenue) * 100, 1) : 0,
            ];
        }

        // ─── Revenue by token (สำหรับ donut chart ด้านขวา) ───
        $tokenBreakdown = [];
        if ($revenueByToken['tpix']['total'] > 0) {
            $tokenBreakdown[] = [
                'symbol' => 'TPIX',
                'name' => 'TPIX (Native)',
                'chain' => 'TPIX Chain',
                'chain_id' => 4289,
                'amount' => $revenueByToken['tpix']['total'],
                'color' => '#06b6d4', // cyan
                'percentage' => $totalRevenue > 0 ? round(($revenueByToken['tpix']['total'] / $totalRevenue) * 100, 1) : 0,
            ];
        }
        if ($revenueByToken['wtpix']['total'] > 0) {
            $tokenBreakdown[] = [
                'symbol' => 'wTPIX',
                'name' => 'wTPIX (Wrapped)',
                'chain' => 'BSC / Ethereum',
                'chain_id' => 56,
                'amount' => $revenueByToken['wtpix']['total'],
                'color' => '#f59e0b', // amber
                'percentage' => $totalRevenue > 0 ? round(($revenueByToken['wtpix']['total'] / $totalRevenue) * 100, 1) : 0,
            ];
        }

        // ─── Daily revenue trend (30 วัน) สำหรับ bar chart ───
        $dailyRevenue = $this->getDailyRevenueTrend(30);

        // ─── Token Factory stats ───
        $factoryStats = ['total_created' => 0, 'total_deployed' => 0, 'pending' => 0];
        try {
            $hasFactory = DB::select("SHOW TABLES LIKE 'factory_tokens'");
            if (! empty($hasFactory)) {
                $factoryStats = [
                    'total_created' => FactoryToken::count(),
                    'total_deployed' => FactoryToken::where('status', 'deployed')->count(),
                    'pending' => FactoryToken::where('status', 'pending')->count(),
                ];
            }
        } catch (\Exception $e) {
            // ignore
        }

        return [
            'wallets' => $wallets,
            'wallet_configured' => $walletConfigured,
            'total' => round($totalRevenue, 4),
            'trading_fees' => round($tradingFees, 4),
            'swap_fees' => round($swapFees, 4),
            'factory_fees' => round($factoryFees, 4),
            'sources' => $sources,
            'token_breakdown' => $tokenBreakdown,
            'revenue_by_token' => $revenueByToken,
            'daily' => $dailyRevenue,
            'factory_stats' => $factoryStats,
        ];
    }

    /**
     * ดึง revenue wallets ที่ตั้งค่าไว้ — แยกตาม token/chain
     *
     * รองรับ:
     * - TPIX (native) บน TPIX Chain (4289)
     * - wTPIX (wrapped) บน BSC (56) หรือ Ethereum (1)
     */
    private function getRevenueWallets(): array
    {
        $wallets = [];

        // TPIX Native wallet
        $tpixWallet = SiteSetting::get('revenue', 'tpix_wallet', '');
        if (empty($tpixWallet)) {
            // Fallback: legacy wallet_address → tpix_wallet
            $tpixWallet = SiteSetting::get('revenue', 'wallet_address', '');
        }
        if (empty($tpixWallet)) {
            // Fallback ต่อ: ใช้ trading fee wallet
            $tpixWallet = SiteSetting::get('trading', 'fee_collector_wallet', '');
        }

        if (! empty($tpixWallet)) {
            $wallets[] = [
                'symbol' => 'TPIX',
                'name' => 'TPIX (Native)',
                'chain' => 'TPIX Chain',
                'chain_id' => 4289,
                'address' => $tpixWallet,
                'color' => '#06b6d4',
                'icon' => 'tpix',
            ];
        }

        // wTPIX Wrapped wallet
        $wtpixWallet = SiteSetting::get('revenue', 'wtpix_wallet', '');
        $wtpixChainId = (int) SiteSetting::get('revenue', 'wtpix_chain_id', 56);
        $wtpixChainName = $this->getChainName($wtpixChainId);

        if (! empty($wtpixWallet)) {
            $wallets[] = [
                'symbol' => 'wTPIX',
                'name' => 'wTPIX (Wrapped)',
                'chain' => $wtpixChainName,
                'chain_id' => $wtpixChainId,
                'address' => $wtpixWallet,
                'color' => '#f59e0b',
                'icon' => 'wtpix',
            ];
        }

        return $wallets;
    }

    /**
     * ชื่อ chain จาก chain ID
     */
    private function getChainName(int $chainId): string
    {
        $chains = [
            1 => 'Ethereum',
            56 => 'BNB Smart Chain',
            137 => 'Polygon',
            43114 => 'Avalanche',
            4289 => 'TPIX Chain',
        ];

        return $chains[$chainId] ?? "Chain #{$chainId}";
    }

    /**
     * ดึง daily revenue สำหรับ bar chart (รวมทุกแหล่ง)
     */
    private function getDailyRevenueTrend(int $days): array
    {
        $daily = [];
        $startDate = now()->subDays($days - 1)->startOfDay();

        // Pre-fill ทุกวันด้วย 0
        for ($i = 0; $i < $days; $i++) {
            $date = $startDate->copy()->addDays($i)->format('Y-m-d');
            $daily[$date] = [
                'date' => $date,
                'label' => $startDate->copy()->addDays($i)->format('M d'),
                'trading' => 0,
                'swap' => 0,
                'factory' => 0,
                'total' => 0,
            ];
        }

        try {
            // Trading fees by day
            $hasTrades = DB::select("SHOW TABLES LIKE 'trades'");
            if (! empty($hasTrades)) {
                $tradingByDay = DB::table('trades')
                    ->selectRaw('DATE(created_at) as day, SUM(COALESCE(maker_fee, 0) + COALESCE(taker_fee, 0)) as fees')
                    ->where('created_at', '>=', $startDate)
                    ->groupByRaw('DATE(created_at)')
                    ->get();

                foreach ($tradingByDay as $row) {
                    if (isset($daily[$row->day])) {
                        $daily[$row->day]['trading'] = round((float) $row->fees, 4);
                        $daily[$row->day]['total'] += round((float) $row->fees, 4);
                    }
                }
            }

            // Swap fees by day
            $swapByDay = Transaction::selectRaw('DATE(created_at) as day, SUM(fee_amount) as fees')
                ->where('status', 'completed')
                ->where('fee_amount', '>', 0)
                ->where('created_at', '>=', $startDate)
                ->groupByRaw('DATE(created_at)')
                ->get();

            foreach ($swapByDay as $row) {
                if (isset($daily[$row->day])) {
                    $daily[$row->day]['swap'] = round((float) $row->fees, 4);
                    $daily[$row->day]['total'] += round((float) $row->fees, 4);
                }
            }

            // Factory fees by day (จาก deployed_at ใน metadata)
            $hasFactory = DB::select("SHOW TABLES LIKE 'factory_tokens'");
            if (! empty($hasFactory)) {
                $deployedTokens = FactoryToken::where('status', 'deployed')
                    ->where('created_at', '>=', $startDate)
                    ->get();

                foreach ($deployedTokens as $token) {
                    $day = $token->created_at->format('Y-m-d');
                    $fee = (float) ($token->metadata['fee_amount'] ?? 0);
                    if (isset($daily[$day]) && $fee > 0) {
                        $daily[$day]['factory'] += round($fee, 4);
                        $daily[$day]['total'] += round($fee, 4);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Daily revenue trend error', ['error' => $e->getMessage()]);
        }

        return array_values($daily);
    }
}
