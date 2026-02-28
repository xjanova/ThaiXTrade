<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeeConfig;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * FeeController
 *
 * CRUD operations for platform fee configurations.
 * Supports maker/taker fee models with per-chain overrides.
 */
class FeeController extends Controller
{
    /**
     * Display a listing of fee configurations.
     */
    public function index(): InertiaResponse
    {
        $fees = FeeConfig::with('chain')
            ->orderBy('type')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Admin/Fees/Index', [
            'fees' => $fees,
        ]);
    }

    /**
     * Store a newly created fee configuration.
     */
    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:trading,swap,withdrawal,deposit'],
            'maker_fee' => ['required', 'numeric', 'min:0', 'max:100'],
            'taker_fee' => ['required', 'numeric', 'min:0', 'max:100'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0', 'gt:min_amount'],
            'chain_id' => ['nullable', 'integer', 'exists:chains,id'],
            'is_active' => ['boolean'],
        ]);

        FeeConfig::create($validated);

        return back()->with('success', 'Fee configuration created successfully.');
    }

    /**
     * Update the specified fee configuration.
     */
    public function update(Request $request, FeeConfig $fee): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:trading,swap,withdrawal,deposit'],
            'maker_fee' => ['required', 'numeric', 'min:0', 'max:100'],
            'taker_fee' => ['required', 'numeric', 'min:0', 'max:100'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0', 'gt:min_amount'],
            'chain_id' => ['nullable', 'integer', 'exists:chains,id'],
            'is_active' => ['boolean'],
        ]);

        $fee->update($validated);

        return back()->with('success', 'Fee configuration updated successfully.');
    }

    /**
     * Remove the specified fee configuration.
     */
    public function destroy(FeeConfig $fee): \Illuminate\Http\RedirectResponse
    {
        $fee->delete();

        return back()->with('success', 'Fee configuration deleted successfully.');
    }
}
