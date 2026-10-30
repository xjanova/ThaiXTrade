<?php

namespace App\Services\Validator;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TpixRpc — JSON-RPC ของเชน TPIX สำหรับงาน validator (อ่านอย่างเดียว).
 *
 * คืน null ทุกครั้งที่อ่านไม่ได้ — ผู้เรียกต้องแยก "ไม่ทราบ" ออกจาก "ไม่มี" เอง
 * ส่ง User-Agent เสมอ ไม่งั้น Cloudflare หน้า rpc ตอบ 403 เป็น HTML
 *
 * Developed by Xman Studio
 */
class TpixRpc
{
    private const USER_AGENT = 'TPIX-TRADE-Server/1.0 (+https://tpix.online)';

    public function url(): string
    {
        return (string) config('blockchain.tpix_rpc_url', 'https://rpc1.tpix.online');
    }

    public function call(string $method, array $params = []): mixed
    {
        try {
            $response = Http::timeout(8)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->asJson()
                ->post($this->url(), [
                    'jsonrpc' => '2.0',
                    'method' => $method,
                    'params' => $params,
                    'id' => 1,
                ]);

            if (! $response->successful() || $response->json('error')) {
                Log::warning('TPIX validator RPC error', [
                    'method' => $method,
                    'status' => $response->status(),
                    'error' => $response->json('error.message'),
                ]);

                return null;
            }

            return $response->json('result');
        } catch (\Throwable $e) {
            Log::warning('TPIX validator RPC failed', ['method' => $method, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function blockNumber(): ?int
    {
        $result = $this->call('eth_blockNumber');

        return is_string($result) && preg_match('/^0x[0-9a-fA-F]+$/', $result) ? (int) hexdec(substr($result, 2)) : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function block(int|string $number): ?array
    {
        $tag = is_int($number) ? '0x'.dechex($number) : $number;
        $result = $this->call('eth_getBlockByNumber', [$tag, false]);

        return is_array($result) ? $result : null;
    }

    /** eth_call — null ถ้าไม่มีโค้ดที่ที่อยู่นั้น (ได้ '0x') หรือ RPC ล้ม */
    public function ethCall(string $to, string $data): ?string
    {
        $result = $this->call('eth_call', [['to' => $to, 'data' => $data], 'latest']);

        return is_string($result) && strlen($result) > 2 ? $result : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function transaction(string $hash): ?array
    {
        $result = $this->call('eth_getTransactionByHash', [$hash]);

        return is_array($result) ? $result : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function receipt(string $hash): ?array
    {
        $result = $this->call('eth_getTransactionReceipt', [$hash]);

        return is_array($result) ? $result : null;
    }
}
