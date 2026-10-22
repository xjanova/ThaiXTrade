<?php

namespace App\Services\MasterNode;

use App\Support\Wei;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ChainHeadService — ตรวจว่าบล็อกที่โหนดอ้างว่าเห็น อยู่บนเชนจริงและยังใหม่อยู่.
 *
 * ใช้กับ heartbeat รุ่น 2: โหนดส่ง (เลขบล็อก, แฮชบล็อก) ที่โหนดในเครื่องเห็นล่าสุด
 * เว็บเทียบกับ RPC ของเราเอง
 *
 *   ok          แฮชตรงกับบล็อกเลขนั้น และล้าหลังหัวเชนไม่เกิน max_block_lag
 *   stale       แฮชอาจตรง แต่เก่าเกินไป — โหนดตามเชนไม่ทัน/ค้าง
 *   mismatch    แฮชไม่ตรง — เชนอื่น หรือแต่งตัวเลขขึ้นมาเอง
 *   ahead       เลขบล็อกเกินหัวเชนไปไกล — เป็นไปไม่ได้บนเชนนี้ (validator อยู่ฝั่งเรา)
 *   unverified  RPC ของเราตอบไม่ได้ หรือเราเองตามหลังโหนดอยู่ 1-3 บล็อก — ไม่ใช่ความผิดโหนด
 *
 * ⚠️ แฮชบล็อกเป็นข้อมูลสาธารณะ ผลนี้จึงพิสูจน์แค่ "รู้หัวเชนล่าสุด" ไม่ใช่ "รันโหนด"
 *    หลักฐานว่ารันจริงคือ bootnode เห็น peer ID นั้นต่ออยู่ (ดู UptimeCalculator)
 *
 * IBFT ปิดบล็อกทันที (ไม่มี reorg) แฮชของเลขบล็อกหนึ่งจึง cache ได้นาน
 *
 * Developed by Xman Studio
 */
class ChainHeadService
{
    public const OK = 'ok';

    public const STALE = 'stale';

    public const MISMATCH = 'mismatch';

    public const AHEAD = 'ahead';

    public const UNVERIFIED = 'unverified';

    /** RPC ของเราตามหลังโหนดได้เท่านี้บล็อกโดยไม่ถือว่าโหนดโกหก */
    private const AHEAD_TOLERANCE = 3;

    private const USER_AGENT = 'TPIX-TRADE-Server/1.0 (+https://tpix.online)';

    /**
     * @return array{status:string, lag:int|null, head:int|null}
     */
    public function verify(int $blockNumber, string $blockHash): array
    {
        $head = $this->head();
        if ($head === null) {
            return ['status' => self::UNVERIFIED, 'lag' => null, 'head' => null];
        }

        if ($blockNumber > $head) {
            // หัวเชนใน cache อาจเก่าไปไม่กี่วินาที — ถามสดอีกรอบก่อนตัดสิน
            $head = $this->head(fresh: true) ?? $head;
        }

        if ($blockNumber > $head) {
            $status = $blockNumber - $head > self::AHEAD_TOLERANCE ? self::AHEAD : self::UNVERIFIED;

            return ['status' => $status, 'lag' => $head - $blockNumber, 'head' => $head];
        }

        $lag = $head - $blockNumber;
        $maxLag = (int) config('masternode.uptime.max_block_lag', 60);
        if ($lag > $maxLag) {
            return ['status' => self::STALE, 'lag' => $lag, 'head' => $head];
        }

        $canonical = $this->blockHash($blockNumber);
        if ($canonical === null) {
            return ['status' => self::UNVERIFIED, 'lag' => $lag, 'head' => $head];
        }

        return [
            'status' => hash_equals($canonical, strtolower($blockHash)) ? self::OK : self::MISMATCH,
            'lag' => $lag,
            'head' => $head,
        ];
    }

    /** หัวเชนล่าสุด — cache สั้น ๆ heartbeat หลายร้อยโหนดจะได้ไม่ยิง RPC ทีละตัว */
    public function head(bool $fresh = false): ?int
    {
        $key = 'masternode:chain:head';
        if (! $fresh) {
            $cached = Cache::get($key);
            if (is_int($cached)) {
                return $cached;
            }
        }

        $hex = $this->rpc('eth_blockNumber', []);
        if (! is_string($hex) || ! preg_match('/^0x[0-9a-f]+$/i', $hex)) {
            return null;
        }

        $head = (int) Wei::hexToInt($hex);
        Cache::put($key, $head, 5);

        return $head;
    }

    /** แฮชของบล็อกเลขนั้นบนเชนจริง (lowercase) — null = อ่านไม่ได้ */
    public function blockHash(int $blockNumber): ?string
    {
        $key = "masternode:chain:hash:{$blockNumber}";
        $cached = Cache::get($key);
        if (is_string($cached)) {
            return $cached;
        }

        $block = $this->rpc('eth_getBlockByNumber', ['0x'.dechex($blockNumber), false]);
        $hash = is_array($block) ? strtolower((string) ($block['hash'] ?? '')) : '';
        if (! preg_match('/^0x[0-9a-f]{64}$/', $hash)) {
            return null;
        }

        Cache::put($key, $hash, 600);

        return $hash;
    }

    /**
     * ใบเสร็จธุรกรรม — null = ยังไม่ขึ้นบล็อก หรืออ่านไม่ได้.
     *
     * @return array{status:bool, block_number:int}|null
     */
    public function transactionReceipt(string $txHash): ?array
    {
        $receipt = $this->rpc('eth_getTransactionReceipt', [$txHash]);
        if (! is_array($receipt) || ! isset($receipt['status'], $receipt['blockNumber'])) {
            return null;
        }

        return [
            'status' => Wei::hexToInt((string) $receipt['status']) === '1',
            'block_number' => (int) Wei::hexToInt((string) $receipt['blockNumber']),
        ];
    }

    private function rpcUrl(): string
    {
        return (string) config('masternode.registry.rpc_url', 'https://rpc1.tpix.online');
    }

    /** JSON-RPC ดิบ — ต้องมี User-Agent เสมอ (Cloudflare ตอบ 403 ให้คำขอที่ไม่มี UA) */
    private function rpc(string $method, array $params): mixed
    {
        try {
            $response = Http::timeout(8)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->asJson()
                ->post($this->rpcUrl(), [
                    'jsonrpc' => '2.0',
                    'method' => $method,
                    'params' => $params,
                    'id' => 1,
                ]);

            if (! $response->successful() || $response->json('error')) {
                Log::warning('ChainHead RPC error', [
                    'method' => $method,
                    'status' => $response->status(),
                    'error' => $response->json('error.message'),
                ]);

                return null;
            }

            return $response->json('result');
        } catch (\Throwable $e) {
            Log::warning('ChainHead RPC failed', ['method' => $method, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
