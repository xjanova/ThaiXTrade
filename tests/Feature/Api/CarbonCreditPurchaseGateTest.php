<?php

namespace Tests\Feature\Api;

use App\Models\CarbonCredit;
use App\Models\CarbonProject;
use App\Services\CarbonCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * TPIX TRADE — ซื้อคาร์บอนเครดิตต้องปิดไว้จนกว่าจะมีขั้นจ่ายเงินที่ตรวจบนเชน.
 *
 * ช่องโหว่ที่ปิด: POST /api/v1/carbon-credits/purchase รับ payment_amount / tx_hash เป็นค่าไม่บังคับ
 * และไม่ตรวจการจ่ายเงินเลย แล้ว CarbonCreditService::purchaseCredits ออกเครดิต active + หักสต็อกทันที
 * = ใครก็เซ็นกระเป๋าตัวเอง (ฟรี) แล้วกวาดสต็อกทั้งโครงการ (กลายเป็น sold_out) ไป retire รับใบรับรอง
 * หน้าเว็บที่ใช้อยู่ก็ทำแบบนั้นเมื่อกดซื้อ — ไม่ได้ส่งธุรกรรมอะไรเลย
 *
 * สวิตช์ config/carbon.php `purchase_enabled` ปิดเป็นค่าเริ่มต้น และเปิดได้ด้วย true จริงๆ เท่านั้น
 *
 * Developed by Xman Studio.
 */
class CarbonCreditPurchaseGateTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = '0x1111111111111111111111111111111111111111';

    private const OWNER_IP = '203.0.113.10';

    private CarbonProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // เจ้าของกระเป๋าเพิ่งเซ็นจาก IP นี้ — ผ่านด่าน VerifyWalletOwnership ได้ (แถวเดียวกับ verify-signature)
        Cache::put('wallet_verified:'.self::OWNER, [
            'chain_id' => 4289,
            'ip' => self::OWNER_IP,
            'verified_at' => now()->toIso8601String(),
            'signature_verified' => true,
        ], 14400);

        $this->project = CarbonProject::create([
            'name' => 'Nan Reforestation',
            'description' => 'ปลูกป่าต้นน้ำน่าน',
            'location' => 'Nan',
            'country' => 'TH',
            'project_type' => 'reforestation',
            'total_credits' => 1000,
            'available_credits' => 1000,
            'price_per_credit_usd' => 12,
            'status' => 'active',
        ]);
    }

    private function fromOwner(): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::OWNER_IP]);
    }

    /** ทุกแบบที่เคยออกเครดิตให้ได้ — รวมถึงที่ปลอมหลักฐานการจ่ายเงินมาด้วย */
    public static function purchaseAttempts(): array
    {
        return [
            'แบบที่หน้าเว็บส่ง (ไม่มีธุรกรรม)' => [[
                'amount' => 10,
                'payment_currency' => 'TPIX',
                'payment_amount' => 120,
            ]],
            'กวาดสต็อกทั้งโครงการ' => [[
                'amount' => 1000,
                'payment_currency' => 'USDT',
            ]],
            'ปลอม tx_hash มาด้วย' => [[
                'amount' => 10,
                'payment_currency' => 'BNB',
                'payment_amount' => 999999,
                'tx_hash' => '0x'.str_repeat('ab', 32),
            ]],
            'ไม่ส่งอะไรเลย (ต้องไม่ได้ validation error ที่บอกรายละเอียด)' => [[]],
        ];
    }

    /** สวิตช์ค่าอื่นที่ไม่ใช่ true จริงๆ — ทุกตัวต้องถือว่าปิด */
    public static function notExactlyTrue(): array
    {
        return [
            'ไม่ได้ตั้ง' => [null],
            'false' => [false],
            'สตริง 1' => ['1'],
            'ตัวเลข 1' => [1],
            'yes' => ['yes'],
            'on' => ['on'],
            'สตริง true ที่ไม่ผ่าน env()' => ['true'],
        ];
    }

    #[Test]
    #[DataProvider('purchaseAttempts')]
    public function ปิดอยู่_ซื้อไม่ได้และไม่มีเครดิตออก(array $payload): void
    {
        $this->assertFalse(config('carbon.purchase_enabled'), 'ค่าเริ่มต้นต้องปิด');

        $response = $this->fromOwner()->postJson('/api/v1/carbon-credits/purchase', array_merge([
            'project_id' => $this->project->id,
            'wallet_address' => self::OWNER,
        ], $payload));

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', CarbonCreditService::ERR_PURCHASE_CLOSED);

        // ข้อความที่ผู้ใช้อ่านได้ ไม่ใช่รหัสหรือข้อความ exception ดิบ
        $this->assertStringContainsString('ยังไม่เปิดให้ซื้อคาร์บอนเครดิต', $response->json('error.message'));

        $this->assertSame(0, CarbonCredit::count());

        $project = $this->project->fresh();
        $this->assertEqualsWithDelta(1000.0, (float) $project->available_credits, 0.001);
        $this->assertSame('active', $project->status);
    }

    #[Test]
    #[DataProvider('notExactlyTrue')]
    public function เปิดได้ด้วย_true_จริงๆ_เท่านั้น(mixed $value): void
    {
        config(['carbon.purchase_enabled' => $value]);

        $this->fromOwner()->postJson('/api/v1/carbon-credits/purchase', [
            'project_id' => $this->project->id,
            'wallet_address' => self::OWNER,
            'amount' => 10,
            'payment_currency' => 'TPIX',
        ])->assertStatus(403)->assertJsonPath('error.code', CarbonCreditService::ERR_PURCHASE_CLOSED);

        $this->assertSame(0, CarbonCredit::count());
    }

    /** ด่านอยู่ที่ service ด้วย — ทางอื่นที่เรียก purchaseCredits ในอนาคตต้องไม่ออกเครดิตฟรี */
    #[Test]
    public function service_ปฏิเสธเองแม้ไม่ผ่าน_controller(): void
    {
        try {
            app(CarbonCreditService::class)->purchaseCredits([
                'project_id' => $this->project->id,
                'wallet_address' => self::OWNER,
                'amount' => 10,
                'payment_currency' => 'TPIX',
            ]);
            $this->fail('purchaseCredits ต้องโยน exception ตอนปิดอยู่');
        } catch (RuntimeException $e) {
            $this->assertSame(CarbonCreditService::ERR_PURCHASE_CLOSED, $e->getMessage());
        }

        $this->assertSame(0, CarbonCredit::count());
    }

    /** ปิดการซื้อไม่กระทบเครดิตที่ถืออยู่แล้ว — retire ได้ตามปกติ */
    #[Test]
    public function ปิดการซื้อแล้ว_retire_เครดิตที่มีอยู่ยังได้(): void
    {
        $credit = CarbonCredit::create([
            'carbon_project_id' => $this->project->id,
            'serial_number' => 'CC-EXISTING-20261029',
            'owner_address' => self::OWNER,
            'amount' => 5,
            'price_paid_usd' => 60,
            'status' => 'active',
        ]);

        $this->fromOwner()->postJson('/api/v1/carbon-credits/retire', [
            'credit_id' => $credit->id,
            'amount' => 5,
            'wallet_address' => self::OWNER,
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertSame('retired', $credit->fresh()->status);
    }

    /** หน้าเว็บได้รู้ว่าปิด — ซ่อนปุ่มซื้อแทนที่จะให้ผู้ใช้กดแล้วเจอ error */
    #[Test]
    public function หน้าเว็บได้สถานะปิดขาย(): void
    {
        $this->withoutVite();

        $this->get('/carbon-credits')->assertOk()->assertInertia(fn ($page) => $page
            ->component('CarbonCredit')
            ->where('purchaseEnabled', false));
    }

    /** สวิตช์คือสิ่งเดียวที่ขวางอยู่ — เปิดแล้วทางเดิมต้องยังทำงาน (ขั้นจ่ายเงินบนเชนยังไม่มี ดู config/carbon.php) */
    #[Test]
    public function เปิดสวิตช์แล้วด่านนี้ไม่ขวาง(): void
    {
        config(['carbon.purchase_enabled' => true]);

        $this->fromOwner()->postJson('/api/v1/carbon-credits/purchase', [
            'project_id' => $this->project->id,
            'wallet_address' => self::OWNER,
            'amount' => 10,
            'payment_currency' => 'TPIX',
        ])->assertCreated();

        $this->assertSame(1, CarbonCredit::count());

        $this->withoutVite();
        $this->get('/carbon-credits')->assertInertia(fn ($page) => $page->where('purchaseEnabled', true));
    }
}
