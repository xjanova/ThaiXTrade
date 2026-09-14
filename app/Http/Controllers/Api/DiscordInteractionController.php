<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChatbotService;
use App\Services\Discord\DiscordClient;
use App\Services\Discord\DiscordContent;
use App\Services\Discord\DiscordInteractionVerifier;
use App\Services\Discord\DiscordSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use function Illuminate\Support\defer;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * TPIX TRADE — รับคำสั่ง slash ของบอท Discord (/ถาม · /ขายเหรียญ).
 *
 * เจ้าของสั่ง: บอทต้อง "ตอบคำถามจากข้อมูลในเว็บได้ สมองเดียวกับที่อยู่หน้าเว็บ"
 * → /ถาม เรียก ChatbotService ตัวเดียวกับผู้ช่วยลอยบนหน้าเว็บ
 *
 * ทำไมเป็น slash command ไม่ใช่อ่านข้อความทั่วไป: การอ่านข้อความต้องต่อ Gateway ค้างไว้ตลอด
 * ซึ่งเครื่อง shared ไม่อนุญาต process ที่รันค้าง — slash command มาทาง HTTP จึงทำได้ทันที
 *
 * Discord รอคำตอบแค่ 3 วินาที แต่ AI ใช้หลายวินาที → ตอบ "กำลังคิด…" (type 5) ไปก่อน
 * แล้วค่อยแก้ข้อความเป็นคำตอบจริงหลังส่ง response แล้ว (defer)
 *
 * Developed by Xman Studio.
 */
class DiscordInteractionController extends Controller
{
    private const PING = 1;

    private const APPLICATION_COMMAND = 2;

    private const RESPOND_MESSAGE = 4;

    private const RESPOND_DEFERRED = 5;

    /** ข้อความเห็นเฉพาะคนที่พิมพ์คำสั่ง */
    private const EPHEMERAL = 64;

    public function __construct(
        private readonly DiscordSettings $settings,
        private readonly DiscordInteractionVerifier $verifier,
        private readonly DiscordContent $content,
        private readonly DiscordClient $client,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        if (! $this->verifier->verify($request, $this->settings->publicKey())) {
            return response()->json(['error' => 'invalid request signature'], 401);
        }

        $interaction = $request->json()->all();

        if ((int) ($interaction['type'] ?? 0) === self::PING) {
            return response()->json(['type' => self::PING]);
        }

        if ((int) ($interaction['type'] ?? 0) !== self::APPLICATION_COMMAND) {
            return $this->ephemeral('ยังไม่รองรับคำสั่งแบบนี้');
        }

        return match ((string) ($interaction['data']['name'] ?? '')) {
            'ask' => $this->ask($interaction),
            'sale' => $this->sale($interaction),
            default => $this->ephemeral('ไม่รู้จักคำสั่งนี้'),
        };
    }

    /**
     * สถานะการขาย — ตรวจความพร้อมอาจต้องถามยอดจากเชน (นานกว่า 3 วิที่ Discord รอ) จึงตอบทีหลังเช่นกัน.
     */
    private function sale(array $interaction): JsonResponse
    {
        $applicationId = (string) ($interaction['application_id'] ?? '');
        $token = (string) ($interaction['token'] ?? '');

        defer(function () use ($applicationId, $token) {
            $result = $this->client->editOriginalResponse($applicationId, $token, $this->content->saleStatus());

            if (! $result['ok']) {
                Log::warning('Discord /ขายเหรียญ: ส่งคำตอบกลับไม่สำเร็จ', ['error' => $result['error']]);
            }
        });

        return response()->json(['type' => self::RESPOND_DEFERRED]);
    }

    private function ask(array $interaction): JsonResponse
    {
        if (! $this->settings->askEnabled()) {
            return $this->ephemeral('ปิดการถามตอบกับผู้ช่วย AI ชั่วคราว — ดูข้อมูลได้ที่ '.config('app.url'));
        }

        $question = '';
        foreach ((array) ($interaction['data']['options'] ?? []) as $option) {
            if (($option['name'] ?? null) === 'question') {
                $question = trim((string) ($option['value'] ?? ''));
            }
        }

        $maxLength = (int) config('discord.ask.max_question_length', 500);
        if ($question === '' || mb_strlen($question) > $maxLength) {
            return $this->ephemeral("พิมพ์คำถามหลังคำสั่ง /ถาม (ไม่เกิน {$maxLength} ตัวอักษร)");
        }

        $userId = (string) ($interaction['member']['user']['id'] ?? $interaction['user']['id'] ?? 'unknown');
        $perHour = max(1, (int) $this->settings->get('discord_ask_user_per_hour', config('discord.ask.per_user_per_hour', 10)));

        // ต่อคน: กันคนเดียวยิงรัว · ทั้งวัน: เพดานค่าใช้จ่าย (คีย์ OpenAI บิลรวมทั้ง org)
        if (RateLimiter::tooManyAttempts("discord-ask:{$userId}", $perHour)) {
            $minutes = (int) ceil(RateLimiter::availableIn("discord-ask:{$userId}") / 60);

            return $this->ephemeral("ถามถี่เกินไปแล้ว ลองใหม่ได้ในอีก {$minutes} นาที");
        }

        $dailyCap = max(1, (int) $this->settings->get('discord_ask_daily_cap', config('discord.ask.daily_cap', 200)));
        $dayKey = 'discord-ask:day:'.now()->timezone('Asia/Bangkok')->toDateString();
        Cache::add($dayKey, 0, now()->addDay());

        if ((int) Cache::get($dayKey, 0) >= $dailyCap) {
            return $this->ephemeral('วันนี้ผู้ช่วยตอบครบโควตาแล้ว ลองใหม่พรุ่งนี้ หรือดูข้อมูลที่ '.config('app.url'));
        }

        RateLimiter::hit("discord-ask:{$userId}", 3600);
        Cache::increment($dayKey);

        $applicationId = (string) ($interaction['application_id'] ?? '');
        $token = (string) ($interaction['token'] ?? '');
        $language = DiscordContent::languageOf($question, (string) ($interaction['locale'] ?? ''));

        defer(function () use ($question, $language, $applicationId, $token) {
            $reply = app(ChatbotService::class)->chat($question, $language);

            $result = $this->client->editOriginalResponse(
                $applicationId,
                $token,
                $this->content->answer($question, $reply['message'], $reply['navigation'] ?? null),
            );

            if (! $result['ok']) {
                Log::warning('Discord /ถาม: ส่งคำตอบกลับไม่สำเร็จ', ['error' => $result['error']]);
            }
        });

        return response()->json(['type' => self::RESPOND_DEFERRED]);
    }

    private function ephemeral(string $message): JsonResponse
    {
        return response()->json([
            'type' => self::RESPOND_MESSAGE,
            'data' => ['content' => $message, 'flags' => self::EPHEMERAL, 'allowed_mentions' => ['parse' => []]],
        ]);
    }
}
