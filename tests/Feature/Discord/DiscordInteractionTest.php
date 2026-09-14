<?php

namespace Tests\Feature\Discord;

use App\Services\ChatbotService;
use App\Services\Discord\DiscordSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * TPIX TRADE — คำสั่ง /ถาม และ /ขายเหรียญ ใน Discord (เจ้าของ: "ตอบคำถามจากข้อมูลในเว็บ สมองเดียวกับหน้าเว็บ").
 *
 * ห้ามหลุด:
 *   1. ลายเซ็นไม่ถูก/เก่า = 401 และไม่ถึงผู้ช่วย AI (มีค่าใช้จ่ายต่อคำถาม)
 *   2. /ถาม ตอบ "กำลังคิด" ทันที แล้วแก้เป็นคำตอบจาก ChatbotService ตัวเดียวกับหน้าเว็บ
 *   3. เพดานต่อคน/ต่อวันกันคนยิงรัวจนบิล OpenAI บาน
 *   4. คำตอบของ AI ปิงคนทั้งเซิร์ฟเวอร์ไม่ได้
 *
 * Developed by Xman Studio.
 */
class DiscordInteractionTest extends TestCase
{
    private const APP_ID = '333333333333333333';

    private string $secretKey;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $keypair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);

        $settings = app(DiscordSettings::class);
        $settings->set('discord_public_key', bin2hex(sodium_crypto_sign_publickey($keypair)));
        $settings->set('discord_ask_enabled', true, 'boolean');

        Http::fake(['discord.com/*' => Http::response(['id' => '1']), '*' => Http::response(['result' => '0x0'])]);
        $this->withoutDefer();
    }

    private function interact(array $payload, ?int $timestamp = null, bool $badSignature = false): TestResponse
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $timestamp = (string) ($timestamp ?? time());
        $signature = bin2hex(sodium_crypto_sign_detached($timestamp.$body, $this->secretKey));

        if ($badSignature) {
            $signature = str_repeat('0', 128);
        }

        return $this->call('POST', '/api/v1/discord/interactions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE_ED25519' => $signature,
            'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp,
        ], $body);
    }

    private function askPayload(string $question, string $userId = '444444444444444444', string $locale = 'th'): array
    {
        return [
            'type' => 2,
            'application_id' => self::APP_ID,
            'token' => 'interaction-token-abcdefghijklmnopqrstuvwxyz',
            'locale' => $locale,
            'member' => ['user' => ['id' => $userId, 'username' => 'สมาชิก']],
            'data' => ['name' => 'ask', 'options' => [['name' => 'question', 'type' => 3, 'value' => $question]]],
        ];
    }

    private function expectBrain(int $times = 1, array $reply = ['message' => 'ตอนนี้ยังไม่เปิดขาย ติดตามที่ /token-sale @everyone', 'navigation' => '/token-sale', 'success' => true]): void
    {
        $this->mock(ChatbotService::class, function ($mock) use ($times, $reply) {
            $mock->shouldReceive('chat')->times($times)->andReturn($reply);
        });
    }

    public function test_a_request_with_a_forged_signature_is_rejected_before_reaching_the_ai(): void
    {
        $this->expectBrain(0);

        $this->interact($this->askPayload('ขอข้อมูลหน่อย'), badSignature: true)->assertStatus(401);
    }

    public function test_a_replayed_old_request_is_rejected(): void
    {
        $this->expectBrain(0);

        $this->interact($this->askPayload('ขอข้อมูลหน่อย'), timestamp: time() - 3600)->assertStatus(401);
    }

    public function test_discord_ping_is_answered_with_pong(): void
    {
        $this->interact(['type' => 1])->assertOk()->assertExactJson(['type' => 1]);
    }

    public function test_ask_defers_then_edits_in_the_answer_from_the_website_brain(): void
    {
        $this->mock(ChatbotService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->with('ตอนนี้เปิดขายเหรียญหรือยัง', 'th')
                ->andReturn(['message' => 'ยังไม่เปิดขายอย่างเป็นทางการ @everyone', 'navigation' => '/token-sale', 'success' => true]);
        });

        $this->interact($this->askPayload('ตอนนี้เปิดขายเหรียญหรือยัง'))->assertOk()->assertExactJson(['type' => 5]);

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/webhooks/'.self::APP_ID.'/interaction-token-abcdefghijklmnopqrstuvwxyz/messages/@original')
            && str_contains($r['content'], 'ตอนนี้เปิดขายเหรียญหรือยัง')
            && str_contains($r['content'], 'ยังไม่เปิดขายอย่างเป็นทางการ')
            && $r['allowed_mentions'] === ['parse' => []]
            && str_ends_with($r['components'][0]['components'][0]['url'], '/token-sale')
            && ! $r->hasHeader('Authorization'));
    }

    public function test_foreign_links_never_go_out_under_the_bots_name(): void
    {
        // คำตอบโพสต์ให้ทั้งห้องเห็นในนามบอททางการ — ลิงก์ฟิชชิงในคำถาม หรือที่ AI ถูกหลอกให้ตอบ ต้องไม่หลุดออกไป
        config(['app.url' => 'https://tpix.online']);
        $this->mock(ChatbotService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn([
                'message' => 'รับเหรียญฟรีที่ http://tpix-airdrop.scam.io/claim หรือ www.fake-tpix.com — ของจริงอยู่ที่ https://tpix.online/token-sale',
                'navigation' => null,
                'success' => true,
            ]);
        });

        $this->interact($this->askPayload('ลิงก์นี้ของจริงไหม https://evil.example/claim?ref=1'))->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && ! str_contains($r['content'], 'evil.example')
            && ! str_contains($r['content'], 'scam.io')
            && ! str_contains($r['content'], 'fake-tpix.com')
            && str_contains($r['content'], 'https://tpix.online/token-sale')
            && str_contains($r['content'], 'ลิงก์ภายนอกถูกซ่อน'));
    }

    public function test_an_english_question_gets_an_english_answer(): void
    {
        $this->mock(ChatbotService::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->with('Is the token sale open?', 'en')
                ->andReturn(['message' => 'Not yet.', 'navigation' => null, 'success' => true]);
        });

        $this->interact($this->askPayload('Is the token sale open?', locale: 'en-US'))->assertOk();
    }

    public function test_one_member_cannot_flood_the_assistant(): void
    {
        app(DiscordSettings::class)->set('discord_ask_user_per_hour', 1, 'number');
        $this->expectBrain(1);

        $this->interact($this->askPayload('คำถามแรก'))->assertOk()->assertJsonPath('type', 5);

        $this->interact($this->askPayload('คำถามที่สอง'))
            ->assertOk()
            ->assertJsonPath('type', 4)
            ->assertJsonPath('data.flags', 64)
            ->assertJsonPath('data.content', fn ($text) => str_contains($text, 'ถามถี่เกินไป'));
    }

    public function test_the_daily_cap_protects_the_shared_ai_bill(): void
    {
        app(DiscordSettings::class)->set('discord_ask_daily_cap', 1, 'number');
        $this->expectBrain(1);

        $this->interact($this->askPayload('คำถามแรก', '500000000000000001'))->assertJsonPath('type', 5);

        $this->interact($this->askPayload('คำถามจากอีกคน', '500000000000000002'))
            ->assertJsonPath('type', 4)
            ->assertJsonPath('data.content', fn ($text) => str_contains($text, 'ครบโควตา'));
    }

    public function test_asking_is_refused_politely_when_switched_off(): void
    {
        app(DiscordSettings::class)->set('discord_ask_enabled', false, 'boolean');
        $this->expectBrain(0);

        $this->interact($this->askPayload('ขอข้อมูลหน่อย'))
            ->assertJsonPath('type', 4)
            ->assertJsonPath('data.flags', 64);
    }

    public function test_sale_command_answers_with_the_truthful_sale_status(): void
    {
        $this->interact([
            'type' => 2,
            'application_id' => self::APP_ID,
            'token' => 'interaction-token-abcdefghijklmnopqrstuvwxyz',
            'data' => ['name' => 'sale'],
        ])->assertOk()->assertExactJson(['type' => 5]);

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/messages/@original')
            && str_contains($r['embeds'][0]['title'], 'การขายเหรียญ'));
    }
}
