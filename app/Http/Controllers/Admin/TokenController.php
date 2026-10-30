<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Chain;
use App\Models\Token;
use App\Services\TokenList\TokenLogoStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * TokenController.
 *
 * Manages token/cryptocurrency configurations per blockchain chain.
 * Tokens are nested under their parent chain for organizational clarity.
 *
 * ⚠️ แถว tokens บนเชน TPIX ที่ is_verified เป็นแหล่งของ /tokenlist.json ซึ่ง explorer และ
 *    TPIX Wallet ถือเป็นรายการ "ของจริง" — เพิ่ม/แก้/ลบได้เฉพาะ admin/super_admin (ดู routes/admin.php)
 *    และทุกการเปลี่ยนแปลงลง AuditLog พร้อมค่าเดิม/ค่าใหม่
 */
class TokenController extends Controller
{
    /** ฟิลด์ที่บันทึกค่าเดิม/ค่าใหม่ลง AuditLog */
    private const AUDITED = ['chain_id', 'name', 'symbol', 'contract_address', 'decimals', 'logo', 'is_active', 'is_verified'];

    public function __construct(private TokenLogoStorage $logos) {}

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
            'canManage' => $this->canManage(),
            'logoRules' => $this->logoRules(),
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
            'canManage' => $this->canManage(),
            'logoRules' => $this->logoRules(),
        ]);
    }

    /**
     * Store a newly created token.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePayload($request);
        unset($validated['logo_file']);
        $validated['logo'] = $this->resolveLogoInput($request, $validated);

        $token = Token::create($validated);

        AuditLog::log('token.create', $token, null, $token->only(self::AUDITED));

        return back()->with('success', 'Token created successfully.');
    }

    /**
     * Update the specified token.
     */
    public function update(Request $request, Token $token): RedirectResponse
    {
        $validated = $this->validatePayload($request);
        unset($validated['logo_file']);
        $before = $token->only(self::AUDITED);
        $validated['logo'] = $this->resolveLogoInput($request, $validated, $token);

        $token->update($validated);

        $fields = array_values(array_intersect(self::AUDITED, array_keys($token->getChanges())));
        if ($fields !== []) {
            AuditLog::log(
                in_array('logo', $fields, true) ? 'token.logo_update' : 'token.update',
                $token,
                array_intersect_key($before, array_flip($fields)),
                $token->only($fields),
            );
        }

        return back()->with('success', 'Token updated successfully.');
    }

    /**
     * Remove the specified token.
     */
    public function destroy(Token $token): RedirectResponse
    {
        $before = $token->only(self::AUDITED);
        $logo = $token->logo;
        $token->delete();

        // ลบ logo file ถ้าเป็นไฟล์ที่หลังบ้านอัปโหลดเองและไม่มีแถวอื่นใช้อยู่
        $this->logos->deleteIfUnused($logo);
        AuditLog::log('token.delete', $token, $before, null);

        return back()->with('success', 'Token deleted successfully.');
    }

    // =========================================================================
    // Logo helpers
    // =========================================================================

    /**
     * Validation rules ที่ใช้ทั้ง store + update
     * รองรับ logo เป็น file upload หรือ URL string.
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
            /*
             * ไม่รับ svg — เหตุผลเดียวกับ TokenFactoryApiController::uploadLogo()
             * แอดมินที่ถูกยึดบัญชี = ฝัง JS ลงโดเมนได้เหมือนกัน
             * PNG/WebP สี่เหลี่ยมจัตุรัส ≥128px ≤512KB — โลโก้ชุดนี้ไปขึ้น explorer/wallet/CoinGecko
             */
            'logo_file' => TokenLogoStorage::rules(required: false),
            'coingecko_id' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
            'is_verified' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ], TokenLogoStorage::messages('logo_file'));
    }

    /**
     * คืนค่า logo ที่ควรเก็บ:
     * — ถ้ามี file upload → เก็บที่ token-logos/{chainId}/{address}-{hash}.{ext} (ชื่อผูกกับเนื้อไฟล์) แล้วคืน relative path
     * — ถ้าไม่มี file แต่มี URL string → คืน URL ตรงๆ (รองรับ external CDN เช่น trustwallet)
     * — ถ้าทั้งคู่ว่าง → คงค่าเดิมของ token (ไม่ทับเป็น null โดยไม่ตั้งใจ)
     *
     * ลบ logo เก่าใน storage ทิ้งถ้าเปลี่ยนเป็น file ใหม่หรือ URL ใหม่ (เฉพาะไฟล์ที่ไม่มีแถวอื่นใช้)
     *
     * @param  array<string, mixed>  $validated
     */
    private function resolveLogoInput(Request $request, array $validated, ?Token $existing = null): ?string
    {
        // 1. มี file upload → ใช้ file ก่อน
        if ($request->hasFile('logo_file')) {
            $chain = Chain::find($validated['chain_id']);
            $path = $this->logos->store(
                $request->file('logo_file'),
                (int) ($chain?->chain_id ?? 0),
                (string) $validated['contract_address'],
            );

            if ($existing && $existing->logo !== $path) {
                $this->logos->deleteIfUnused($existing->logo, $existing);
            }

            return $path; // e.g. "token-logos/4289/0xabc…-1a2b3c4d5e6f7a8b.png"
        }

        // 2. ไม่มี file แต่มี URL string → ใช้ URL
        $logoString = $request->input('logo');
        if (filled($logoString)) {
            // ถ้า URL ใหม่ != เดิม → ลบ logo file เก่า (ถ้ามีและไม่มีใครใช้)
            if ($existing && $existing->logo !== $logoString) {
                $this->logos->deleteIfUnused($existing->logo, $existing);
            }

            return $logoString;
        }

        // 3. ทั้งคู่ว่าง — keep existing (สำหรับ update) หรือ null (สำหรับ create)
        return $existing?->logo;
    }

    private function canManage(): bool
    {
        return in_array(Auth::guard('admin')->user()?->role, ['super_admin', 'admin'], true);
    }

    /** @return array{max_kb:int, min_px:int, types:string} */
    private function logoRules(): array
    {
        return ['max_kb' => TokenLogoStorage::MAX_KB, 'min_px' => TokenLogoStorage::MIN_PX, 'types' => 'PNG, WebP'];
    }
}
