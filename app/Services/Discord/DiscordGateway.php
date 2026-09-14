<?php

namespace App\Services\Discord;

use Illuminate\Support\Facades\Log;

use function Ratchet\Client\connect;

use React\EventLoop\Loop;

/**
 * TPIX TRADE — ต่อ Discord Gateway ครั้งเดียวเพื่อ "ยืนยันตัวบอท" แล้วตัดทันที.
 *
 * Discord กำหนดว่าบอทต้อง identify กับ Gateway อย่างน้อยหนึ่งครั้ง ก่อนจะส่งข้อความผ่าน REST ได้
 * เราไม่มี process ที่รันค้างได้บนเครื่อง shared (ดู routes/console.php ส่วน queue:work)
 * จึงต่อแค่พอให้ได้ READY แล้วปิด — ใช้ ratchet/pawl ที่มากับ laravel/reverb อยู่แล้ว
 *
 * ผลพลอยได้: READY บอกชื่อบอทและจำนวนเซิร์ฟเวอร์ที่บอทอยู่ ใช้ยืนยันว่าเชิญบอทเข้าเซิร์ฟเวอร์แล้ว
 *
 * Developed by Xman Studio.
 */
class DiscordGateway
{
    /**
     * @return array{ok: bool, error?: string, username?: string, guilds?: int}
     */
    public function identify(string $token, int $timeoutSeconds = 12): array
    {
        if (! function_exists('Ratchet\Client\connect')) {
            return ['ok' => false, 'error' => 'เซิร์ฟเวอร์ยังไม่มีไลบรารี websocket (ratchet/pawl)'];
        }

        $loop = Loop::get();
        $result = ['ok' => false, 'error' => 'Discord ไม่ตอบภายในเวลาที่กำหนด'];
        $timer = $loop->addTimer($timeoutSeconds, fn () => $loop->stop());

        connect((string) config('discord.gateway_url'), [], [], $loop)->then(
            function ($connection) use ($token, &$result, $loop, $timer) {
                $connection->on('message', function ($message) use ($connection, $token, &$result, $loop, $timer) {
                    $data = json_decode((string) $message, true);

                    switch ($data['op'] ?? null) {
                        case 10: // HELLO → IDENTIFY (intents 0 = ไม่ขอรับเหตุการณ์ใด ๆ)
                            $connection->send(json_encode([
                                'op' => 2,
                                'd' => [
                                    'token' => $token,
                                    'intents' => 0,
                                    'properties' => ['os' => 'linux', 'browser' => 'tpix-trade', 'device' => 'tpix-trade'],
                                ],
                            ]));
                            break;

                        case 0:
                            if (($data['t'] ?? null) === 'READY') {
                                $result = [
                                    'ok' => true,
                                    'username' => (string) ($data['d']['user']['username'] ?? ''),
                                    'guilds' => count((array) ($data['d']['guilds'] ?? [])),
                                ];
                                $loop->cancelTimer($timer);
                                $connection->close(1000);
                            }
                            break;

                        case 9: // INVALID SESSION
                            $result = ['ok' => false, 'error' => 'Discord ไม่รับการยืนยันตัวบอท (invalid session)'];
                            $connection->close(1000);
                            break;
                    }
                });

                $connection->on('close', function ($code) use (&$result, $loop, $timer) {
                    if (! $result['ok'] && (int) $code === 4004) {
                        $result = ['ok' => false, 'error' => 'โทเค็นบอทไม่ถูกต้อง (Discord ปฏิเสธ 4004)'];
                    }
                    $loop->cancelTimer($timer);
                    $loop->stop();
                });
            },
            function (\Throwable $e) use (&$result, $loop, $timer) {
                Log::warning('Discord: ต่อ Gateway ไม่ได้', ['exception' => $e::class]);
                $result = ['ok' => false, 'error' => 'ต่อ Discord Gateway ไม่สำเร็จ'];
                $loop->cancelTimer($timer);
                $loop->stop();
            },
        );

        $loop->run();

        return $result;
    }
}
