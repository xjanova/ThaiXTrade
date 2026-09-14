<?php

namespace App\Services\Discord;

use App\Models\DiscordModAction;
use App\Models\DiscordModStrike;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — ไล่ระดับโทษสมาชิกที่ AutoMod จับได้ซ้ำ ๆ (คำสั่ง discord:moderate ทุก 5 นาที).
 *
 * เจ้าของสั่ง: "บอทควบคุมห้อง จัดการ แบน เตะ คนได้หากมีแนวโน้มไม่ดี"
 *
 * ═══ ขั้นตอน ═══
 *   1. อ่าน audit log ของเซิร์ฟเวอร์เฉพาะรายการ "AutoMod บล็อกข้อความ" (action_type 143) ที่ใหม่กว่ารอบก่อน
 *   2. บันทึกเป็นความผิด (unique audit_id — อ่านซ้อนก็ไม่นับซ้ำ) น้ำหนักตามกฎที่จับได้
 *   3. คะแนนรวมใน window_days → ปิดเสียง / เตะ / แบน (ระดับเดิมหรือต่ำกว่าที่ลงไปแล้วในช่วงนี้ ไม่ทำซ้ำ)
 *
 * ═══ ด่านกันลงโทษผิดคน ═══
 *   - ทีมงาน (ยศมีสิทธิ์ดูแล) เจ้าของเซิร์ฟเวอร์ และบอทตัวอื่น ไม่ถูกลงโทษอัตโนมัติเด็ดขาด
 *   - นับเฉพาะความผิดที่เกิดหลังเปิดระบบ — ไม่ย้อนลงโทษข้อความเก่าที่ AutoMod บล็อกไปแล้ว
 *   - เพดานแบน/เตะต่อวัน — กฎทำงานผิดทั้งเซิร์ฟเวอร์จะหยุดที่เพดานแล้วแจ้งแอดมิน ไม่ใช่แบนคนดีทั้งห้อง
 *   - โหมด "แจ้งเตือนอย่างเดียว" บันทึกว่าจะทำอะไร แต่ไม่แตะสมาชิกจริง
 *   - ทุกการลงโทษมีเหตุผลใน audit log ของ Discord + ข้อความในห้องแจ้งเตือนแอดมิน
 *
 * Developed by Xman Studio.
 */
class DiscordModerator
{
    public const MODE_OFF = 'off';

    public const MODE_OBSERVE = 'observe';

    public const MODE_ENFORCE = 'enforce';

    /** AUTO_MODERATION_BLOCK_MESSAGE */
    private const AUDIT_BLOCK = 143;

    private const RANK = ['timeout' => 1, 'kick' => 2, 'ban' => 3];

    public function __construct(
        private readonly DiscordSettings $settings,
        private readonly DiscordClient $client,
        private readonly DiscordAutoMod $automod,
    ) {}

    public function mode(): string
    {
        $mode = (string) $this->settings->get('discord_mod_mode', self::MODE_OFF);

        return in_array($mode, [self::MODE_OFF, self::MODE_OBSERVE, self::MODE_ENFORCE], true) ? $mode : self::MODE_OFF;
    }

    /** เกณฑ์ (แอดมินปรับได้ ไม่งั้นใช้ค่าจาก config) */
    public function threshold(string $key): int
    {
        $value = (int) $this->settings->get("discord_mod_{$key}", config("discord.moderation.{$key}"));

        // เพดานต่อวันตั้ง 0 ได้ = ไม่ลงโทษระดับนั้นเองเลย ส่งให้แอดมินตัดสินทุกครั้ง · เกณฑ์คะแนนต่ำสุด 1
        return str_starts_with($key, 'max_') ? max(0, $value) : max(1, $value);
    }

    /**
     * เริ่มนับความผิดจากตอนนี้ — เรียกตอนเปิดระบบ (จากปิด) เพื่อไม่ย้อนลงโทษข้อความเก่าที่ AutoMod บล็อกไปแล้ว.
     */
    public function startCounting(): void
    {
        $this->settings->mergeState(['mod_counting_since' => now()->toIso8601String()]);
    }

    /**
     * @return array{ran: bool, reason?: string, strikes?: int, actions?: list<array<string, mixed>>}
     */
    public function run(): array
    {
        if ($this->mode() === self::MODE_OFF) {
            return ['ran' => false, 'reason' => 'ปิดระบบดูแลห้องอยู่'];
        }

        $guildId = $this->settings->guildId();
        if ($guildId === null || ! $this->settings->hasBotToken()) {
            return ['ran' => false, 'reason' => 'ยังตั้งค่าไม่ครบ (โทเค็นบอท / Guild ID)'];
        }

        $lock = Cache::lock('discord:moderate', 240);
        if (! $lock->get()) {
            return ['ran' => false, 'reason' => 'กำลังตรวจอยู่อีกรอบหนึ่ง'];
        }

        try {
            return $this->pass($guildId);
        } finally {
            $lock->release();
        }
    }

    /**
     * ลงโทษเองจากหลังบ้าน (แอดมินกด) — ผ่านด่านทีมงานเหมือนกัน แต่ไม่ติดเพดานต่อวัน.
     *
     * @return array{ok: bool, message: string}
     */
    public function manual(string $userId, string $action, string $reason, bool $announce = true): array
    {
        $guildId = $this->settings->guildId();

        if ($guildId === null || ! preg_match(DiscordSettings::SNOWFLAKE, $userId)) {
            return ['ok' => false, 'message' => 'User ID ต้องเป็นตัวเลข 17-20 หลัก (คลิกขวาที่ชื่อสมาชิก → Copy User ID)'];
        }

        $protection = $action === 'unban' ? null : $this->protection($guildId, $userId);
        if ($protection !== null) {
            return ['ok' => false, 'message' => $protection === 'staff'
                ? 'สมาชิกคนนี้เป็นทีมงาน เจ้าของเซิร์ฟเวอร์ หรือบอท — ไม่ลงโทษผ่านบอท'
                : 'ตรวจตัวตนกับ Discord ไม่ได้ตอนนี้ — ยังไม่ลงโทษ ลองใหม่อีกครั้ง'];
        }

        $result = $this->apply($guildId, $userId, $action, 'แอดมิน: '.$reason);
        $this->record($userId, $action, 'manual', $this->score($userId), $result['ok'] ? 'done' : 'failed', $reason, $result['error']);

        // กดจากการ์ดรายงาน: การ์ดเดิมถูกแก้เป็นผลตัดสินอยู่แล้ว ไม่ต้องโพสต์ซ้ำอีกข้อความ
        if ($announce) {
            $this->announce($userId, $action, 'manual', $this->score($userId), $reason, $result['ok']);
        }

        return [
            'ok' => $result['ok'],
            'message' => $result['ok']
                ? $this->label($action).'แล้ว'
                : $this->label($action).'ไม่สำเร็จ: '.$result['error'],
        ];
    }

    /** คะแนนความผิดสะสมในช่วงล่าสุด */
    public function score(string $userId): int
    {
        return (int) DiscordModStrike::where('user_id', $userId)
            ->where('occurred_at', '>=', now()->subDays((int) config('discord.moderation.window_days', 7)))
            ->sum('weight');
    }

    // ── ภายใน ────────────────────────────────────────────────────────────────

    private function pass(string $guildId): array
    {
        $after = (string) ($this->settings->state()['mod_last_audit_id'] ?? '');
        $query = '?action_type='.self::AUDIT_BLOCK.'&limit=100'.($after !== '' ? '&after='.$after : '');
        $response = $this->client->request('GET', "/guilds/{$guildId}/audit-logs{$query}");

        if (! $response['ok']) {
            return ['ran' => false, 'reason' => 'อ่าน audit log ไม่ได้: '.$response['error'].' — ให้สิทธิ์ View Audit Log กับบอท'];
        }

        $entries = collect((array) ($response['data']['audit_log_entries'] ?? []))
            ->filter(fn ($e) => preg_match(DiscordSettings::SNOWFLAKE, (string) ($e['id'] ?? '')))
            ->sortBy(fn ($e) => (int) $e['id'])
            ->values();

        // ไม่ย้อนลงโทษของเก่า: นับเฉพาะที่เกิดหลังเปิดระบบ (ของเก่าใช้แค่เลื่อนตำแหน่งอ่าน)
        $since = $this->countingSince();

        $touched = [];
        $newStrikes = 0;

        foreach ($entries as $entry) {
            $userId = (string) ($entry['target_id'] ?? $entry['user_id'] ?? '');
            if (! preg_match(DiscordSettings::SNOWFLAKE, $userId) || $this->snowflakeTime((string) $entry['id'])->lt($since)) {
                continue;
            }

            $strike = DiscordModStrike::firstOrCreate(['audit_id' => (string) $entry['id']], [
                'user_id' => $userId,
                'rule_name' => mb_substr((string) ($entry['options']['auto_moderation_rule_name'] ?? ''), 0, 120) ?: null,
                'weight' => $this->automod->weightFor(null, $entry['options']['auto_moderation_rule_name'] ?? null),
                'channel_id' => preg_match(DiscordSettings::SNOWFLAKE, (string) ($entry['options']['channel_id'] ?? '')) ? (string) $entry['options']['channel_id'] : null,
                'occurred_at' => $this->snowflakeTime((string) $entry['id']),
            ]);

            if ($strike->wasRecentlyCreated) {
                $newStrikes++;
                $touched[$userId] = true;
            }
        }

        if ($entries->isNotEmpty()) {
            $this->settings->mergeState(['mod_last_audit_id' => (string) $entries->last()['id']]);
        }

        $actions = [];
        foreach (array_keys($touched) as $userId) {
            if ($decision = $this->decide($guildId, (string) $userId)) {
                $actions[] = $decision;
            }
        }

        $this->settings->mergeState(['mod_last_run_at' => now()->toIso8601String()]);

        return ['ran' => true, 'strikes' => $newStrikes, 'actions' => $actions];
    }

    private function decide(string $guildId, string $userId): ?array
    {
        $score = $this->score($userId);
        $action = match (true) {
            $score >= $this->threshold('ban_at') => 'ban',
            $score >= $this->threshold('kick_at') => 'kick',
            $score >= $this->threshold('timeout_at') => 'timeout',
            default => null,
        };

        if ($action === null) {
            return null;
        }

        // ลงระดับนี้ (หรือแรงกว่า) ไปแล้วในช่วงนี้ → ไม่ทำซ้ำ
        // นับเฉพาะของโหมดปัจจุบัน: สลับจาก "แจ้งเตือน" เป็น "ลงโทษจริง" แล้วคนที่เคยถูกแจ้งไว้ต้องโดนจริง
        // "capped" (ติดเพดาน) ไม่นับ — ความผิดครั้งถัดไปต้องถูกพิจารณาใหม่
        $already = DiscordModAction::where('user_id', $userId)
            ->where('mode', $this->mode())
            ->whereIn('status', ['done', 'skipped'])
            ->where('created_at', '>=', now()->subDays((int) config('discord.moderation.window_days', 7)))
            ->pluck('action')
            ->contains(fn ($a) => (self::RANK[$a] ?? 0) >= self::RANK[$action]);

        if ($already) {
            return null;
        }

        $reason = "AutoMod จับได้ซ้ำ — คะแนนความผิด {$score} ใน ".config('discord.moderation.window_days').' วัน';

        $protection = $this->protection($guildId, $userId);
        if ($protection === 'staff') {
            return $this->record($userId, $action, $this->mode(), $score, 'skipped', $reason, 'ทีมงาน/เจ้าของเซิร์ฟเวอร์/บอท — ไม่ลงโทษอัตโนมัติ');
        }
        if ($protection === 'unverified') {
            return $this->record($userId, $action, $this->mode(), $score, 'failed', $reason, 'ตรวจตัวตนกับ Discord ไม่ได้ — ยังไม่ลงโทษ จะตัดสินใหม่เมื่อทำผิดครั้งถัดไป');
        }

        if ($this->overCap($action)) {
            // แจ้งคนละครั้งต่อวันพอ — ช่วงถูกบุก คนเดิมโดนจับซ้ำทุกรอบ ห้องแอดมินจะรก
            $told = DiscordModAction::where('user_id', $userId)->where('action', $action)->where('status', 'capped')
                ->where('created_at', '>=', now()->subDay())->exists();
            if (! $told) {
                $this->announce($userId, $action, 'cap', $score, $reason.' · เกินเพดานต่อวัน รอแอดมินตัดสิน', false);
            }

            return $this->record($userId, $action, $this->mode(), $score, 'capped', $reason, 'เกินเพดาน'.$this->label($action).'ต่อวัน');
        }

        if ($this->mode() === self::MODE_OBSERVE) {
            $this->announce($userId, $action, self::MODE_OBSERVE, $score, $reason, true);

            return $this->record($userId, $action, self::MODE_OBSERVE, $score, 'skipped', $reason, 'โหมดแจ้งเตือนอย่างเดียว');
        }

        $result = $this->apply($guildId, $userId, $action, $reason);
        $this->announce($userId, $action, self::MODE_ENFORCE, $score, $reason, $result['ok']);

        return $this->record($userId, $action, self::MODE_ENFORCE, $score, $result['ok'] ? 'done' : 'failed', $reason, $result['error']);
    }

    /** @return array{ok: bool, error: ?string} */
    private function apply(string $guildId, string $userId, string $action, string $reason): array
    {
        $auditReason = 'TPIX bot · '.$reason;

        $response = match ($action) {
            'timeout' => $this->client->request('PATCH', "/guilds/{$guildId}/members/{$userId}", [
                'communication_disabled_until' => now()->addMinutes($this->threshold('timeout_minutes'))->toIso8601String(),
            ], null, $auditReason),
            'kick' => $this->client->request('DELETE', "/guilds/{$guildId}/members/{$userId}", [], null, $auditReason),
            'ban' => $this->client->request('PUT', "/guilds/{$guildId}/bans/{$userId}", ['delete_message_seconds' => 86400], null, $auditReason),
            'unban' => $this->client->request('DELETE', "/guilds/{$guildId}/bans/{$userId}", [], null, $auditReason),
            default => ['ok' => false, 'error' => 'ไม่รู้จักการลงโทษนี้'],
        };

        // เตะคนที่ออกไปแล้ว = ไม่มีอะไรต้องทำ (แบนยังทำได้แม้ออกไปแล้ว กันกลับเข้ามา)
        if (! $response['ok'] && in_array($action, ['kick', 'timeout'], true) && ($response['code'] ?? null) === 10007) {
            return ['ok' => false, 'error' => 'สมาชิกออกจากเซิร์ฟเวอร์ไปแล้ว'];
        }

        return ['ok' => $response['ok'], 'error' => $response['ok'] ? null : $response['error']];
    }

    /**
     * ห้ามลงโทษคนนี้ไหม — ตรวจไม่ได้ = ไม่ลงโทษ (fail-closed).
     *
     * @return 'staff'|'unverified'|null staff = ทีมงาน (ยศมีสิทธิ์ดูแล) เจ้าของเซิร์ฟเวอร์ หรือบอทตัวอื่น ·
     *                                   unverified = Discord ตอบไม่ได้ตอนนี้ (ไม่บันทึกเป็น "ข้าม" จะได้ตัดสินใหม่ครั้งหน้า)
     */
    private function protection(string $guildId, string $userId): ?string
    {
        $member = $this->client->request('GET', "/guilds/{$guildId}/members/{$userId}");

        if (! $member['ok']) {
            // ออกจากเซิร์ฟเวอร์ไปแล้ว = ไม่ใช่ทีมงานในตอนนี้ แบนได้ (กันกลับเข้ามา)
            return ($member['code'] ?? null) === 10007 ? null : 'unverified';
        }

        // บอทตัวอื่นในเซิร์ฟเวอร์ (บอทเพลง/บอทต้อนรับ) — ให้แอดมินตัดสินเอง
        if (! empty($member['data']['user']['bot'])) {
            return 'staff';
        }

        $guild = $this->client->guild($guildId);
        if (! $guild['ok']) {
            return 'unverified';
        }
        if ((string) ($guild['data']['owner_id'] ?? '') === $userId) {
            return 'staff';
        }

        $roles = $this->client->request('GET', "/guilds/{$guildId}/roles");
        if (! $roles['ok']) {
            return 'unverified';
        }

        $staff = $this->automod->staffRoles((array) $roles['data']);

        return array_intersect(array_map('strval', (array) ($member['data']['roles'] ?? [])), $staff) !== [] ? 'staff' : null;
    }

    private function overCap(string $action): bool
    {
        $cap = match ($action) {
            'ban' => $this->threshold('max_bans_per_day'),
            'kick' => $this->threshold('max_kicks_per_day'),
            default => PHP_INT_MAX,
        };

        return DiscordModAction::where('action', $action)
            ->where('mode', self::MODE_ENFORCE)
            ->where('status', 'done')
            ->where('created_at', '>=', now()->subDay())
            ->count() >= $cap;
    }

    private function record(string $userId, string $action, string $mode, int $score, string $status, string $reason, ?string $error): array
    {
        DiscordModAction::create([
            'user_id' => $userId,
            'action' => $action,
            'mode' => $mode,
            'score' => $score,
            'status' => $status,
            'reason' => mb_substr($reason, 0, 255),
            'error' => $error,
        ]);

        Log::info('Discord moderation', ['user' => $userId, 'action' => $action, 'mode' => $mode, 'score' => $score, 'status' => $status]);

        return ['user_id' => $userId, 'action' => $action, 'mode' => $mode, 'score' => $score, 'status' => $status, 'error' => $error];
    }

    /** แจ้งห้องแอดมิน — แท็กชื่อให้กดดูได้ แต่ allowed_mentions ว่าง จึงไม่ปิงใคร */
    private function announce(string $userId, string $action, string $mode, int $score, string $reason, bool $ok): void
    {
        $channel = $this->settings->get('discord_mod_log_channel');
        if (! is_string($channel) || ! preg_match(DiscordSettings::SNOWFLAKE, $channel)) {
            return;
        }

        $headline = match ($mode) {
            self::MODE_OBSERVE => "👀 โหมดแจ้งเตือน: ถ้าเปิดลงโทษจริงจะ{$this->label($action)} <@{$userId}>",
            'cap' => "⚠️ ถึงเพดานต่อวันแล้ว — ยังไม่{$this->label($action)} <@{$userId}> รอแอดมินตัดสิน",
            'manual' => ($ok ? '🛠️ แอดมิน' : '❌ แอดมินสั่ง').$this->label($action)." <@{$userId}>".($ok ? '' : ' ไม่สำเร็จ'),
            default => ($ok ? '🛡️ ' : '❌ ').$this->label($action)." <@{$userId}>".($ok ? ' แล้ว' : ' ไม่สำเร็จ'),
        };

        $this->client->createMessage($channel, [
            'content' => $headline."\nเหตุผล: {$reason} · คะแนนความผิดตอนนี้ {$score}",
            'allowed_mentions' => ['parse' => []],
        ]);
    }

    private function label(string $action): string
    {
        return match ($action) {
            'timeout' => 'ปิดเสียง',
            'kick' => 'เตะ',
            'ban' => 'แบน',
            'unban' => 'ยกเลิกแบน',
            default => $action,
        };
    }

    /** เปิดระบบครั้งแรกโดยไม่ผ่านหน้าหลังบ้าน (ไม่มีจุดเริ่มนับ) → เริ่มนับจากรอบนี้ */
    private function countingSince(): Carbon
    {
        $since = $this->settings->state()['mod_counting_since'] ?? null;

        if (! is_string($since) || $since === '') {
            $this->startCounting();

            return now();
        }

        return Carbon::parse($since);
    }

    /** เวลาจาก snowflake ของ Discord (ms ตั้งแต่ 2015-01-01) */
    private function snowflakeTime(string $id): Carbon
    {
        $ms = intdiv((int) $id, 1 << 22) + 1420070400000;

        return Carbon::createFromTimestampMs($ms);
    }
}
