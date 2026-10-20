<?php

namespace App\Services\TokenFactory;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * JSON-RPC ของเชน TPIX (4289) สำหรับโรงงานสร้างเหรียญ.
 *
 * ⚠️ ต้องส่ง User-Agent แบบเบราว์เซอร์เสมอ — rpc.tpix.online อยู่หลัง Cloudflare bot rule
 *    ที่ตอบ 403 ให้ client ที่ไม่มี UA (ยืนยันสด 2026-08-27)
 *
 * ต่างจาก ContractRegistry::rpc() ตรงที่ "โยน" เมื่อคุยกับเชนไม่ได้ แทนที่จะคืน null
 * — การตรวจค่าธรรมเนียมต้องแยกให้ออกระหว่าง "เชนบอกว่าไม่มีธุรกรรมนี้" กับ "ถามเชนไม่ได้"
 * ไม่งั้นผู้ใช้ที่จ่ายเงินแล้วจะโดนบอกว่าไม่พบการชำระเงินเพราะ RPC ล่มชั่วคราว
 *
 * Developed by Xman Studio
 */
class TpixRpcClient
{
    public const USER_AGENT = 'Mozilla/5.0 (compatible; TPIX-TRADE-Server/1.0; +https://tpix.online)';

    /**
     * @throws RuntimeException เมื่อติดต่อเชนไม่ได้ หรือเชนตอบ error
     */
    public function call(string $method, array $params = []): mixed
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json'])
                ->asJson()
                ->post($this->url(), [
                    'jsonrpc' => '2.0',
                    'method' => $method,
                    'params' => $params,
                    'id' => 1,
                ]);
        } catch (\Throwable $e) {
            throw new RuntimeException("RPC {$method} ไม่ตอบ: ".$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException("RPC {$method} ตอบ HTTP {$response->status()}");
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new RuntimeException("RPC {$method} ตอบไม่ใช่ JSON");
        }

        if (isset($body['error'])) {
            $message = is_array($body['error']) ? ($body['error']['message'] ?? 'error') : (string) $body['error'];

            throw new RuntimeException("RPC {$method} error: {$message}");
        }

        return $body['result'] ?? null;
    }

    public function url(): string
    {
        return (string) config('blockchain.tpix_rpc_url', 'https://rpc1.tpix.online');
    }

    /** เลขฐานสิบหกของเชน → int (บล็อก/nonce/chainId ไม่เกิน PHP_INT_MAX) */
    public static function hexToInt(mixed $hex): ?int
    {
        if (! is_string($hex) || ! preg_match('/^0x[0-9a-fA-F]+$/', $hex)) {
            return null;
        }

        return (int) hexdec($hex);
    }
}
