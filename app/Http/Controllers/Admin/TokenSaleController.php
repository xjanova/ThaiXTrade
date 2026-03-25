<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SalePhase;
use App\Models\SaleTransaction;
use App\Models\TokenSale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin TokenSaleController — จัดการระบบขายเหรียญ TPIX และ Token Control.
 *
 * ฟีเจอร์:
 * - ดู overview: total supply, sold, raised, master wallet balance
 * - CRUD sale phases (price, allocation, vesting)
 * - ดู transactions ทั้งหมด (search by wallet)
 * - ดู master wallet info (balance, address)
 */
class TokenSaleController extends Controller
{
    /**
     * หน้าหลัก Token Sales + Control — ส่งข้อมูลทั้งหมดไป Vue.
     */
    public function index(): Response
    {
        $sales = TokenSale::with(['phases' => function ($q) {
            $q->orderBy('phase_order');
        }])->latest()->get();

        $transactions = SaleTransaction::with(['tokenSale', 'salePhase'])
            ->latest()
            ->paginate(20);

        // ดึง master wallet balance จาก TPIX Chain RPC
        $walletInfo = $this->getMasterWalletInfo();

        // สถิติรวม
        $stats = [
            'total_supply' => 7_000_000_000,
            'total_sold' => TokenSale::sum('total_sold'),
            'total_raised_usd' => TokenSale::sum('total_raised_usd'),
            'total_buyers' => SaleTransaction::where('status', 'confirmed')
                ->distinct('wallet_address')->count('wallet_address'),
            'total_transactions' => SaleTransaction::count(),
            'pending_transactions' => SaleTransaction::where('status', 'pending')->count(),
        ];

        return Inertia::render('Admin/TokenSales/Index', [
            'sales' => $sales,
            'transactions' => $transactions,
            'walletInfo' => $walletInfo,
            'stats' => $stats,
        ]);
    }

    /**
     * สร้าง/อัปเดต Token Sale.
     */
    public function store(Request $request)
    {
        $status = $request->input('status', 'draft');
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'total_supply_for_sale' => 'required|numeric|min:0',
            'accept_currencies' => 'nullable|array',
            'sale_wallet_address' => [
                $status === 'active' ? 'required' : 'nullable',
                'string',
                'regex:/^0x[a-fA-F0-9]{40}$/',
            ],
            'status' => 'required|string|in:draft,upcoming,active,paused,completed',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
        ]);

        $id = $request->input('id');
        if ($id) {
            $sale = TokenSale::findOrFail($id);
            $sale->update($validated);
        } else {
            $sale = TokenSale::create($validated);
        }

        return redirect()->back()->with('success', 'Token Sale saved!');
    }

    /**
     * สร้าง/อัปเดต Sale Phase.
     */
    public function updatePhase(Request $request)
    {
        $validated = $request->validate([
            'token_sale_id' => 'required|exists:token_sales,id',
            'name' => 'required|string|max:255',
            'phase_order' => 'required|integer|min:1',
            'price_usd' => 'required|numeric|min:0.001',
            'allocation' => 'required|numeric|min:0',
            'min_purchase' => 'nullable|numeric|min:0',
            'max_purchase' => 'nullable|numeric|min:0',
            'vesting_tge_percent' => 'nullable|numeric|min:0|max:100',
            'vesting_cliff_days' => 'nullable|integer|min:0',
            'vesting_duration_days' => 'nullable|integer|min:0',
            'status' => 'required|string|in:upcoming,active,completed,cancelled',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date',
        ]);

        $id = $request->input('id');
        if ($id) {
            $phase = SalePhase::findOrFail($id);

            // ล็อคราคาถ้ามี transaction แล้ว (ป้องกันแก้ราคาหลังขาย)
            if ($phase->sold > 0 && isset($validated['price_usd']) && (float) $validated['price_usd'] !== (float) $phase->price_usd) {
                return redirect()->back()->with('error', 'Cannot change price — this phase already has '.$phase->sold.' TPIX sold. Create a new phase instead.');
            }

            $phase->update($validated);
        } else {
            // min_purchase ต้องมากกว่า 0
            if (isset($validated['min_purchase']) && (float) $validated['min_purchase'] <= 0) {
                $validated['min_purchase'] = null;
            }

            SalePhase::create($validated);
        }

        return redirect()->back()->with('success', 'Phase saved!');
    }

    /**
     * ดู master wallet info จาก TPIX Chain RPC.
     */
    private function getMasterWalletInfo(): array
    {
        $address = config('services.tpix_chain.master_wallet', '');
        $rpcUrl = config('services.tpix_chain.rpc_url', 'https://rpc.tpix.online');

        if (empty($address)) {
            return [
                'address' => 'Not configured',
                'balance' => '0',
                'balance_formatted' => '0 TPIX',
                'chain_id' => 4289,
                'rpc_url' => $rpcUrl,
                'status' => 'not_configured',
            ];
        }

        try {
            // เรียก eth_getBalance จาก RPC
            $response = Http::timeout(5)->post($rpcUrl, [
                'jsonrpc' => '2.0',
                'method' => 'eth_getBalance',
                'params' => [$address, 'latest'],
                'id' => 1,
            ]);

            $balanceHex = $response->json('result', '0x0');
            // Use GMP for arbitrary precision to avoid float overflow on large balances
            $hex = str_starts_with($balanceHex, '0x') ? substr($balanceHex, 2) : $balanceHex;
            $balanceWei = gmp_init($hex ?: '0', 16);
            $balanceTpix = (float) bcdiv(gmp_strval($balanceWei), '1000000000000000000', 18);

            return [
                'address' => $address,
                'balance' => number_format($balanceTpix, 4, '.', ''),
                'balance_formatted' => number_format($balanceTpix, 2).' TPIX',
                'chain_id' => 4289,
                'rpc_url' => $rpcUrl,
                'status' => 'connected',
            ];
        } catch (\Exception $e) {
            return [
                'address' => $address,
                'balance' => '0',
                'balance_formatted' => 'RPC Error',
                'chain_id' => 4289,
                'rpc_url' => $rpcUrl,
                'status' => 'rpc_error',
                'error' => $e->getMessage(),
            ];
        }
    }
}
