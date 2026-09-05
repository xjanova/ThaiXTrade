<?php

namespace App\Services;

use App\Support\Wei;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use kornrunner\Keccak;

/**
 * TpixDexService — อ่านสถานะ DEX (AMM แบบ Uniswap V2) บนเชน TPIX 4289 จากเชนตรง ๆ.
 *
 * ทำหน้าที่แค่ "อ่าน" — ไม่มีคีย์ ไม่เซ็น ไม่ส่งธุรกรรม (ฝั่งเซิร์ฟเวอร์ไม่ถือกระเป๋าใคร)
 * ผู้ใช้สวอป/เติมสภาพคล่องเองผ่านกระเป๋าในเบราว์เซอร์ ส่วนตัวนี้มีไว้ให้
 *   - หน้าเว็บ/แอปรู้ว่ามีพูลอะไรบ้าง ราคาเท่าไร ลึกแค่ไหน (ticker / กราฟ / ความลึก)
 *   - dex:sync สร้างคู่เทรดในฐานข้อมูลตามพูลที่มีอยู่จริง (ทุกเหรียญบนเชนเทรดได้)
 *   - AI TRADE รู้ว่ากลยุทธ์ที่พึ่ง DEX เปิดได้หรือยัง
 *
 * ที่อยู่สัญญามาจาก ContractRegistry ที่เดียว (สคริปต์ deploy ลงทะเบียนให้เอง)
 * ห้าม hardcode ที่อยู่หรือ selector ที่นี่ — selector คำนวณจากลายเซ็นฟังก์ชันด้วย keccak
 * เพราะเคยมีคน hardcode selector ผิดทั้งไฟล์มาแล้ว (ดู NodeRegistryContract)
 *
 * ⚠️ ทุก request ต้องมี User-Agent — rpc.tpix.online อยู่หลัง Cloudflare bot rule
 *    ที่ตอบ 403 เป็น HTML ให้ client ที่ไม่มี UA (ยืนยันสด 2026-08-27)
 *
 * Developed by Xman Studio
 */
class TpixDexService
{
    public const ZERO = '0x0000000000000000000000000000000000000000';

    private const USER_AGENT = 'TPIX-TRADE-Server/1.0 (+https://tpix.online)';

    /** selector ที่คำนวณแล้ว — คำนวณครั้งเดียวต่อ process */
    private static array $selectors = [];

    public function __construct(private ContractRegistry $registry) {}

    // =========================================================================
    // สถานะรวม
    // =========================================================================

    /**
     * ที่อยู่ชุด DEX + ธง ready (ดู ContractRegistry::dexConfig).
     *
     * @return array{ready:bool, chainId:int, rpc:string, WTPIX:?string, USDT:?string, FACTORY:?string, ROUTER:?string, missing:string[]}
     */
    public function config(): array
    {
        return $this->registry->dexConfig();
    }

    public function ready(): bool
    {
        return $this->registry->dexReady();
    }

    /**
     * แปลงที่อยู่ที่ฐานข้อมูลใช้ (native TPIX = 0x0) เป็นที่อยู่ที่มีอยู่จริงบนเชน (WTPIX).
     */
    public function onchainAddress(string $address): ?string
    {
        $address = strtolower(trim($address));

        if ($address === '' || $address === self::ZERO || $address === '0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee') {
            $wtpix = $this->config()['WTPIX'];

            return $wtpix ? strtolower($wtpix) : null;
        }

        return preg_match('/^0x[a-f0-9]{40}$/', $address) ? $address : null;
    }

    /** ที่อยู่นี้คือ WTPIX (ตัวแทน native TPIX ในพูล) ไหม */
    public function isWtpix(string $address): bool
    {
        $wtpix = $this->config()['WTPIX'];

        return $wtpix !== null && strtolower($wtpix) === strtolower($address);
    }

    /** ที่อยู่นี้คือ USDT_TPIX ไหม */
    public function isUsdt(string $address): bool
    {
        $usdt = $this->config()['USDT'];

        return $usdt !== null && strtolower($usdt) === strtolower($address);
    }

    // =========================================================================
    // Factory
    // =========================================================================

    /**
     * รายการที่อยู่พูลทั้งหมดจาก factory (ลำดับตามที่ถูกสร้าง).
     *
     * @return string[]
     */
    public function allPairs(): array
    {
        $factory = $this->config()['FACTORY'];
        if (! $factory) {
            return [];
        }

        return Cache::remember('dex:allpairs:'.strtolower($factory), 30, function () use ($factory) {
            $raw = $this->call($factory, $this->selector('allPairsLength()'));
            $length = (int) Wei::hexToInt($raw);

            // กันลูปยาวผิดปกติ — ถ้ามีพูลเกินนี้จริงค่อยขยาย (ตอนนี้เชนมีศูนย์)
            $length = min($length, 2000);

            $pairs = [];
            for ($i = 0; $i < $length; $i++) {
                $data = $this->selector('allPairs(uint256)').self::encUint((string) $i);
                $address = self::decAddress($this->call($factory, $data));
                if ($address !== null && $address !== self::ZERO) {
                    $pairs[] = $address;
                }
            }

            return $pairs;
        });
    }

    /**
     * ที่อยู่พูลของคู่ (ไม่สนลำดับ) — null ถ้ายังไม่มีใครสร้าง.
     */
    public function getPair(string $tokenA, string $tokenB): ?string
    {
        $factory = $this->config()['FACTORY'];
        $a = $this->onchainAddress($tokenA);
        $b = $this->onchainAddress($tokenB);

        if (! $factory || ! $a || ! $b || $a === $b) {
            return null;
        }

        $data = $this->selector('getPair(address,address)').self::encAddress($a).self::encAddress($b);
        $pair = self::decAddress($this->call($factory, $data));

        return ($pair === null || $pair === self::ZERO) ? null : $pair;
    }

    // =========================================================================
    // Pair
    // =========================================================================

    /**
     * ข้อมูลพูล: token0/token1 + reserve (หน่วย wei เป็นสตริงทศนิยม) + totalSupply ของ LP.
     *
     * @return array{pair:string, token0:string, token1:string, reserve0:string, reserve1:string, totalSupply:string}|null
     */
    public function pairInfo(string $pair): ?array
    {
        $pair = strtolower($pair);
        if (! preg_match('/^0x[a-f0-9]{40}$/', $pair)) {
            return null;
        }

        // token0/token1 ไม่เปลี่ยนตลอดอายุพูล — จำได้นาน; reserve เปลี่ยนทุกสวอป อ่านสด
        $tokens = Cache::remember("dex:pair:tokens:{$pair}", 3600, function () use ($pair) {
            $t0 = self::decAddress($this->call($pair, $this->selector('token0()')));
            $t1 = self::decAddress($this->call($pair, $this->selector('token1()')));

            return ($t0 && $t1) ? ['token0' => $t0, 'token1' => $t1] : null;
        });

        if ($tokens === null) {
            return null;
        }

        $reserves = $this->call($pair, $this->selector('getReserves()'));
        if ($reserves === null) {
            return null;
        }

        $words = self::words($reserves);
        if (count($words) < 2) {
            return null;
        }

        $supply = $this->call($pair, $this->selector('totalSupply()'));

        return [
            'pair' => $pair,
            'token0' => $tokens['token0'],
            'token1' => $tokens['token1'],
            'reserve0' => Wei::hexToInt('0x'.$words[0]),
            'reserve1' => Wei::hexToInt('0x'.$words[1]),
            'totalSupply' => Wei::hexToInt($supply ?? '0x0'),
        ];
    }

    /**
     * พูลของคู่ (base, quote) เรียง reserve ตามฝั่งที่ขอ — null ถ้าไม่มีพูล.
     *
     * @return array{pair:string, reserveBase:string, reserveQuote:string, totalSupply:string}|null
     */
    public function poolFor(string $base, string $quote): ?array
    {
        $pair = $this->getPair($base, $quote);
        if ($pair === null) {
            return null;
        }

        $info = $this->pairInfo($pair);
        if ($info === null) {
            return null;
        }

        $baseOnchain = $this->onchainAddress($base);
        $baseIsToken0 = $baseOnchain !== null && strtolower($info['token0']) === $baseOnchain;

        return [
            'pair' => $pair,
            'reserveBase' => $baseIsToken0 ? $info['reserve0'] : $info['reserve1'],
            'reserveQuote' => $baseIsToken0 ? $info['reserve1'] : $info['reserve0'],
            'totalSupply' => $info['totalSupply'],
        ];
    }

    /**
     * ราคากลางของพูล = reserveQuote / reserveBase (ปรับ decimals แล้ว) เป็นสตริงทศนิยม.
     * คืน null เมื่อพูลว่าง — ไม่แต่งราคาขึ้นมาเอง
     */
    public static function midPrice(string $reserveBase, string $reserveQuote, int $baseDecimals, int $quoteDecimals): ?string
    {
        if (bccomp($reserveBase, '0', 0) <= 0 || bccomp($reserveQuote, '0', 0) <= 0) {
            return null;
        }

        // price = (rq / 10^qd) / (rb / 10^bd) = rq * 10^bd / (rb * 10^qd)
        $numerator = bcmul($reserveQuote, bcpow('10', (string) $baseDecimals, 0), 0);
        $denominator = bcmul($reserveBase, bcpow('10', (string) $quoteDecimals, 0), 0);

        return bcdiv($numerator, $denominator, 18);
    }

    /**
     * ปริมาณที่ได้เมื่อสวอป amountIn ผ่านพูลเดียว (ค่าธรรมเนียม 0.3% ตาม UniV2).
     * ใช้สังเคราะห์ "ความลึก" ให้การ์ดสมุดคำสั่ง — พูล AMM ไม่มีออร์เดอร์จริงให้โชว์
     */
    public static function amountOut(string $amountIn, string $reserveIn, string $reserveOut): string
    {
        if (bccomp($amountIn, '0', 0) <= 0 || bccomp($reserveIn, '0', 0) <= 0 || bccomp($reserveOut, '0', 0) <= 0) {
            return '0';
        }

        $inWithFee = bcmul($amountIn, '997', 0);
        $numerator = bcmul($inWithFee, $reserveOut, 0);
        $denominator = bcadd(bcmul($reserveIn, '1000', 0), $inWithFee, 0);

        return bcdiv($numerator, $denominator, 0);
    }

    // =========================================================================
    // ERC-20 metadata
    // =========================================================================

    /**
     * symbol / name / decimals ของโทเคน — จำไว้ 1 ชั่วโมง (ค่าเหล่านี้ไม่เปลี่ยน).
     *
     * @return array{symbol:string, name:string, decimals:int}|null
     */
    public function tokenMeta(string $token): ?array
    {
        $token = strtolower($token);
        if (! preg_match('/^0x[a-f0-9]{40}$/', $token)) {
            return null;
        }

        return Cache::remember("dex:token:meta:{$token}", 3600, function () use ($token) {
            $decimalsRaw = $this->call($token, $this->selector('decimals()'));
            if ($decimalsRaw === null) {
                return null;
            }

            $symbol = self::decString($this->call($token, $this->selector('symbol()')));
            $name = self::decString($this->call($token, $this->selector('name()')));
            $decimals = (int) Wei::hexToInt($decimalsRaw);

            if ($symbol === '' || $decimals < 0 || $decimals > 36) {
                return null;
            }

            return [
                // ตัดให้เหลือแค่ตัวอักษรที่หน้าเว็บใช้เป็นชื่อคู่ได้ ("ABC-TPIX") กันสัญลักษณ์แปลก ๆ
                'symbol' => mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $symbol) ?: '', 0, 12),
                'name' => mb_substr(trim($name) !== '' ? trim($name) : $symbol, 0, 100),
                'decimals' => $decimals,
            ];
        });
    }

    /** ยอดคงเหลือ ERC-20 (wei สตริงทศนิยม) */
    public function balanceOf(string $token, string $owner): ?string
    {
        $token = $this->onchainAddress($token);
        $owner = strtolower($owner);
        if (! $token || ! preg_match('/^0x[a-f0-9]{40}$/', $owner)) {
            return null;
        }

        $raw = $this->call($token, $this->selector('balanceOf(address)').self::encAddress($owner));

        return $raw === null ? null : Wei::hexToInt($raw);
    }

    // =========================================================================
    // ราคาอ้างอิงของ TPIX
    // =========================================================================

    /**
     * ราคา TPIX เป็น USDT จากพูล WTPIX/USDT — null ถ้า DEX ยังไม่พร้อมหรือพูลว่าง.
     */
    public function tpixUsdPrice(): ?float
    {
        $cfg = $this->config();
        if (! $cfg['ready']) {
            return null;
        }

        return Cache::remember('dex:tpix-usd', 10, function () use ($cfg) {
            $pool = $this->poolFor($cfg['WTPIX'], $cfg['USDT']);
            if ($pool === null) {
                return null;
            }

            $usdtMeta = $this->tokenMeta($cfg['USDT']);
            $price = self::midPrice($pool['reserveBase'], $pool['reserveQuote'], 18, $usdtMeta['decimals'] ?? 6);

            return $price === null ? null : (float) $price;
        });
    }

    // =========================================================================
    // ABI helpers (แค่ที่ต้องใช้ — ไม่ลากไลบรารี ABI เต็มตัวเข้ามา)
    // =========================================================================

    public function selector(string $signature): string
    {
        if (! isset(self::$selectors[$signature])) {
            self::$selectors[$signature] = '0x'.substr(Keccak::hash($signature, 256), 0, 8);
        }

        return self::$selectors[$signature];
    }

    public static function encAddress(string $address): string
    {
        return str_pad(strtolower(substr($address, 2)), 64, '0', STR_PAD_LEFT);
    }

    public static function encUint(string $decimal): string
    {
        $hex = '';
        $value = $decimal;
        if (bccomp($value, '0', 0) === 0) {
            $hex = '0';
        }
        while (bccomp($value, '0', 0) > 0) {
            $hex = dechex((int) bcmod($value, '16')).$hex;
            $value = bcdiv($value, '16', 0);
        }

        return str_pad($hex, 64, '0', STR_PAD_LEFT);
    }

    public static function decAddress(?string $raw): ?string
    {
        $words = self::words($raw);
        if ($words === []) {
            return null;
        }

        return '0x'.substr($words[0], 24);
    }

    /**
     * ถอด string แบบ ABI (offset + length + data) และรองรับ bytes32 ที่โทเคนเก่าบางตัวใช้.
     */
    public static function decString(?string $raw): string
    {
        $words = self::words($raw);
        if ($words === []) {
            return '';
        }

        // bytes32 — คำเดียว จบ
        if (count($words) === 1) {
            return trim(self::hexToBin($words[0]), "\0");
        }

        $offset = (int) Wei::hexToInt('0x'.$words[0]) / 32;
        $length = (int) Wei::hexToInt('0x'.($words[$offset] ?? '0'));
        $hex = implode('', array_slice($words, $offset + 1));
        $text = substr(self::hexToBin($hex), 0, $length);

        return mb_check_encoding($text, 'UTF-8') ? $text : '';
    }

    /**
     * แยก payload hex เป็นคำละ 32 ไบต์.
     *
     * @return string[]
     */
    public static function words(?string $raw): array
    {
        if (! is_string($raw) || strlen($raw) < 66) {
            return [];
        }

        return str_split(substr($raw, 2), 64);
    }

    private static function hexToBin(string $hex): string
    {
        $hex = strlen($hex) % 2 === 1 ? '0'.$hex : $hex;
        $bin = @hex2bin($hex);

        return $bin === false ? '' : $bin;
    }

    // =========================================================================
    // RPC
    // =========================================================================

    /**
     * eth_call แบบอ่านอย่างเดียว — คืน hex ผลลัพธ์ หรือ null ถ้าเชนตอบไม่ได้/revert.
     */
    public function call(string $to, string $data): ?string
    {
        $result = $this->rpc('eth_call', [['to' => $to, 'data' => $data], 'latest']);

        return is_string($result) && str_starts_with($result, '0x') ? $result : null;
    }

    private function rpc(string $method, array $params): mixed
    {
        try {
            $response = Http::timeout(8)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->asJson()
                ->post(config('blockchain.tpix_rpc_url', 'https://rpc.tpix.online'), [
                    'jsonrpc' => '2.0',
                    'method' => $method,
                    'params' => $params,
                    'id' => 1,
                ]);

            if (! $response->successful() || $response->json('error')) {
                return null;
            }

            return $response->json('result');
        } catch (\Throwable $e) {
            Log::warning('TpixDexService RPC failed', ['method' => $method, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
