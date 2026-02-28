<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Chain;
use App\Models\Token;
use App\Models\TradingPair;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * TradingPairController
 *
 * Manages trading pair configurations (e.g., ETH/USDT).
 * Provides chain and token lookups for pair creation forms.
 */
class TradingPairController extends Controller
{
    /**
     * Display a listing of trading pairs with related chains and tokens.
     */
    public function index(): InertiaResponse
    {
        $pairs = TradingPair::with(['baseToken', 'quoteToken', 'chain'])
            ->orderBy('sort_order')
            ->get();

        $chains = Chain::active()->ordered()->get();
        $tokens = Token::with('chain')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('Admin/TradingPairs/Index', [
            'pairs' => $pairs,
            'chains' => $chains,
            'tokens' => $tokens,
        ]);
    }

    /**
     * Store a newly created trading pair.
     */
    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'base_token_id' => ['required', 'integer', 'exists:tokens,id'],
            'quote_token_id' => ['required', 'integer', 'exists:tokens,id', 'different:base_token_id'],
            'chain_id' => ['required', 'integer', 'exists:chains,id'],
            'symbol' => ['required', 'string', 'max:50'],
            'is_active' => ['boolean'],
            'min_trade_amount' => ['nullable', 'numeric', 'min:0'],
            'max_trade_amount' => ['nullable', 'numeric', 'min:0', 'gt:min_trade_amount'],
            'price_precision' => ['required', 'integer', 'min:0', 'max:18'],
            'amount_precision' => ['required', 'integer', 'min:0', 'max:18'],
            'maker_fee_override' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'taker_fee_override' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        TradingPair::create($validated);

        return back()->with('success', 'Trading pair created successfully.');
    }

    /**
     * Update the specified trading pair.
     */
    public function update(Request $request, TradingPair $tradingPair): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'base_token_id' => ['required', 'integer', 'exists:tokens,id'],
            'quote_token_id' => ['required', 'integer', 'exists:tokens,id', 'different:base_token_id'],
            'chain_id' => ['required', 'integer', 'exists:chains,id'],
            'symbol' => ['required', 'string', 'max:50'],
            'is_active' => ['boolean'],
            'min_trade_amount' => ['nullable', 'numeric', 'min:0'],
            'max_trade_amount' => ['nullable', 'numeric', 'min:0', 'gt:min_trade_amount'],
            'price_precision' => ['required', 'integer', 'min:0', 'max:18'],
            'amount_precision' => ['required', 'integer', 'min:0', 'max:18'],
            'maker_fee_override' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'taker_fee_override' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $tradingPair->update($validated);

        return back()->with('success', 'Trading pair updated successfully.');
    }

    /**
     * Toggle the active status of a trading pair.
     */
    public function toggleActive(TradingPair $tradingPair): \Illuminate\Http\RedirectResponse
    {
        $tradingPair->update([
            'is_active' => ! $tradingPair->is_active,
        ]);

        $status = $tradingPair->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "Trading pair {$status} successfully.");
    }

    /**
     * Remove the specified trading pair.
     */
    public function destroy(TradingPair $tradingPair): \Illuminate\Http\RedirectResponse
    {
        $tradingPair->delete();

        return back()->with('success', 'Trading pair deleted successfully.');
    }
}
