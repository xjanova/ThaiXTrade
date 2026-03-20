<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CarbonCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CarbonCreditApiController extends Controller
{
    public function __construct(
        private CarbonCreditService $carbonCreditService,
    ) {}

    /**
     * ดึงโปรเจกต์ที่เปิดขาย.
     */
    public function projects(): JsonResponse
    {
        $projects = $this->carbonCreditService->getActiveProjects();

        return response()->json([
            'success' => true,
            'data' => $projects->items(),
            'meta' => [
                'current_page' => $projects->currentPage(),
                'per_page' => $projects->perPage(),
                'total' => $projects->total(),
            ],
        ]);
    }

    /**
     * ดึงรายละเอียดโปรเจกต์.
     */
    public function project(string $slug): JsonResponse
    {
        $project = $this->carbonCreditService->getProject($slug);

        if (! $project) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Project not found.'],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $project,
        ]);
    }

    /**
     * สถิติ Carbon Credit.
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->carbonCreditService->getStats(),
        ]);
    }

    /**
     * ซื้อ Carbon Credits.
     */
    public function purchase(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'required|integer|exists:carbon_projects,id',
            'amount' => 'required|numeric|min:0.01',
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'payment_currency' => 'required|in:TPIX,BNB,USDT',
            'payment_amount' => 'nullable|numeric',
            'tx_hash' => 'nullable|string|max:66',
        ]);

        try {
            $credit = $this->carbonCreditService->purchaseCredits($validated);

            return response()->json([
                'success' => true,
                'data' => $credit->load('project'),
                'message' => 'Carbon credits purchased successfully.',
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'PURCHASE_FAILED', 'message' => 'Operation failed. Please try again.'],
            ], 422);
        }
    }

    /**
     * Retire Carbon Credits.
     */
    public function retire(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'credit_id' => 'required|integer|exists:carbon_credits,id',
            'amount' => 'required|numeric|min:0.01',
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'beneficiary_name' => 'nullable|string|max:255',
            'retirement_reason' => 'nullable|string|max:1000',
        ]);

        try {
            $retirement = $this->carbonCreditService->retireCredits($validated);

            return response()->json([
                'success' => true,
                'data' => $retirement->load('credit.project'),
                'message' => 'Carbon credits retired successfully.',
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'RETIRE_FAILED', 'message' => 'Operation failed. Please try again.'],
            ], 422);
        }
    }

    /**
     * ดึง Credits ของ wallet.
     */
    public function myCredits(string $walletAddress): JsonResponse
    {
        if (! preg_match('/^0x[a-fA-F0-9]{40}$/', $walletAddress)) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'INVALID_ADDRESS', 'message' => 'Invalid wallet address format.'],
            ], 400);
        }

        $credits = $this->carbonCreditService->getCreditsByOwner($walletAddress);

        return response()->json([
            'success' => true,
            'data' => $credits,
        ]);
    }

    /**
     * ดึง Retirements ของ wallet.
     */
    public function myRetirements(string $walletAddress): JsonResponse
    {
        if (! preg_match('/^0x[a-fA-F0-9]{40}$/', $walletAddress)) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'INVALID_ADDRESS', 'message' => 'Invalid wallet address format.'],
            ], 400);
        }

        $retirements = $this->carbonCreditService->getRetirementsByAddress($walletAddress);

        return response()->json([
            'success' => true,
            'data' => $retirements,
        ]);
    }
}
