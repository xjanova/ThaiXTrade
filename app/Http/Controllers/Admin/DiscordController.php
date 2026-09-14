<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DiscordModAction;
use App\Models\DiscordModStrike;
use App\Models\DiscordPost;
use App\Services\ChatbotService;
use App\Services\Discord\DiscordAutoMod;
use App\Services\Discord\DiscordContent;
use App\Services\Discord\DiscordModerator;
use App\Services\Discord\DiscordPermissions;
use App\Services\Discord\DiscordPublisher;
use App\Services\Discord\DiscordSettings;
use App\Services\Discord\DiscordSetup;
use App\Services\SaleStatusService;
use App\Services\SiteKnowledgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TPIX TRADE — หลังบ้านบอท Discord (/admin/discord · super_admin เท่านั้น).
 *
 * เจ้าของสั่ง: "โทเค็นให้ใส่ในหลังบ้านได้" · "บอทรู้ว่าจะโพสต์อะไรในห้องไหนได้เอง"
 *
 * ทำไม super_admin: โทเค็นบอท = สิทธิ์โพสต์ในนามทีมงานถึงสมาชิกทั้งเซิร์ฟเวอร์
 * (หน้า /admin/settings เปิดให้แอดมินทุกระดับ จึงไม่เอาไปรวมไว้ที่นั่น)
 *
 * Developed by Xman Studio.
 */
class DiscordController extends Controller
{
    public function __construct(
        private readonly DiscordSettings $settings,
    ) {}

    public function index(SaleStatusService $sale, DiscordModerator $moderator): Response
    {
        $state = $this->settings->state();

        return Inertia::render('Admin/Discord/Index', [
            'settings' => [
                'enabled' => $this->settings->enabled(),
                'ask_enabled' => $this->settings->askEnabled(),
                'application_id' => (string) $this->settings->get('discord_application_id', ''),
                'public_key' => (string) $this->settings->get('discord_public_key', ''),
                'guild_id' => (string) $this->settings->get('discord_guild_id', ''),
                'bot_token_masked' => $this->settings->maskedBotToken(),
                'has_token' => $this->settings->hasBotToken(),
                'rules_text' => $this->settings->rulesText(),
                'ask_daily_cap' => (int) $this->settings->get('discord_ask_daily_cap', config('discord.ask.daily_cap')),
                'ask_user_per_hour' => (int) $this->settings->get('discord_ask_user_per_hour', config('discord.ask.per_user_per_hour')),
            ],
            'status' => [
                'bot_name' => $state['bot_name'] ?? null,
                'guild_name' => $state['guild_name'] ?? null,
                'identified_at' => $state['identified_at'] ?? null,
                'commands_registered_at' => $state['commands_registered_at'] ?? null,
                'channels_at' => $state['channels_at'] ?? null,
                'last_sync_at' => $state['last_sync_at'] ?? null,
                'last_sync' => $state['last_sync'] ?? [],
            ],
            'channels' => $state['channels'] ?? [],
            'channelMap' => (object) $this->settings->channelMap(),
            'roles' => collect((array) config('discord.roles'))->map(fn ($r, $key) => ['key' => $key, 'label' => $r['label']])->values(),
            'posts' => DiscordPost::orderByDesc('updated_at')->limit(30)->get(['kind', 'ref_key', 'channel_id', 'message_id', 'last_error', 'posted_at', 'updated_at']),
            'salePreview' => $sale->snapshot(),
            'interactionsUrl' => rtrim((string) config('app.url'), '/').'/api/v1/discord/interactions',
            'moderation' => [
                'mode' => $moderator->mode(),
                'log_channel' => (string) $this->settings->get('discord_mod_log_channel', ''),
                'timeout_at' => $moderator->threshold('timeout_at'),
                'kick_at' => $moderator->threshold('kick_at'),
                'ban_at' => $moderator->threshold('ban_at'),
                'max_bans_per_day' => $moderator->threshold('max_bans_per_day'),
                'window_days' => (int) config('discord.moderation.window_days'),
                'rules' => $state['automod_results'] ?? [],
                'installed_at' => $state['automod_installed_at'] ?? null,
                'last_run_at' => $state['mod_last_run_at'] ?? null,
                'weights' => collect((array) config('discord.moderation.rules'))->map(fn ($r) => ['name' => $r['name'], 'weight' => $r['weight']])->values(),
                'strikes' => DiscordModStrike::latest('occurred_at')->limit(20)->get(['user_id', 'rule_name', 'weight', 'occurred_at']),
                'actions' => DiscordModAction::latest()->limit(20)->get(['user_id', 'action', 'mode', 'score', 'status', 'reason', 'error', 'created_at']),
                // ลิงก์ให้สิทธิ์เพิ่ม — เปิดแล้วกด Authorize บอทจะได้สิทธิ์ดูแลห้องครบในครั้งเดียว
                'upgrade_url' => $this->settings->applicationId() !== null
                    ? 'https://discord.com/oauth2/authorize?client_id='.$this->settings->applicationId().'&scope=bot+applications.commands&permissions='.DiscordPermissions::inviteBits()
                    : null,
            ],
        ]);
    }

    /** บันทึกค่าตั้งของระบบดูแลห้อง */
    public function updateModeration(Request $request, DiscordAutoMod $automod, DiscordModerator $moderator): RedirectResponse
    {
        $known = array_column($this->settings->state()['channels'] ?? [], 'id');

        $validated = $request->validate([
            'mode' => ['required', Rule::in([DiscordModerator::MODE_OFF, DiscordModerator::MODE_OBSERVE, DiscordModerator::MODE_ENFORCE])],
            'log_channel' => ['nullable', 'string', Rule::in($known)],
            'timeout_at' => ['required', 'integer', 'min:1', 'max:100'],
            'kick_at' => ['required', 'integer', 'min:1', 'max:100', 'gte:timeout_at'],
            'ban_at' => ['required', 'integer', 'min:1', 'max:100', 'gte:kick_at'],
            'max_bans_per_day' => ['required', 'integer', 'min:0', 'max:50'],
        ], [
            'kick_at.gte' => 'คะแนนที่เตะต้องไม่น้อยกว่าคะแนนที่ปิดเสียง',
            'ban_at.gte' => 'คะแนนที่แบนต้องไม่น้อยกว่าคะแนนที่เตะ',
            'log_channel.in' => 'ห้องที่เลือกไม่อยู่ในเซิร์ฟเวอร์ — กด "ทดสอบการเชื่อมต่อ" เพื่อดึงรายชื่อห้องใหม่',
        ]);

        $channelChanged = (string) ($validated['log_channel'] ?? '') !== (string) $this->settings->get('discord_mod_log_channel', '');

        // เปิดจากปิด → เริ่มนับความผิดใหม่จากตอนนี้ ไม่ย้อนลงโทษของเก่าที่เกิดตอนระบบปิดอยู่
        if ($moderator->mode() === DiscordModerator::MODE_OFF && $validated['mode'] !== DiscordModerator::MODE_OFF) {
            $moderator->startCounting();
        }

        $this->settings->set('discord_mod_mode', $validated['mode']);
        $this->settings->set('discord_mod_log_channel', (string) ($validated['log_channel'] ?? ''));
        foreach (['timeout_at', 'kick_at', 'ban_at', 'max_bans_per_day'] as $key) {
            $this->settings->set("discord_mod_{$key}", (int) $validated[$key], 'number');
        }

        // ห้องแจ้งเตือนเปลี่ยน → กฎ AutoMod ที่ติดตั้งไว้ต้องชี้ห้องใหม่ด้วย
        if ($channelChanged && ! empty($this->settings->state()['automod_installed_at'])) {
            $automod->install();
        }

        return back()->with('success', match ($validated['mode']) {
            DiscordModerator::MODE_ENFORCE => 'บันทึกแล้ว — บอทจะลงโทษจริงตามเกณฑ์',
            DiscordModerator::MODE_OBSERVE => 'บันทึกแล้ว — โหมดแจ้งเตือนอย่างเดียว ยังไม่ลงโทษใคร',
            default => 'บันทึกแล้ว — ปิดระบบดูแลห้อง (กฎ AutoMod ที่ติดตั้งไว้ยังบล็อกข้อความอยู่)',
        });
    }

    /** ติดตั้ง/อัปเดตกฎ AutoMod (ชื่อขึ้นต้น "TPIX •" เท่านั้น — ไม่แตะกฎที่แอดมินตั้งเอง) */
    public function installAutoMod(DiscordAutoMod $automod): RedirectResponse
    {
        $result = $automod->install();

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /** ลงโทษเอง (หน้าเว็บถามยืนยันก่อน) — ไม่ลงโทษทีมงาน/เจ้าของเซิร์ฟเวอร์ */
    public function moderateMember(Request $request, DiscordModerator $moderator): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'string', 'regex:'.DiscordSettings::SNOWFLAKE],
            'action' => ['required', Rule::in(['timeout', 'kick', 'ban', 'unban'])],
            'reason' => ['required', 'string', 'max:200'],
        ], [
            'user_id.regex' => 'User ID ต้องเป็นตัวเลข 17-20 หลัก (เปิด Developer Mode แล้วคลิกขวาที่ชื่อสมาชิก → Copy User ID)',
            'reason.required' => 'ใส่เหตุผลก่อน — จะบันทึกไว้ใน audit log ของ Discord',
        ]);

        $result = $moderator->manual($validated['user_id'], $validated['action'], $validated['reason']);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /** สิทธิ์ระดับเซิร์ฟเวอร์ที่ระบบดูแลห้องยังขาด (ไม่แตะอะไรใน Discord) */
    public function moderationPermissions(DiscordPermissions $permissions): JsonResponse
    {
        $result = $permissions->moderationCheck();

        return $result['ok']
            ? response()->json(['success' => true, 'missing' => $result['missing'], 'above_bot' => $result['above_bot']])
            : response()->json(['success' => false, 'message' => $result['error']], 422);
    }

    public function update(Request $request, SiteKnowledgeService $knowledge): RedirectResponse
    {
        $known = array_column($this->settings->state()['channels'] ?? [], 'id');

        $validated = $request->validate([
            'enabled' => ['boolean'],
            'ask_enabled' => ['boolean'],
            'application_id' => ['nullable', 'string', 'regex:'.DiscordSettings::SNOWFLAKE],
            'public_key' => ['nullable', 'string', 'regex:'.DiscordSettings::PUBLIC_KEY],
            'guild_id' => ['nullable', 'string', 'regex:'.DiscordSettings::SNOWFLAKE],
            // ว่าง = ใช้โทเค็นเดิม (หน้าเว็บไม่เคยได้ค่าจริงไป จึงไม่มีอะไรให้ส่งกลับ)
            'bot_token' => ['nullable', 'string', 'regex:/^[A-Za-z0-9._-]{50,120}$/'],
            'rules_text' => ['nullable', 'string', 'max:3500'],
            'ask_daily_cap' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'ask_user_per_hour' => ['nullable', 'integer', 'min:1', 'max:120'],
            'channel_map' => ['nullable', 'array'],
            'channel_map.*' => ['nullable', 'string', Rule::in($known)],
        ], [
            'application_id.regex' => 'Application ID ต้องเป็นตัวเลข 17-20 หลัก (Developer Portal → General Information)',
            'public_key.regex' => 'Public Key ต้องเป็นตัวอักษร hex 64 ตัว (Developer Portal → General Information)',
            'guild_id.regex' => 'Guild ID ต้องเป็นตัวเลข 17-20 หลัก (คลิกขวาที่ชื่อเซิร์ฟเวอร์ → Copy Server ID)',
            'bot_token.regex' => 'รูปแบบโทเค็นบอทไม่ถูกต้อง (Developer Portal → Bot → Reset Token แล้วคัดลอกมาทั้งหมด)',
            'channel_map.*.in' => 'ห้องที่เลือกไม่อยู่ในเซิร์ฟเวอร์ — กด "ทดสอบการเชื่อมต่อ" เพื่อดึงรายชื่อห้องใหม่',
        ]);

        $tokenChanged = filled($validated['bot_token'] ?? null);
        $guildChanged = (string) ($validated['guild_id'] ?? '') !== (string) $this->settings->get('discord_guild_id', '');

        if ($guildChanged) {
            // ย้ายเซิร์ฟเวอร์ = ห้องชุดเดิมไม่มีอยู่ในเซิร์ฟเวอร์ใหม่ → ล้างผังห้อง รอดึงรายชื่อห้องใหม่ตอนทดสอบการเชื่อมต่อ
            $this->settings->saveChannelMap([]);
            $this->settings->mergeState(['channels' => [], 'guild_name' => null]);
            unset($validated['channel_map']);
        }

        if ($tokenChanged) {
            $this->settings->saveBotToken($validated['bot_token']);
            // โทเค็นใหม่ = บอทคนละตัวได้ → ต้องยืนยันตัวกับ Gateway ใหม่
            $this->settings->mergeState(['identified_at' => null, 'bot_name' => null]);
        }

        $this->settings->set('discord_enabled', (bool) ($validated['enabled'] ?? false), 'boolean');
        $this->settings->set('discord_ask_enabled', (bool) ($validated['ask_enabled'] ?? false), 'boolean');
        $this->settings->set('discord_application_id', (string) ($validated['application_id'] ?? ''));
        $this->settings->set('discord_public_key', strtolower((string) ($validated['public_key'] ?? '')));
        $this->settings->set('discord_guild_id', (string) ($validated['guild_id'] ?? ''));
        $this->settings->set('discord_rules_text', trim((string) ($validated['rules_text'] ?? '')), 'text');

        if (isset($validated['ask_daily_cap'])) {
            $this->settings->set('discord_ask_daily_cap', (int) $validated['ask_daily_cap'], 'number');
        }
        if (isset($validated['ask_user_per_hour'])) {
            $this->settings->set('discord_ask_user_per_hour', (int) $validated['ask_user_per_hour'], 'number');
        }
        if (array_key_exists('channel_map', $validated)) {
            $this->settings->saveChannelMap((array) $validated['channel_map']);
        }

        $knowledge->forget();

        return back()->with('success', $tokenChanged
            ? 'บันทึกแล้ว — กด "ทดสอบการเชื่อมต่อ" เพื่อยืนยันโทเค็นใหม่'
            : 'บันทึกการตั้งค่าบอท Discord แล้ว');
    }

    public function connect(DiscordSetup $setup): RedirectResponse
    {
        $result = $setup->connect();

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function replan(DiscordPublisher $publisher): RedirectResponse
    {
        $result = $publisher->refreshChannels(replan: true);

        return $result['ok']
            ? back()->with('success', 'จัดห้องใหม่ตามชื่อห้องแล้ว '.count($result['map']).' เรื่อง — ตรวจแล้วกดบันทึกถ้าต้องการเปลี่ยน')
            : back()->with('error', $result['error']);
    }

    public function registerCommands(DiscordSetup $setup): RedirectResponse
    {
        $result = $setup->registerCommands();

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /**
     * ถามทดสอบผ่านหน้าเว็บ — ผ่านสมองตัวเดียวกับ /ถาม และจัดหน้าแบบเดียวกับที่บอทจะตอบใน Discord
     * ไม่ส่งอะไรเข้า Discord (เจ้าของสั่ง: "ต้องทดสอบบอทผ่านหน้าเว็บได้เลย").
     */
    public function testAsk(Request $request, ChatbotService $chatbot, DiscordContent $content): JsonResponse
    {
        $max = (int) config('discord.ask.max_question_length', 500);
        $validated = $request->validate(
            ['question' => ['required', 'string', "max:{$max}"]],
            ['question.required' => 'พิมพ์คำถามก่อน', 'question.max' => "คำถามยาวเกิน {$max} ตัวอักษร"],
        );

        $question = trim($validated['question']);
        $reply = $chatbot->chat($question, DiscordContent::languageOf($question));
        $message = $content->answer($question, $reply['message'], $reply['navigation'] ?? null);

        return response()->json([
            'success' => (bool) ($reply['success'] ?? false),
            'message' => $message,
        ]);
    }

    /** ตัวอย่างสิ่งที่บอทจะโพสต์ในแต่ละห้อง (เนื้อหาจริง ไม่ส่งไป Discord) */
    public function preview(DiscordPublisher $publisher): JsonResponse
    {
        $channels = collect($this->settings->state()['channels'] ?? [])->keyBy('id');
        $labels = collect((array) config('discord.roles'))->map(fn ($r) => $r['label']);

        $items = array_map(fn (array $item) => $item + [
            'channel_name' => $item['channel_id'] !== null ? ($channels[$item['channel_id']]['name'] ?? $item['channel_id']) : null,
            'role_label' => $labels[$item['role']] ?? $item['role'],
        ], $publisher->preview());

        return response()->json(['success' => true, 'items' => $items]);
    }

    /** ตรวจว่าบอทส่งข้อความได้ในทุกห้องที่ผังห้องใช้ — คำนวณจากยศ/ข้อยกเว้นของห้อง ไม่ลองโพสต์จริง */
    public function permissions(DiscordPermissions $permissions): JsonResponse
    {
        $result = $permissions->checkMappedRooms();

        return $result['ok']
            ? response()->json(['success' => true, 'rooms' => $result['rooms']])
            : response()->json(['success' => false, 'message' => $result['error']], 422);
    }

    /** โพสต์/อัปเดตเข้า Discord ทันที (หน้าเว็บถามยืนยันก่อนกด เพราะเป็นการโพสต์ถึงสมาชิกจริง) */
    public function sync(DiscordPublisher $publisher, SaleStatusService $sale): RedirectResponse
    {
        $sale->forget();
        $outcome = $publisher->sync();

        if (! $outcome['ran']) {
            return back()->with('error', $outcome['reason']);
        }

        $count = fn (string $action) => count(array_filter($outcome['results'], fn ($r) => $r['action'] === $action));
        $summary = "โพสต์ใหม่ {$count('created')} · แก้ข้อความเดิม {$count('edited')} · ไม่เปลี่ยน {$count('unchanged')}";

        return $count('error') + $count('no_channel') > 0
            ? back()->with('error', "{$summary} · มีปัญหา ".($count('error') + $count('no_channel')).' รายการ (ดูตารางด้านล่าง)')
            : back()->with('success', $summary);
    }
}
