<?php

namespace Tests\Support;

use App\Models\SiteSetting;
use App\Services\Bridge\Abi;
use App\Services\Bridge\BridgeConfig;
use App\Services\ContractRegistry;
use App\Services\Launchpad\LaunchpadAgent;
use App\Support\Wei;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

/**
 * สัญญา Launchpad จำลองบนเชน 4289 (ต่อจาก FakesBridgeChains) — LaunchpadFactory + FixedPriceSale + PoolFeeder + เหรียญ.
 *
 * กฎของ buyFor เหมือนสัญญาจริง (ช่วงเวลา/หยุด/ขั้นต่ำ/เพดานขาย/เพดานต่อกระเป๋า/allowance/ยอดเงิน)
 * ทั้งตอนจำลอง (eth_call) และตอนลงบล็อก — ลงบล็อกแล้วผิดกฎ = ใบเสร็จ revert
 * ราคา IDTC: $0.0000015 → 1 หน่วย USDT (6 ทศนิยม) = 2e18 / 3 หน่วยเหรียญ (18 ทศนิยม)
 */
trait FakesLaunchpad
{
    use SignsWithTestKeys;

    protected string $agentAddr = '0x4000000000000000000000000000000000000004';

    protected string $lpFactory = '0x5100000000000000000000000000000000000051';

    protected string $lpSale = '0x5200000000000000000000000000000000000052';

    protected string $lpFeeder = '0x5300000000000000000000000000000000000053';

    protected string $lpToken = '0x5400000000000000000000000000000000000054';

    protected string $lpVesting = '0x5500000000000000000000000000000000000055';

    protected string $lpCreator = '0x5600000000000000000000000000000000000056';

    /** สถานะของสัญญา Launchpad จำลอง */
    protected array $lp = [];

    protected function setUpLaunchpad(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'launchpad-agent-');
        file_put_contents($path, json_encode(['address' => substr($this->agentAddr, 2), 'version' => 3]));
        $this->keystoreFiles[] = $path;

        config([
            'bridge.signers.launchpad_agent.keystore' => $path,
            'bridge.signers.launchpad_agent.passphrase' => 'test-only-passphrase',
            'launchpad.enabled' => true,
            'launchpad.source_chains' => [56],
            'launchpad.receipt_wait_seconds' => 0,
        ]);

        SiteSetting::set(ContractRegistry::GROUP, 'launchpad_factory', $this->lpFactory);
        Cache::flush();

        $now = now()->getTimestamp();
        $this->lp = [
            'rate_num' => '2000000000000000000',
            'rate_den' => '3',
            'cap' => '100000000000000000000000000000', // 100B IDTC
            'sold' => '0',
            'start' => $now - 86400,
            'end' => $now + 89 * 86400,
            'paused' => false,
            'min_purchase' => '1000000',
            'max_per_wallet' => '0',
            'purchased' => [],
            'allow' => [],
            'min_feed' => '1000000',
            'pool_share_bps' => 10000,
            'lp_unlock' => $now + 455 * 86400,
            'token_check' => '0',
            'name' => 'IDTC Token',
            'symbol' => 'IDTC',
            'fed' => '0',
            'launch_of' => [strtolower($this->lpSale) => 1],
        ];

        $this->callHook = fn (int $chainId, string $to, string $sel, array $args, string $from) => $this->answerLaunchpad($chainId, $to, $sel, $args, $from);
        $this->mineHook = fn (array $tx, string $sel, array $args) => $this->mineLaunchpad($tx, $sel, $args);

        // เคอร์เซอร์สแกน BSC เริ่มที่ 900 (รอบแรกจริงเริ่มที่หัว) — ฝากที่บล็อก 980 = ~40 วินาทีก่อนตอนนี้
        app(BridgeConfig::class)->setCursor('deposit_56', 900);
    }

    /** หน้าขายจริงจากเชนจำลอง — ลงทะเบียน + อนุมัติผ่านคำสั่งเดียวกับที่ใช้บนเซิร์ฟเวอร์ */
    protected function registerSale(): void
    {
        $this->artisan('launchpad:register', ['sale' => $this->lpSale, '--approve' => true])->assertSuccessful();
    }

    protected function buyerKey(int $n = 1): string
    {
        return $this->testKey($n);
    }

    protected function buyerOf(int $n = 1): string
    {
        return strtolower($this->addressOf($this->testKey($n)));
    }

    /**
     * สร้างคำสั่งผ่าน API จริง: prepare → เซ็นด้วยกุญแจทดสอบ → POST.
     *
     * @return array{response: TestResponse, prepared: array}
     */
    protected function placeOrder(string $amount = '100', int $keyN = 1, int $saleId = 1, bool $risk = true, ?string $signWith = null): array
    {
        $buyer = $this->buyerOf($keyN);
        $prepared = $this->postJson('/api/v1/launchpad/orders/prepare', [
            'sale_id' => $saleId, 'buyer' => $buyer, 'amount' => $amount, 'source_chain_id' => 56,
        ])->assertOk()->json('data');

        $response = $this->postJson('/api/v1/launchpad/orders', [
            'sale_id' => $saleId,
            'buyer' => $buyer,
            'amount' => $amount,
            'source_chain_id' => 56,
            'issued_at' => $prepared['issued_at'],
            'nonce' => $prepared['nonce'],
            'signature' => $this->personalSign($signWith ?? $this->buyerKey($keyN), $prepared['message']),
            'risk_accepted' => $risk,
        ]);

        return ['response' => $response, 'prepared' => $prepared];
    }

    protected function usdtTpixBalance(string $owner): string
    {
        return $this->chainState[4289]['balances'][$this->usdtTpix][strtolower($owner)] ?? '0';
    }

    // ── สัญญาจำลอง ──────────────────────────────────────────────────────────

    private function answerLaunchpad(int $chainId, string $to, string $sel, array $args, string $from): string|false|null
    {
        if ($chainId !== 4289) {
            return false;
        }

        $u = fn ($v) => '0x'.Abi::encUint((string) $v);
        $a = fn (string $addr) => '0x'.Abi::encAddress($addr);
        $b = fn (bool $v) => '0x'.Abi::encUint($v ? '1' : '0');
        $arg = fn (int $i) => '0x'.substr($args[$i] ?? str_repeat('0', 64), 24);
        $num = fn (int $i) => Wei::hexToInt('0x'.($args[$i] ?? '0'));
        $is = fn (string $sig) => $sel === Abi::selector($sig);

        if ($to === $this->usdtTpix && $is('allowance(address,address)')) {
            return $u($this->lp['allow'][$arg(0)][$arg(1)] ?? '0');
        }

        if ($to === $this->lpFactory) {
            if ($is('launchIdOfSale(address)')) {
                return $u($this->lp['launch_of'][$arg(0)] ?? 0);
            }
            if ($is('launchAt(uint256)')) {
                return '0x'.Abi::encAddress($this->lpToken).Abi::encAddress($this->lpSale).Abi::encAddress($this->lpFeeder)
                    .Abi::encAddress($this->lpVesting).Abi::encAddress($this->lpCreator).Abi::encUint((string) $this->lp['start']);
            }
            if ($is('tokenCheck(address)')) {
                return $u($this->lp['token_check']);
            }

            return null;
        }

        if ($to === $this->lpSale) {
            return match (true) {
                $is('paused()') => $b($this->lp['paused']),
                $is('isActive()') => $b(! $this->lp['paused'] && now()->getTimestamp() >= $this->lp['start']
                    && now()->getTimestamp() < $this->lp['end'] && bccomp($this->lp['sold'], $this->lp['cap'], 0) < 0),
                $is('remaining()') => $u(bcsub($this->lp['cap'], $this->lp['sold'], 0)),
                $is('minPurchase()') => $u($this->lp['min_purchase']),
                $is('maxPerWallet()') => $u($this->lp['max_per_wallet']),
                $is('purchased(address)') => $u($this->lp['purchased'][$arg(0)] ?? '0'),
                $is('payToken()') => $a($this->usdtTpix),
                $is('saleToken()') => $a($this->lpToken),
                $is('treasury()') => $a($this->lpFeeder),
                $is('rateNumerator()') => $u($this->lp['rate_num']),
                $is('rateDenominator()') => $u($this->lp['rate_den']),
                $is('saleCap()') => $u($this->lp['cap']),
                $is('startTime()') => $u($this->lp['start']),
                $is('endTime()') => $u($this->lp['end']),
                $is('buyFor(address,uint256)') => ($out = $this->simulateBuy($from, $arg(0), $num(1))) === null ? null : $u($out),
                default => null,
            };
        }

        if ($to === $this->lpFeeder) {
            $pending = $this->usdtTpixBalance($this->lpFeeder);

            return match (true) {
                $is('sale()') => $a($this->lpSale),
                $is('minFeed()') => $u($this->lp['min_feed']),
                $is('poolShareBps()') => $u($this->lp['pool_share_bps']),
                $is('lpUnlockTime()') => $u($this->lp['lp_unlock']),
                $is('pendingQuote()') => $u($pending),
                $is('feed()') => bccomp($pending, $this->lp['min_feed'], 0) >= 0 ? $u('0') : null,
                default => null,
            };
        }

        if ($to === $this->lpToken) {
            return match (true) {
                $is('name()') => $this->abiString($this->lp['name']),
                $is('symbol()') => $this->abiString($this->lp['symbol']),
                $is('decimals()') => $u(18),
                default => null,
            };
        }

        return false;
    }

    /** กฎของ FixedPriceSale._buy — คืน tokensOut หรือ null (= revert) */
    private function simulateBuy(string $payer, string $buyer, string $pay): ?string
    {
        $now = now()->getTimestamp();
        $out = bcdiv(bcmul($pay, $this->lp['rate_num'], 0), $this->lp['rate_den'], 0);
        $bought = bcadd($this->lp['purchased'][$buyer] ?? '0', $out, 0);

        if ($this->lp['paused'] || $now < $this->lp['start'] || $now >= $this->lp['end']
            || bccomp($pay, $this->lp['min_purchase'], 0) < 0
            || bccomp($out, '0', 0) === 0
            || bccomp(bcadd($this->lp['sold'], $out, 0), $this->lp['cap'], 0) > 0
            || ($this->lp['max_per_wallet'] !== '0' && bccomp($bought, $this->lp['max_per_wallet'], 0) > 0)
            || bccomp($this->lp['allow'][$payer][strtolower($this->lpSale)] ?? '0', $pay, 0) < 0
            || bccomp($this->usdtTpixBalance($payer), $pay, 0) < 0) {
            return null;
        }

        return $out;
    }

    private function mineLaunchpad(array $tx, string $sel, array $args): void
    {
        if ($tx['chain'] !== 4289) {
            return;
        }

        $s = &$this->chainState[4289];
        $arg0 = '0x'.substr($args[0] ?? str_repeat('0', 64), 24);

        if ($tx['to'] === $this->usdtTpix && $sel === Abi::selector('approve(address,uint256)')) {
            $this->lp['allow'][$tx['from']][$arg0] = Wei::hexToInt('0x'.$args[1]);

            return;
        }

        if ($tx['to'] === $this->lpSale && $sel === Abi::selector('buyFor(address,uint256)')) {
            $pay = Wei::hexToInt('0x'.$args[1]);
            $out = $this->simulateBuy($tx['from'], $arg0, $pay);

            if ($out === null) {
                $s['receipts'][$tx['hash']]['status'] = '0x0';

                return;
            }

            $sale = strtolower($this->lpSale);
            $s['balances'][$this->usdtTpix][$tx['from']] = bcsub($s['balances'][$this->usdtTpix][$tx['from']], $pay, 0);
            $s['balances'][$this->usdtTpix][$this->lpFeeder] = bcadd($s['balances'][$this->usdtTpix][$this->lpFeeder] ?? '0', $pay, 0);
            $s['balances'][$this->lpToken][$arg0] = bcadd($s['balances'][$this->lpToken][$arg0] ?? '0', $out, 0);
            $this->lp['allow'][$tx['from']][$sale] = bcsub($this->lp['allow'][$tx['from']][$sale], $pay, 0);
            $this->lp['sold'] = bcadd($this->lp['sold'], $out, 0);
            $this->lp['purchased'][$arg0] = bcadd($this->lp['purchased'][$arg0] ?? '0', $out, 0);

            $log = [
                'address' => $sale,
                'topics' => [Abi::topic(LaunchpadAgent::PURCHASED_EVENT), '0x'.Abi::encAddress($arg0)],
                'data' => '0x'.Abi::encUint($pay).Abi::encUint($out),
                'blockNumber' => '0x'.dechex($s['latest']),
                'transactionHash' => $tx['hash'],
                'logIndex' => '0x0',
            ];
            $s['logs'][] = $log;
            $s['receipts'][$tx['hash']]['logs'] = [$log];

            return;
        }

        if ($tx['to'] === $this->lpFeeder && $sel === Abi::selector('feed()')) {
            $pending = $s['balances'][$this->usdtTpix][$this->lpFeeder] ?? '0';

            if (bccomp($pending, $this->lp['min_feed'], 0) < 0) {
                $s['receipts'][$tx['hash']]['status'] = '0x0';

                return;
            }

            $this->lp['fed'] = bcadd($this->lp['fed'], $pending, 0);
            $s['balances'][$this->usdtTpix][$this->lpFeeder] = '0';
        }
    }

    private function abiString(string $value): string
    {
        $hex = bin2hex($value);

        return '0x'.Abi::encUint('32').Abi::encUint((string) strlen($value)).str_pad($hex, (int) (ceil(max(1, strlen($hex)) / 64) * 64), '0');
    }
}
