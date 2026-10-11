<?php

namespace Tests\Support;

use App\Models\Kline;
use App\Models\SiteSetting;
use App\Models\TradingPair;
use App\Services\ContractRegistry;
use App\Services\Stabilizer\StabilizerTxSigner;
use App\Services\TpixDexService;
use App\Support\Wei;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * เชน TPIX จำลองสำหรับเทสต์ Stability Reserve — ตอบ RPC ตาม selector จริง (keccak)
 * ไม่มีอะไรออกไปนอกเครื่อง ไม่มีคีย์จริง ตัวเซ็นถูกสลับเป็น FakeStabilizerSigner.
 *
 * สถานะทั้งหมดเก็บใน property แล้ว callback ของ Http::fake อ่านค่าล่าสุดทุกครั้ง
 * (เทสต์เปลี่ยน reserve/ยอด/receipt กลางเทสต์ได้โดยไม่ต้อง fake ซ้ำ)
 */
trait FakesStabilizerChain
{
    protected string $sWtpix = '0x1111111111111111111111111111111111111111';

    protected string $sUsdt = '0x2222222222222222222222222222222222222222';

    protected string $sFactory = '0x3333333333333333333333333333333333333333';

    protected string $sRouter = '0x4444444444444444444444444444444444444444';

    protected string $sPair = '0x6666666666666666666666666666666666666666';

    protected string $sWallet = '0x7777777777777777777777777777777777777777';

    /** reserve ของพูล (wei) — WTPIX เป็น token0 */
    protected string $chainTpixReserve = '0';

    protected string $chainUsdtReserve = '0';

    protected string $chainWalletTpix = '0';

    protected string $chainWalletUsdt = '0';

    protected string $chainAllowance = '0';

    protected int $chainNonce = 0;

    protected int $chainHeadBlock = 5000;

    protected ?int $chainHeadTime = null;

    /** @var array<string, array> tx hash → receipt */
    protected array $chainReceipts = [];

    /** @var array<string, array> tx hash → tx ที่โหนดรู้จัก (อยู่ใน mempool หรือลงบล็อกแล้ว) */
    protected array $chainKnownTxs = [];

    protected bool $chainDown = false;

    protected FakeStabilizerSigner $signer;

    /** ตั้งระบบให้พร้อมเปิด: สัญญา DEX + กระเป๋า + keystore ปลอม (มีแค่ฟิลด์ address) + ตัวเซ็นปลอม */
    protected function setUpStabilizer(): void
    {
        Cache::flush();

        SiteSetting::set(ContractRegistry::GROUP, 'wtpix', $this->sWtpix);
        SiteSetting::set(ContractRegistry::GROUP, 'usdt_tpix', $this->sUsdt);
        SiteSetting::set(ContractRegistry::GROUP, 'dex_factory', $this->sFactory);
        SiteSetting::set(ContractRegistry::GROUP, 'dex_router', $this->sRouter);

        $keystore = storage_path('framework/testing/stabilizer-keystore-'.getmypid().'.json');
        if (! is_dir(dirname($keystore))) {
            mkdir(dirname($keystore), 0777, true);
        }
        // keystore ปลอมที่มีแค่ address — ไม่มีคีย์อะไรอยู่ข้างใน (ตัวเซ็นปลอมไม่อ่านไฟล์นี้)
        file_put_contents($keystore, json_encode(['address' => substr($this->sWallet, 2), 'version' => 3]));

        config([
            'blockchain.tpix_rpc_url' => 'https://rpc.tpix.test',
            'blockchain.tpix_dex_index_from_block' => 100,
            'blockchain.dex.wtpix' => '',
            'blockchain.dex.usdt' => '',
            'blockchain.dex.factory' => '',
            'blockchain.dex.router' => '',
            'stabilizer.enabled' => true,
            'stabilizer.wallet.address' => $this->sWallet,
            'stabilizer.wallet.keystore_path' => $keystore,
            'stabilizer.wallet.passphrase' => 'test-only',
            'stabilizer.report.ai_enabled' => false,
        ]);

        $this->signer = new FakeStabilizerSigner();
        $this->app->instance(StabilizerTxSigner::class, $this->signer);

        $this->chainHeadTime = null;
        $this->installFakeChain();
    }

    protected function tearDownStabilizerKeystore(): void
    {
        $keystore = storage_path('framework/testing/stabilizer-keystore-'.getmypid().'.json');
        if (is_file($keystore)) {
            unlink($keystore);
        }
    }

    /** ตั้งพูลเป็นจำนวนเต็มหน่วย (TPIX, USDT) */
    protected function setPool(string $tpix, string $usdt): void
    {
        $this->chainTpixReserve = self::wei($tpix, 18);
        $this->chainUsdtReserve = self::wei($usdt, 6);
        Cache::flush();
    }

    protected function setWallet(string $tpix, string $usdt, string $allowanceUsdt = '1000000'): void
    {
        $this->chainWalletTpix = self::wei($tpix, 18);
        $this->chainWalletUsdt = self::wei($usdt, 6);
        $this->chainAllowance = self::wei($allowanceUsdt, 6);
    }

    /**
     * สร้างคู่ TPIX-USDT ในฐานข้อมูลผ่าน dex:sync จริง แล้วเติมแท่ง 1 นาทีย้อนหลัง
     * (ราคาอ้างอิงคำนวณจากแท่งเหล่านี้).
     */
    protected function seedHistory(string $price, int $minutes = 1440, int $endMinutesAgo = 0): TradingPair
    {
        $this->artisan('dex:sync')->assertSuccessful();
        $pair = TradingPair::whereRaw('LOWER(dex_pair_address) = ?', [strtolower($this->sPair)])->firstOrFail();

        // dex:sync เพิ่งบันทึกแท่งของนาทีนี้จากพูลปัจจุบัน — ลบทิ้งให้ประวัติเป็นไปตามที่เทสต์กำหนด
        Kline::where('trading_pair_id', $pair->id)->delete();

        $now = now()->startOfMinute();
        $rows = [];
        for ($i = $endMinutesAgo; $i < $endMinutesAgo + $minutes; $i++) {
            $rows[] = [
                'trading_pair_id' => $pair->id,
                'interval' => '1m',
                'open_time' => $now->copy()->subMinutes($i)->toDateTimeString(),
                'open' => $price, 'high' => $price, 'low' => $price, 'close' => $price,
                'volume' => '0', 'quote_volume' => '0', 'trade_count' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ];
            if (count($rows) === 500) {
                DB::table('klines')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('klines')->insert($rows);
        }

        return $pair;
    }

    protected function setParam(string $name, int|float $value): void
    {
        SiteSetting::set('stabilizer', 'stabilizer_'.$name, (string) $value, 'number');
    }

    protected function switchOn(): void
    {
        SiteSetting::set('stabilizer', 'stabilizer_enabled', '1', 'boolean');
    }

    protected function receiptFor(string $hash, bool $success = true, int $block = 4990): void
    {
        $this->chainReceipts[strtolower($hash)] = [
            'transactionHash' => strtolower($hash),
            'status' => $success ? '0x1' : '0x0',
            'blockNumber' => '0x'.dechex($block),
            'logs' => [],
        ];
        $this->chainKnownTxs[strtolower($hash)] = ['hash' => strtolower($hash), 'blockNumber' => '0x'.dechex($block)];
    }

    private function installFakeChain(): void
    {
        $dex = app(TpixDexService::class);
        $sel = fn (string $sig) => $dex->selector($sig);

        Http::fake(function (Request $request) use ($sel) {
            if ($this->chainDown) {
                return Http::response('upstream down', 502);
            }

            $body = $request->data();
            $method = $body['method'] ?? '';
            $params = $body['params'] ?? [];
            $ok = fn ($result) => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);

            switch ($method) {
                case 'eth_getCode':
                    return $ok('0x6080604052');
                case 'eth_blockNumber':
                    return $ok('0x'.dechex($this->chainHeadBlock));
                case 'eth_getBlockByNumber':
                    $time = $this->chainHeadTime ?? now()->getTimestamp();

                    return $ok(['number' => '0x'.dechex($this->chainHeadBlock), 'timestamp' => '0x'.dechex($time)]);
                case 'eth_getBalance':
                    return $ok(self::hexOf($this->chainWalletTpix));
                case 'eth_getTransactionCount':
                    return $ok('0x'.dechex($this->chainNonce));
                case 'eth_getTransactionReceipt':
                    return $ok($this->chainReceipts[strtolower($params[0] ?? '')] ?? null);
                case 'eth_getTransactionByHash':
                    return $ok($this->chainKnownTxs[strtolower($params[0] ?? '')] ?? null);
                case 'eth_getLogs':
                    return $ok([]);
            }

            if ($method !== 'eth_call') {
                return $ok('0x');
            }

            $to = strtolower($params[0]['to'] ?? '');
            $data = strtolower($params[0]['data'] ?? '');
            $selector = substr($data, 0, 10);
            $result = null;

            if ($to === $this->sFactory) {
                $result = match ($selector) {
                    $sel('allPairsLength()') => self::word('1'),
                    $sel('allPairs(uint256)') => '0x'.TpixDexService::encAddress($this->sPair),
                    $sel('getPair(address,address)') => '0x'.TpixDexService::encAddress($this->sPair),
                    default => null,
                };
            } elseif ($to === $this->sPair) {
                $result = match ($selector) {
                    $sel('token0()') => '0x'.TpixDexService::encAddress($this->sWtpix),
                    $sel('token1()') => '0x'.TpixDexService::encAddress($this->sUsdt),
                    $sel('getReserves()') => self::word($this->chainTpixReserve).TpixDexService::encUint($this->chainUsdtReserve).TpixDexService::encUint('1700000000'),
                    $sel('totalSupply()') => self::word('1000000000000000000000'),
                    default => null,
                };
            } elseif ($to === $this->sWtpix || $to === $this->sUsdt) {
                $isUsdt = $to === $this->sUsdt;
                $result = match ($selector) {
                    $sel('symbol()') => self::abiText($isUsdt ? 'USDT' : 'WTPIX'),
                    $sel('name()') => self::abiText($isUsdt ? 'Tether USD (TPIX bridged)' : 'Wrapped TPIX'),
                    $sel('decimals()') => self::word($isUsdt ? '6' : '18'),
                    $sel('balanceOf(address)') => self::word($isUsdt ? $this->chainWalletUsdt : '0'),
                    $sel('allowance(address,address)') => self::word($isUsdt ? $this->chainAllowance : '0'),
                    default => null,
                };
            }

            if ($result === null) {
                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32000, 'message' => 'execution reverted']]);
            }

            return $ok($result);
        });
    }

    protected static function word(string $decimal): string
    {
        return '0x'.TpixDexService::encUint($decimal);
    }

    protected static function hexOf(string $decimal): string
    {
        $hex = ltrim(TpixDexService::encUint($decimal), '0');

        return '0x'.($hex === '' ? '0' : $hex);
    }

    protected static function abiText(string $text): string
    {
        $hex = bin2hex($text);
        $padded = str_pad($hex, (int) (ceil(strlen($hex) / 64) * 64), '0', STR_PAD_RIGHT);

        return '0x'.TpixDexService::encUint('32').TpixDexService::encUint((string) strlen($text)).$padded;
    }

    protected static function wei(string $amount, int $decimals): string
    {
        return Wei::toWei($amount, $decimals);
    }
}
