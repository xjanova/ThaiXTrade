<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GroqService;
use App\Services\MarketDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AIController extends Controller
{
    public function __construct(
        private GroqService $groqService,
        private MarketDataService $marketDataService,
    ) {}

    public function analyze(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'symbol' => ['required', 'string', 'max:20'],
            'type' => ['nullable', 'string', 'in:technical,sentiment,price_prediction,market_analysis'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Invalid parameters.'],
            ], 422);
        }

        $symbol = $request->input('symbol');
        $type = $request->input('type', 'technical');

        // Get real market data for context
        $marketData = $this->marketDataService->getTokenPrice($symbol);

        $result = $this->groqService->analyzeMarket($symbol, $type, $marketData ?? []);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'AI_ERROR', 'message' => $result['error'] ?? 'Analysis failed.'],
            ], 500);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'symbol' => $symbol,
                'type' => $type,
                'analysis' => $result['content'],
                'model' => $result['model'] ?? null,
                'processing_time_ms' => $result['processing_time_ms'] ?? 0,
            ],
        ]);
    }

    public function predict(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'symbol' => ['required', 'string', 'max:20'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Invalid parameters.'],
            ], 422);
        }

        $symbol = $request->input('symbol');
        $marketData = $this->marketDataService->getTokenPrice($symbol);

        $result = $this->groqService->analyzeMarket($symbol, 'price_prediction', $marketData ?? []);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'AI_ERROR', 'message' => $result['error'] ?? 'Prediction failed.'],
            ], 500);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'symbol' => $symbol,
                'prediction' => $result['content'],
                'disclaimer' => 'This is AI-generated analysis and should not be considered financial advice.',
            ],
        ]);
    }

    public function suggest(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'context' => ['required', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Invalid parameters.'],
            ], 422);
        }

        $context = $request->input('context');

        $result = $this->groqService->chat(
            "Based on the following trading context, provide trading suggestions:\n\n{$context}",
            'You are a professional crypto trading advisor for TPIX TRADE. Provide actionable suggestions with risk warnings. Never promise returns.',
        );

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'AI_ERROR', 'message' => $result['error'] ?? 'Suggestion failed.'],
            ], 500);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'suggestions' => $result['content'],
                'disclaimer' => 'This is AI-generated advice and should not be considered financial advice.',
            ],
        ]);
    }

    public function insights(string $symbol): JsonResponse
    {
        $marketData = $this->marketDataService->getTokenPrice($symbol);

        $result = $this->groqService->analyzeMarket($symbol, 'market_analysis', $marketData ?? []);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'AI_ERROR', 'message' => $result['error'] ?? 'Insights failed.'],
            ], 500);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'symbol' => $symbol,
                'insights' => $result['content'],
                'market_data' => $marketData,
            ],
        ]);
    }
}
