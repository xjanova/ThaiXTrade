<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Chain;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * ChainController.
 *
 * Manages blockchain network configurations in the admin panel.
 * Supports CRUD operations, activation toggling, and soft-deletes.
 */
class ChainController extends Controller
{
    /**
     * Display a listing of blockchain chains.
     */
    public function index(): InertiaResponse
    {
        $chains = Chain::withCount('tokens', 'tradingPairs')
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('Admin/Chains/Index', [
            'chains' => $chains,
        ]);
    }

    /**
     * Store a newly created chain.
     */
    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'symbol' => ['required', 'string', 'max:20'],
            'chain_id_hex' => ['nullable', 'string', 'max:20'],
            'rpc_url' => ['required', 'url', 'max:500'],
            'explorer_url' => ['nullable', 'url', 'max:500'],
            'logo' => ['nullable', 'string', 'max:500'],
            'is_testnet' => ['boolean'],
            'is_active' => ['boolean'],
            'native_currency_name' => ['required', 'string', 'max:100'],
            'native_currency_symbol' => ['required', 'string', 'max:20'],
            'native_currency_decimals' => ['required', 'integer', 'min:1', 'max:36'],
            'block_confirmations' => ['required', 'integer', 'min:1', 'max:200'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        Chain::create($validated);

        return back()->with('success', 'Chain created successfully.');
    }

    /**
     * Update the specified chain.
     */
    public function update(Request $request, Chain $chain): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'symbol' => ['required', 'string', 'max:20'],
            'chain_id_hex' => ['nullable', 'string', 'max:20'],
            'rpc_url' => ['required', 'url', 'max:500'],
            'explorer_url' => ['nullable', 'url', 'max:500'],
            'logo' => ['nullable', 'string', 'max:500'],
            'is_testnet' => ['boolean'],
            'is_active' => ['boolean'],
            'native_currency_name' => ['required', 'string', 'max:100'],
            'native_currency_symbol' => ['required', 'string', 'max:20'],
            'native_currency_decimals' => ['required', 'integer', 'min:1', 'max:36'],
            'block_confirmations' => ['required', 'integer', 'min:1', 'max:200'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $chain->update($validated);

        return back()->with('success', 'Chain updated successfully.');
    }

    /**
     * Toggle the active status of a chain.
     */
    public function toggleActive(Chain $chain): \Illuminate\Http\RedirectResponse
    {
        $chain->update([
            'is_active' => ! $chain->is_active,
        ]);

        $status = $chain->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "Chain {$status} successfully.");
    }

    /**
     * Soft delete the specified chain.
     */
    public function destroy(Chain $chain): \Illuminate\Http\RedirectResponse
    {
        $chain->delete();

        return back()->with('success', 'Chain deleted successfully.');
    }
}
