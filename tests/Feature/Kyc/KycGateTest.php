<?php

namespace Tests\Feature\Kyc;

use App\Models\KycSubmission;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Kyc\KycGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — ด่านยืนยันตัวตนรายฟีเจอร์.
 *
 * เจ้าของสั่งว่า "มีทุกส่วน เปิดปิดได้ที่สำคัญๆ" — เทสต์ชุดนี้พิสูจน์สามข้อ:
 *   1. ปิดอยู่ต้องไม่กันใคร (ค่าตั้งต้นต้องไม่ทำให้เว็บที่ใช้งานอยู่พัง)
 *   2. เปิดแล้วต้องกันจริงที่ API ไม่ใช่แค่ซ่อนปุ่ม
 *   3. สวิตช์ใหญ่ปิดแล้วต้องกันทุกด่านพร้อมกัน
 *
 * Developed by Xman Studio.
 */
class KycGateTest extends TestCase
{
    use RefreshDatabase;

    private KycGate $gate;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->gate = app(KycGate::class);

        $this->user = User::create([
            'email' => 'trader@tpix.test',
            'password' => bcrypt('secret-password'),
            'wallet_address' => '0x1111111111111111111111111111111111111111',
        ]);
    }

    private function enableSystem(bool $on = true): void
    {
        SiteSetting::set(KycGate::SETTING_GROUP, KycGate::KEY_ENABLED, $on ? '1' : '0', 'boolean');
        Cache::flush();
    }

    private function enableGate(string $feature, bool $on = true, string $level = 'basic'): void
    {
        SiteSetting::set(KycGate::SETTING_GROUP, KycGate::KEY_GATE_PREFIX.$feature, $on ? '1' : '0', 'boolean');
        SiteSetting::set(KycGate::SETTING_GROUP, KycGate::KEY_LEVEL_PREFIX.$feature, $level, 'string');
        Cache::flush();
    }

    private function approveKyc(User $user, string $level = 'basic'): KycSubmission
    {
        return KycSubmission::create([
            'user_id' => $user->id,
            'level' => $level,
            'status' => KycSubmission::STATUS_APPROVED,
            'consent_version' => '1.0',
            'consented_at' => now(),
            'submitted_at' => now(),
            'reviewed_at' => now(),
        ]);
    }

    // =========================================================================
    // ค่าตั้งต้น
    // =========================================================================

    #[Test]
    public function ยังไม่เปิดระบบต้องไม่กันใครเลย(): void
    {
        /*
         * ข้อนี้สำคัญกว่าที่เห็น — โค้ดนี้ deploy ลงเว็บที่มีคนใช้อยู่แล้ว
         * ถ้าค่าตั้งต้นคือ "กัน" ผู้ใช้ทุกคนจะใช้อะไรไม่ได้ทันทีที่ deploy เสร็จ
         * โดยที่เจ้าของยังไม่ได้กดอะไรเลย
         */
        foreach (array_keys(config('kyc.features')) as $feature) {
            $this->assertFalse(
                $this->gate->requires($feature),
                "ด่าน {$feature} ไม่ควรทำงานตอนที่ยังไม่เปิดระบบ"
            );
            $this->assertTrue($this->gate->passes($this->user, $feature));
            $this->assertTrue($this->gate->passes(null, $feature));
        }
    }

    #[Test]
    public function สวิตช์ใหญ่ปิดแล้วด่านที่เปิดไว้ก็ไม่ทำงาน(): void
    {
        $this->enableGate('ai_bot', true);
        $this->enableSystem(false);

        $this->assertFalse($this->gate->requires('ai_bot'));
        $this->assertTrue($this->gate->passes($this->user, 'ai_bot'));
    }

    // =========================================================================
    // เปิดแล้วกันจริง
    // =========================================================================

    #[Test]
    public function เปิดด่านแล้วคนที่ยังไม่ผ่านต้องถูกกัน(): void
    {
        $this->enableSystem();
        $this->enableGate('ai_bot');

        $this->assertTrue($this->gate->requires('ai_bot'));
        $this->assertFalse($this->gate->passes($this->user, 'ai_bot'));

        // ด่านอื่นที่ไม่ได้เปิดต้องไม่โดนหางเลข
        $this->assertTrue($this->gate->passes($this->user, 'bridge'));
    }

    #[Test]
    public function อนุมัติแล้วผ่านด่าน(): void
    {
        $this->enableSystem();
        $this->enableGate('ai_bot');

        $this->approveKyc($this->user);

        $this->assertTrue($this->gate->passes($this->user, 'ai_bot'));
        $this->assertSame('basic', $this->gate->approvedLevel($this->user));
    }

    #[Test]
    public function ยังไม่ล็อกอินไม่ผ่านด่านที่เปิดอยู่(): void
    {
        $this->enableSystem();
        $this->enableGate('token_factory');

        // ผูกการยืนยันตัวตนกับบัญชี ไม่ใช่กระเป๋า — กระเป๋าสร้างใหม่ฟรีไม่จำกัด
        $this->assertFalse($this->gate->passes(null, 'token_factory'));
    }

    // =========================================================================
    // ระดับ
    // =========================================================================

    #[Test]
    public function ระดับปกติผ่านด่านที่ขอระดับเพิ่มเติมไม่ได้(): void
    {
        $this->enableSystem();
        $this->enableGate('token_sale', true, 'enhanced');

        $this->approveKyc($this->user, 'basic');

        $this->assertFalse($this->gate->passes($this->user, 'token_sale'));
    }

    #[Test]
    public function ระดับเพิ่มเติมครอบระดับปกติเสมอ(): void
    {
        $this->enableSystem();
        $this->enableGate('ai_bot', true, 'basic');
        $this->enableGate('token_sale', true, 'enhanced');

        $this->approveKyc($this->user, 'enhanced');

        // ส่งเอกสารมากกว่าแล้วต้องไม่ถูกขอซ้ำในด่านที่ขอน้อยกว่า
        $this->assertTrue($this->gate->passes($this->user, 'ai_bot'));
        $this->assertTrue($this->gate->passes($this->user, 'token_sale'));
    }

    #[Test]
    public function ใบที่ถูกล้างข้อมูลแล้วใช้ผ่านด่านไม่ได้(): void
    {
        $this->enableSystem();
        $this->enableGate('ai_bot');

        $submission = $this->approveKyc($this->user);

        $this->assertTrue($this->gate->passes($this->user, 'ai_bot'));

        /*
         * ขอลบข้อมูลแล้วสิทธิต้องหายไปด้วย
         *
         * ไม่งั้นใครก็ได้สิทธิถาวรด้วยการยื่นเอกสารแล้วขอลบทันที
         * เหลือบัญชีที่ "ผ่าน KYC" โดยที่เราไม่มีเอกสารอะไรพิสูจน์เลย
         */
        $submission->forceFill(['purged_at' => now()])->save();

        $this->assertFalse($this->gate->passes($this->user->fresh(), 'ai_bot'));
    }

    #[Test]
    public function แอดมินแก้สถานะที่หน้าสมาชิกอย่างเดียวไม่ทำให้ผ่านด่าน(): void
    {
        $this->enableSystem();
        $this->enableGate('ai_bot');

        // หน้าสมาชิกเดิมแก้คอลัมน์นี้ได้ตรงๆ โดยไม่มีใบคำขอรองรับ
        $this->user->forceFill(['kyc_status' => 'approved'])->save();

        $this->assertFalse(
            $this->gate->passes($this->user->fresh(), 'ai_bot'),
            'ด่านต้องดูใบจริง ไม่ใช่คอลัมน์เงาที่แก้มือได้'
        );
    }

    // =========================================================================
    // resolveUser — ทางเข้าของมือถือ
    // =========================================================================

    #[Test]
    public function เลขกระเป๋าที่ยังไม่พิสูจน์ลายเซ็นใช้ผ่านด่านไม่ได้(): void
    {
        /*
         * ช่องโหว่ที่ต้องกัน: ถ้าเชื่อ wallet_address ที่ติดมากับคำขอเฉยๆ
         * ใครก็ผ่านด่านได้ด้วยการพิมพ์เลขกระเป๋าของคนที่ยืนยันตัวตนแล้ว
         */
        $request = Request::create('/x', 'POST', [
            'wallet_address' => $this->user->wallet_address,
        ]);

        $this->assertNull($this->gate->resolveUser($request));
    }

    #[Test]
    public function เลขกระเป๋าที่พิสูจน์แล้วหาบัญชีเจอ(): void
    {
        $wallet = $this->user->wallet_address;

        // cache ตัวเดียวกับที่ VerifyWalletOwnership ใช้ ไม่ได้ผ่อนเงื่อนไขใหม่
        Cache::put("wallet_verified:{$wallet}", ['ip' => '127.0.0.1'], 3600);

        $request = Request::create('/x', 'POST', ['wallet_address' => $wallet]);

        $this->assertSame($this->user->id, $this->gate->resolveUser($request)?->id);
    }

    // =========================================================================
    // กันจริงที่ปลายทาง ไม่ใช่แค่ซ่อนปุ่ม
    // =========================================================================

    #[Test]
    public function ปลายทางสร้างเหรียญถูกกันเมื่อเปิดด่าน(): void
    {
        $this->enableSystem();
        $this->enableGate('token_factory');

        $wallet = $this->user->wallet_address;
        Cache::put("wallet_verified:{$wallet}", ['ip' => '127.0.0.1'], 3600);

        $response = $this->postJson('/api/v1/token-factory/create', [
            'wallet_address' => $wallet,
            'name' => 'Test Token',
            'symbol' => 'TST',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'KYC_REQUIRED');
    }

    #[Test]
    public function ปลายทางสร้างเหรียญผ่านได้เมื่อยืนยันตัวตนแล้ว(): void
    {
        $this->enableSystem();
        $this->enableGate('token_factory');
        $this->approveKyc($this->user);

        $wallet = $this->user->wallet_address;
        Cache::put("wallet_verified:{$wallet}", ['ip' => '127.0.0.1'], 3600);

        $response = $this->postJson('/api/v1/token-factory/create', [
            'wallet_address' => $wallet,
        ]);

        // ผ่านด่าน KYC แล้ว — ที่เหลือเป็นเรื่องของ validation ของ controller เอง
        $this->assertNotSame(403, $response->status());
    }

    #[Test]
    public function ปลายทางที่แค่อ่านสถานะบอทไม่ถูกกัน(): void
    {
        $this->enableSystem();
        $this->enableGate('ai_bot');

        $wallet = $this->user->wallet_address;
        Cache::put("wallet_verified:{$wallet}", ['ip' => '127.0.0.1'], 3600);

        /*
         * ถ้าไปแปะด่านที่ /status ด้วย หน้า AI Trade จะพังทั้งหน้า
         * ผู้ใช้ที่ยังไม่ยืนยันตัวตนจะเปิดดูไม่ได้เลยว่ามีอะไรให้ใช้
         * เท่ากับปิดไม่ให้เขารู้ว่าต้องยืนยันตัวตนไปเพื่ออะไร
         */
        $response = $this->getJson("/api/v1/ai-bot/status?wallet_address={$wallet}");

        $this->assertNotSame(403, $response->status());
    }

    // =========================================================================
    // สรุปสถานะให้หน้าจอ
    // =========================================================================

    #[Test]
    public function สรุปสถานะส่งครบทุกฟีเจอร์ในคำขอเดียว(): void
    {
        $this->enableSystem();
        $this->enableGate('ai_bot');

        $status = $this->gate->statusFor($this->user);

        $this->assertTrue($status['enabled']);
        $this->assertNull($status['approved_level']);
        $this->assertTrue($status['features']['ai_bot']['required']);
        $this->assertFalse($status['features']['ai_bot']['passed']);
        $this->assertFalse($status['features']['bridge']['required']);
        $this->assertTrue($status['features']['bridge']['passed']);
    }
}
