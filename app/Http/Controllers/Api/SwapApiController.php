<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chain;
use App\Models\SiteSetting;
use App\Models\SwapConfig;
use App\Models\Token;
use App\Models\Transaction;
use App\Services\FeeCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * SwapApiController.
 *
 * Handles swap-related API endpoints including quotes, execution,
 * and route discovery for the TPIX TRADE DEX platform.
 */
class SwapApiController extends Controller
{
    public function __construct(
        private FeeCalculationService $feeCalculationService,
    ) {
    }

    // =========================================================================
    // Endpoints
    // =========================================================================

    /**
     * Get a swap quote with fee breakdown and estimated output.
     *
     * GET /api/v1/swap/quote
     */
    public function quote(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from_token' => 'required|string',
            'to_token' => 'required|string',
            'amount' => 'required|numeric|gt:0',
            'chain_id' => 'required|integer|exists:chains,id',
            'slippage' => 'nullable|numeric|min:0.01|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid request parameters.',
                    'details' => $validator->errors(),
                ],
            ], 422);
        }

        $validated = $validator->validated();

        try {
            // Verify chain is active
            $chain = Chain::active()->find($validated['chain_id']);
            if (! $chain) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INVALID_CHAIN',
                        'message' => 'The specified chain is not active or does not exist.',
                    ],
                ], 404);
            }

            // Verify tokens exist on this chain
            $fromToken = Token::active()
                ->where('chain_id', $validated['chain_id'])
                ->where('contract_address', $validated['from_token'])
                ->first();

            $toToken = Token::active()
                ->where('chain_id', $validated['chain_id'])
                ->where('contract_address', $validated['to_token'])
                ->first();

            if (! $fromToken || ! $toToken) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INVALID_TOKEN',
                        'message' => 'One or both tokens are not supported on this chain.',
                    ],
                ], 404);
            }

            // Get swap quote from the fee calculation service
            $quote = $this->feeCalculationService->getSwapQuote(
                (float) $validated['amount'],
                $validated['from_token'],
                $validated['to_token'],
                (int) $validated['chain_id'],
            );

            // Override slippage if provided by user
            if (isset($validated['slippage'])) {
                $slippage = (float) $validated['slippage'];
                $quote['slippage'] = $slippage;
                $quote['minimum_received'] = round(
                    $quote['to_amount_estimate'] * (1 - ($slippage / 100)),
                    8,
                );
            }

            // Get fee collector wallet address
            $feeCollectorWallet = SiteSetting::get('trading', 'fee_collector_wallet', '');

            return response()->json([
                'success' => true,
                'data' => [
                    'quote' => $quote,
                    'from_token' => [
                        'symbol' => $fromToken->symbol,
                        'name' => $fromToken->name,
                        'address' => $fromToken->contract_address,
                        'decimals' => $fromToken->decimals,
                    ],
                    'to_token' => [
                        'symbol' => $toToken->symbol,
                        'name' => $toToken->name,
                        'address' => $toToken->contract_address,
                        'decimals' => $toToken->decimals,
                    ],
                    'chain' => [
                        'id' => $chain->id,
                        'name' => $chain->name,
                    ],
                    'fee_collector' => $feeCollectorWallet,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Swap quote error', [
                'error' => $e->getMessage(),
                'params' => $validated,
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'QUOTE_ERROR',
                    'message' => 'Unable to generate swap quote. Please try again.',
                ],
            ], 500);
        }
    }

    /**
     * Record a completed swap transaction.
     *
     * POST /api/v1/swap/execute
     */
    public function execute(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from_token' => 'required|string',
            'to_token' => 'required|string',
            'from_amount' => 'required|numeric|gt:0',
            'to_amount' => 'required|numeric|gte:0',
            'fee_amount' => 'required|numeric|gte:0',
            'tx_hash' => 'required|string|unique:transactions,tx_hash',
            'chain_id' => 'required|integer|exists:chains,id',
            'wallet_address' => 'required|string|regex:/^0x[a-fA-F0-9]{40}$/',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid request parameters.',
                    'details' => $validator->errors(),
                ],
            ], 422);
        }

        $validated = $validator->validated();

        try {
            // Verify chain is active
            $chain = Chain::active()->find($validated['chain_id']);
            if (! $chain) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INVALID_CHAIN',
                        'message' => 'The specified chain is not active or does not exist.',
                    ],
                ], 404);
            }

            // Verify the fee amount is reasonable
            $expectedFee = $this->feeCalculationService->calculateSwapFee(
                (float) $validated['from_amount'],
                (int) $validated['chain_id'],
            );

            $submittedFee = (float) $validated['fee_amount'];
            $expectedFeeAmount = $expectedFee['fee_amount'];

            // Allow 1% tolerance for rounding differences
            if ($expectedFeeAmount > 0 && abs($submittedFee - $expectedFeeAmount) / $expectedFeeAmount > 0.01) {
                Log::warning('Swap fee mismatch', [
                    'submitted_fee' => $submittedFee,
                    'expected_fee' => $expectedFeeAmount,
                    'wallet' => $validated['wallet_address'],
                    'tx_hash' => $validated['tx_hash'],
                ]);
            }

            // Record the transaction
            $transaction = Transaction::create([
                'type' => 'swap',
                'wallet_address' => $validated['wallet_address'],
                'chain_id' => $validated['chain_id'],
                'from_token' => $validated['from_token'],
                'to_token' => $validated['to_token'],
                'from_amount' => $validated['from_amount'],
                'to_amount' => $validated['to_amount'],
                'fee_amount' => $validated['fee_amount'],
                'tx_hash' => $validated['tx_hash'],
                'status' => 'pending',
                'metadata' => [
                    'fee_rate' => $expectedFee['fee_rate'],
                    'fee_collector' => SiteSetting::get('trading', 'fee_collector_wallet', ''),
                ],
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'transaction_id' => $transaction->uuid,
                    'tx_hash' => $transaction->tx_hash,
                    'status' => $transaction->status,
                    'from_token' => $transaction->from_token,
                    'to_token' => $transaction->to_token,
                    'from_amount' => $transaction->from_amount,
                    'to_amount' => $transaction->to_amount,
                    'fee_amount' => $transaction->fee_amount,
                    'created_at' => $transaction->created_at->toIso8601String(),
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('Swap execution error', [
                'error' => $e->getMessage(),
                'params' => $validated,
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'EXECUTION_ERROR',
                    'message' => 'Unable to record swap transaction. Please try again.',
                ],
            ], 500);
        }
    }

    /**
     * Get available swap routes with router addresses.
     *
     * GET /api/v1/swap/routes
     */
    public function routes(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'chain_id' => 'nullable|integer|exists:chains,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid request parameters.',
                    'details' => $validator->errors(),
                ],
            ], 422);
        }

        try {
            $query = SwapConfig::active()->with('chain');

            if ($request->filled('chain_id')) {
                $query->where('chain_id', $request->input('chain_id'));
            }

            $configs = $query->get();

            $routes = $configs->map(function (SwapConfig $config) {
                $feeRate = $this->feeCalculationService->getEffectiveFeeRate(
                    'swap',
                    $config->chain_id,
                );

                return [
                    'id' => $config->id,
                    'name' => $config->name,
                    'protocol' => $config->protocol,
                    'router_address' => $config->router_address,
                    'factory_address' => $config->factory_address,
                    'slippage_tolerance' => (float) $config->slippage_tolerance,
                    'fee_rate' => $feeRate,
                    'chain' => [
                        'id' => $config->chain->id,
                        'name' => $config->chain->name,
                        'symbol' => $config->chain->symbol,
                    ],
                    'metadata' => $config->metadata,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $routes,
            ]);
        } catch (\Exception $e) {
            Log::error('Swap routes error', [
                'error' => $e->getMessage(),
                'chain_id' => $request->input('chain_id'),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'ROUTES_ERROR',
                    'message' => 'Unable to retrieve swap routes. Please try again.',
                ],
            ], 500);
        }
    }
}
