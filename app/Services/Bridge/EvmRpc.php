<?php

namespace App\Services\Bridge;

use App\Models\SystemAlert;
use App\Support\Wei;
use Illuminate\Support\Facades\Cache;
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
 *   - ใช้ RPC ตัวไหนต้องตอบ eth_chainId ตรงกับเชนนี้ก่อน — URL ชี้ผิดเชน log ของเชนอื่นจะกลายเป็นเงินฝากปลอม
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

    /** เฉพาะ RPC ตัวนี้ตัวเดียว — ไว้ถามไขว้ระหว่าง provider */
    public function only(string $url): self
    {
        return new self($this->chainId, [$url], $this->userAgent, $this->timeout);
    }

    /**
     * เรียก JSON-RPC — คืน ['result' => …] หรือ ['error' => …] หรือ null เมื่อไม่มี RPC ไหนตอบได้.
     */
    public function request(string $method, array $params = []): ?array
    {
        foreach ($this->urls as $url) {
            $answer = $this->requestUrl($url, $method, $params);

            if ($answer !== null) {
                return $answer;
            }
        }

        return null;
    }

    /**
     * เรียก RPC ตัวเดียว — null = ตัวนี้ตอบไม่ได้ (ล่ม / ไม่ใช่ JSON / ปัญหาของตัว RPC เอง) ให้ลองตัวถัดไป.
     */
    private function requestUrl(string $url, string $method, array $params): ?array
    {
        if ($method !== 'eth_chainId' && ! $this->servesChain($url)) {
            return null;
        }

        try {
            $response = Http::withHeaders(['User-Agent' => $this->userAgent])
                ->timeout($this->timeout)
                ->asJson()
                ->post($url, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
        } catch (\Throwable $e) {
            Log::warning('bridge rpc: เรียกไม่สำเร็จ', ['chain' => $this->chainId, 'method' => $method, 'error' => mb_substr($e->getMessage(), 0, 200)]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();
        if (! is_array($data)) {
            return null;
        }

        if (isset($data['error'])) {
            return self::isProviderProblem($data['error']) ? null : ['error' => $data['error']];
        }

        return array_key_exists('result', $data) ? ['result' => $data['result']] : null;
    }

    /**
     * RPC ตัวนี้เป็นของเชนนี้จริงไหม — จำผลไว้ ไม่ถามซ้ำทุกคำขอ.
     *
     * ตอบผิดเชนจำแค่ไม่กี่นาที แก้ .env แล้วต้องกลับมาใช้ได้เอง
     * ⚠️ แจ้งแค่ชื่อโฮสต์ — URL ของ provider เสียเงินมีคีย์ฝังอยู่
     */
    private function servesChain(string $url): bool
    {
        $key = "bridge:rpc-chain:{$this->chainId}:".md5($url);
        $known = Cache::get($key);
        if (is_bool($known)) {
            return $known;
        }

        $hex = $this->requestUrl($url, 'eth_chainId', [])['result'] ?? null;
        if (! is_string($hex) || ! str_starts_with($hex, '0x')) {
            return false; // ตัวนี้ตอบไม่ได้ตอนนี้ — ลองตัวถัดไป ไม่จำผล
        }

        $matches = (int) Wei::hexToInt($hex) === $this->chainId;
        Cache::put($key, $matches, $matches ? now()->addHour() : now()->addMinutes(5));

        if (! $matches) {
            $host = (string) parse_url($url, PHP_URL_HOST);
            Log::critical('bridge rpc: RPC ตอบคนละเชน', ['chain' => $this->chainId, 'answered' => $hex, 'host' => $host]);
            SystemAlert::raise(
                BridgeReconciler::ALERT_NODE,
                "bridge_rpc_wrong_chain_{$this->chainId}",
                'critical',
                "RPC {$host} ตั้งไว้สำหรับเชน {$this->chainId} แต่ตอบเป็นเชนอื่น — ระบบข้ามตัวนี้ ตรวจค่า RPC ใน .env",
                ['host' => $host, 'answered' => $hex],
            );
        }

        return $matches;
    }

    /**
     * error นี้เป็นปัญหาของ "ตัว RPC" (ตัวอื่นอาจตอบได้) หรือเป็นคำตอบจริงของเชน (เช่น execution reverted).
     *
     * โหนดที่ลบประวัติแล้ว (pruned / history / header not found / missing trie node) หรือโหนดที่ตามหัวไม่ทัน
     * ตอบ error แบบนี้ได้ทั้งที่อีกตัวตอบได้ — เดิมถือเป็นคำตอบจริงแล้วหยุดทันที ตัวสแกนจึงค้างอยู่ที่ช่วงเดิม
     * ทุกรอบตลอดไป ทั้งที่ RPC ตัวที่สองมีข้อมูลครบ (เกิดได้จริงหลังเซิร์ฟเวอร์ดับนานเกินหน้าต่างของ publicnode)
     */
    private static function isProviderProblem(mixed $error): bool
    {
        $message = is_array($error) ? (string) ($error['message'] ?? '') : (string) $error;
        $code = is_array($error) ? (int) ($error['code'] ?? 0) : 0;

        // -32005 = limit exceeded · -32603 = internal error ของตัวโหนด
        if (in_array($code, [-32005, -32603], true)) {
            return true;
        }

        return (bool) preg_match(
            '/rate|limit|too many|range|exceed|unsupported|not supported|timeout|timed out|capacity'
            .'|prun|history|archive|header not found|missing trie|unknown block|not available|unavailable'
            .'|try again|overload|busy|temporar|syncing/i',
            $message,
        );
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

    /**
     * logs ทั้งช่วงแบบหั่นเป็นท่อน — null ถ้าท่อนไหนอ่านไม่ได้ (ได้ไม่ครบ = ห้ามสรุปว่า "ไม่มี").
     *
     * $stop คืน true เมื่อเจอสิ่งที่ตามหาแล้ว — หยุดอ่านท่อนที่เหลือ (ไม่ต้องไล่ทั้งช่วงเมื่อเจอแล้ว)
     *
     * @return array<int, array>|null
     */
    public function logsBetween(string $address, array $topics, int $fromBlock, int $toBlock, int $chunk, ?callable $stop = null): ?array
    {
        $chunk = max(1, $chunk);
        $found = [];

        for ($start = max(0, $fromBlock); $start <= $toBlock; $start += $chunk) {
            $logs = $this->logs($address, $topics, $start, min($toBlock, $start + $chunk - 1));

            if ($logs === null) {
                return null;
            }

            foreach ($logs as $log) {
                $found[] = $log;

                if ($stop !== null && $stop($log)) {
                    return $found;
                }
            }
        }

        return $found;
    }

    public function receipt(string $txHash): ?array
    {
        $result = $this->result('eth_getTransactionReceipt', [$txHash]);

        return is_array($result) ? $result : null;
    }

    /**
     * ใบเสร็จจาก RPC "ทุกตัว" จนกว่าจะเจอ — ใช้ตอนที่คำว่า "ไม่มีใบเสร็จ" จะนำไปสู่การตัดสินใจเรื่องเงิน.
     *
     * receipt() หยุดที่ตัวแรกที่ตอบ แม้จะตอบ null เพราะโหนดตามหัวไม่ทัน/ลบ index เก่าไปแล้ว
     * ที่นี่ถามต่อทุกตัว และแยกนับ "ตอบว่าไม่มี" กับ "อ่านไม่ได้" ไว้ให้ผู้เรียกดู
     * ⚠️ แม้ทุกตัวตอบ null ก็ยังไม่ใช่หลักฐานว่าธุรกรรมไม่เคยลงบล็อก (BSC ลบ tx index เกิน ~2.35M บล็อก)
     *
     * @return array{receipt: ?array, nulls: int, errors: int}
     */
    public function receiptAny(string $txHash): array
    {
        $nulls = 0;
        $errors = 0;

        foreach ($this->urls as $url) {
            $answer = $this->requestUrl($url, 'eth_getTransactionReceipt', [$txHash]);

            if ($answer === null || isset($answer['error'])) {
                $errors++;

                continue;
            }

            if (is_array($answer['result'] ?? null)) {
                return ['receipt' => $answer['result'], 'nulls' => $nulls, 'errors' => $errors];
            }

            $nulls++;
        }

        return ['receipt' => null, 'nulls' => $nulls, 'errors' => $errors];
    }

    public function transaction(string $txHash): ?array
    {
        $result = $this->result('eth_getTransactionByHash', [$txHash]);

        return is_array($result) ? $result : null;
    }

    /** ธุรกรรมจาก RPC ทุกตัวจนกว่าจะเจอ (เหตุผลเดียวกับ receiptAny) — null = ไม่มีตัวไหนเจอ */
    public function transactionAny(string $txHash): ?array
    {
        foreach ($this->urls as $url) {
            $answer = $this->requestUrl($url, 'eth_getTransactionByHash', [$txHash]);

            if (is_array($answer['result'] ?? null)) {
                return $answer['result'];
            }
        }

        return null;
    }

    /** nonce ที่ลงบล็อกแล้วของที่อยู่ (นับธุรกรรมที่ลงบล็อกแล้วเท่านั้น) — null = อ่านไม่ได้ */
    public function nonceLatest(string $address): ?int
    {
        $hex = $this->result('eth_getTransactionCount', [$address, 'latest']);

        return is_string($hex) && str_starts_with($hex, '0x') ? (int) Wei::hexToInt($hex) : null;
    }

    /**
     * บล็อกที่ "เก่ากว่าหรือเท่ากับ" เวลานี้แน่นอน (ขอบล่างที่ปลอดภัยสำหรับไล่ log) — null = อ่านไม่ได้.
     *
     * เดาจากเวลาต่อบล็อกก่อน แล้วยืนยันด้วย timestamp จริงของบล็อกนั้น ถ้ายังใหม่เกินก็ถอยไกลขึ้นเป็นเท่าตัว
     * (บล็อกจริงช้ากว่าที่เดา = ได้ขอบล่างเก่ากว่าที่จำเป็น ซึ่งปลอดภัย · เร็วกว่า = ถูกจับได้ด้วย timestamp)
     */
    public function blockAtOrBefore(int $timestamp, float $blockSeconds): ?int
    {
        $latest = $this->blockNumber();
        $latestTs = $latest !== null ? $this->blockTimestamp($latest) : null;

        if ($latest === null || $latestTs === null) {
            return null;
        }

        if ($timestamp >= $latestTs) {
            return $latest;
        }

        $perBlock = max(0.05, $blockSeconds);
        $guess = $latest - (int) ceil(($latestTs - $timestamp) / $perBlock);

        for ($i = 0; $i < 16; $i++) {
            $block = max(0, $guess);
            $ts = $this->blockTimestamp($block);

            if ($ts === null) {
                return null;
            }

            if ($ts <= $timestamp || $block === 0) {
                return $block;
            }

            $guess = $block - 2 * max(1, (int) ceil(($ts - $timestamp) / $perBlock));
        }

        return null;
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
