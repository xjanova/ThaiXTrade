<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SaleTransaction;
use App\Models\SiteSetting;
use App\Models\TokenSale;
use App\Models\Transaction;
use App\Services\StripePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Stripe\Balance;
use Stripe\BalanceTransaction;
use Stripe\PaymentIntent;
use Stripe\Stripe;

/**
 * FinanceController — Admin Finance Dashboard.
 *
 * แสดงรายงานการเงินแบบเรียลไทม์:
 * - Stripe ยอดเงินคงเหลือ + ประวัติ payment
 * - Token Sale สรุปยอดขาย + refund
 * - Trading volume + fee revenue
 *
 * Developed by Xman Studio.
 */
class FinanceController extends Controller
{
    public function __construct(
        private StripePaymentService $stripe,
    ) {}

    /**
     * Finance dashboard page.
     */
    public function index(): InertiaResponse
    {
        return Inertia::render('Admin/Finance/Index', [
            'summary' => $this->getFinanceSummary(),
            'stripeBalance' => $this->getStripeBalance(),
            'recentPayments' => $this->getRecentStripePayments(),
            'salesSummary' => $this->getSalesSummary(),
            'dailyRevenue' => $this->getDailyRevenue(30),
            'stripeEnabled' => $this->stripe->isEnabled(),
        ]);
    }

    /**
     * API: Refresh Stripe balance (real-time polling).
     */
    public function stripeBalance(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->getStripeBalance(),
        ]);
    }

    /**
     * API: Get recent Stripe payments.
     */
    public function recentPayments(Request $request): JsonResponse
    {
        $limit = min((int) $request->input('limit', 20), 100);

        return response()->json([
            'success' => true,
            'data' => $this->getRecentStripePayments($limit),
        ]);
    }

    /**
     * API: Revenue chart data.
     */
    public function revenueChart(Request $request): JsonResponse
    {
        $days = min((int) $request->input('days', 30), 365);

        return response()->json([
            'success' => true,
            'data' => $this->getDailyRevenue($days),
        ]);
    }

    /**
     * API: Issue a Stripe refund from admin.
     */
    public function refund(Request $request): JsonResponse
    {
        $request->validate([
            'transaction_id' => ['required', 'integer'],
        ]);

        $transaction = SaleTransaction::findOrFail($request->input('transaction_id'));

        if ($transaction->status === 'refunded') {
            return response()->json([
                'success' => false,
                'error' => ['message' => 'Transaction already refunded.'],
            ], 422);
        }

        if (! str_starts_with($transaction->tx_hash, 'stripe_')) {
            return response()->json([
                'success' => false,
                'error' => ['message' => 'Can only refund Stripe payments.'],
            ], 422);
        }

        try {
            $result = $this->stripe->refundTransaction($transaction);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            Log::error('Admin refund failed', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => ['message' => 'Refund failed. Check Stripe dashboard.'],
            ], 500);
        }
    }

    // =========================================================================
    // Private Methods — Data Aggregation
    // =========================================================================

    private function getFinanceSummary(): array
    {
        return cache()->remember('admin:finance:summary', 60, function () {
            // Token Sale totals
            $totalRaisedUsd = TokenSale::sum('total_raised_usd');
            $totalTpixSold = TokenSale::sum('total_sold');

            // Stripe payments
            $stripePayments = SaleTransaction::where('payment_currency', 'USD_STRIPE')
                ->where('status', 'confirmed')
                ->count();
            $stripeRevenue = SaleTransaction::where('payment_currency', 'USD_STRIPE')
                ->where('status', 'confirmed')
                ->sum('payment_usd_value');

            // Refunds
            $totalRefunds = SaleTransaction::where('status', 'refunded')->count();
            $refundAmount = SaleTransaction::where('status', 'refunded')
                ->sum('payment_usd_value');

            // Disputes
            $totalDisputes = SaleTransaction::where('status', 'disputed')->count();

            // Trading fees (from transactions table)
            $tradingFeeRevenue = Transaction::whereIn('status', ['confirmed', 'completed'])
                ->sum('fee_amount');

            // Internal trades fees
            $internalTradeFees = DB::table('trades')
                ->selectRaw('COALESCE(SUM(maker_fee), 0) + COALESCE(SUM(taker_fee), 0) as total_fees')
                ->value('total_fees') ?? 0;

            return [
                'total_raised_usd' => round((float) $totalRaisedUsd, 2),
                'total_tpix_sold' => round((float) $totalTpixSold, 2),
                'stripe_payments' => $stripePayments,
                'stripe_revenue' => round((float) $stripeRevenue, 2),
                'total_refunds' => $totalRefunds,
                'refund_amount' => round((float) $refundAmount, 2),
                'total_disputes' => $totalDisputes,
                'trading_fee_revenue' => round((float) $tradingFeeRevenue, 2),
                'internal_trade_fees' => round((float) $internalTradeFees, 2),
                'net_revenue' => round((float) $stripeRevenue - (float) $refundAmount + (float) $tradingFeeRevenue + (float) $internalTradeFees, 2),
            ];
        });
    }

    private function getStripeBalance(): array
    {
        if (! $this->stripe->isEnabled()) {
            return ['available' => [], 'pending' => [], 'error' => 'Stripe disabled'];
        }

        try {
            $this->initStripe();
            $balance = Balance::retrieve();

            $available = collect($balance->available)->map(fn ($b) => [
                'currency' => strtoupper($b->currency),
                'amount' => $b->amount / 100,
            ])->toArray();

            $pending = collect($balance->pending)->map(fn ($b) => [
                'currency' => strtoupper($b->currency),
                'amount' => $b->amount / 100,
            ])->toArray();

            return [
                'available' => $available,
                'pending' => $pending,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'available' => [],
                'pending' => [],
                'error' => 'Failed to fetch balance. Check API keys.',
            ];
        }
    }

    private function getRecentStripePayments(int $limit = 20): array
    {
        return SaleTransaction::where('payment_currency', 'USD_STRIPE')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (SaleTransaction $tx) => [
                'id' => $tx->id,
                'uuid' => $tx->uuid,
                'wallet' => $tx->wallet_address,
                'amount_usd' => round((float) $tx->payment_usd_value, 2),
                'tpix_amount' => round((float) $tx->tpix_amount, 2),
                'price_per_tpix' => round((float) $tx->price_per_tpix, 4),
                'status' => $tx->status,
                'tx_hash' => $tx->tx_hash,
                'created_at' => $tx->created_at->toIso8601String(),
            ])
            ->toArray();
    }

    private function getSalesSummary(): array
    {
        $sales = TokenSale::with('phases')->get();

        return $sales->map(fn (TokenSale $sale) => [
            'id' => $sale->id,
            'name' => $sale->name ?? 'Token Sale',
            'total_sold' => round((float) $sale->total_sold, 2),
            'total_raised_usd' => round((float) $sale->total_raised_usd, 2),
            'phases' => $sale->phases->map(fn ($p) => [
                'name' => $p->name,
                'price_usd' => (float) $p->price_usd,
                'allocation' => (float) $p->allocation,
                'sold' => (float) $p->sold,
                'percent' => $p->allocation > 0 ? round(($p->sold / $p->allocation) * 100, 1) : 0,
                'is_active' => $p->is_active,
            ])->toArray(),
        ])->toArray();
    }

    private function getDailyRevenue(int $days): array
    {
        $since = now()->subDays($days)->startOfDay();

        $dailyStripe = SaleTransaction::where('payment_currency', 'USD_STRIPE')
            ->where('status', 'confirmed')
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as date, SUM(payment_usd_value) as revenue, COUNT(*) as tx_count')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $dailyRefunds = SaleTransaction::where('status', 'refunded')
            ->where('updated_at', '>=', $since)
            ->selectRaw('DATE(updated_at) as date, SUM(payment_usd_value) as refunded')
            ->groupByRaw('DATE(updated_at)')
            ->get()
            ->keyBy('date');

        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $stripe = $dailyStripe->get($date);
            $refund = $dailyRefunds->get($date);

            $result[] = [
                'date' => $date,
                'revenue' => round((float) ($stripe->revenue ?? 0), 2),
                'refunded' => round((float) ($refund->refunded ?? 0), 2),
                'tx_count' => (int) ($stripe->tx_count ?? 0),
                'net' => round((float) ($stripe->revenue ?? 0) - (float) ($refund->refunded ?? 0), 2),
            ];
        }

        return $result;
    }

    private function initStripe(): void
    {
        $secretKey = SiteSetting::get('stripe', 'stripe_secret_key')
            ?: config('services.stripe.secret');

        Stripe::setApiKey($secretKey);
    }
}
