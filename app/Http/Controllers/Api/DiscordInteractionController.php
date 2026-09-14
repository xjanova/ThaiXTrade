<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChatbotService;
use App\Services\Discord\DiscordClient;
use App\Services\Discord\DiscordContent;
use App\Services\Discord\DiscordInteractionVerifier;
use App\Services\Discord\DiscordModerator;
use App\Services\Discord\DiscordPermissions;
use App\Services\Discord\DiscordSettings;
use App\Services\Discord\DiscordSetup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use function Illuminate\Support\defer;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * TPIX TRADE — รับคำสั่งของบอท Discord (/ถาม · /ขายเหรียญ · /ราคา · /เชน · /ลิงก์ · คลิกขวา "รายงานให้แอดมิน" · ปุ่มตัดสินบนการ์ดรายงาน).
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

    /** กดปุ่มบนข้อความของบอท (ปุ่มตัดสินบนการ์ดรายงาน) */
    private const MESSAGE_COMPONENT = 3;

    private const RESPOND_MESSAGE = 4;

    private const RESPOND_DEFERRED = 5;

    /** รับปุ่มแล้ว จะแก้ข้อความเดิมตามหลัง (ไม่โพสต์ข้อความใหม่) */
    private const RESPOND_DEFERRED_UPDATE = 6;

    /** ข้อความเห็นเฉพาะคนที่พิมพ์คำสั่ง */
    private const EPHEMERAL = 64;

    /** ปุ่มบนการ์ดรายงาน → สิทธิ์ที่คนกดต้องมีในห้องนั้น */
    private const BUTTON_PERMISSION = [
        'timeout' => DiscordPermissions::MODERATE_MEMBERS,
        'kick' => DiscordPermissions::KICK_MEMBERS,
        'ban' => DiscordPermissions::BAN_MEMBERS,
        'delete' => DiscordPermissions::MANAGE_MESSAGES,
        'dismiss' => DiscordPermissions::MANAGE_MESSAGES,
    ];

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
        $type = (int) ($interaction['type'] ?? 0);

        if ($type === self::PING) {
            return response()->json(['type' => self::PING]);
        }

        if ($type === self::MESSAGE_COMPONENT) {
            return $this->button($interaction);
        }

        if ($type !== self::APPLICATION_COMMAND) {
            return $this->ephemeral('ยังไม่รองรับคำสั่งแบบนี้');
        }

        return match ((string) ($interaction['data']['name'] ?? '')) {
            'ask' => $this->ask($interaction),
            'sale' => $this->later($interaction, '/ขายเหรียญ', fn () => $this->content->saleStatus()),
            'price' => $this->later($interaction, '/ราคา', fn () => $this->content->priceReply()),
            'chain' => $this->later($interaction, '/เชน', fn () => $this->content->chainStatus()),
            'links' => $this->later($interaction, '/ลิงก์', fn () => $this->content->officialLinks()),
            DiscordSetup::REPORT_COMMAND => $this->report($interaction),
            default => $this->ephemeral('ไม่รู้จักคำสั่งนี้'),
        };
    }

    /**
     * คำสั่งที่ต้องดึงข้อมูลสด (ถามเชน/ราคา/ยอดขาย อาจนานกว่า 3 วินาทีที่ Discord รอ)
     * → ตอบ "กำลังคิด…" ไปก่อน แล้วแก้เป็นคำตอบจริงหลังส่ง response แล้ว.
     *
     * @param  callable(): array<string, mixed>  $build
     */
    private function later(array $interaction, string $label, callable $build): JsonResponse
    {
        $applicationId = (string) ($interaction['application_id'] ?? '');
        $token = (string) ($interaction['token'] ?? '');

        defer(function () use ($applicationId, $token, $label, $build) {
            $result = $this->client->editOriginalResponse($applicationId, $token, $build());

            if (! $result['ok']) {
                Log::warning("Discord {$label}: ส่งคำตอบกลับไม่สำเร็จ", ['error' => $result['error']]);
            }
        });

        return response()->json(['type' => self::RESPOND_DEFERRED]);
    }

    /**
     * คลิกขวาที่ข้อความ → Apps → รายงานให้แอดมิน
     * ส่งการ์ดพร้อมปุ่มตัดสินเข้าห้องแจ้งเตือนแอดมิน · ตอบผู้รายงานแบบเห็นคนเดียว.
     */
    private function report(array $interaction): JsonResponse
    {
        $guildId = $this->settings->guildId();
        if ($guildId === null || (string) ($interaction['guild_id'] ?? '') !== $guildId) {
            return $this->ephemeral('รายงานได้เฉพาะในเซิร์ฟเวอร์ TPIX');
        }

        $logChannel = (string) $this->settings->get('discord_mod_log_channel', '');
        if (! preg_match(DiscordSettings::SNOWFLAKE, $logChannel)) {
            return $this->ephemeral('ยังไม่ได้เปิดระบบรายงาน — แจ้งแอดมินโดยตรงก่อนนะครับ');
        }

        $messageId = (string) ($interaction['data']['target_id'] ?? '');
        $message = $interaction['data']['resolved']['messages'][$messageId] ?? null;
        $reporterId = (string) ($interaction['member']['user']['id'] ?? '');
        $authorId = (string) ($message['author']['id'] ?? '');
        $channelId = (string) ($message['channel_id'] ?? $interaction['channel_id'] ?? '');

        foreach ([$messageId, $reporterId, $authorId, $channelId] as $id) {
            if (! preg_match(DiscordSettings::SNOWFLAKE, $id)) {
                return $this->ephemeral('อ่านข้อความที่รายงานไม่ได้ ลองใหม่อีกครั้ง');
            }
        }

        if ($authorId === $reporterId) {
            return $this->ephemeral('รายงานข้อความของตัวเองไม่ได้ — ลบหรือแก้ข้อความเองได้เลย');
        }

        // กันคนเดียวรายงานรัว (ใช้ป่วนห้องแอดมิน) · ข้อความเดียวกันรายงานซ้ำไม่สร้างการ์ดใหม่
        if (RateLimiter::tooManyAttempts("discord-report:{$reporterId}", 10)) {
            return $this->ephemeral('รายงานถี่เกินไป — แอดมินกำลังดูรายงานก่อนหน้าของคุณอยู่');
        }
        if (! Cache::add("discord-report:msg:{$messageId}", $reporterId, now()->addDay())) {
            return $this->ephemeral('มีคนรายงานข้อความนี้แล้ว แอดมินกำลังดูอยู่ ขอบคุณครับ');
        }
        RateLimiter::hit("discord-report:{$reporterId}", 3600);

        $card = [
            'guild_id' => $guildId,
            'channel_id' => $channelId,
            'message_id' => $messageId,
            'author_id' => $authorId,
            'author_name' => (string) ($message['author']['global_name'] ?? $message['author']['username'] ?? ''),
            'content' => (string) ($message['content'] ?? ''),
            'attachments' => count((array) ($message['attachments'] ?? [])),
            'reporter_id' => $reporterId,
        ];
        $applicationId = (string) ($interaction['application_id'] ?? '');
        $token = (string) ($interaction['token'] ?? '');

        defer(function () use ($card, $logChannel, $applicationId, $token, $messageId) {
            $card['score'] = app(DiscordModerator::class)->score($card['author_id']);
            $posted = $this->client->createMessage($logChannel, $this->content->report($card));

            if (! $posted['ok']) {
                // ส่งไม่สำเร็จ = ปลดล็อกให้รายงานซ้ำได้ ไม่งั้นข้อความนี้จะรายงานไม่ได้อีกเลย 1 วัน
                Cache::forget("discord-report:msg:{$messageId}");
                Log::warning('Discord รายงานข้อความ: ส่งเข้าห้องแอดมินไม่สำเร็จ', ['error' => $posted['error']]);
            }

            $this->client->editOriginalResponse($applicationId, $token, [
                'content' => $posted['ok']
                    ? '✅ ส่งให้แอดมินตรวจแล้ว ขอบคุณที่ช่วยดูแลชุมชน — ผู้เขียนไม่เห็นว่าใครรายงาน'
                    : '⚠️ ส่งรายงานไม่สำเร็จ ลองใหม่อีกครั้ง หรือแจ้งแอดมินโดยตรง',
                'allowed_mentions' => ['parse' => []],
            ]);
        });

        return response()->json(['type' => self::RESPOND_DEFERRED, 'data' => ['flags' => self::EPHEMERAL]]);
    }

    /**
     * ปุ่มตัดสินบนการ์ดรายงาน — custom_id = mod:{action}:{ผู้เขียน}:{ห้อง}:{ข้อความ}.
     *
     * สิทธิ์ตรวจจาก member.permissions ที่ Discord คำนวณให้ในห้องนั้น (interaction ลงลายเซ็นมาแล้ว ปลอมไม่ได้)
     * ห้องแจ้งเตือนเป็นห้องส่วนตัวอยู่แล้ว แต่ไม่พึ่งแค่นั้น — ใครเผลอได้เห็นห้องก็กดแบนคนอื่นไม่ได้
     */
    private function button(array $interaction): JsonResponse
    {
        $guildId = $this->settings->guildId();
        if ($guildId === null || (string) ($interaction['guild_id'] ?? '') !== $guildId) {
            return $this->ephemeral('ปุ่มนี้ใช้ได้เฉพาะในเซิร์ฟเวอร์ TPIX');
        }

        if (! preg_match('/^mod:(timeout|kick|ban|delete|dismiss):(\d{17,20}):(\d{17,20}):(\d{17,20})$/', (string) ($interaction['data']['custom_id'] ?? ''), $m)) {
            return $this->ephemeral('ปุ่มนี้ใช้ไม่ได้แล้ว');
        }
        [, $action, $authorId, $channelId, $messageId] = $m;

        $permissions = (string) ($interaction['member']['permissions'] ?? '');
        if (! DiscordPermissions::allows($permissions, self::BUTTON_PERMISSION[$action])) {
            return $this->ephemeral('ปุ่มนี้สำหรับทีมงานที่มีสิทธิ์ดูแลห้องเท่านั้น');
        }

        // สองแอดมินกดพร้อมกัน / กดรัว = ทำครั้งเดียว
        if (! Cache::add("discord-mod-click:{$messageId}:{$action}", 1, 60)) {
            return $this->ephemeral('กำลังดำเนินการปุ่มนี้อยู่');
        }

        $adminId = (string) ($interaction['member']['user']['id'] ?? '');
        $adminName = (string) ($interaction['member']['user']['global_name'] ?? $interaction['member']['user']['username'] ?? 'แอดมิน');
        $card = (array) ($interaction['message'] ?? []);
        $applicationId = (string) ($interaction['application_id'] ?? '');
        $token = (string) ($interaction['token'] ?? '');

        defer(function () use ($action, $authorId, $channelId, $messageId, $adminId, $adminName, $card, $applicationId, $token) {
            $reason = 'รายงานจากสมาชิก · ตัดสินโดย '.$adminName;

            $outcome = match ($action) {
                'dismiss' => ['ok' => true, 'message' => 'ปิดเรื่องแล้ว — ไม่ผิด'],
                'delete' => $this->deleteReported($channelId, $messageId, $reason),
                default => app(DiscordModerator::class)->manual($authorId, $action, $reason, announce: false),
            };

            $this->client->editOriginalResponse($applicationId, $token, $this->decided($card, $action, $outcome, $adminId, $authorId, $channelId, $messageId));
        });

        return response()->json(['type' => self::RESPOND_DEFERRED_UPDATE]);
    }

    /** @return array{ok: bool, message: string} */
    private function deleteReported(string $channelId, string $messageId, string $reason): array
    {
        $deleted = $this->client->deleteMessage($channelId, $messageId, $reason);

        return match (true) {
            $deleted['ok'] => ['ok' => true, 'message' => 'ลบข้อความแล้ว'],
            ($deleted['code'] ?? null) === 10008 => ['ok' => true, 'message' => 'ข้อความถูกลบไปก่อนแล้ว'],
            default => ['ok' => false, 'message' => 'ลบไม่สำเร็จ: '.$deleted['error']],
        };
    }

    /**
     * แก้การ์ดรายงานหลังตัดสิน — ต่อบรรทัดผลไว้ท้ายการ์ด แล้วเหลือเฉพาะปุ่มที่ยังมีความหมาย
     * แบน/เตะ/ปิดเรื่อง = จบเรื่อง ไม่เหลือปุ่ม · ลบข้อความ/ปิดเสียง = ยังลงโทษเพิ่มได้ · ทำไม่สำเร็จ = ปุ่มยังอยู่ให้ลองใหม่.
     *
     * @param  array<string, mixed>  $card
     * @param  array{ok: bool, message: string}  $outcome
     */
    private function decided(array $card, string $action, array $outcome, string $adminId, string $authorId, string $channelId, string $messageId): array
    {
        // ส่งกลับเฉพาะช่องที่ Discord รับตอนแก้ข้อความ (embed ที่ได้มามีช่องอ่านอย่างเดียวติดมาด้วย)
        $original = (array) ($card['embeds'][0] ?? []);
        $embed = array_filter([
            'title' => $original['title'] ?? '🚩 สมาชิกรายงานข้อความ',
            'url' => $original['url'] ?? null,
            'description' => $original['description'] ?? null,
            'color' => $original['color'] ?? null,
            'fields' => array_map(fn ($f) => ['name' => (string) ($f['name'] ?? ''), 'value' => (string) ($f['value'] ?? ''), 'inline' => (bool) ($f['inline'] ?? false)], (array) ($original['fields'] ?? [])),
            'footer' => isset($original['footer']['text']) ? ['text' => (string) $original['footer']['text']] : null,
        ], fn ($v) => $v !== null);
        $icon = $outcome['ok'] ? '✅' : '❌';
        $embed['fields'] = array_slice(array_merge((array) ($embed['fields'] ?? []), [[
            'name' => 'ผลการตัดสิน',
            'value' => "{$icon} ".$outcome['message']." — โดย <@{$adminId}> · <t:".now()->timestamp.':R>',
            'inline' => false,
        ]]), 0, 25);

        $remaining = [];
        foreach ((array) ($card['components'] ?? []) as $row) {
            foreach ((array) ($row['components'] ?? []) as $component) {
                if (preg_match('/^mod:(\w+):/', (string) ($component['custom_id'] ?? ''), $cm)) {
                    $remaining[] = $cm[1];
                }
            }
        }

        if ($outcome['ok']) {
            $remaining = in_array($action, ['ban', 'kick', 'dismiss'], true)
                ? []
                : array_values(array_diff($remaining, [$action, 'dismiss']));
        }

        return [
            'embeds' => [$embed],
            'components' => DiscordContent::reportButtons($remaining, $authorId, $channelId, $messageId),
            'allowed_mentions' => ['parse' => []],
        ];
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
