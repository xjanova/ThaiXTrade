<?php

namespace App\Services\Bridge;

use App\Support\Wei;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * JSON-RPC ไปยังเชน EVM หนึ่งเชน พร้อม RPC สำรองหลายตัว — อ่านอย่างเดียว ไม่มีคีย์.
 *
 * กติกา:
 *   - ทุกเมธอดคืน null เมื่ออ่านไม่ได้ ไม่โยน exception — "อ่านไม่ได้" ต้องแยกจาก "ค่าเป็นศูนย์"
 *     (กลืน null เป็น 0 = ระบบเห็นกระเป๋าว่างทั้งที่แค่ RPC ล่ม แล้วตัดสินใจผิด)
 *   - ส่ง User-Agent เสมอ — Cloudflare หน้า rpc.tpix.online ตอบ 403/429 เป็น HTML ให้ request ที่ไม่มี
 *   - RPC ตัวแรกล้ม (timeout / 5xx / ตอบไม่ใช่ JSON / rate limit) → ลองตัวถัดไปทันที
 *     แต่ error ระดับ JSON-RPC (เช่น execution reverted) คือคำตอบจริงของเชน ไม่ลองตัวอื่นซ้ำ
 */
class EvmRpc
{
    /** @var array<int, string> */
    private array $urls;

    public function __construct(
        public readonly int $chainId,
        array $urls,
        private readonly string $userAgent,
        private readonly int $timeout = 10,
    ) {
        $this->urls = array_values(array_filter($urls, fn ($u) => is_string($u) && str_starts_with($u, 'http')));
    }

    /** @return array<int, string> */
    public function urls(): array
    {
        return $this->urls;
    }

    /**
     * เรียก JSON-RPC — คืน ['result' => …] หรือ ['error' => …] หรือ null เมื่อไม่มี RPC ไหนตอบได้.
     */
    public function request(string $method, array $params = []): ?array
    {
        foreach ($this->urls as $url) {
            try {
                $response = Http::withHeaders(['User-Agent' => $this->userAgent])
                    ->timeout($this->timeout)
                    ->asJson()
                    ->post($url, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
            } catch (\Throwable $e) {
                Log::warning('bridge rpc: เรียกไม่สำเร็จ', ['chain' => $this->chainId, 'method' => $method, 'error' => mb_substr($e->getMessage(), 0, 200)]);

                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $data = $response->json();
            if (! is_array($data)) {
                continue;
            }

            if (isset($data['error'])) {
                $message = (string) ($data['error']['message'] ?? '');

                // ปัญหาของตัว RPC (ช่วงยาวเกิน / โควตาหมด / ไม่รองรับ) — ตัวอื่นอาจตอบได้
                if (preg_match('/rate|limit|too many|range|exceed|unsupported|not supported|timeout|capacity/i', $message)) {
                    continue;
                }

                return ['error' => $data['error']];
            }

            if (array_key_exists('result', $data)) {
                return ['result' => $data['result']];
            }
        }

        return null;
    }

    /** ผลลัพธ์อย่างเดียว — null ทั้งเมื่อ error และอ่านไม่ได้ */
    public function result(string $method, array $params = []): mixed
    {
        $answer = $this->request($method, $params);

        return $answer['result'] ?? null;
    }

    public function blockNumber(): ?int
    {
        $hex = $this->result('eth_blockNumber');

        return is_string($hex) && str_starts_with($hex, '0x') ? (int) Wei::hexToInt($hex) : null;
    }

    /** เลขบล็อกของแท็ก (finalized / safe / latest) — null ถ้า RPC ไม่รองรับ */
    public function blockNumberByTag(string $tag): ?int
    {
        $block = $this->result('eth_getBlockByNumber', [$tag, false]);
        $hex = is_array($block) ? ($block['number'] ?? null) : null;

        return is_string($hex) && str_starts_with($hex, '0x') ? (int) Wei::hexToInt($hex) : null;
    }

    /**
     * หัวที่ปลอดภัย = บล็อกที่ถือว่าจบแล้ว ไม่ย้อน (reorg) แน่นอน.
     *
     * finalized: min(บล็อก finalized, ล่าสุด − minConf) — ใช้ทั้งสองเงื่อนไขกัน RPC บางตัวตอบ
     *            finalized = latest มั่ว ๆ (เห็นกับ BSC บางโหนด)
     * ไม่รองรับแท็ก → ล่าสุด − fallbackConf
     */
    public function safeHead(string $finality, int $minConfirmations, int $fallbackConfirmations): ?int
    {
        $latest = $this->blockNumber();
        if ($latest === null) {
            return null;
        }

        if ($finality === 'finalized') {
            $finalized = $this->blockNumberByTag('finalized');

            $head = $finalized === null
                ? $latest - $fallbackConfirmations
                : min($finalized, $latest - $minConfirmations);
        } else {
            $head = $latest - $minConfirmations;
        }

        return $head > 0 ? $head : null;
    }

    /** @return array<int, array>|null null = อ่านไม่ได้ ([] = ช่วงนี้ไม่มี log) */
    public function logs(string $address, array $topics, int $fromBlock, int $toBlock): ?array
    {
        $result = $this->result('eth_getLogs', [[
            'address' => $address,
            'topics' => $topics,
            'fromBlock' => '0x'.dechex($fromBlock),
            'toBlock' => '0x'.dechex($toBlock),
        ]]);

        return is_array($result) ? $result : null;
    }

    public function receipt(string $txHash): ?array
    {
        $result = $this->result('eth_getTransactionReceipt', [$txHash]);

        return is_array($result) ? $result : null;
    }

    public function transaction(string $txHash): ?array
    {
        $result = $this->result('eth_getTransactionByHash', [$txHash]);

        return is_array($result) ? $result : null;
    }

    /**
     * ธุรกรรมนี้เชนรู้จักไหม — true/false หรือ null เมื่ออ่านไม่ได้ (ต้องแยกให้ออก
     * เพราะ "ไม่รู้จัก" นำไปสู่การส่ง raw ซ้ำ ส่วน "อ่านไม่ได้" ต้องรอเฉย ๆ).
     */
    public function knowsTransaction(string $txHash): ?bool
    {
        $answer = $this->request('eth_getTransactionByHash', [$txHash]);
        if ($answer === null || isset($answer['error'])) {
            return null;
        }

        return is_array($answer['result'] ?? null);
    }

    /** eth_call — คืน hex หรือ null (revert/อ่านไม่ได้) */
    public function call(string $to, string $data): ?string
    {
        $result = $this->result('eth_call', [['to' => $to, 'data' => $data], 'latest']);

        return is_string($result) && str_starts_with($result, '0x') && strlen($result) > 2 ? $result : null;
    }

    public function callUint(string $to, string $signature, string $args = ''): ?string
    {
        return Abi::decUint($this->call($to, Abi::selector($signature).$args));
    }

    public function callBool(string $to, string $signature, string $args = ''): ?bool
    {
        return Abi::decBool($this->call($to, Abi::selector($signature).$args));
    }

    public function tokenBalance(string $token, string $owner): ?string
    {
        return $this->callUint($token, 'balanceOf(address)', Abi::encAddress($owner));
    }

    public function nativeBalance(string $address): ?string
    {
        $hex = $this->result('eth_getBalance', [$address, 'latest']);

        return is_string($hex) && str_starts_with($hex, '0x') ? Wei::hexToInt($hex) : null;
    }

    /** bytecode — '0x' = EOA, null = อ่านไม่ได้ */
    public function code(string $address): ?string
    {
        $code = $this->result('eth_getCode', [$address, 'latest']);

        return is_string($code) && str_starts_with($code, '0x') ? strtolower($code) : null;
    }

    public function blockTimestamp(int $block): ?int
    {
        $result = $this->result('eth_getBlockByNumber', ['0x'.dechex($block), false]);
        $hex = is_array($result) ? ($result['timestamp'] ?? null) : null;

        return is_string($hex) && str_starts_with($hex, '0x') ? (int) Wei::hexToInt($hex) : null;
    }
}
