<?php

namespace Tests\Feature\Api;

use App\Models\AiMarketView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — หน้าเว็บต้องเห็นว่าบอทกำลังคิดอะไรอยู่.
 *
 * แผงนี้ต่างจาก "คำแนะนำ AI" ตรงที่มันคือสิ่งที่ **มีผลต่อการเทรดจริง**
 * ผู้ใช้จึงต้องตรวจสอบได้ และต้องแยกออกว่า "ไม่มีมุมมอง" กับ "ระบบพัง" คนละเรื่อง
 *
 * Developed by Xman Studio.
 */
class AiMarketViewEndpointTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reports_the_feature_being_off(): void
    {
        config()->set('aibot_analyst.enabled', false);

        $this->getJson('/api/v1/ai-bot/market-view')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.view', null);
    }

    #[Test]
    public function no_fresh_view_says_the_bot_is_on_rules_alone(): void
    {
        /*
         * เคสนี้ต้องไม่เงียบ — ผู้ใช้ต้องแยกออกว่าตอนนี้ AI ไม่ได้ช่วยอยู่
         * ไม่งั้นจะเข้าใจว่ามันทำงานตลอดเวลา ทั้งที่รอบวิเคราะห์อาจตายไปแล้ว
         */
        config()->set('aibot_analyst.enabled', true);

        $this->makeView(expired: true);

        $this->getJson('/api/v1/ai-bot/market-view')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.view', null)
            ->assertJsonFragment(['reason' => 'ยังไม่มีมุมมองล่าสุด — บอทกำลังตัดสินใจจากกฎล้วน']);
    }

    #[Test]
    public function it_returns_the_latest_view_without_internal_details(): void
    {
        config()->set('aibot_analyst.enabled', true);

        $this->makeView();

        $response = $this->getJson('/api/v1/ai-bot/market-view')->assertOk();

        $response->assertJsonPath('data.view.regime', 'risk_on');
        $response->assertJsonPath('data.view.coins.BTC.stance', 'buy');
        $response->assertJsonPath('data.view.shortlist', ['BTC/USDT']);

        // prompt กับคำตอบดิบยาวมากและเป็นรายละเอียดภายใน — ห้ามหลุดออก API
        $response->assertJsonMissingPath('data.view.prompt');
        $response->assertJsonMissingPath('data.view.raw_response');
    }

    #[Test]
    public function the_tactical_view_wins_when_both_exist(): void
    {
        config()->set('aibot_analyst.enabled', true);

        $this->makeView(scope: AiMarketView::SCOPE_STRATEGIC, regime: 'risk_off');
        $this->makeView(scope: AiMarketView::SCOPE_TACTICAL, regime: 'risk_on');

        $this->getJson('/api/v1/ai-bot/market-view')
            ->assertOk()
            ->assertJsonPath('data.view.scope', 'tactical')
            ->assertJsonPath('data.view.regime', 'risk_on');
    }

    #[Test]
    public function the_shadow_flag_is_exposed_so_the_page_can_say_so(): void
    {
        config()->set('aibot_analyst.enabled', true);
        config()->set('aibot_analyst.shadow_mode', true);

        $this->makeView();

        $this->getJson('/api/v1/ai-bot/market-view')
            ->assertOk()
            ->assertJsonPath('data.shadow', true);
    }

    #[Test]
    public function the_catalog_exposes_the_shared_switches(): void
    {
        /*
         * หน้าเว็บวาดฟอร์มจาก params ของกลยุทธ์ที่เลือกเท่านั้น — ไม่ส่งสวิตช์ร่วม
         * ไปด้วย ผู้ใช้จะไม่มีทางเปิด "ให้ AI เลือกเหรียญ" ได้เลย ทั้งที่เซิร์ฟเวอร์
         * รองรับแล้ว (รูปแบบเดียวกับ news_filter ที่เคยตั้งไม่ได้มาตลอด)
         */
        $keys = collect($this->getJson('/api/v1/ai-bot/catalog')->assertOk()->json('data.common_params'))
            ->pluck('key');

        $this->assertContains('auto_pair', $keys);
        $this->assertContains('news_filter', $keys);
    }

    private function makeView(
        string $scope = AiMarketView::SCOPE_STRATEGIC,
        string $regime = 'risk_on',
        bool $expired = false,
    ): AiMarketView {
        return AiMarketView::create([
            'scope' => $scope,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'regime' => $regime,
            'confidence' => 0.8,
            'size_multiplier' => 1.0,
            'coins' => ['BTC' => ['score' => 0.6, 'stance' => 'buy', 'why' => 'เงินไหลเข้า']],
            'shortlist' => ['BTC/USDT'],
            'summary' => 'ตลาดฟื้นตัว',
            'prompt' => 'ข้อความยาวมากที่ไม่ควรหลุดออก API',
            'raw_response' => '{"regime":"risk_on"}',
            'expires_at' => $expired ? now()->subMinute() : now()->addHour(),
        ]);
    }
}
