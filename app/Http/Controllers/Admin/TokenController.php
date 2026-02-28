<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Chain;
use App\Models\Token;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * TokenController.
 *
 * Manages token/cryptocurrency configurations per blockchain chain.
 * Tokens are nested under their parent chain for organizational clarity.
 */
class TokenController extends Controller
{
    /**
     * Display all tokens across all chains.
     */
    public function all(): InertiaResponse
    {
        $tokens = Token::with('chain')
            ->orderBy('chain_id')
            ->orderBy('sort_order')
            ->get();

        $chains = Chain::ordered()->get();

        return Inertia::render('Admin/Tokens/Index', [
            'tokens' => $tokens,
            'chains' => $chains,
        ]);
    }

    /**
     * Display tokens for a specific chain.
     */
    public function index(Chain $chain): InertiaResponse
    {
        $tokens = $chain->tokens()
            ->orderBy('sort_order')
            ->get();

        $chains = Chain::ordered()->get();

        return Inertia::render('Admin/Tokens/Index', [
            'chain' => $chain,
            'tokens' => $tokens,
            'chains' => $chains,
        ]);
    }

    /**
     * Store a newly created token.
     */
    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'chain_id' => ['required', 'integer', 'exists:chains,id'],
            'name' => ['required', 'string', 'max:255'],
            'symbol' => ['required', 'string', 'max:20'],
            'contract_address' => ['required', 'string', 'max:255'],
            'decimals' => ['required', 'integer', 'min:0', 'max:36'],
            'logo' => ['nullable', 'string', 'max:500'],
            'coingecko_id' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        Token::create($validated);

        return back()->with('success', 'Token created successfully.');
    }

    /**
     * Update the specified token.
     */
    public function update(Request $request, Token $token): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'chain_id' => ['required', 'integer', 'exists:chains,id'],
            'name' => ['required', 'string', 'max:255'],
            'symbol' => ['required', 'string', 'max:20'],
            'contract_address' => ['required', 'string', 'max:255'],
            'decimals' => ['required', 'integer', 'min:0', 'max:36'],
            'logo' => ['nullable', 'string', 'max:500'],
            'coingecko_id' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $token->update($validated);

        return back()->with('success', 'Token updated successfully.');
    }

    /**
     * Remove the specified token.
     */
    public function destroy(Token $token): \Illuminate\Http\RedirectResponse
    {
        $token->delete();

        return back()->with('success', 'Token deleted successfully.');
    }
}
