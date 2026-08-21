<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\Chain;
use App\Models\Token;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — /admin/chains ต้อง "จัดการเชนได้จริง" ไม่ใช่หน้าประดับ.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * สภาพเดิมที่เทสต์ชุดนี้กันไม่ให้กลับมา
 * ═══════════════════════════════════════════════════════════════════════════
 * 1. แก้เชนไม่ได้เลยสักแถว — ฟอร์มยิง PUT พร้อม FormData ซึ่ง PHP ไม่แกะให้
 *    ($request ว่างทั้งก้อน) ทั้ง 11 แถวบน production จึงค้างค่าจาก seeder
 * 2. อัปโหลดไอคอนไม่ได้ — ไฟล์ถูกตรวจด้วยกฎ `string`
 *    8 จาก 11 แถวมี logo = NULL รวมถึง TPIX Chain เอง
 * 3. แก้อย่างอื่นแล้วไอคอนหาย — ค่าว่างจากฟอร์มถูกเขียนทับเป็น NULL
 * 4. ลบเชนที่ยังมีโทเคน/คู่เทรดผูกอยู่ได้ → ของกลายเป็นลูกกำพร้าและ API 500
 * 5. แก้อะไรก็ไม่มีผลกับเว็บ — /api/v1/chains อ่าน config/chains.php ไม่ใช่ฐานข้อมูล
 *
 * Developed by Xman Studio.
 */
class ChainManagementTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::create([
            'name' => 'ผู้ดูแลระบบ',
            'email' => 'admin@tpix.test',
            'password' => bcrypt('secret-password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin, 'admin');
    }

    private function tpix(): Chain
    {
        return Chain::where('chain_id', 4289)->firstOrFail();
    }

    /** ค่าที่ครบพอให้ผ่าน validation — ทับเฉพาะที่ต้องการทดสอบ */
    private function payload(Chain $chain, array $overrides = []): array
    {
        return array_merge([
            'name' => $chain->name,
            'symbol' => $chain->symbol,
            'chain_id_hex' => $chain->chain_id_hex,
            'rpc_url' => $chain->rpc_url,
            'explorer_url' => $chain->explorer_url,
            'native_currency_name' => $chain->native_currency_name,
            'native_currency_symbol' => $chain->native_currency_symbol,
            'native_currency_decimals' => $chain->native_currency_decimals,
            'block_confirmations' => $chain->block_confirmations,
            'status' => $chain->status,
            'is_active' => $chain->is_active,
            'is_testnet' => $chain->is_testnet,
        ], $overrides);
    }

    // =========================================================================
    // แก้ไขได้จริง
    // =========================================================================

    #[Test]
    public function แก้ไขเชนผ่านฟอร์มแบบมีไฟล์แนบแล้วบันทึกจริง(): void
    {
        $chain = $this->tpix();

        // ฟอร์มจริงส่งเป็น POST + _method=put เพราะ PHP แกะ multipart ให้เฉพาะ POST
        $this->post("/admin/chains/{$chain->id}", $this->payload($chain, [
            '_method' => 'put',
            'rpc_url' => 'https://rpc2.tpix.online',
        ]))->assertRedirect();

        $this->assertSame('https://rpc2.tpix.online', $chain->fresh()->rpc_url);
    }

    #[Test]
    public function แก้ไขเรื่องอื่นแล้วไอคอนเดิมต้องไม่หาย(): void
    {
        $chain = $this->tpix();
        $this->assertNotNull($chain->logo, 'ต้องมีไอคอนตั้งต้นก่อนเริ่มทดสอบ');
        $originalLogo = $chain->logo;

        // ฟอร์มส่ง logo เป็นค่าว่างมาด้วยทุกครั้งที่ไม่ได้เลือกไฟล์ใหม่
        $this->post("/admin/chains/{$chain->id}", $this->payload($chain, [
            '_method' => 'put',
            'logo' => '',
            'block_confirmations' => 5,
        ]))->assertRedirect();

        $fresh = $chain->fresh();
        $this->assertSame(5, $fresh->block_confirmations);
        $this->assertSame($originalLogo, $fresh->logo, 'ไอคอนต้องคงเดิม ห้ามถูกทับเป็น NULL');
    }

    #[Test]
    public function อัปโหลดไอคอนเชนได้จริงและได้_url_ที่เปิดได้(): void
    {
        Storage::fake('public');
        $chain = $this->tpix();

        $this->post("/admin/chains/{$chain->id}", $this->payload($chain, [
            '_method' => 'put',
            'logo_file' => UploadedFile::fake()->image('tpix.png', 64, 64),
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $chain->fresh();

        $this->assertStringStartsWith('chains/', $fresh->logo, 'ต้องถูกเก็บลงโฟลเดอร์ chains');
        Storage::disk('public')->assertExists($fresh->logo);

        // ต้องแปลงเป็น URL ที่ผ่าน storage symlink ไม่ใช่ path ตรงที่ public root
        $this->assertStringContainsString('storage/chains/', $fresh->logo_url);
    }

    // =========================================================================
    // ความถูกต้องของ chain id
    // =========================================================================

    #[Test]
    public function ปฏิเสธ_chain_id_ที่ผิดรูปแบบ(): void
    {
        $chain = $this->tpix();

        // พิมพ์เลขฐานสิบมาแทน hex — เดิมบันทึกผ่านแล้วกลายเป็นเชนที่ไม่มีใครหาเจอ
        $this->post("/admin/chains/{$chain->id}", $this->payload($chain, [
            '_method' => 'put',
            'chain_id_hex' => '4289',
        ]))->assertSessionHasErrors('chain_id_hex');

        $this->assertSame('0x10c1', $chain->fresh()->chain_id_hex);
    }

    #[Test]
    public function ปฏิเสธ_chain_id_ที่ซ้ำกับเชนอื่น(): void
    {
        $chain = $this->tpix();

        // 0x38 = BSC ซึ่งมีอยู่แล้ว — สองแถว hex เดียวกันทำให้ ->first() หยิบมั่ว
        $this->post("/admin/chains/{$chain->id}", $this->payload($chain, [
            '_method' => 'put',
            'chain_id_hex' => '0x38',
        ]))->assertSessionHasErrors('chain_id_hex');
    }

    #[Test]
    public function เก็บ_chain_id_hex_เป็นตัวพิมพ์เล็กและเติมเลขให้อัตโนมัติ(): void
    {
        $this->post('/admin/chains', [
            'name' => 'Test Chain',
            'symbol' => 'TST',
            'chain_id_hex' => '0xABCD',
            'rpc_url' => 'https://rpc.example.com',
            'native_currency_name' => 'Test',
            'native_currency_symbol' => 'TST',
            'native_currency_decimals' => 18,
            'block_confirmations' => 12,
            'status' => 'coming_soon',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $created = Chain::where('name', 'Test Chain')->firstOrFail();

        $this->assertSame('0xabcd', $created->chain_id_hex);
        $this->assertSame(43981, $created->chain_id, 'chain_id ต้องถูกคำนวณจาก hex ไม่ให้กรอกสองที่');
        $this->assertSame(43981, $created->network_id);
    }

    #[Test]
    public function เชนที่เพิ่งเพิ่มต้องไปต่อท้าย_ไม่ใช่แทรกหน้าสุด(): void
    {
        $maxBefore = (int) Chain::max('sort_order');

        $this->post('/admin/chains', [
            'name' => 'Zeta Chain',
            'symbol' => 'ZETA',
            'chain_id_hex' => '0x1b58',
            'rpc_url' => 'https://rpc.example.com',
            'native_currency_name' => 'Zeta',
            'native_currency_symbol' => 'ZETA',
            'native_currency_decimals' => 18,
            'block_confirmations' => 12,
            'status' => 'coming_soon',
        ])->assertRedirect();

        $this->assertSame($maxBefore + 1, Chain::where('name', 'Zeta Chain')->value('sort_order'));
    }

    // =========================================================================
    // การลบ
    // =========================================================================

    #[Test]
    public function ลบเชนที่ยังมีโทเคนผูกอยู่ไม่ได้(): void
    {
        $chain = $this->tpix();

        Token::create([
            'chain_id' => $chain->id,
            'name' => 'Tether USD',
            'symbol' => 'USDT',
            'contract_address' => '0x'.str_repeat('ab', 20),
            'decimals' => 18,
            'is_active' => true,
        ]);

        $this->delete("/admin/chains/{$chain->id}")
            ->assertSessionHasErrors('chain');

        $this->assertNotNull(Chain::find($chain->id), 'เชนต้องยังอยู่ ห้ามถูกลบทิ้ง');
    }

    #[Test]
    public function ลบเชนที่ไม่มีอะไรผูกอยู่ได้ตามปกติ(): void
    {
        $chain = Chain::create([
            'name' => 'Orphan Chain',
            'symbol' => 'ORP',
            'chain_id_hex' => '0x270f',
            'chain_id' => 9999,
            'rpc_url' => 'https://rpc.example.com',
            'native_currency_name' => 'Orphan',
            'native_currency_symbol' => 'ORP',
            'native_currency_decimals' => 18,
            'block_confirmations' => 12,
            'status' => 'coming_soon',
        ]);

        $this->delete("/admin/chains/{$chain->id}")->assertSessionHasNoErrors();

        $this->assertNull(Chain::find($chain->id));
    }

    // =========================================================================
    // ★ หัวใจของเรื่อง — แก้ในหลังบ้านต้องมีผลกับเว็บจริง
    // =========================================================================

    #[Test]
    public function เปลี่ยนสถานะเชนในหลังบ้านแล้ว_api_สาธารณะเปลี่ยนตามทันที(): void
    {
        $chain = $this->tpix();

        $this->assertSame('coming_soon', $chain->status);

        $this->post("/admin/chains/{$chain->id}", $this->payload($chain, [
            '_method' => 'put',
            'status' => Chain::STATUS_LIVE,
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $payload = $this->getJson('/api/v1/chains')->assertOk()->json('data');
        $tpix = collect($payload)->firstWhere('chainId', 4289);

        $this->assertNotNull($tpix);
        $this->assertSame('live', $tpix['status'], 'สถานะที่แอดมินตั้งต้องออกไปถึงหน้าเว็บ');
    }

    #[Test]
    public function ปิดใช้งานเชนแล้วหายจากรายการสาธารณะ(): void
    {
        $chain = $this->tpix();

        $this->patch("/admin/chains/{$chain->id}/toggle")->assertRedirect();

        $this->assertFalse($chain->fresh()->is_active);

        $payload = $this->getJson('/api/v1/chains')->assertOk()->json('data');

        $this->assertNull(
            collect($payload)->firstWhere('chainId', 4289),
            'เชนที่ถูกปิดต้องไม่ถูกส่งออกไปให้ผู้ใช้เลือก'
        );
    }

    #[Test]
    public function เปลี่ยน_rpc_ในหลังบ้านแล้ว_api_ส่งค่าใหม่ออกไป(): void
    {
        $chain = $this->tpix();

        $this->post("/admin/chains/{$chain->id}", $this->payload($chain, [
            '_method' => 'put',
            'rpc_url' => 'https://rpc-new.tpix.online',
        ]))->assertRedirect();

        $payload = $this->getJson('/api/v1/chains')->assertOk()->json('data');
        $tpix = collect($payload)->firstWhere('chainId', 4289);

        $this->assertContains('https://rpc-new.tpix.online', $tpix['rpc']);
    }

    // =========================================================================
    // ความถูกต้องของสัญลักษณ์เชน
    // =========================================================================

    #[Test]
    public function เชน_l2_ต้องใช้_eth_เป็นเหรียญค่าแก๊ส(): void
    {
        // ARB/OP/BASE/ZKSYNC เป็นเหรียญ governance ไม่ใช่เหรียญค่าแก๊ส
        foreach ([42161, 10, 8453, 324] as $chainId) {
            $chain = Chain::where('chain_id', $chainId)->firstOrFail();

            $this->assertSame(
                'ETH',
                $chain->native_currency_symbol,
                "เชน {$chain->name} ต้องจ่ายค่าแก๊สเป็น ETH"
            );
        }
    }

    #[Test]
    public function polygon_ต้องใช้ชื่อเหรียญ_pol_ไม่ใช่_matic(): void
    {
        $polygon = Chain::where('chain_id', 137)->firstOrFail();

        $this->assertSame('POL', $polygon->native_currency_symbol);
        $this->assertSame('POL', $polygon->symbol);
    }

    #[Test]
    public function ทุกเชนต้องมี_chain_id_ที่แปลงจาก_hex_ได้ตรงกัน(): void
    {
        foreach (Chain::all() as $chain) {
            $this->assertNotNull($chain->chain_id, "เชน {$chain->name} ไม่มี chain_id");

            $this->assertSame(
                '0x'.dechex($chain->chain_id),
                $chain->chain_id_hex,
                "เชน {$chain->name} มี chain_id กับ chain_id_hex ไม่ตรงกัน"
            );
        }
    }

    #[Test]
    public function tpix_chain_ต้องมีไอคอนของตัวเอง(): void
    {
        // เชนหลักของโปรเจกต์เองเคยเป็นหนึ่งใน 8 แถวที่ logo = NULL
        $this->assertSame('/tpixlogo.webp', $this->tpix()->logo);
        $this->assertNotNull($this->tpix()->logo_url);
    }
}
