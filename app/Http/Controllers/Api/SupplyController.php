<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SupplyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * TPIX Supply endpoints — implements CoinGecko's plain-text spec.
 *
 * CoinGecko Standards:
 *   GET /api/v1/supply/total_supply         → "7000000000"       (text/plain)
 *   GET /api/v1/supply/circulating_supply   → "<computed>"       (text/plain)
 *   GET /api/v1/supply/max_supply           → "7000000000"       (text/plain)
 *
 * JSON spec for CoinMarketCap + our own UI:
 *   GET /api/v1/supply                      → full snapshot (JSON with breakdown)
 *
 * Developed by Xman Studio.
 */
class SupplyController extends Controller
{
    public function __construct(private readonly SupplyService $supply)
    {
    }

    /**
     * Plain-text total supply — CoinGecko spec.
     */
    public function total(): Response
    {
        return response($this->supply->snapshot()['total'], 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'public, max-age=60',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * Plain-text circulating supply — CoinGecko spec.
     * This is the #1 number CG/CMC use to compute market cap. Must be accurate.
     */
    public function circulating(): Response
    {
        return response($this->supply->snapshot()['circulating'], 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'public, max-age=60',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * Plain-text max supply — CoinGecko spec. Same as total for TPIX (no minting).
     */
    public function max(): Response
    {
        return response($this->supply->snapshot()['max'], 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'public, max-age=60',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * Full JSON snapshot — breakdown of every locked address and current balance.
     * Used by our DEX UI, auditors, and anyone verifying supply independently.
     */
    public function index(): JsonResponse
    {
        $snapshot = $this->supply->snapshot();

        return response()->json([
            'name' => 'TPIX',
            'symbol' => 'TPIX',
            'decimals' => (int) config('supply.decimals', 18),
            'total_supply' => $snapshot['total'],
            'circulating_supply' => $snapshot['circulating'],
            'max_supply' => $snapshot['max'],
            'locked_supply' => $snapshot['locked'],
            'computation_strategy' => $snapshot['strategy'],
            'rpc_source' => $snapshot['rpc'],
            'verification' => [
                'method' => 'circulating = total_supply - sum(balance of locked_addresses)',
                'reproducible' => true,
                'notes' => 'Anyone can verify circulating supply by querying eth_getBalance against each locked address and subtracting from total_supply. All locked addresses are listed in the breakdown below.',
            ],
            'locked_addresses' => $snapshot['breakdown'],
            'updated_at' => $snapshot['updated_at'],
        ], 200, [
            'Access-Control-Allow-Origin' => '*',
        ]);
    }
}
