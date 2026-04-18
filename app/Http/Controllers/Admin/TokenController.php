<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Chain;
use App\Models\Token;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePayload($request);
        $validated['logo'] = $this->resolveLogoInput($request);

        Token::create($validated);

        return back()->with('success', 'Token created successfully.');
    }

    /**
     * Update the specified token.
     */
    public function update(Request $request, Token $token): RedirectResponse
    {
        $validated = $this->validatePayload($request);
        $validated['logo'] = $this->resolveLogoInput($request, $token);

        $token->update($validated);

        return back()->with('success', 'Token updated successfully.');
    }

    /**
     * Remove the specified token.
     */
    public function destroy(Token $token): RedirectResponse
    {
        // ลบ logo file ถ้าเป็น storage upload (ไม่ใช่ external URL)
        $this->deleteStoredLogo($token->logo);
        $token->delete();

        return back()->with('success', 'Token deleted successfully.');
    }

    // =========================================================================
    // Logo helpers
    // =========================================================================

    /**
     * Validation rules ที่ใช้ทั้ง store + update
     * รองรับ logo เป็น file upload หรือ URL string
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'chain_id' => ['required', 'integer', 'exists:chains,id'],
            'name' => ['required', 'string', 'max:255'],
            'symbol' => ['required', 'string', 'max:20'],
            'contract_address' => ['required', 'string', 'max:255'],
            'decimals' => ['required', 'integer', 'min:0', 'max:36'],
            'logo' => ['nullable', 'string', 'max:500'],
            'logo_file' => ['nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048'],
            'coingecko_id' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ]);
    }

    /**
     * คืนค่า logo ที่ควรเก็บ:
     * — ถ้ามี file upload → store ลง storage/app/public/tokens/ แล้วคืน relative path
     * — ถ้าไม่มี file แต่มี URL string → คืน URL ตรงๆ (รองรับ external CDN เช่น trustwallet)
     * — ถ้าทั้งคู่ว่าง → คงค่าเดิมของ token (ไม่ทับเป็น null โดยไม่ตั้งใจ)
     *
     * ลบ logo เก่าใน storage ทิ้งถ้าเปลี่ยนเป็น file ใหม่หรือ URL ใหม่
     */
    private function resolveLogoInput(Request $request, ?Token $existing = null): ?string
    {
        // 1. มี file upload → ใช้ file ก่อน
        if ($request->hasFile('logo_file')) {
            $this->deleteStoredLogo($existing?->logo);
            $path = $request->file('logo_file')->store('tokens', 'public');

            return $path; // e.g. "tokens/abc123.png"
        }

        // 2. ไม่มี file แต่มี URL string → ใช้ URL
        $logoString = $request->input('logo');
        if (filled($logoString)) {
            // ถ้า URL ใหม่ != เดิม → ลบ logo file เก่า (ถ้ามี)
            if ($existing && $existing->logo !== $logoString) {
                $this->deleteStoredLogo($existing->logo);
            }

            return $logoString;
        }

        // 3. ทั้งคู่ว่าง — keep existing (สำหรับ update) หรือ null (สำหรับ create)
        return $existing?->logo;
    }

    /**
     * ลบ logo file จาก storage ถ้าเป็น relative path (ไม่ใช่ external URL)
     */
    private function deleteStoredLogo(?string $logo): void
    {
        if (! $logo) {
            return;
        }
        if (str_starts_with($logo, 'http')) {
            return; // external URL — ไม่แตะ
        }

        $path = ltrim($logo, '/');
        // ลบ "storage/" prefix ถ้ามี เพื่อให้ disk('public') resolve ถูก
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, 8);
        }

        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
