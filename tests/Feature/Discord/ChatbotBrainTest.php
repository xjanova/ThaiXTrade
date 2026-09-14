<?php

namespace Tests\Feature\Discord;

use App\Models\Article;
use App\Models\SalePhase;
use App\Models\SiteSetting;
use App\Models\TokenSale;
use App\Services\ChatbotService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TPIX TRADE — "สมอง" ของผู้ช่วย AI (หน้าเว็บ + /ถาม ใน Discord ใช้ตัวเดียวกัน).
 *
 * พบจริงบน production 2026-09-14: ผู้ช่วยตอบ "ขออภัย ระบบไม่สามารถตอบได้" ทุกคำถาม
 * เพราะ ChatbotService บังคับชื่อโมเดล llama-3.3-70b-versatile ที่ทั้ง OpenAI และ Groq ไม่มีแล้ว
 * และความรู้ใน prompt บอกว่า "รับ USDT อย่างเดียว" ทั้งที่รอบขายรับบัตรอย่างเดียว
 *
 * Developed by Xman Studio.
 */
class ChatbotBrainTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        SiteSetting::set('ai', 'openai_api_key', 'sk-test-brain-key');
        config(['services.groq.api_key' => '']);
    }

    private function preparingSale(): void
    {
        $sale = TokenSale::create([
            'name' => 'TPIX Public Sale',
            'slug' => 'tpix-public-sale',
            'status' => 'active',
            'total_supply_for_sale' => 700000000,
            'accept_currencies' => ['CARD'],
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addMonth(),
        ]);

        SalePhase::create([
            'token_sale_id' => $sale->id,
            'name' => 'Public Sale',
            'slug' => 'public-sale',
            'phase_order' => 1,
            'duration_days' => 62,
            'price_usd' => 0.1,
            'allocation' => 200000000,
            'sold' => 0,
            'min_purchase' => 10,
            'max_purchase' => 1000000,
            'vesting_tge_percent' => 50,
            'vesting_cliff_days' => 0,
            'vesting_duration_days' => 90,
            'status' => 'active',
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(20),
        ]);
    }

    public function test_the_brain_uses_the_configured_model_and_knows_the_live_sale_status(): void
    {
        $this->preparingSale();
        Article::create([
            'title' => 'TPIX Chain อัปเกรดใหม่',
            'summary' => 'สรุป',
            'content' => '<p>เนื้อหา</p>',
            'language' => 'th',
            'category' => 'news',
            'status' => 'published',
            'published_at' => now()->subHour(),
        ]);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'ตอนนี้ยังไม่เปิดขายอย่างเป็นทางการครับ [NAV:/token-sale]']]],
                'usage' => ['total_tokens' => 321],
            ]),
            '*' => Http::response(['result' => '0x0']),
        ]);

        $reply = app(ChatbotService::class)->chat('ตอนนี้เปิดขายเหรียญหรือยัง', 'th');

        $this->assertTrue($reply['success']);
        $this->assertSame('ตอนนี้ยังไม่เปิดขายอย่างเป็นทางการครับ', $reply['message']);
        $this->assertSame('/token-sale', $reply['navigation']);

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'api.openai.com')) {
                return false;
            }
            $system = $request['messages'][0]['content'];

            return $request['model'] === 'gpt-4o-mini'
                && str_contains($system, 'LIVE DATA')
                && str_contains($system, 'เตรียมเปิดขาย')
                && str_contains($system, 'TPIX Chain อัปเกรดใหม่')
                && ! str_contains($system, 'Accepts USDT only');
        });
        Http::assertNotSent(fn (Request $r) => ($r['model'] ?? null) === 'llama-3.3-70b-versatile');
    }

    public function test_a_provider_failure_is_reported_as_not_successful(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'model not found']], 404)]);

        $reply = app(ChatbotService::class)->chat('สวัสดี', 'th');

        $this->assertFalse($reply['success']);
        $this->assertStringContainsString('ขออภัย', $reply['message']);
    }
}
