<?php

namespace Tests\Support;

use App\Models\SiteSetting;
use App\Services\ContractRegistry;
use App\Services\TpixDexService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * เชน TPIX จำลองสำหรับเทสต์ DEX — ตอบ eth_call ตาม selector จริง (คำนวณด้วย keccak)
 * เพื่อให้ตัวถอด ABI ของ TpixDexService ถูกทดสอบจริง ไม่ใช่ mock ผลลัพธ์ทิ้งไปเฉย ๆ.
 *
 * พูลกำหนดเป็น [pairAddress => [token0, token1, reserve0(wei), reserve1(wei)]]
 * โทเคนกำหนดเป็น [address => [symbol, name, decimals]]
 */
trait FakesTpixDexChain
{
    protected string $wtpix = '0x1111111111111111111111111111111111111111';

    protected string $usdt = '0x2222222222222222222222222222222222222222';

    protected string $factory = '0x3333333333333333333333333333333333333333';

    protected string $router = '0x4444444444444444444444444444444444444444';

    protected string $abc = '0xabcabcabcabcabcabcabcabcabcabcabcabcabca';

    protected string $pairAbcTpix = '0x5555555555555555555555555555555555555555';

    protected string $pairTpixUsdt = '0x6666666666666666666666666666666666666666';

    /** ลงทะเบียนสัญญา DEX ครบ 4 ตัวเหมือนที่สคริปต์ deploy ทำ */
    protected function registerDex(): void
    {
        SiteSetting::set(ContractRegistry::GROUP, 'wtpix', $this->wtpix);
        SiteSetting::set(ContractRegistry::GROUP, 'usdt_tpix', $this->usdt);
        SiteSetting::set(ContractRegistry::GROUP, 'dex_factory', $this->factory);
        SiteSetting::set(ContractRegistry::GROUP, 'dex_router', $this->router);
        Cache::flush();
    }

    /** สถานะเชนจำลอง — เก็บเป็น property เพื่อให้ fakeChain() เรียกซ้ำในเทสต์เดียวแล้วค่าใหม่มีผล
     *  (Http::fake ซ้อนกันจะให้ตัวแรกที่ตอบชนะ ลงทะเบียน callback ครั้งเดียวแล้วอ่านค่าล่าสุดแทน) */
    private array $fakePools = [];

    private array $fakeTokens = [];

    private bool $fakeHasCode = true;

    private bool $fakeInstalled = false;

    /**
     * @param  array<string, array{0:string,1:string,2:string,3:string}>  $pools
     * @param  array<string, array{0:string,1:string,2:int}>  $tokens
     */
    protected function fakeChain(array $pools = [], array $tokens = [], bool $hasCode = true): void
    {
        $this->fakeTokens = array_change_key_case($tokens + [
            $this->wtpix => ['WTPIX', 'Wrapped TPIX', 18],
            $this->usdt => ['USDT', 'Tether USD (TPIX bridged)', 6],
            $this->abc => ['ABC', 'Abc Coin', 18],
        ]);
        $this->fakePools = array_change_key_case($pools);
        $this->fakeHasCode = $hasCode;

        if ($this->fakeInstalled) {
            return;
        }
        $this->fakeInstalled = true;

        $dex = app(TpixDexService::class);
        $sel = fn (string $sig) => $dex->selector($sig);

        Http::fake(function (Request $request) use ($sel) {
            $pools = $this->fakePools;
            $tokens = $this->fakeTokens;
            $pairList = array_keys($pools);
            $body = $request->data();
            $method = $body['method'] ?? '';

            if ($method === 'eth_getCode') {
                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $this->fakeHasCode ? '0x6080604052' : '0x']);
            }

            if ($method !== 'eth_call') {
                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x']);
            }

            $to = strtolower($body['params'][0]['to'] ?? '');
            $data = strtolower($body['params'][0]['data'] ?? '');
            $selector = substr($data, 0, 10);
            $args = str_split(substr($data, 10), 64);

            $result = null;

            if ($to === strtolower($this->factory)) {
                if ($selector === $sel('allPairsLength()')) {
                    $result = self::abiUint((string) count($pairList));
                } elseif ($selector === $sel('allPairs(uint256)')) {
                    $index = hexdec($args[0]);
                    $result = self::abiAddress($pairList[$index] ?? TpixDexService::ZERO);
                } elseif ($selector === $sel('getPair(address,address)')) {
                    $a = '0x'.substr($args[0], 24);
                    $b = '0x'.substr($args[1], 24);
                    $result = self::abiAddress(TpixDexService::ZERO);
                    foreach ($pools as $pair => $p) {
                        $set = [strtolower($p[0]), strtolower($p[1])];
                        if (in_array($a, $set, true) && in_array($b, $set, true)) {
                            $result = self::abiAddress($pair);
                        }
                    }
                }
            } elseif (isset($pools[$to])) {
                [$t0, $t1, $r0, $r1] = $pools[$to];
                if ($selector === $sel('token0()')) {
                    $result = self::abiAddress($t0);
                } elseif ($selector === $sel('token1()')) {
                    $result = self::abiAddress($t1);
                } elseif ($selector === $sel('getReserves()')) {
                    $result = self::abiUint($r0).substr(self::abiUint($r1), 2).substr(self::abiUint('1700000000'), 2);
                } elseif ($selector === $sel('totalSupply()')) {
                    $result = self::abiUint('1000000000000000000000');
                }
            } elseif (isset($tokens[$to])) {
                [$symbol, $name, $decimals] = $tokens[$to];
                if ($selector === $sel('symbol()')) {
                    $result = self::abiString($symbol);
                } elseif ($selector === $sel('name()')) {
                    $result = self::abiString($name);
                } elseif ($selector === $sel('decimals()')) {
                    $result = self::abiUint((string) $decimals);
                }
            }

            if ($result === null) {
                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32000, 'message' => 'execution reverted']]);
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
        });
    }

    protected static function abiUint(string $decimal): string
    {
        return '0x'.TpixDexService::encUint($decimal);
    }

    protected static function abiAddress(string $address): string
    {
        return '0x'.TpixDexService::encAddress($address);
    }

    protected static function abiString(string $text): string
    {
        $hex = bin2hex($text);
        $padded = str_pad($hex, (int) (ceil(strlen($hex) / 64) * 64), '0', STR_PAD_RIGHT);

        return '0x'.TpixDexService::encUint('32').TpixDexService::encUint((string) strlen($text)).$padded;
    }

    /** wei ของจำนวนเต็มหน่วย */
    protected static function units(string $whole, int $decimals = 18): string
    {
        return bcmul($whole, bcpow('10', (string) $decimals, 0), 0);
    }
}
