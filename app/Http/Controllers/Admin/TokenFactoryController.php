<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FactoryToken;
use App\Services\TokenFactoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TokenFactoryController extends Controller
{
    public function __construct(
        private TokenFactoryService $tokenFactoryService,
    ) {}

    public function index(Request $request): Response
    {
        $status = $request->query('status');
        $tokens = $this->tokenFactoryService->getAllTokens($status);
        $stats = $this->tokenFactoryService->getStats();

        return Inertia::render('Admin/TokenFactory/Index', [
            'tokens' => $tokens,
            'stats' => $stats,
            'currentStatus' => $status,
        ]);
    }

    public function approve(int $id): RedirectResponse
    {
        $token = FactoryToken::findOrFail($id);

        try {
            $this->tokenFactoryService->approveToken($token);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Token {$token->symbol} approved. Deployment in progress...");
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $request->validate(['reason' => 'required|string|max:500']);

        $token = FactoryToken::findOrFail($id);

        try {
            $this->tokenFactoryService->rejectToken($token, $request->input('reason'));
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Token {$token->symbol} rejected.");
    }

    public function toggleVerified(int $id): RedirectResponse
    {
        $token = FactoryToken::findOrFail($id);

        try {
            $this->tokenFactoryService->toggleVerified($token);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Token {$token->symbol} verification toggled.");
    }

    public function retry(int $id): RedirectResponse
    {
        $token = FactoryToken::findOrFail($id);

        try {
            $this->tokenFactoryService->approveToken($token);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Token {$token->symbol} retry deployment dispatched.");
    }
}
