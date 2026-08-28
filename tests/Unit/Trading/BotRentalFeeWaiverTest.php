<?php

namespace Tests\Unit\Trading;

use App\Models\AiBotPlan;
use App\Models\AiBotSubscription;
use App\Models\SiteSetting;
use App\Models\TradingFeeTier;
use App\Services\Trading\TradingFeeQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — เช่าบอทแล้วไม่เก็บค่าวางไม้อีก.
 *
 * เจ้าของสั่ง 28 ส.ค. 2026: "ยกเลิกค่าวางไม้ทั้งหมด เมื่อเช่าบอทแล้ว ให้เหมาไปเลย
 * ไม่ต้องเอามาคิดอีก เพื่อจะได้คิดกำไรได้เต็มที่ ง่ายกว่าเดิมไม่ซับซ้อน ตรงไปตรงมา"
 *
 * เส้นแบ่งที่ต้องคุม: **แพลนที่เซิร์ฟเวอร์รันบอทให้ (คลาวด์)** ไม่ใช่ดูที่ราคา
 * ฟรี = เบราว์เซอร์ (ปิดจอแล้วหยุด) ไม่ใช่การเช่า · เช่าจริง = คลาวด์
 *
 * ที่ไม่ใช้ราคาเป็นเกณฑ์เพราะเจอกรณีจริงบน prod: แพลน `admin` ของเจ้าของเป็น
 * tier vip รันบนคลาวด์เต็มรูปแบบ แต่ราคา 0 — ถ้าเช็คที่ราคาจะโดนเก็บค่าวางไม้
 *
 * Developed by Xman Studio.
 */
class BotRentalFeeWaiverTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0xabcdefabcdefabcdefabcdefabcdefabcdefabcd';

    private TradingFeeQuoteService $quotes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->quotes = app(TradingFeeQuoteService::class);

        SiteSetting::updateOrCreate(
            ['group' => 'trading', 'key' => 'tpix_fee_enabled'],
            ['value' => '1', 'type' => 'boolean'],
        );

        TradingFeeTier::create([
            'min_order_usd' => 0,
            'max_order_usd' => 100000,
            'fee_tpix' => 25,
            'label' => 'ขั้นทดสอบ',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function a_paid_rental_pays_nothing_to_place_an_order(): void
    {
        $this->rent(pricePerDay: 5, execution: 'cloud');

        $quote = $this->quotes->quote(self::WALLET, 1000, 4289);

        $this->assertTrue($quote['waived'] ?? false);
        $this->assertSame(0.0, $quote['tpix']['fee_tpix']);
        $this->assertSame(0.0, $quote['onchain']['fee_usd']);
        $this->assertTrue($quote['can_place']);
    }

    #[Test]
    public function a_free_browser_plan_still_pays_the_normal_fee(): void
    {
        $this->rent(pricePerDay: 0, execution: 'browser');

        $quote = $this->quotes->quote(self::WALLET, 1000, 4289);

        $this->assertArrayNotHasKey('waived', $quote);
        $this->assertSame(25.0, $quote['tpix']['fee_tpix']);
    }

    #[Test]
    public function an_expired_rental_stops_the_waiver(): void
    {
        $this->rent(pricePerDay: 5, execution: 'cloud', expiresAt: now()->subDay());

        $quote = $this->quotes->quote(self::WALLET, 1000, 4289);

        $this->assertArrayNotHasKey('waived', $quote);
        $this->assertSame(25.0, $quote['tpix']['fee_tpix']);
    }

    #[Test]
    public function a_cancelled_rental_stops_the_waiver(): void
    {
        $this->rent(pricePerDay: 5, execution: 'cloud', status: 'cancelled');

        $quote = $this->quotes->quote(self::WALLET, 1000, 4289);

        $this->assertArrayNotHasKey('waived', $quote);
    }

    #[Test]
    public function a_wallet_without_any_rental_pays_as_before(): void
    {
        $quote = $this->quotes->quote(self::WALLET, 1000, 4289);

        $this->assertSame(25.0, $quote['tpix']['fee_tpix']);
    }

    #[Test]
    public function the_waiver_does_not_leak_to_another_wallet(): void
    {
        $this->rent(pricePerDay: 5, execution: 'cloud');

        $other = $this->quotes->quote('0x'.str_repeat('9', 40), 1000, 4289);

        $this->assertArrayNotHasKey('waived', $other);
        $this->assertSame(25.0, $other['tpix']['fee_tpix']);
    }

    #[Test]
    public function an_anonymous_quote_is_never_waived(): void
    {
        // หน้าเว็บเรียกดูค่าบริการก่อนต่อกระเป๋าได้ — ต้องไม่ตอบว่าฟรี
        $this->rent(pricePerDay: 5, execution: 'cloud');

        $quote = $this->quotes->quote(null, 1000, 4289);

        $this->assertArrayNotHasKey('waived', $quote);
    }

    #[Test]
    public function a_zero_price_cloud_plan_is_still_a_rental(): void
    {
        /*
         * แพลน `admin` บน prod จริง — tier vip รันคลาวด์ แต่ราคา 0
         * เคยพลาดตรงนี้ตอนเขียนครั้งแรก (เช็คที่ราคา) แล้วเจ้าของจะยังโดนเก็บค่าวางไม้
         */
        $this->rent(pricePerDay: 0, execution: 'cloud');

        $quote = $this->quotes->quote(self::WALLET, 1000, 4289);

        $this->assertTrue($quote['waived'] ?? false);
        $this->assertSame(0.0, $quote['tpix']['fee_tpix']);
    }

    private function rent(float $pricePerDay, string $execution = 'cloud', ?string $status = 'active', $expiresAt = null): void
    {
        $plan = AiBotPlan::create([
            'code' => $execution === 'cloud' ? 'pro' : 'free',
            'name' => 'แพลนทดสอบ',
            'tier' => $execution === 'cloud' ? 'pro' : 'free',
            'execution' => $execution,
            'credits_per_day' => $pricePerDay,
            'price_tpix_per_day' => $pricePerDay,
            'max_bots' => 3,
            'max_capital_usd' => 1000,
            'is_active' => true,
        ]);

        AiBotSubscription::create([
            'wallet_address' => self::WALLET,
            'ai_bot_plan_id' => $plan->id,
            'status' => $status,
            'days' => 30,
            'credits_spent' => 0,
            'started_at' => now()->subDay(),
            'expires_at' => $expiresAt ?? now()->addDays(29),
        ]);
    }
}
