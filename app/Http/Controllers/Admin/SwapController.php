<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Chain;
use App\Models\SwapConfig;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * SwapController.
 *
 * Manages DEX swap router configurations per blockchain.
 * Each config ties a swap protocol (UniswapV2, PancakeSwap, etc.) to a chain.
 */
class SwapController extends Controller
{
    /**
     * Display a listing of swap configurations with their chains.
     */
    public function index(): InertiaResponse
    {
        $swapConfigs = SwapConfig::with('chain')
            ->orderBy('chain_id')
            ->orderBy('name')
            ->get();

        $chains = Chain::active()->ordered()->get();

        return Inertia::render('Admin/Swap/Index', [
            'swapConfigs' => $swapConfigs,
            'chains' => $chains,
        ]);
    }

    /**
     * Store a newly created swap configuration.
     */
    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'chain_id' => ['required', 'integer', 'exists:chains,id'],
            'router_address' => ['required', 'string', 'max:255'],
            'factory_address' => ['nullable', 'string', 'max:255'],
            'protocol' => ['required', 'string', 'in:uniswap_v2,uniswap_v3,pancakeswap,sushiswap,custom'],
            'name' => ['required', 'string', 'max:255'],
            'slippage_tolerance' => ['required', 'numeric', 'min:0.01', 'max:50'],
            'is_active' => ['boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        SwapConfig::create($validated);

        return back()->with('success', 'Swap configuration created successfully.');
    }

    /**
     * Update the specified swap configuration.
     */
    public function update(Request $request, SwapConfig $swap): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'chain_id' => ['required', 'integer', 'exists:chains,id'],
            'router_address' => ['required', 'string', 'max:255'],
            'factory_address' => ['nullable', 'string', 'max:255'],
            'protocol' => ['required', 'string', 'in:uniswap_v2,uniswap_v3,pancakeswap,sushiswap,custom'],
            'name' => ['required', 'string', 'max:255'],
            'slippage_tolerance' => ['required', 'numeric', 'min:0.01', 'max:50'],
            'is_active' => ['boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        $swap->update($validated);

        return back()->with('success', 'Swap configuration updated successfully.');
    }

    /**
     * Toggle the active status of a swap configuration.
     */
    public function toggleActive(SwapConfig $swap): \Illuminate\Http\RedirectResponse
    {
        $swap->update([
            'is_active' => ! $swap->is_active,
        ]);

        $status = $swap->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "Swap configuration {$status} successfully.");
    }

    /**
     * Remove the specified swap configuration.
     */
    public function destroy(SwapConfig $swap): \Illuminate\Http\RedirectResponse
    {
        $swap->delete();

        return back()->with('success', 'Swap configuration deleted successfully.');
    }
}
