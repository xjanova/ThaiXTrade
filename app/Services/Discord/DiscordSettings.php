<?php

namespace App\Services\Discord;

use App\Models\SiteSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — ค่าตั้งของบอท Discord (กรอกที่ /admin/discord).
 *
 * เจ้าของสั่ง: "โทเค็นให้ใส่ในหลังบ้านได้" — ไม่ต้อง ssh ไปแก้ .env
 *
 * ⚠️ โทเค็นบอทเก็บแบบเข้ารหัส (Crypt / APP_KEY)
 *    site_settings ปกติเก็บค่าลับเป็นข้อความเปล่า แล้วค่อย mask ตอนส่งไปหน้าเว็บ —
 *    แต่โทเค็นบอทที่หลุด = ใครก็โพสต์ในเซิร์ฟเวอร์ชุมชนในนามทีมงานได้ทันที
 *    (สแปมลิงก์หลอกซื้อเหรียญถึงสมาชิกทุกคน) จึงเข้ารหัสไว้ตั้งแต่ในฐานข้อมูล
 *
 * ⚠️ ห้ามให้ชั้นนี้ throw — cron และ webhook ของ Discord เรียกผ่านทางนี้
 *
 * Developed by Xman Studio.
 */
class DiscordSettings
{
    public const GROUP = 'discord';

    /** ช่องที่หน้าแอดมินบันทึกได้ — เติมคำนำหน้า discord_ ทุกตัว (หน้าตั้งค่าแบนทุกกลุ่มลง map เดียว) */
    public const EDITABLE = [
        'discord_enabled',
        'discord_application_id',
        'discord_public_key',
        'discord_guild_id',
        'discord_ask_enabled',
        'discord_rules_text',
    ];

    /** Discord ID (snowflake) ตัวเลข 17-20 หลัก — ตรวจทุกครั้งก่อนเอาไปต่อ path ของ API */
    public const SNOWFLAKE = '/^\d{17,20}$/';

    /** Public Key ของแอป = Ed25519 32 ไบต์ (hex 64 ตัว) */
    public const PUBLIC_KEY = '/^[0-9a-f]{64}$/i';

    /** @var array<string, mixed>|null */
    private ?array $stored = null;

    public function enabled(): bool
    {
        return (bool) filter_var($this->get('discord_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    public function askEnabled(): bool
    {
        return (bool) filter_var($this->get('discord_ask_enabled', true), FILTER_VALIDATE_BOOLEAN);
    }

    public function applicationId(): ?string
    {
        return $this->snowflake($this->get('discord_application_id'));
    }

    public function guildId(): ?string
    {
        return $this->snowflake($this->get('discord_guild_id'));
    }

    public function publicKey(): ?string
    {
        $key = trim((string) $this->get('discord_public_key', ''));

        return preg_match(self::PUBLIC_KEY, $key) ? strtolower($key) : null;
    }

    /**
     * โทเค็นบอทตัวจริง (ถอดรหัสแล้ว) — null ถ้ายังไม่ใส่หรือถอดไม่ได้ (APP_KEY เปลี่ยน).
     */
    public function botToken(): ?string
    {
        $cipher = (string) $this->get('discord_bot_token', '');

        if ($cipher === '') {
            return null;
        }

        try {
            $token = Crypt::decryptString($cipher);
        } catch (DecryptException) {
            Log::warning('Discord: ถอดรหัสโทเค็นบอทไม่ได้ (APP_KEY เปลี่ยน?) — ต้องใส่โทเค็นใหม่ที่หลังบ้าน');

            return null;
        }

        return $token !== '' ? $token : null;
    }

    public function hasBotToken(): bool
    {
        return $this->botToken() !== null;
    }

    /** ส่งไปหน้าเว็บได้แค่ 4 ตัวท้าย */
    public function maskedBotToken(): string
    {
        $token = $this->botToken();

        return $token === null ? '' : str_repeat('•', 12).substr($token, -4);
    }

    public function saveBotToken(string $token): void
    {
        SiteSetting::set(self::GROUP, 'discord_bot_token', Crypt::encryptString(trim($token)), 'text');
        $this->forget();
    }

    public function rulesText(): string
    {
        $text = trim((string) $this->get('discord_rules_text', ''));

        return $text !== '' ? $text : trim((string) config('discord.default_rules'));
    }

    /**
     * ผังห้อง role => channel_id (เฉพาะที่เป็น snowflake ถูกรูป).
     *
     * @return array<string, string>
     */
    public function channelMap(): array
    {
        $map = $this->json('discord_channel_map');

        return array_filter(
            array_map(fn ($id) => $this->snowflake($id), $map),
            fn ($id) => $id !== null,
        );
    }

    public function channelFor(string $role): ?string
    {
        return $this->channelMap()[$role] ?? null;
    }

    /** @param  array<string, string|null>  $map */
    public function saveChannelMap(array $map): void
    {
        $clean = array_filter(array_map(fn ($id) => $this->snowflake($id), $map), fn ($id) => $id !== null);
        SiteSetting::set(self::GROUP, 'discord_channel_map', json_encode($clean), 'json');
        $this->forget();
    }

    /**
     * สถานะที่บอทเก็บเอง (ชื่อบอท/เซิร์ฟเวอร์ รายชื่อห้อง ผลซิงก์ล่าสุด ฯลฯ).
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return $this->json('discord_state');
    }

    /** @param  array<string, mixed>  $changes */
    public function mergeState(array $changes): void
    {
        SiteSetting::set(self::GROUP, 'discord_state', json_encode(array_merge($this->state(), $changes), JSON_UNESCAPED_UNICODE), 'json');
        $this->forget();
    }

    public function set(string $key, mixed $value, string $type = 'string'): void
    {
        SiteSetting::set(self::GROUP, $key, is_bool($value) ? ($value ? '1' : '0') : (string) $value, $type);
        $this->forget();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->stored()[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    public function forget(): void
    {
        $this->stored = null;
    }

    // ── ภายใน ────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function stored(): array
    {
        if ($this->stored !== null) {
            return $this->stored;
        }

        try {
            return $this->stored = SiteSetting::getGroup(self::GROUP)->all();
        } catch (\Throwable $e) {
            Log::warning('Discord: อ่านค่าตั้งจากฐานข้อมูลไม่ได้', ['error' => $e->getMessage()]);

            return $this->stored = [];
        }
    }

    /** @return array<string, mixed> */
    private function json(string $key): array
    {
        $value = $this->get($key, []);

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }

    private function snowflake(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return preg_match(self::SNOWFLAKE, $value) ? $value : null;
    }
}
