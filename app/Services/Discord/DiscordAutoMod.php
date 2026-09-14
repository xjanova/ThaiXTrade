<?php

namespace App\Services\Discord;

use App\Models\SiteSetting;

/**
 * TPIX TRADE — ติดตั้ง/อัปเดตกฎ AutoMod ของ Discord ให้เซิร์ฟเวอร์ชุมชน.
 *
 * ทำไมใช้ AutoMod: บอทเราไม่ได้ต่อ Gateway ค้าง (เครื่อง shared ไม่อนุญาต process ที่รันค้าง)
 * จึงอ่านแชทแบบเรียลไทม์ไม่ได้ — AutoMod ทำงานบนฝั่ง Discord ตลอด 24 ชม. บล็อกข้อความได้ก่อนคนอื่นเห็น
 * แล้วเขียนผลลง audit log ให้ DiscordModerator มาอ่านไล่ระดับโทษต่อ
 *
 * - แตะเฉพาะกฎที่ชื่อขึ้นต้น "TPIX •" — กฎที่แอดมินตั้งเองไม่ถูกแก้/ลบ
 * - ทีมงาน (ยศที่มีสิทธิ์ Administrator / Manage Server / Manage Messages / Moderate Members) ได้รับยกเว้น
 * - ต้องให้บอทมีสิทธิ์ Manage Server (สร้างกฎ) และ Moderate Members (ปิดเสียงชั่วคราว)
 *
 * Developed by Xman Studio.
 */
class DiscordAutoMod
{
    public const PREFIX = 'TPIX •';

    private const ACTION_BLOCK = 1;

    private const ACTION_ALERT = 2;

    private const ACTION_TIMEOUT = 3;

    public function __construct(
        private readonly DiscordSettings $settings,
        private readonly DiscordClient $client,
    ) {}

    /**
     * @return array{ok: bool, message: string, rules?: list<array<string, mixed>>}
     */
    public function install(): array
    {
        $guildId = $this->settings->guildId();

        if ($guildId === null || ! $this->settings->hasBotToken()) {
            return ['ok' => false, 'message' => 'ยังตั้งค่าไม่ครบ (โทเค็นบอท / Guild ID)'];
        }

        $existing = $this->client->request('GET', "/guilds/{$guildId}/auto-moderation/rules");
        if (! $existing['ok']) {
            return ['ok' => false, 'message' => 'อ่านกฎ AutoMod ไม่ได้: '.$existing['error'].' — ให้สิทธิ์ Manage Server กับบอท'];
        }

        $roles = $this->client->request('GET', "/guilds/{$guildId}/roles");
        $staffRoles = $roles['ok'] ? $this->staffRoles((array) $roles['data']) : [];

        $byName = collect((array) $existing['data'])->keyBy('name');
        $results = [];
        $ruleIds = [];

        foreach ((array) config('discord.moderation.rules', []) as $key => $definition) {
            $payload = $this->payload($definition, $staffRoles);
            $current = $byName[$definition['name']] ?? null;

            if ($payload['actions'] === []) {
                $results[] = ['key' => $key, 'name' => $definition['name'], 'ok' => true, 'note' => 'ข้าม — กฎนี้แจ้งแอดมินอย่างเดียว ต้องเลือกห้องแจ้งเตือนก่อนแล้วกดติดตั้งอีกครั้ง'];

                continue;
            }

            // มีได้กฎเดียวต่อเซิร์ฟเวอร์ (สแปม / preset) — ถ้าแอดมินตั้งไว้เองแล้วด้วยชื่ออื่น ใช้ของแอดมินต่อไป
            if ($current === null && in_array($definition['trigger_type'], [3, 4], true)) {
                $theirs = collect((array) $existing['data'])->first(fn ($r) => (int) $r['trigger_type'] === $definition['trigger_type']);
                if ($theirs !== null) {
                    $results[] = ['key' => $key, 'name' => $definition['name'], 'ok' => true, 'note' => "ใช้กฎเดิมของแอดมิน \"{$theirs['name']}\""];
                    $ruleIds[(string) $theirs['id']] = $key;

                    continue;
                }
            }

            $response = $current !== null
                ? $this->client->request('PATCH', "/guilds/{$guildId}/auto-moderation/rules/{$current['id']}", $this->withoutTrigger($payload))
                : $this->client->request('POST', "/guilds/{$guildId}/auto-moderation/rules", $payload);

            if ($response['ok']) {
                $ruleIds[(string) ($response['data']['id'] ?? $current['id'] ?? '')] = $key;
            }

            $results[] = [
                'key' => $key,
                'name' => $definition['name'],
                'ok' => $response['ok'],
                'note' => $response['ok'] ? ($current !== null ? 'อัปเดตแล้ว' : 'ติดตั้งแล้ว') : $response['error'],
            ];
        }

        $this->settings->mergeState(['automod_rules' => $ruleIds, 'automod_installed_at' => now()->toIso8601String(), 'automod_results' => $results]);

        $failed = count(array_filter($results, fn ($r) => ! $r['ok']));

        return [
            'ok' => $failed === 0,
            'message' => $failed === 0
                ? 'ติดตั้งกฎ AutoMod ครบ '.count($results).' กฎ — Discord บล็อกข้อความที่เข้าข่ายได้ทันที'
                : "ติดตั้งไม่สำเร็จ {$failed} กฎ — ดูรายละเอียดในตาราง",
            'rules' => $results,
        ];
    }

    /**
     * น้ำหนักความผิดของกฎ (หาจาก id ที่ติดตั้งไว้ ไม่งั้นจากชื่อ) — กฎที่ไม่ใช่ของเรานับ 1.
     */
    public function weightFor(?string $ruleId, ?string $ruleName): int
    {
        $rules = (array) config('discord.moderation.rules', []);
        $key = $this->settings->state()['automod_rules'][(string) $ruleId] ?? null;

        if ($key === null && $ruleName !== null) {
            $key = collect($rules)->search(fn ($r) => $r['name'] === $ruleName) ?: null;
        }

        return (int) ($rules[$key]['weight'] ?? 1);
    }

    /**
     * @param  list<array<string, mixed>>  $roles
     * @return list<string>
     */
    public function staffRoles(array $roles): array
    {
        $staff = (int) config('discord.moderation.staff_permissions');

        return collect($roles)
            ->filter(fn ($r) => ((int) ($r['permissions'] ?? 0) & $staff) !== 0 && ! ($r['managed'] ?? false))
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->take(20) // exempt_roles รับได้สูงสุด 20
            ->values()
            ->all();
    }

    // ── ภายใน ────────────────────────────────────────────────────────────────

    /** @param  list<string>  $staffRoles */
    private function payload(array $definition, array $staffRoles): array
    {
        $alertChannel = $this->settings->get('discord_mod_log_channel');
        $alert = is_string($alertChannel) && preg_match(DiscordSettings::SNOWFLAKE, $alertChannel)
            ? ['type' => self::ACTION_ALERT, 'metadata' => ['channel_id' => $alertChannel]]
            : null;

        if (! empty($definition['alert_only'])) {
            // แจ้งแอดมินอย่างเดียว: ไม่บล็อก ไม่ลงบันทึก "บล็อก" ใน audit log จึงไม่นับคะแนน · ไม่มีห้องแจ้งเตือน = ไม่มี action (ข้ามกฎ)
            $actions = $alert !== null ? [$alert] : [];
        } else {
            // custom_message ยาวได้ไม่เกิน 150 ตัวอักษร (ข้อจำกัดของ Discord)
            $block = ['type' => self::ACTION_BLOCK];
            if (! empty($definition['block_message'])) {
                $block['metadata'] = ['custom_message' => mb_substr($definition['block_message'], 0, 150)];
            }
            $actions = array_values(array_filter([$block, $alert]));

            // ปิดเสียงทันทีใช้ได้เฉพาะกฎ keyword / mention spam (ข้อจำกัดของ Discord)
            if (isset($definition['timeout_seconds']) && in_array($definition['trigger_type'], [1, 5], true)) {
                $actions[] = ['type' => self::ACTION_TIMEOUT, 'metadata' => ['duration_seconds' => (int) $definition['timeout_seconds']]];
            }
        }

        $metadata = match ($definition['trigger_type']) {
            1 => array_filter([
                'keyword_filter' => array_values($definition['keywords'] ?? []),
                'regex_patterns' => array_values($definition['regex'] ?? []),
                'allow_list' => $this->allowList(),
            ], fn ($v) => $v !== []),
            4 => ['presets' => $definition['presets'] ?? [1, 2, 3]],
            5 => ['mention_total_limit' => (int) ($definition['mention_limit'] ?? 5), 'mention_raid_protection_enabled' => true],
            default => (object) [],
        };

        return [
            'name' => $definition['name'],
            'event_type' => 1, // MESSAGE_SEND
            'trigger_type' => $definition['trigger_type'],
            'trigger_metadata' => $metadata,
            'actions' => $actions,
            'enabled' => true,
            'exempt_roles' => $staffRoles,
        ];
    }

    /** ตอนแก้กฎเดิม ห้ามส่ง trigger_type (Discord ไม่ให้เปลี่ยน) */
    private function withoutTrigger(array $payload): array
    {
        unset($payload['trigger_type']);

        return $payload;
    }

    /** ลิงก์ของเราไม่นับว่าผิด — เว็บหลัก และลิงก์เชิญเข้าเซิร์ฟเวอร์ของเราเอง */
    private function allowList(): array
    {
        $allow = ['tpix.online'];
        $invite = (string) (SiteSetting::get('social', 'discord') ?: '');

        if (preg_match('#(?:discord\.gg|discord(?:app)?\.com/invite)/([A-Za-z0-9-]+)#', $invite, $m)) {
            $allow[] = 'discord.gg/'.$m[1];
            $allow[] = 'discord.com/invite/'.$m[1];
        }

        return $allow;
    }
}
