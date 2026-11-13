<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Services\AiTextService;
use App\Services\GroqService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — งานข้อความด้วย AI ต้องมีตัวสำรองที่ใช้ได้จริง (OpenAI → Groq).
 *
 * ตัวสำรองเคยตายพร้อมตัวหลักมาตลอด: สาย Groq ไม่อ่าน config/ai_text.php ใช้โมเดลที่ฝังในโค้ด
 * (llama-3.3-70b-versatile ที่ Groq ถอดแล้ว) และค่าเดียวกันยังค้างอยู่ในหลังบ้านของ production
 * ชุดนี้ยิง HTTP ปลอมเพื่อดูว่า "ชื่อโมเดลที่ส่งออกไปจริง" คืออะไร ไม่ใช่แค่ว่าเรียกเมธอดถูก
 *
 * Developed by Xman Studio.
 */
class AiTextServiceTest extends TestCase
{
    private const RETIRED = 'llama-3.3-70b-versatile';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        SiteSetting::set('ai', 'openai_api_key', 'sk-test-openai');
        SiteSetting::set('ai', 'groq_api_key', 'gsk-test-groq');
    }

    private function answer(string $content = 'สวัสดี'): array
    {
        return ['choices' => [['message' => ['content' => $content]]], 'usage' => ['total_tokens' => 42]];
    }

    /** @return list<Request> คำขอที่ยิงไปยังโฮสต์นี้ ตามลำดับ */
    private function sentTo(string $host): array
    {
        return collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $request) => str_contains($request->url(), $host))
            ->values()
            ->all();
    }

    /** ⭐ OpenAI ล้ม → Groq ต้องตอบได้ด้วยโมเดลปริยายจาก config ไม่ใช่โมเดลที่ฝังในโค้ด */
    #[Test]
    public function openai_ล้มแล้ว_groq_ตอบด้วยโมเดลจาก_config(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'upstream exploded']], 500),
            'api.groq.com/*' => Http::response($this->answer('คำตอบจากตัวสำรอง')),
        ]);

        $result = app(AiTextService::class)->chat('ทดสอบ', 'system');

        $this->assertTrue($result['success']);
        $this->assertSame('groq', $result['provider']);
        $this->assertSame('คำตอบจากตัวสำรอง', $result['content']);
        $this->assertSame('openai/gpt-oss-120b', $result['model']);

        $groq = $this->sentTo('api.groq.com');
        $this->assertCount(1, $groq);
        $this->assertSame('openai/gpt-oss-120b', $groq[0]['model']);
        $this->assertSame('https://api.groq.com/openai/v1/chat/completions', $groq[0]->url());
        $this->assertTrue($groq[0]->hasHeader('Authorization', 'Bearer gsk-test-groq'));
    }

    /** ⭐ โมเดลที่ถูกถอดแล้วแต่ยังค้างในหลังบ้าน (สภาพจริงของ production) = ถือว่าไม่ได้ตั้ง */
    #[Test]
    public function โมเดลที่ถูกถอดแล้วในหลังบ้านถูกเมินแล้วใช้ค่าปริยายแทน(): void
    {
        SiteSetting::set('ai', 'groq_default_model', self::RETIRED);
        Http::fake([
            'api.openai.com/*' => Http::response([], 429),
            'api.groq.com/*' => Http::response($this->answer()),
        ]);

        $result = app(AiTextService::class)->chat('ทดสอบ');

        $this->assertTrue($result['success']);
        $this->assertSame('openai/gpt-oss-120b', $this->sentTo('api.groq.com')[0]['model']);
    }

    /** แอดมินเลือกโมเดลที่ยังมีอยู่ = ใช้ตามที่เลือก (ด่านถอดโมเดลต้องไม่ทับค่าที่ถูกต้อง) */
    #[Test]
    public function โมเดลที่แอดมินเลือกและยังใช้ได้ถูกใช้ตามนั้น(): void
    {
        SiteSetting::set('ai', 'groq_default_model', 'openai/gpt-oss-20b');
        Http::fake([
            'api.openai.com/*' => Http::response([], 500),
            'api.groq.com/*' => Http::response($this->answer()),
        ]);

        app(AiTextService::class)->chat('ทดสอบ');

        $this->assertSame('openai/gpt-oss-20b', $this->sentTo('api.groq.com')[0]['model']);
    }

    /**
     * ⭐ ชื่อโมเดลที่ผู้เรียกระบุมาต้องไม่ถูกส่งต่อไปทุกเจ้า.
     *
     * เดิมหน้าหลังบ้านส่ง model=llama-3.3-70b-versatile มาทุกครั้ง แล้วถูกส่งต่อไปทั้ง OpenAI และ Groq
     * ทั้งสองเจ้าไม่มีโมเดลนี้ → เขียนบทความเองจากหลังบ้านไม่ได้เลยสักครั้ง
     */
    #[Test]
    public function โมเดลที่ผู้เรียกระบุมาใช้เฉพาะกับผู้ให้บริการที่มีโมเดลนั้น(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response($this->answer()),
            'api.groq.com/*' => Http::response($this->answer()),
        ]);

        // โมเดลที่ถูกถอดแล้ว → OpenAI ใช้โมเดลของตัวเอง
        $result = app(AiTextService::class)->chat('ทดสอบ', '', ['model' => self::RETIRED]);
        $this->assertTrue($result['success']);
        $this->assertSame('gpt-4o-mini', $this->sentTo('api.openai.com')[0]['model']);

        // โมเดลของ OpenAI ที่มีอยู่จริง → ใช้ตามที่ขอ
        app(AiTextService::class)->chat('ทดสอบ', '', ['model' => 'gpt-4o']);
        $this->assertSame('gpt-4o', $this->sentTo('api.openai.com')[1]['model']);
    }

    #[Test]
    public function โมเดลของ_groq_ที่ผู้เรียกระบุมาไม่ถูกส่งให้_openai_แต่ใช้กับ_groq_ได้(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([], 500),
            'api.groq.com/*' => Http::response($this->answer()),
        ]);

        app(AiTextService::class)->chat('ทดสอบ', '', ['model' => 'qwen/qwen3.8-27b']);

        $this->assertSame('gpt-4o-mini', $this->sentTo('api.openai.com')[0]['model']);
        $this->assertSame('qwen/qwen3.8-27b', $this->sentTo('api.groq.com')[0]['model']);
    }

    /** ล้มทุกเจ้า: ข้อความที่คืนต้องไม่มีข้อความดิบของผู้ให้บริการหรือ exception และไม่มีคีย์ */
    #[Test]
    public function ล้มทุกผู้ให้บริการแล้วข้อความที่คืนไม่มีรายละเอียดดิบ(): void
    {
        Http::fake([
            'api.openai.com/*' => fn () => throw new ConnectionException('cURL error 28: timed out for https://api.openai.com/v1/chat/completions'),
            'api.groq.com/*' => Http::response(['error' => ['message' => 'The model `x` has been decommissioned']], 404),
        ]);

        $result = app(AiTextService::class)->chat('ทดสอบ');

        $this->assertFalse($result['success']);
        $this->assertStringNotContainsString('cURL', $result['error']);
        $this->assertStringNotContainsString('decommissioned', $result['error']);
        $this->assertStringNotContainsString('sk-test-openai', $result['error']);
        $this->assertStringNotContainsString('gsk-test-groq', $result['error']);
    }

    /** รายการโมเดลที่หลังบ้านโชว์ต้องไม่มีตัวที่ถูกถอดแล้ว */
    #[Test]
    public function รายการโมเดลที่เลือกได้ไม่มีตัวที่ถูกถอดแล้ว(): void
    {
        $models = array_keys(app(AiTextService::class)->getModels());

        $this->assertContains('openai/gpt-oss-120b', $models);
        $this->assertContains('qwen/qwen3.8-27b', $models);

        foreach ((array) config('ai_text.providers.groq.retired_models') as $retired) {
            $this->assertNotContains($retired, $models, "{$retired} ถูกถอดแล้ว ต้องไม่อยู่ในรายการเลือก");
        }

        $this->assertSame(
            array_keys((array) config('ai_text.providers.groq.models')),
            array_keys(app(GroqService::class)->getModels()),
        );
    }
}
