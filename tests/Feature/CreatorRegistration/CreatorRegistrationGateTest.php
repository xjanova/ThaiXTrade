<?php

namespace Tests\Feature\CreatorRegistration;

use App\Models\CreatorRegistration;
use App\Models\SiteSetting;
use App\Services\CreatorRegistration\CreatorRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesTokenFactory;
use Tests\TestCase;

/**
 * สวิตช์ "ต้องลงทะเบียนผู้สร้างก่อนสร้างเหรียญ" ที่ Token Factory.
 *
 * ปิดอยู่ (ค่าตั้งต้น) ต้องไม่กันใคร — เปิดแล้วต้องกันที่ใบเสนอราคา จนกว่าใบจะอนุมัติ
 *
 * Developed by Xman Studio.
 */
class CreatorRegistrationGateTest extends TestCase
{
    use FakesTokenFactory;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTokenFactory();
        Queue::fake();
    }

    private function requireRegistration(bool $on = true): void
    {
        // set() ล้างแคชของคีย์นี้เอง — ไม่ flush ทั้งหมด (จะลบสถานะกระเป๋าที่ยืนยันไว้ใน setUp)
        SiteSetting::set(CreatorRegistrationService::SETTING_GROUP, CreatorRegistrationService::KEY_REQUIRED, $on ? '1' : '0', 'boolean');
    }

    private function registration(string $status): CreatorRegistration
    {
        return CreatorRegistration::create([
            'wallet_address' => $this->creator,
            'status' => $status,
            'ownership_message' => 'test',
            'ownership_signature' => '0x'.str_repeat('1', 130),
            'ownership_signed_at' => now(),
            'starter_allocation_bps' => $status === CreatorRegistration::STATUS_APPROVED ? 1000 : null,
        ]);
    }

    #[Test]
    public function ปิดสวิตช์อยู่_สร้างเหรียญได้ตามปกติ(): void
    {
        $this->postJson('/api/v1/token-factory/quote', $this->tokenForm())->assertOk();
    }

    #[Test]
    public function เปิดสวิตช์แล้ว_ยังไม่ลงทะเบียนสร้างไม่ได้(): void
    {
        $this->requireRegistration();

        $this->postJson('/api/v1/token-factory/quote', $this->tokenForm())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CREATOR_REGISTRATION_REQUIRED')
            ->assertJsonPath('error.url', '/token-creator');
    }

    #[Test]
    public function เปิดสวิตช์แล้ว_ใบที่ยังรอตรวจหรือถูกปฏิเสธสร้างไม่ได้(): void
    {
        $this->requireRegistration();
        $r = $this->registration(CreatorRegistration::STATUS_SUBMITTED);

        $this->postJson('/api/v1/token-factory/quote', $this->tokenForm())->assertStatus(403);

        $r->update(['status' => CreatorRegistration::STATUS_REJECTED]);
        $this->postJson('/api/v1/token-factory/quote', $this->tokenForm())->assertStatus(403);
    }

    #[Test]
    public function เปิดสวิตช์แล้ว_ใบที่อนุมัติแล้วสร้างได้(): void
    {
        $this->requireRegistration();
        $this->registration(CreatorRegistration::STATUS_APPROVED);

        $this->postJson('/api/v1/token-factory/quote', $this->tokenForm())->assertOk();
    }
}
