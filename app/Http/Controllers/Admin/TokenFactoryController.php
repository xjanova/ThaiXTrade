<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FactoryToken;
use App\Models\SiteSetting;
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
        $search = $request->query('search');
        $tokens = $this->tokenFactoryService->getAllTokens($status, $search);
        $stats = $this->tokenFactoryService->getStats();
        $readiness = $this->tokenFactoryService->isFactoryReady();
        $factoryConfig = $this->tokenFactoryService->getFactoryConfig();

        return Inertia::render('Admin/TokenFactory/Index', [
            'tokens' => $tokens,
            'stats' => $stats,
            'currentStatus' => $status,
            'currentSearch' => $search,
            'factoryReady' => $readiness,
            'factoryConfig' => $factoryConfig,
        ]);
    }

    public function approve(int $id): RedirectResponse
    {
        $token = FactoryToken::findOrFail($id);

        try {
            $this->tokenFactoryService->approveToken($token);
            AuditLog::log('token_factory.approve', $token->id);
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
            AuditLog::log('token_factory.reject', $token->id, null, [
                'reason' => $request->input('reason'),
            ]);
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
            AuditLog::log('token_factory.verify_toggle', $token->id);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $status = $token->fresh()->is_verified ? 'verified' : 'unverified';

        return back()->with('success', "Token {$token->symbol} is now {$status}.");
    }

    public function toggleListed(int $id): RedirectResponse
    {
        $token = FactoryToken::findOrFail($id);

        try {
            $this->tokenFactoryService->toggleListed($token);
            AuditLog::log('token_factory.list_toggle', $token->id);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $status = $token->fresh()->is_listed ? 'listed' : 'unlisted';

        return back()->with('success', "Token {$token->symbol} is now {$status}.");
    }

    public function retry(int $id): RedirectResponse
    {
        $token = FactoryToken::findOrFail($id);

        try {
            $this->tokenFactoryService->approveToken($token);
            AuditLog::log('token_factory.retry', $token->id);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Token {$token->symbol} retry deployment dispatched.");
    }
}
