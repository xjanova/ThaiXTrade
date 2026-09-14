<?php

namespace App\Services\Discord;

/**
 * TPIX TRADE — คำนวณว่าบอทมองเห็น/ส่งข้อความ/แนบการ์ดได้ในห้องไหนบ้าง (ไม่ต้องลองโพสต์จริง).
 *
 * เจอจริงตอนเปิดบอทครั้งแรก (2026-09-14): #announcements #roadmap #faq ตั้ง @everyone ห้ามส่งข้อความ
 * บอทจึงโพสต์ไม่ได้ (50013 Missing Permissions) — หน้าทดสอบในหลังบ้านใช้ตัวนี้บอกเจ้าของล่วงหน้าว่าห้องไหนต้องเพิ่มสิทธิ์
 *
 * ลำดับการคิดสิทธิ์ตามเอกสาร Discord (Permission Overwrites):
 *   สิทธิ์ของ @everyone | สิทธิ์ของทุกยศที่บอทมี → Administrator = ได้ทุกอย่าง
 *   → ทับด้วยข้อยกเว้นของห้องสำหรับ @everyone → ข้อยกเว้นของยศ (รวม deny แล้ว allow) → ข้อยกเว้นของตัวบอท
 *
 * Developed by Xman Studio.
 */
class DiscordPermissions
{
    public const ADMINISTRATOR = 1 << 3;

    public const VIEW_CHANNEL = 1 << 10;

    public const SEND_MESSAGES = 1 << 11;

    public const EMBED_LINKS = 1 << 14;

    public const READ_MESSAGE_HISTORY = 1 << 16;

    public const KICK_MEMBERS = 1 << 1;

    public const BAN_MEMBERS = 1 << 2;

    public const MANAGE_GUILD = 1 << 5;

    public const VIEW_AUDIT_LOG = 1 << 7;

    public const MODERATE_MEMBERS = 1 << 40;

    /** สิทธิ์ที่ระบบดูแลห้องต้องใช้ (ตั้งกฎ AutoMod · อ่านบันทึก · ปิดเสียง · เตะ · แบน) */
    public const MODERATION = [
        'Manage Server (ตั้งกฎ AutoMod)' => self::MANAGE_GUILD,
        'View Audit Log (อ่านผลของ AutoMod)' => self::VIEW_AUDIT_LOG,
        'Timeout Members (ปิดเสียง)' => self::MODERATE_MEMBERS,
        'Kick Members (เตะ)' => self::KICK_MEMBERS,
        'Ban Members (แบน)' => self::BAN_MEMBERS,
    ];

    /** สิทธิ์ทั้งหมดของบอท = โพสต์ (84992) + ดูแลห้อง — ใช้สร้างลิงก์ "ให้สิทธิ์เพิ่ม" */
    public static function inviteBits(): int
    {
        return self::VIEW_CHANNEL | self::SEND_MESSAGES | self::EMBED_LINKS | self::READ_MESSAGE_HISTORY | array_sum(self::MODERATION);
    }

    /**
     * สิทธิ์ระดับเซิร์ฟเวอร์ที่ระบบดูแลห้องยังขาด.
     *
     * @return array{ok: bool, error?: string, missing?: list<string>, above_bot?: list<string>}
     */
    public function moderationCheck(): array
    {
        $guildId = $this->settings->guildId();

        if ($guildId === null || ! $this->settings->hasBotToken()) {
            return ['ok' => false, 'error' => 'ยังตั้งค่าไม่ครบ (โทเค็นบอท / Guild ID)'];
        }

        $me = $this->client->me();
        $roles = $this->client->request('GET', "/guilds/{$guildId}/roles");

        if (! $me['ok'] || ! $roles['ok']) {
            return ['ok' => false, 'error' => ($me['ok'] ? $roles : $me)['error']];
        }

        $member = $this->client->request('GET', "/guilds/{$guildId}/members/".($me['data']['id'] ?? ''));
        if (! $member['ok']) {
            return ['ok' => false, 'error' => $member['error']];
        }

        $byId = collect((array) $roles['data'])->keyBy(fn ($r) => (string) $r['id']);
        $mine = array_map('strval', (array) ($member['data']['roles'] ?? []));
        $base = $this->compute((array) $roles['data'], $mine, $guildId, (string) $me['data']['id'], []);

        $missing = array_keys(array_filter(self::MODERATION, fn ($bit) => ! $this->has($base, $bit)));

        // Discord ห้ามบอทลงโทษคนที่มียศ "สูงกว่า" ยศของบอท — บอกให้แอดมินลากยศบอทขึ้น
        $top = max(array_map(fn ($id) => (int) ($byId[$id]['position'] ?? 0), $mine ?: ['0']));
        $above = $byId->filter(fn ($r) => (int) $r['position'] > $top && ! ($r['managed'] ?? false))->pluck('name')->values()->all();

        return ['ok' => true, 'missing' => $missing, 'above_bot' => $above];
    }

    public function __construct(
        private readonly DiscordSettings $settings,
        private readonly DiscordClient $client,
    ) {}

    /**
     * ตรวจสิทธิ์ของบอทในทุกห้องที่ผังห้องใช้อยู่.
     *
     * @return array{ok: bool, error?: string, rooms?: list<array<string, mixed>>}
     */
    public function checkMappedRooms(): array
    {
        $guildId = $this->settings->guildId();

        if ($guildId === null || ! $this->settings->hasBotToken()) {
            return ['ok' => false, 'error' => 'ยังตั้งค่าไม่ครบ (โทเค็นบอท / Guild ID)'];
        }

        $me = $this->client->me();
        $roles = $this->client->request('GET', "/guilds/{$guildId}/roles");
        $channels = $this->client->guildChannels($guildId);

        foreach ([$me, $roles, $channels] as $response) {
            if (! $response['ok']) {
                return ['ok' => false, 'error' => $response['error']];
            }
        }

        $botId = (string) ($me['data']['id'] ?? '');
        $member = $this->client->request('GET', "/guilds/{$guildId}/members/{$botId}");

        if (! $member['ok']) {
            return ['ok' => false, 'error' => $member['error']];
        }

        $channelsById = collect((array) $channels['data'])->keyBy('id');
        $roleLabels = collect((array) config('discord.roles'))->map(fn ($r) => $r['label']);
        $rooms = [];

        // ห้องเดียวอาจรับหลายเรื่อง (เช่น ขายเหรียญ + ประกาศ) — รวมเป็นแถวเดียวต่อห้อง
        foreach ($this->settings->channelMap() as $role => $channelId) {
            $rooms[$channelId]['roles'][] = $roleLabels[$role] ?? $role;
        }

        $result = [];
        foreach ($rooms as $channelId => $room) {
            $channel = $channelsById[$channelId] ?? null;

            if ($channel === null) {
                $result[] = ['channel_id' => $channelId, 'name' => null, 'roles' => $room['roles'], 'ok' => false, 'missing' => ['ห้องนี้ไม่มีอยู่แล้ว']];

                continue;
            }

            $perms = $this->compute((array) $roles['data'], (array) ($member['data']['roles'] ?? []), $guildId, $botId, (array) ($channel['permission_overwrites'] ?? []));
            $missing = array_keys(array_filter([
                'View Channel' => ! $this->has($perms, self::VIEW_CHANNEL),
                'Send Messages' => ! $this->has($perms, self::SEND_MESSAGES),
                'Embed Links' => ! $this->has($perms, self::EMBED_LINKS),
            ]));

            $result[] = [
                'channel_id' => $channelId,
                'name' => (string) ($channel['name'] ?? ''),
                'roles' => $room['roles'],
                'view' => $this->has($perms, self::VIEW_CHANNEL),
                'send' => $this->has($perms, self::VIEW_CHANNEL) && $this->has($perms, self::SEND_MESSAGES),
                'embed' => $this->has($perms, self::VIEW_CHANNEL) && $this->has($perms, self::EMBED_LINKS),
                'ok' => $missing === [],
                'missing' => $missing,
            ];
        }

        return ['ok' => true, 'rooms' => $result];
    }

    /**
     * สิทธิ์สุดท้ายของบอทในห้องหนึ่ง.
     *
     * @param  list<array<string, mixed>>  $guildRoles  GET /guilds/{id}/roles
     * @param  list<string>  $memberRoles  role id ที่บอทมี (ไม่รวม @everyone)
     * @param  list<array<string, mixed>>  $overwrites  permission_overwrites ของห้อง
     */
    public function compute(array $guildRoles, array $memberRoles, string $guildId, string $botId, array $overwrites): int
    {
        $roles = collect($guildRoles)->keyBy(fn ($r) => (string) $r['id']);

        $base = (int) ($roles[$guildId]['permissions'] ?? 0);
        foreach ($memberRoles as $roleId) {
            $base |= (int) ($roles[(string) $roleId]['permissions'] ?? 0);
        }

        if (($base & self::ADMINISTRATOR) === self::ADMINISTRATOR) {
            return PHP_INT_MAX;
        }

        $overwrites = collect($overwrites)->keyBy(fn ($o) => (string) $o['id']);
        $perms = $base;

        if ($everyone = $overwrites[$guildId] ?? null) {
            $perms = ($perms & ~(int) $everyone['deny']) | (int) $everyone['allow'];
        }

        $allow = 0;
        $deny = 0;
        foreach ($memberRoles as $roleId) {
            if ($overwrite = $overwrites[(string) $roleId] ?? null) {
                $allow |= (int) $overwrite['allow'];
                $deny |= (int) $overwrite['deny'];
            }
        }
        $perms = ($perms & ~$deny) | $allow;

        if ($member = $overwrites[$botId] ?? null) {
            $perms = ($perms & ~(int) $member['deny']) | (int) $member['allow'];
        }

        return $perms;
    }

    private function has(int $perms, int $flag): bool
    {
        return ($perms & $flag) === $flag;
    }
}
