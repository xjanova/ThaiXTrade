<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\AiAnalysis;
use App\Models\AiNews;
use App\Models\Article;
use App\Models\SiteSetting;
use App\Services\AiTextService;
use App\Services\ChatbotService;
use App\Services\SiteKnowledgeService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — สวิตช์ AI ในหลังบ้านต้องมีผลจริง และงาน AI ที่แอดมินกดเองต้องล้มอย่างสะอาด.
 *
 * `ai.ai_chatbot_enabled` กับ `ai.ai_content_enabled` ถูกบันทึกจากแท็บ AI มาตลอด แต่ไม่มีโค้ดฝั่ง
 * เซิร์ฟเวอร์ตัวไหนอ่าน — แอดมินปิดแล้วผู้ช่วยยังตอบ บอทยังเขียนบทความ และ API ยังถูกเรียกเก็บเงินต่อ
 *
 * ชุดนี้ยืนยันสามเรื่อง:
 *   1. ปิดแล้ว "ไม่มีคำขอออกไปหาผู้ให้บริการ AI เลย" (ไม่ใช่แค่ซ่อนผลลัพธ์)
 *   2. ไม่เคยตั้งค่า = เปิด (production ที่ยังไม่มีแถวต้องทำงานเหมือนเดิม)
 *   3. ผู้ให้บริการล้มทุกเจ้า → ข้อความสะอาด และแถววิเคราะห์ไม่ค้างสถานะ processing
 *
 * Developed by Xman Studio.
 */
class AiFeatureTogglesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('public');

        SiteSetting::set('ai', 'openai_api_key', 'sk-test-toggle-key');
        config(['services.groq.api_key' => '']);
    }

    private function fakeAi(string $content = 'สวัสดีค่ะ'): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => $content]]],
                'usage' => ['total_tokens' => 10],
            ]),
            'image.pollinations.ai/*' => Http::response(str_repeat("\xFF\xD8\xFF", 600), 200, ['Content-Type' => 'image/jpeg']),
            '*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x0']),
        ]);
    }

    private function aiRequests(): int
    {
        return collect(Http::recorded())
            ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'api.openai.com') || str_contains($pair[0]->url(), 'api.groq.com'))
            ->count();
    }

    private function signInAdmin(): AdminUser
    {
        $admin = AdminUser::create([
            'name' => 'ผู้ดูแลระบบ',
            'email' => 'admin-ai@tpix.test',
            'password' => bcrypt('secret-password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->withSession([Auth::guard('admin')->getName() => $admin->id]);

        return $admin;
    }

    private function articleJson(): string
    {
        return json_encode([
            'title' => 'TPIX Chain คืออะไร',
            'summary' => 'สรุปสั้นๆ เกี่ยวกับ TPIX Chain',
            'content' => '<p>'.str_repeat('TPIX Chain เป็นบล็อกเชนที่ไม่มีค่าแก๊ส เหมาะกับการใช้งานจริง ', 12).'</p>',
            'tags' => ['TPIX Chain'],
            'seo_title' => 'TPIX Chain คืออะไร',
            'seo_description' => 'ทำความรู้จัก TPIX Chain',
        ], JSON_UNESCAPED_UNICODE);
    }

    // ── ผู้ช่วย AI ──────────────────────────────────────────────────────────

    /** ⭐ ปิดแล้วต้องตอบข้อความปิดบริการในรูปที่หน้าเว็บรับได้ และไม่ยิง AI เลย */
    #[Test]
    public function ปิดผู้ช่วย_ai_แล้วตอบว่าปิดบริการชั่วคราวโดยไม่เรียกผู้ให้บริการ(): void
    {
        SiteSetting::set('ai', 'ai_chatbot_enabled', '0', 'boolean');
        $this->fakeAi();

        $this->postJson('/api/v1/chatbot', ['message' => 'TPIX คืออะไร', 'language' => 'th'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.success', false)
            ->assertJsonPath('data.navigation', null)
            ->assertJsonPath('data.message', 'ผู้ช่วย AI ปิดให้บริการชั่วคราว กรุณาลองใหม่ภายหลัง');

        $this->postJson('/api/v1/chatbot', ['message' => 'What is TPIX?', 'language' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.success', false)
            ->assertJsonPath('data.message', 'The AI assistant is temporarily unavailable. Please try again later.');

        $this->assertSame(0, $this->aiRequests());
    }

    /** ไม่เคยตั้งค่า / เปิดอยู่ = ผู้ช่วยตอบตามปกติ (ค่าที่บันทึกแบบข้อความก็ต้องอ่านถูก) */
    #[Test]
    public function ไม่เคยตั้งหรือเปิดอยู่ผู้ช่วย_ai_ตอบตามปกติ(): void
    {
        $this->fakeAi('TPIX คือเหรียญหลักของ TPIX Chain');

        $this->postJson('/api/v1/chatbot', ['message' => 'TPIX คืออะไร'])
            ->assertOk()->assertJsonPath('data.success', true);

        foreach ([['1', 'boolean'], ['true', 'string'], ['1', 'string']] as [$value, $type]) {
            SiteSetting::set('ai', 'ai_chatbot_enabled', $value, $type);

            $this->postJson('/api/v1/chatbot', ['message' => 'TPIX คืออะไร'])
                ->assertOk()->assertJsonPath('data.success', true);
        }

        // ค่าที่ถูกเก็บเป็นข้อความ "0" (ชนิดคอลัมน์เพี้ยนจากรุ่นเก่า) ก็ต้องนับว่าปิด
        SiteSetting::set('ai', 'ai_chatbot_enabled', '0', 'string');
        $this->postJson('/api/v1/chatbot', ['message' => 'TPIX คืออะไร'])
            ->assertOk()->assertJsonPath('data.success', false);
    }

    /** พรอมต์ต้องไม่โฆษณาของที่ไม่มีบนเว็บ และต้องบอกว่า AI TRADE เป็นโหมดทดลอง */
    #[Test]
    public function พรอมต์ของผู้ช่วยไม่โฆษณาฟีเจอร์ที่ไม่มีจริง(): void
    {
        $this->fakeAi();
        $this->mock(SiteKnowledgeService::class, fn (MockInterface $mock) => $mock->shouldReceive('liveFacts')->andReturn('## LIVE DATA'));

        app(ChatbotService::class)->chat('AI TRADE เทรดเงินจริงไหม');

        $system = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->first(fn ($request) => str_contains($request->url(), 'api.openai.com'))['messages'][0]['content'];

        // ของที่ถอดออก — ห้ามกลับมาในรูปคำโฆษณา
        foreach (['buy/sell AI bots', 'digital collectibles', 'referral rewards in TPIX', '5%-200%', '365 days: 200%', '100 TPIX fee', 'Laravel'] as $gone) {
            $this->assertStringNotContainsString($gone, $system, "พรอมต์ยังโฆษณา: {$gone}");
        }

        $this->assertStringContainsString('DEMO mode only', $system);
        $this->assertStringContainsString('coming soon', $system);
        $this->assertStringContainsString('NEVER quote an APY', $system);
        $this->assertStringContainsString('NOT available on tpix.online yet', $system);
    }

    // ── คอนเทนต์ที่ AI เขียน ────────────────────────────────────────────────

    /** ⭐ ปิด AI Content: บอทตามเวลาไม่เขียนอะไร และไม่ยิง AI แม้เปิด auto content ไว้ */
    #[Test]
    public function ปิด_ai_content_แล้วบอทตามเวลาไม่เขียนบทความ(): void
    {
        $this->fakeAi($this->articleJson());
        SiteSetting::set('content', 'content_auto_enabled', '1', 'boolean');
        SiteSetting::set('content', 'content_event_enabled', '0', 'boolean');
        SiteSetting::set('content', 'content_time_slots', json_encode([now()->format('H:i')]));
        SiteSetting::set('ai', 'ai_content_enabled', '0', 'boolean');

        $this->artisan('content:generate-scheduled')
            ->expectsOutputToContain('AI content generation is disabled')
            ->assertSuccessful();

        $this->assertSame(0, Article::count());
        $this->assertSame(0, $this->aiRequests());

        // เปิดกลับ = เขียนได้ตามเดิม (พิสูจน์ว่าที่ไม่เขียนข้างบนเป็นเพราะสวิตช์ ไม่ใช่เงื่อนไขอื่น)
        SiteSetting::set('ai', 'ai_content_enabled', '1', 'boolean');
        $this->artisan('content:generate-scheduled')->assertSuccessful();

        $this->assertGreaterThan(0, Article::count());
        $this->assertGreaterThan(0, $this->aiRequests());
    }

    /** ปิด AI Content: แอดมินกดสร้างบทความ/ข่าวเองก็ไม่ได้ พร้อมข้อความบอกเหตุผล */
    #[Test]
    public function ปิด_ai_content_แล้วแอดมินกดสร้างเองก็ไม่ได้(): void
    {
        $this->fakeAi($this->articleJson());
        SiteSetting::set('ai', 'ai_content_enabled', '0', 'boolean');
        $this->signInAdmin();

        $this->post('/admin/content/generate', ['topic' => 'TPIX Chain', 'category' => 'tpix_chain', 'language' => 'th'])
            ->assertRedirect()
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'ปิดการสร้างคอนเทนต์ด้วย AI'));

        $this->post('/admin/ai/news/generate', ['topic' => 'TPIX', 'category' => 'analysis'])
            ->assertRedirect()
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'ปิดการสร้างคอนเทนต์ด้วย AI'));

        $this->assertSame(0, Article::count());
        $this->assertSame(0, AiNews::count());
        $this->assertSame(0, $this->aiRequests());
    }

    /**
     * ⭐ แอดมินสร้างบทความเองโดยไม่ส่งชื่อโมเดล (หรือส่งชื่อที่ถูกถอดแล้วจากหน้าจอรุ่นเก่า) ต้องสำเร็จ
     *    ด้วยโมเดลตามค่าตั้ง — เดิมหน้าเว็บส่ง llama-3.3-70b-versatile ทุกครั้งแล้วล้มทุกครั้ง.
     */
    #[Test]
    public function แอดมินสร้างบทความเองใช้โมเดลตามค่าตั้งแม้หน้าจอส่งชื่อที่ถูกถอดแล้ว(): void
    {
        $this->fakeAi($this->articleJson());
        $this->signInAdmin();

        foreach ([[], ['model' => ''], ['model' => 'llama-3.3-70b-versatile']] as $extra) {
            $this->post('/admin/content/generate', ['topic' => 'TPIX Chain', 'category' => 'tpix_chain', 'language' => 'th'] + $extra)
                ->assertRedirect()
                ->assertSessionMissing('error');
        }

        $this->assertSame(3, Article::count());

        $models = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn ($request) => str_contains($request->url(), 'api.openai.com'))
            ->map(fn ($request) => $request['model'])
            ->unique()->values()->all();

        $this->assertSame(['gpt-4o-mini'], $models);
    }

    // ── วิเคราะห์ตลาดในหลังบ้าน ─────────────────────────────────────────────

    /** ⭐ ล้มทุกผู้ให้บริการ: ไม่ 500 · แถวเป็น failed (ไม่ค้าง processing) · ไม่มีข้อความดิบของผู้ให้บริการ */
    #[Test]
    public function วิเคราะห์ตลาดล้มทุกผู้ให้บริการแล้วแถวไม่ค้างและข้อความสะอาด(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key provided: sk-test-****']], 401),
            '*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x0']),
        ]);
        $this->signInAdmin();

        $this->post('/admin/ai/analyze', ['symbol' => 'BTC/USDT', 'type' => 'technical'])
            ->assertRedirect()
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'วิเคราะห์ไม่สำเร็จ')
                && ! str_contains($message, 'Incorrect API key')
                && ! str_contains($message, 'sk-test'));

        $analysis = AiAnalysis::sole();
        $this->assertSame('failed', $analysis->status);
        $this->assertNotEmpty($analysis->error_message);
        $this->assertStringNotContainsString('sk-test', $analysis->error_message);
        $this->assertSame(0, AiAnalysis::where('status', 'processing')->count());
    }

    /** ตัว service โยน exception กลางทางก็ต้องไม่ทิ้งแถวค้าง processing */
    #[Test]
    public function วิเคราะห์ตลาดเจอ_exception_แล้วแถวก็ไม่ค้าง(): void
    {
        $this->mock(AiTextService::class, fn (MockInterface $mock) => $mock->shouldReceive('analyzeMarket')
            ->andThrow(new \RuntimeException('SQLSTATE[HY000] secret internals')));
        $this->signInAdmin();

        $this->post('/admin/ai/analyze', ['symbol' => 'BTC/USDT', 'type' => 'technical'])
            ->assertRedirect()
            ->assertSessionHas('error', fn ($message) => ! str_contains($message, 'SQLSTATE'));

        $this->assertSame('failed', AiAnalysis::sole()->status);
    }

    /** โมเดลที่ส่งมาต้องเป็นตัวที่ระบบรู้จัก — ชื่อที่ถูกถอดแล้วถูกปฏิเสธตั้งแต่ตรวจข้อมูล */
    #[Test]
    public function วิเคราะห์ตลาดรับเฉพาะโมเดลที่ระบบรู้จัก(): void
    {
        $this->fakeAi('ผลวิเคราะห์');
        $this->signInAdmin();

        $this->post('/admin/ai/analyze', ['symbol' => 'BTC/USDT', 'type' => 'technical', 'model' => 'llama-3.3-70b-versatile'])
            ->assertSessionHasErrors('model');
        $this->assertSame(0, AiAnalysis::count());

        $this->post('/admin/ai/analyze', ['symbol' => 'BTC/USDT', 'type' => 'technical', 'model' => 'gpt-4o'])
            ->assertSessionHasNoErrors();

        $analysis = AiAnalysis::sole();
        $this->assertSame('completed', $analysis->status);
        $this->assertSame('gpt-4o', $analysis->model);
    }
}
