<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Chain;
use App\Models\SwapConfig;
use App\Rules\EvmAddress;
use App\Support\MoneySettings;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    /** ช่องที่กำหนดว่าเงินของผู้ใช้วิ่งผ่านสัญญาไหน — ต้องตอบได้ว่าใครเปลี่ยนจากอะไรเป็นอะไร */
    private const AUDITED = ['id', 'chain_id', 'router_address', 'factory_address', 'protocol', 'slippage_tolerance', 'is_active'];

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
            'configs' => $swapConfigs,
            'chains' => $chains,
            'canManageMoney' => MoneySettings::canManage(),
        ]);
    }

    /**
     * Store a newly created swap configuration.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'chain_id' => ['required', 'integer', 'exists:chains,id'],
            'router_address' => ['required', 'string', new EvmAddress],
            'factory_address' => ['nullable', 'string', new EvmAddress],
            'protocol' => ['required', 'string', 'in:uniswap_v2,uniswap_v3,pancakeswap,sushiswap,custom'],
            'name' => ['required', 'string', 'max:255'],
            'slippage_tolerance' => ['required', 'numeric', 'min:0.01', 'max:50'],
            'is_active' => ['boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        DB::transaction(function () use ($validated): void {
            $swap = SwapConfig::create($validated);

            MoneySettings::record('swap_config.create', [], $swap->only(self::AUDITED));
        });

        return back()->with('success', 'Swap configuration created successfully.');
    }

    /**
     * Update the specified swap configuration.
     */
    public function update(Request $request, SwapConfig $swap): RedirectResponse
    {
        $validated = $request->validate([
            'chain_id' => ['required', 'integer', 'exists:chains,id'],
            'router_address' => ['required', 'string', new EvmAddress],
            'factory_address' => ['nullable', 'string', new EvmAddress],
            'protocol' => ['required', 'string', 'in:uniswap_v2,uniswap_v3,pancakeswap,sushiswap,custom'],
            'name' => ['required', 'string', 'max:255'],
            'slippage_tolerance' => ['required', 'numeric', 'min:0.01', 'max:50'],
            'is_active' => ['boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        $this->change($swap, 'swap_config.update', fn (SwapConfig $locked) => $locked->update($validated));

        return back()->with('success', 'Swap configuration updated successfully.');
    }

    /**
     * Toggle the active status of a swap configuration.
     */
    public function toggleActive(SwapConfig $swap): RedirectResponse
    {
        $this->change($swap, 'swap_config.toggle', fn (SwapConfig $locked) => $locked->update([
            'is_active' => ! $locked->is_active,
        ]));

        $status = $swap->refresh()->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "Swap configuration {$status} successfully.");
    }

    /**
     * Remove the specified swap configuration.
     */
    public function destroy(SwapConfig $swap): RedirectResponse
    {
        $this->change($swap, 'swap_config.delete', fn (SwapConfig $locked) => $locked->delete());

        return back()->with('success', 'Swap configuration deleted successfully.');
    }

    /** เขียนแถวกับบันทึกในธุรกรรมเดียวกัน — บันทึกไม่สำเร็จเท่ากับเราเตอร์ไม่เปลี่ยน */
    private function change(SwapConfig $swap, string $action, Closure $write): void
    {
        DB::transaction(function () use ($swap, $action, $write): void {
            $locked = SwapConfig::lockForUpdate()->findOrFail($swap->id);
            $old = $locked->only(self::AUDITED);

            $write($locked);

            MoneySettings::record($action, $old, $locked->exists ? $locked->only(self::AUDITED) : []);
        });
    }
}
