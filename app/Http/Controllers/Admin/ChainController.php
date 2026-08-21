<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Chain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * ChainController — จัดการเชนบล็อกเชนในหลังบ้าน.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * บันทึกสิ่งที่เคยพังทั้งหมดในไฟล์นี้ (อย่าถอยกลับไปแบบเดิม)
 * ═══════════════════════════════════════════════════════════════════════════
 * 1. `logo` ถูกตรวจเป็น `string` ทั้งที่ฟอร์มส่ง UploadedFile มา
 *    → อัปโหลดไอคอนเชนไม่เคยสำเร็จเลยสักครั้ง และ error ก็ไม่เคยถูกแสดง
 *    → เป็นสาเหตุที่ 8 จาก 11 แถวบน production มี logo = NULL รวมถึง TPIX Chain เอง
 *
 * 2. `logo` ที่ว่างถูกเขียนทับลงฐานข้อมูลเป็น NULL
 *    → แก้ rpc_url อย่างเดียวก็ทำให้ไอคอนที่มีอยู่หายถาวร โดยขึ้นข้อความว่าสำเร็จ
 *
 * 3. `chain_id_hex` เป็นช่องข้อความอิสระ ไม่ตรวจรูปแบบ ไม่กันซ้ำ
 *    → พิมพ์ '56' หรือ '0X38' หรือมีช่องว่างติดมา = เชนที่ไม่มีตัวแก้ปัญหาใดหาเจอ
 *      แต่หน้าแอดมินยังโชว์เขียวว่า Active → ผู้ใช้เจอ INVALID_CHAIN ทุกคำสั่ง
 *
 * 4. `destroy()` ลบได้เสมอทั้งที่โหลด withCount มาแล้วแต่ไม่เคยเอามาใช้
 *    → ลบ TPIX Chain = เทรด TPIX ตายทั้งระบบ และ token/pair กลายเป็นลูกกำพร้า
 *      ที่ทำให้ /api/v1/tokens/{address} กับ /api/v1/swap/routes พัง 500
 *
 * 5. `sort_order` ถูกตรวจแต่ฟอร์มไม่เคยส่งมา → เชนใหม่ได้ 0 เสมอ แล้วแทรกหน้าสุด
 *
 * Developed by Xman Studio.
 */
class ChainController extends Controller
{
    /**
     * รายการเชนทั้งหมด.
     */
    public function index(): InertiaResponse
    {
        $chains = Chain::withCount('tokens', 'tradingPairs')
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('Admin/Chains/Index', [
            'chains' => $chains,
        ]);
    }

    /**
     * สร้างเชนใหม่.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePayload($request);

        $validated['logo'] = $this->resolveLogoInput($request);

        /*
         * เชนใหม่ต้องไปต่อท้าย ไม่ใช่แทรกหน้าสุด
         *
         * คอลัมน์นี้มี default 0 ซึ่งต่ำกว่าทุกแถวที่ seeder ใส่ไว้ (1..10)
         * ถ้าฟอร์มไม่ส่ง sort_order มา เชนที่เพิ่งเพิ่มจะกระโดดขึ้นไปอยู่เหนือ
         * Ethereum และ TPIX Chain ในทุก dropdown ของหลังบ้าน
         */
        if (! isset($validated['sort_order'])) {
            $validated['sort_order'] = (int) Chain::max('sort_order') + 1;
        }

        Chain::create($validated);

        return back()->with('success', 'เพิ่มเชนเรียบร้อยแล้ว');
    }

    /**
     * แก้ไขเชน.
     */
    public function update(Request $request, Chain $chain): RedirectResponse
    {
        $validated = $this->validatePayload($request, $chain);

        // ห้ามให้ค่าว่างจากฟอร์มทับไอคอนเดิม — ดู resolveLogoInput()
        $validated['logo'] = $this->resolveLogoInput($request, $chain);

        $chain->update($validated);

        return back()->with('success', 'แก้ไขเชนเรียบร้อยแล้ว');
    }

    /**
     * เปิด/ปิดเชน.
     */
    public function toggleActive(Chain $chain): RedirectResponse
    {
        $chain->update([
            'is_active' => ! $chain->is_active,
        ]);

        $status = $chain->is_active ? 'เปิดใช้งาน' : 'ปิดใช้งาน';

        return back()->with('success', "{$status}เชน {$chain->name} แล้ว");
    }

    /**
     * ลบเชน (soft delete) — ปฏิเสธถ้ายังมีของผูกอยู่.
     */
    public function destroy(Chain $chain): RedirectResponse
    {
        $chain->loadCount(['tokens', 'tradingPairs']);

        /*
         * ★ ด่านที่ขาดหายไปตั้งแต่ต้น
         *
         * $chain->delete() แค่ประทับ deleted_at — CASCADE ระดับฐานข้อมูลไม่ทำงาน
         * token/trading_pair/order/trade จึงยังอยู่ครบแต่ชี้ไปหาพ่อแม่ที่มองไม่เห็น
         * แล้ว global scope ของ SoftDeletes ทำให้ $token->chain คืน null
         *   → GET /api/v1/tokens/{address} อ่าน property บน null = 500
         *   → GET /api/v1/swap/routes ล้มทั้งก้อน ไม่ใช่แค่เชนที่ลบ
         *
         * ถ้าอยากหยุดใช้งานชั่วคราว มีปุ่มเปิด/ปิดอยู่แล้ว ไม่ต้องลบ
         */
        if ($chain->tokens_count > 0 || $chain->trading_pairs_count > 0) {
            return back()->withErrors([
                'chain' => "ลบไม่ได้ — เชนนี้ยังมี {$chain->tokens_count} โทเคน "
                    ."และ {$chain->trading_pairs_count} คู่เทรดผูกอยู่ "
                    .'ให้ย้ายหรือลบของพวกนั้นก่อน หรือใช้ปุ่มปิดใช้งานแทน',
            ]);
        }

        $this->deleteStoredLogo($chain->logo);
        $chain->delete();

        return back()->with('success', "ลบเชน {$chain->name} แล้ว");
    }

    // =========================================================================
    // Validation
    // =========================================================================

    /**
     * กติกาที่ใช้ทั้งตอนสร้างและตอนแก้ไข.
     *
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?Chain $chain = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'symbol' => ['required', 'string', 'max:20'],

            /*
             * ★ ต้องเป็นเลขฐานสิบหกขึ้นต้น 0x เท่านั้น และห้ามซ้ำกับเชนอื่น
             *
             * ทุกตัวแก้ปัญหาในระบบ (TradingController, SwapApiController) ค้นด้วย
             * คอลัมน์นี้แบบตรงตัว ถ้าค่าผิดรูปสักตัวเดียว เชนนั้นจะกลายเป็นเชนที่
             * "มีอยู่ในหลังบ้าน แต่ไม่มีอยู่จริงสำหรับผู้ใช้"
             *
             * ที่ต้องกันซ้ำด้วย เพราะตัวค้นทุกตัวจบด้วย ->first() ถ้ามีสองแถว
             * ที่ hex เดียวกัน แถวไหนชนะขึ้นอยู่กับลำดับการ insert — การกดเปิด/ปิด
             * แถวที่แพ้จะไม่มีผลอะไรเลย และไม่มีใครรู้ว่าทำไม
             */
            'chain_id_hex' => [
                'required',
                'string',
                'max:20',
                'regex:/^0x[0-9a-fA-F]{1,16}$/',
                Rule::unique('chains', 'chain_id_hex')
                    ->ignore($chain?->id)
                    ->whereNull('deleted_at'),
            ],

            'rpc_url' => ['required', 'url', 'max:500'],
            'explorer_url' => ['nullable', 'url', 'max:500'],

            // logo = URL ข้อความ (ทางเลือกสำรอง) · logo_file = ไฟล์ที่อัปโหลด
            'logo' => ['nullable', 'string', 'max:500'],
            'logo_file' => ['nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048'],

            'is_testnet' => ['boolean'],
            'is_active' => ['boolean'],
            'native_currency_name' => ['required', 'string', 'max:100'],
            'native_currency_symbol' => ['required', 'string', 'max:20'],
            'native_currency_decimals' => ['required', 'integer', 'min:1', 'max:36'],
            'block_confirmations' => ['required', 'integer', 'min:1', 'max:200'],
            'sort_order' => ['nullable', 'integer', 'min:0'],

            // ── ค่าที่เคยอยู่ใน config/chains.php เท่านั้น ตอนนี้แก้ได้จากที่นี่ ──
            'short_name' => ['nullable', 'string', 'max:20'],

            /*
             * ★ ตัวที่สำคัญที่สุดในชุดนี้
             * live        = เทรดได้จริง กดเลือกได้ในหน้าเว็บ
             * coming_soon = เห็นในลิสต์แต่กดไม่ได้
             * maintenance = ปิดชั่วคราว
             *
             * เดิมค่านี้ฝังอยู่ในไฟล์ PHP เจ้าของจะเปิด TPIX Chain ให้เทรด
             * ต้องแก้โค้ดแล้ว deploy ใหม่ ทำจากหลังบ้านไม่ได้เลย
             */
            'status' => ['required', 'string', Rule::in([
                Chain::STATUS_LIVE,
                Chain::STATUS_COMING_SOON,
                Chain::STATUS_MAINTENANCE,
            ])],

            'color' => ['nullable', 'string', 'max:20', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'gasless' => ['boolean'],
            'block_time' => ['nullable', 'integer', 'min:1', 'max:600'],
            'consensus' => ['nullable', 'string', 'max:30'],
            'network_id' => ['nullable', 'integer', 'min:1'],
        ], [
            'chain_id_hex.regex' => 'Chain ID ต้องเป็นเลขฐานสิบหกขึ้นต้นด้วย 0x เช่น 0x38 (BSC) หรือ 0x10c1 (TPIX)',
            'chain_id_hex.unique' => 'Chain ID นี้ถูกใช้กับเชนอื่นแล้ว',
            'color.regex' => 'สีต้องเป็นรหัส HEX 6 หลัก เช่น #06B6D4',
        ]);

        /*
         * เก็บเป็นตัวพิมพ์เล็กเสมอ
         *
         * ค่าที่มีอยู่บน production ปนกันทั้งสองแบบ ('0x38' กับ '0xA4B1')
         * ตัวค้นจึงต้องลองทั้งพิมพ์เล็กและพิมพ์ใหญ่ทุกครั้ง ทำให้เขียนซ้ำกัน 4 ที่
         * บังคับรูปแบบเดียวตั้งแต่ตอนเขียน แล้วปัญหานั้นจะหายไปเอง
         */
        $validated['chain_id_hex'] = strtolower($validated['chain_id_hex']);

        /*
         * chain_id (ตัวเลข) มาจาก chain_id_hex เสมอ — ไม่ให้กรอกสองที่
         *
         * ถ้าเปิดให้กรอกแยก วันหนึ่งจะมีแถวที่ hex บอกอย่าง เลขบอกอีกอย่าง
         * แล้วตัวแปลง (ChainResolver) จะหาเจอบ้างไม่เจอบ้างแล้วแต่ว่าค้นด้วยอะไร
         * — เป็นบั๊กประเภทที่ไล่หายากที่สุด เพราะดูยังไงหน้าแอดมินก็ปกติดี
         */
        $validated['chain_id'] = hexdec(substr($validated['chain_id_hex'], 2));

        // ไม่ได้ระบุมา = ใช้เลขเดียวกับ chain id (จริงเกือบทุกเชน)
        if (empty($validated['network_id'])) {
            $validated['network_id'] = $validated['chain_id'];
        }

        // logo_file ไม่ใช่คอลัมน์ในตาราง — ใช้แค่ตอนอัปโหลด
        unset($validated['logo_file']);

        return $validated;
    }

    // =========================================================================
    // Logo helpers — คัดลอกรูปแบบจาก Admin\TokenController ที่ใช้งานได้จริงแล้ว
    // =========================================================================

    /**
     * ค่าที่ควรเก็บลงคอลัมน์ logo:
     * — มีไฟล์อัปโหลด → เก็บลง storage/app/public/chains/ แล้วคืน relative path
     * — ไม่มีไฟล์แต่มี URL → คืน URL ตรงๆ (รองรับ CDN ภายนอก)
     * — ว่างทั้งคู่ → คงค่าเดิมไว้ ห้ามทับเป็น null
     *
     * ข้อสุดท้ายสำคัญที่สุด: เดิมค่าว่างจากฟอร์มถูกเขียนทับลงไปตรงๆ
     * แอดมินที่เปิดเชนขึ้นมาแก้แค่จำนวน confirmation ก็ทำให้ไอคอนหายถาวร
     * โดยระบบขึ้นข้อความว่า "แก้ไขเรียบร้อยแล้ว"
     */
    private function resolveLogoInput(Request $request, ?Chain $existing = null): ?string
    {
        // 1. มีไฟล์อัปโหลด → ไฟล์ชนะเสมอ
        if ($request->hasFile('logo_file')) {
            $this->deleteStoredLogo($existing?->logo);

            return $request->file('logo_file')->store('chains', 'public');
        }

        // 2. ไม่มีไฟล์ แต่กรอก URL มา
        $logoString = $request->input('logo');
        if (filled($logoString)) {
            if ($existing && $existing->logo !== $logoString) {
                $this->deleteStoredLogo($existing->logo);
            }

            return $logoString;
        }

        // 3. ว่างทั้งคู่ — คงของเดิม (แก้ไข) หรือ null (สร้างใหม่)
        return $existing?->logo;
    }

    /**
     * ลบไฟล์ไอคอนใน storage ถ้าเป็นไฟล์ที่เราเก็บเอง (ไม่ใช่ URL ภายนอก).
     */
    private function deleteStoredLogo(?string $logo): void
    {
        if (! $logo || str_starts_with($logo, 'http')) {
            return;
        }

        $path = ltrim($logo, '/');

        // ตัด "storage/" ออกเพื่อให้ disk('public') หาไฟล์เจอ
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, 8);
        }

        /*
         * ไอคอนที่ชี้ไปยังไฟล์ใน public_html (เช่น /tpixlogo.webp) ไม่ใช่ของที่เราอัปโหลด
         * ห้ามลบเด็ดขาด — เป็นทรัพย์สินของเว็บที่หน้าอื่นใช้ร่วมกันอยู่
         */
        if (! str_starts_with($path, 'chains/')) {
            return;
        }

        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
