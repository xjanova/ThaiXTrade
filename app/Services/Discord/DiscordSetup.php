<?php

namespace App\Services\Discord;

/**
 * TPIX TRADE — ขั้นตอนตั้งค่าบอทที่แอดมินกดจากหลังบ้าน (ทดสอบการเชื่อมต่อ / ลงทะเบียนคำสั่ง).
 *
 * Developed by Xman Studio.
 */
class DiscordSetup
{
    /** ชื่อคำสั่งเมนูคลิกขวา (Discord ส่งชื่อนี้กลับมาใน interaction ไม่ว่าแอปผู้ใช้ภาษาอะไร) */
    public const REPORT_COMMAND = 'Report to admins';

    public function __construct(
        private readonly DiscordSettings $settings,
        private readonly DiscordClient $client,
        private readonly DiscordGateway $gateway,
        private readonly DiscordPublisher $publisher,
    ) {}

    /**
     * ตรวจโทเค็น → ยืนยันตัวกับ Gateway (ครั้งแรก) → ดึงเซิร์ฟเวอร์และรายชื่อห้อง → วางผังห้อง.
     *
     * @return array{ok: bool, message: string}
     */
    public function connect(): array
    {
        $token = $this->settings->botToken();

        if ($token === null) {
            return ['ok' => false, 'message' => 'ยังไม่ได้ใส่โทเค็นบอท'];
        }

        $me = $this->client->me($token);
        if (! $me['ok']) {
            return ['ok' => false, 'message' => 'ตรวจโทเค็นไม่ผ่าน: '.$me['error']];
        }

        $state = ['bot_name' => (string) ($me['data']['username'] ?? ''), 'bot_id' => (string) ($me['data']['id'] ?? '')];

        // Discord ต้องการให้บอท identify กับ Gateway อย่างน้อยครั้งหนึ่งก่อนส่งข้อความได้
        if (empty($this->settings->state()['identified_at'])) {
            $identified = $this->gateway->identify($token);

            if (! $identified['ok']) {
                $this->settings->mergeState($state);

                return ['ok' => false, 'message' => 'โทเค็นใช้ได้ แต่ยืนยันตัวกับ Gateway ไม่สำเร็จ: '.$identified['error']];
            }

            $state['identified_at'] = now()->toIso8601String();
        }

        $guildId = $this->settings->guildId();
        if ($guildId === null) {
            $this->settings->mergeState($state);

            return ['ok' => false, 'message' => "เชื่อมบอท {$state['bot_name']} ได้แล้ว — ใส่ Guild ID ของเซิร์ฟเวอร์ต่อ"];
        }

        $guild = $this->client->guild($guildId);
        if (! $guild['ok']) {
            $this->settings->mergeState($state);

            return ['ok' => false, 'message' => 'บอทยังเข้าเซิร์ฟเวอร์นี้ไม่ได้: '.$guild['error']];
        }

        $state['guild_name'] = (string) ($guild['data']['name'] ?? '');
        $this->settings->mergeState($state);

        $channels = $this->publisher->refreshChannels();
        if (! $channels['ok']) {
            return ['ok' => false, 'message' => 'ดึงรายชื่อห้องไม่สำเร็จ: '.$channels['error']];
        }

        $roles = count($channels['map']);
        $total = count($this->settings->state()['channels'] ?? []);

        return ['ok' => true, 'message' => "เชื่อมต่อแล้ว — บอท {$state['bot_name']} ในเซิร์ฟเวอร์ {$state['guild_name']} · พบห้องที่โพสต์ได้ {$total} ห้อง · จัดห้องให้ {$roles} เรื่อง"];
    }

    /**
     * ลงทะเบียนคำสั่งทั้งชุดเป็นคำสั่งของเซิร์ฟเวอร์ (มีผลทันที ต่างจากคำสั่ง global ที่รอได้ถึงชั่วโมง)
     * ⚠️ PUT แทนที่ทั้งชุด — คำสั่งที่ไม่อยู่ใน commands() จะหายจากเซิร์ฟเวอร์.
     *
     * @return array{ok: bool, message: string}
     */
    public function registerCommands(): array
    {
        $applicationId = $this->settings->applicationId();
        $guildId = $this->settings->guildId();

        if ($applicationId === null || $guildId === null) {
            return ['ok' => false, 'message' => 'ต้องใส่ Application ID และ Guild ID ก่อน'];
        }

        $response = $this->client->putGuildCommands($applicationId, $guildId, self::commands());

        if (! $response['ok']) {
            return ['ok' => false, 'message' => 'ลงทะเบียนคำสั่งไม่สำเร็จ: '.$response['error']];
        }

        $this->settings->mergeState(['commands_registered_at' => now()->toIso8601String()]);

        return ['ok' => true, 'message' => 'ลงทะเบียนคำสั่งแล้ว — /ถาม /ขายเหรียญ /ราคา /เชน /ลิงก์ และคลิกขวาข้อความ → Apps → รายงานให้แอดมิน · ใช้ในเซิร์ฟเวอร์ได้ทันที'];
    }

    /** @return list<array<string, mixed>> */
    public static function commands(): array
    {
        return [
            [
                'name' => 'ask',
                'name_localizations' => ['th' => 'ถาม'],
                'description' => 'Ask the TPIX AI assistant about TPIX',
                'description_localizations' => ['th' => 'ถามผู้ช่วย AI เรื่อง TPIX (ตอบจากข้อมูลบนเว็บ)'],
                'type' => 1,
                'options' => [[
                    'type' => 3,
                    'name' => 'question',
                    'name_localizations' => ['th' => 'คำถาม'],
                    'description' => 'Your question',
                    'description_localizations' => ['th' => 'พิมพ์คำถามของคุณ'],
                    'required' => true,
                    'max_length' => (int) config('discord.ask.max_question_length', 500),
                ]],
            ],
            [
                'name' => 'sale',
                'name_localizations' => ['th' => 'ขายเหรียญ'],
                'description' => 'Current TPIX token sale status',
                'description_localizations' => ['th' => 'สถานะการขายเหรียญ TPIX ตอนนี้'],
                'type' => 1,
            ],
            [
                'name' => 'price',
                'name_localizations' => ['th' => 'ราคา'],
                'description' => 'Live TPIX price',
                'description_localizations' => ['th' => 'ราคา TPIX ล่าสุด (ชุดเดียวกับหน้าเว็บ)'],
                'type' => 1,
            ],
            [
                'name' => 'chain',
                'name_localizations' => ['th' => 'เชน'],
                'description' => 'TPIX Chain network status',
                'description_localizations' => ['th' => 'สถานะเครือข่าย TPIX Chain — บล็อก · validator · มาสเตอร์โหนด'],
                'type' => 1,
            ],
            [
                'name' => 'links',
                'name_localizations' => ['th' => 'ลิงก์'],
                'description' => 'Official TPIX links and contract addresses',
                'description_localizations' => ['th' => 'ลิงก์ทางการและที่อยู่สัญญา — กันลิงก์ปลอม'],
                'type' => 1,
            ],
            [
                // คลิกขวาที่ข้อความ → Apps → รายงานให้แอดมิน (คำสั่งแบบเมนูไม่มีคำอธิบาย)
                'name' => self::REPORT_COMMAND,
                'name_localizations' => ['th' => 'รายงานให้แอดมิน'],
                'type' => 3,
            ],
        ];
    }
}
