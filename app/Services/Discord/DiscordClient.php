<?php

namespace App\Services\Discord;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * TPIX TRADE — เรียก Discord REST API (v10) ในนามบอท.
 *
 * - ทุกคำตอบคืนรูปเดียวกัน {ok, status, data, error, code} — ไม่ throw
 * - error เป็นภาษาไทยให้แอดมินอ่านรู้เรื่อง (ข้อความดิบของ Discord เก็บไว้ใน log)
 * - 429 → รอตาม retry_after (ไม่เกิน 5 วิ) แล้วลองใหม่ 1 ครั้ง
 * - โทเค็นอยู่ใน header เท่านั้น และถูกตัดออกก่อนเขียน log ทุกครั้ง
 *
 * Developed by Xman Studio.
 */
class DiscordClient
{
    public function __construct(private readonly DiscordSettings $settings) {}

    /** @return array{ok: bool, status: int, data: mixed, error: ?string, code: ?int} */
    public function me(?string $token = null): array
    {
        return $this->request('GET', '/users/@me', [], $token);
    }

    public function guild(string $guildId): array
    {
        return $this->guarded([$guildId], fn () => $this->request('GET', "/guilds/{$guildId}"));
    }

    public function guildChannels(string $guildId): array
    {
        return $this->guarded([$guildId], fn () => $this->request('GET', "/guilds/{$guildId}/channels"));
    }

    public function createMessage(string $channelId, array $payload): array
    {
        return $this->guarded([$channelId], fn () => $this->request('POST', "/channels/{$channelId}/messages", $payload));
    }

    public function editMessage(string $channelId, string $messageId, array $payload): array
    {
        return $this->guarded([$channelId, $messageId], fn () => $this->request('PATCH', "/channels/{$channelId}/messages/{$messageId}", $payload));
    }

    /** ลบข้อความของคนอื่น (ปุ่ม "ลบข้อความ" บนการ์ดรายงาน) — ต้องมีสิทธิ์ Manage Messages */
    public function deleteMessage(string $channelId, string $messageId, string $auditReason): array
    {
        return $this->guarded([$channelId, $messageId], fn () => $this->request('DELETE', "/channels/{$channelId}/messages/{$messageId}", [], null, $auditReason));
    }

    /**
     * ปักหมุดข้อความ (คู่มือประจำห้อง / การ์ดราคา) — ต้องมีสิทธิ์ Pin Messages
     * ใช้ endpoint ใหม่ /messages/pins/{id} (ตัวเก่า /pins/{id} Discord ประกาศเลิกใช้แล้ว).
     */
    public function pinMessage(string $channelId, string $messageId): array
    {
        return $this->guarded([$channelId, $messageId], fn () => $this->request('PUT', "/channels/{$channelId}/messages/pins/{$messageId}"));
    }

    public function putGuildCommands(string $applicationId, string $guildId, array $commands): array
    {
        return $this->guarded([$applicationId, $guildId], fn () => $this->request('PUT', "/applications/{$applicationId}/guilds/{$guildId}/commands", $commands));
    }

    /**
     * แก้คำตอบของ slash command (หลังตอบ "กำลังคิด…" ไปก่อน).
     *
     * endpoint ของ webhook ยืนยันตัวด้วย interaction token ใน path — ไม่ต้องใช้โทเค็นบอท
     */
    public function editOriginalResponse(string $applicationId, string $interactionToken, array $payload): array
    {
        // token ไปอยู่ใน path — รับเฉพาะอักขระแบบ base64url (ห้าม / ? # % ที่พา request ไปที่อื่นได้)
        if (! preg_match(DiscordSettings::SNOWFLAKE, $applicationId) || ! preg_match('/^[A-Za-z0-9._=-]{20,600}$/', $interactionToken)) {
            return $this->failure(0, 'ข้อมูลคำสั่งจาก Discord ไม่ถูกต้อง');
        }

        return $this->send('PATCH', "/webhooks/{$applicationId}/{$interactionToken}/messages/@original", $payload, null, [$interactionToken]);
    }

    /**
     * @param  string|null  $auditReason  เหตุผลที่ขึ้นใน audit log ของเซิร์ฟเวอร์ (เตะ/แบน/ปิดเสียง ต้องมีเสมอ)
     * @return array{ok: bool, status: int, data: mixed, error: ?string, code: ?int}
     */
    public function request(string $method, string $path, array $payload = [], ?string $token = null, ?string $auditReason = null): array
    {
        $token ??= $this->settings->botToken();

        if ($token === null || $token === '') {
            return $this->failure(0, 'ยังไม่ได้ใส่โทเค็นบอทที่หลังบ้าน');
        }

        // โทเค็นไปอยู่ใน header — ห้ามมีช่องว่าง/ขึ้นบรรทัด (header injection)
        if (! preg_match('/^[A-Za-z0-9._-]{30,200}$/', $token)) {
            return $this->failure(0, 'รูปแบบโทเค็นบอทไม่ถูกต้อง');
        }

        return $this->send($method, $path, $payload, $token, [$token], $auditReason);
    }

    // ── ภายใน ────────────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $secrets
     */
    private function send(string $method, string $path, array $payload, ?string $token, array $secrets, ?string $auditReason = null): array
    {
        $url = rtrim((string) config('discord.api_base'), '/').$path;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $request = Http::timeout((int) config('discord.timeout', 10))
                    ->withHeaders(array_filter([
                        'User-Agent' => (string) config('discord.user_agent'),
                        'Authorization' => $token !== null ? 'Bot '.$token : null,
                        // Discord ต้องการ UTF-8 ที่ URL-encode แล้ว (ภาษาไทยส่งตรง ๆ ไม่ได้) · รับได้ 512 ตัว เราตัดไว้ 120
                        'X-Audit-Log-Reason' => $auditReason !== null ? rawurlencode(mb_substr($auditReason, 0, 120)) : null,
                    ]))
                    ->acceptJson();

                $response = match ($method) {
                    'GET' => $request->get($url),
                    'POST' => $request->asJson()->post($url, $payload),
                    'PATCH' => $request->asJson()->patch($url, $payload),
                    // ปักหมุดไม่มี body — ส่ง "[]" ไปกับ PUT บาง endpoint ของ Discord ถูกปฏิเสธ
                    'PUT' => $payload === [] ? $request->send('PUT', $url) : $request->asJson()->put($url, $payload),
                    'DELETE' => $request->delete($url),
                    default => throw new \InvalidArgumentException("unsupported method {$method}"),
                };
            } catch (\Throwable $e) {
                Log::warning('Discord: ต่อ API ไม่ได้', [
                    'path' => $this->redact($path, $secrets),
                    'exception' => $e::class,
                    'error' => $this->redact($e->getMessage(), $secrets),
                ]);

                return $this->failure(0, 'เชื่อมต่อ Discord ไม่สำเร็จหรือหมดเวลา');
            }

            if ($response->status() === 429 && $attempt === 1) {
                $retryAfter = (float) ($response->json('retry_after') ?? 0);

                if ($retryAfter > 0 && $retryAfter <= 5) {
                    Sleep::for((int) ceil($retryAfter * 1000))->milliseconds();

                    continue;
                }
            }

            return $this->toResult($response, $path, $secrets);
        }

        return $this->failure(429, 'Discord จำกัดความถี่ชั่วคราว กรุณาลองใหม่ภายหลัง');
    }

    private function toResult(Response $response, string $path, array $secrets): array
    {
        if ($response->successful()) {
            return ['ok' => true, 'status' => $response->status(), 'data' => $response->json(), 'error' => null, 'code' => null];
        }

        $code = is_numeric($response->json('code')) ? (int) $response->json('code') : null;

        Log::warning('Discord: API ปฏิเสธ', [
            'path' => $this->redact($path, $secrets),
            'status' => $response->status(),
            'body' => $this->redact(mb_substr($response->body(), 0, 400), $secrets),
        ]);

        $error = match (true) {
            $response->status() === 401 => 'โทเค็นบอทไม่ถูกต้องหรือถูกรีเซ็ต',
            $code === 50001 => 'บอทมองไม่เห็นห้องนี้ — ให้สิทธิ์ View Channel กับบอท',
            $code === 50013 => 'บอทไม่มีสิทธิ์พอ — ให้สิทธิ์ Send Messages / Embed Links กับบอทในห้องนี้',
            $code === 10003 => 'ไม่พบห้องนี้แล้ว (ถูกลบหรือ ID ผิด)',
            $code === 10008 => 'ไม่พบข้อความเดิม (ถูกลบไปแล้ว)',
            $code === 10004 => 'ไม่พบเซิร์ฟเวอร์ — ตรวจ Guild ID และเชิญบอทเข้าเซิร์ฟเวอร์ก่อน',
            $response->status() === 403 => 'บอทไม่มีสิทธิ์ทำสิ่งนี้',
            $response->status() === 404 => 'ไม่พบสิ่งที่อ้างถึง (ID ผิดหรือถูกลบ)',
            $response->status() === 429 => 'Discord จำกัดความถี่ชั่วคราว กรุณาลองใหม่ภายหลัง',
            default => 'Discord ปฏิเสธคำขอ (รหัส '.$response->status().')',
        };

        return ['ok' => false, 'status' => $response->status(), 'data' => $response->json(), 'error' => $error, 'code' => $code];
    }

    /**
     * ID ทุกตัวที่จะไปต่อ path ต้องเป็น snowflake — กันค่าที่หลุดมาจากฐานข้อมูลพา request ไปที่อื่น.
     *
     * @param  list<string>  $ids
     */
    private function guarded(array $ids, callable $call): array
    {
        foreach ($ids as $id) {
            if (! preg_match(DiscordSettings::SNOWFLAKE, $id)) {
                return $this->failure(0, 'ID ของ Discord ไม่ถูกต้อง (ต้องเป็นตัวเลข 17-20 หลัก)');
            }
        }

        return $call();
    }

    private function failure(int $status, string $error): array
    {
        return ['ok' => false, 'status' => $status, 'data' => null, 'error' => $error, 'code' => null];
    }

    /** @param  list<string>  $secrets */
    private function redact(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $text = str_replace($secret, '***', $text);
            }
        }

        return $text;
    }
}
