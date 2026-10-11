<?php

namespace Tests\Support;

use App\Models\SiteSetting;
use App\Services\ContractRegistry;
use App\Services\TokenFactory\TokenFactoryChain;
use App\Services\TokenFactory\TokenFactoryPolicy;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * ตัวช่วยของเทสต์โรงงานสร้างเหรียญ — เชน TPIX ปลอมที่ตอบ JSON-RPC ได้ครบทุกเมธอดที่เราใช้.
 *
 * ค่าที่เชนตอบปรับได้ผ่าน $this->chain (เช่นใส่ธุรกรรมค่าธรรมเนียม/receipt/บล็อก)
 */
trait FakesTokenFactory
{
    protected string $creator = '0x1111111111111111111111111111111111111111';

    protected string $feeWallet = '0x9999999999999999999999999999999999999999';

    protected string $erc20Factory = '0xAaAaaAAaaAaaAAaAAAaaaaaaaaaaAAaaAAAaaAAa';

    protected string $nftFactory = '0xBbbBBbbBbbBBbBbbbbBBbBBbbBbbBbBBbbbBBbbB';

    /** สถานะเชนปลอม */
    protected array $chain = [
        'code' => '0x60806040',
        'owner' => null,          // null = กระเป๋าของเซิร์ฟเวอร์ (deployer)
        'txs' => [],              // hash => tx
        'receipts' => [],         // hash => receipt
        'blocks' => [],           // hex block number => ['timestamp' => hex]
        'latest' => '0x64',       // 100
        'explorer' => null,       // closure(Request): response|null
        'rpc_requests' => [],
    ];

    protected function setUpTokenFactory(): void
    {
        Cache::flush();

        config([
            'blockchain.tpix_rpc_url' => 'https://rpc.tpix.test',
            'blockchain.deployer_private_key' => '0x'.str_repeat('1', 64),
            'blockchain.factory_address' => '',
            'blockchain.factory_v2_address' => '',
            'blockchain.nft_factory_address' => '',
            'blockchain.explorer_api_url' => 'https://explorer.tpix.test/api',
        ]);

        SiteSetting::set(ContractRegistry::GROUP, 'token_factory_v2', $this->erc20Factory);
        SiteSetting::set(ContractRegistry::GROUP, 'nft_factory', $this->nftFactory);
        // กระเป๋ารับค่าธรรมเนียมอยู่ในนโยบายที่เดียว (ไม่อ่าน factory.fee_wallet แล้ว)
        $this->setPolicy(['fee_wallet' => $this->feeWallet]);

        $this->fakeChain();
        $this->verifyWallet($this->creator);
    }

    protected function deployer(): string
    {
        return app(TokenFactoryChain::class)->deployerAddress();
    }

    protected function verifyWallet(string $wallet): void
    {
        Cache::put('wallet_verified:'.strtolower($wallet), [
            'ip' => '127.0.0.1',
            'verified_at' => now()->toIso8601String(),
        ], now()->addHours(4));
    }

    protected function fakeChain(): void
    {
        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), 'https://explorer.tpix.test')) {
                $handler = $this->chain['explorer'];

                return $handler ? $handler($request) : Http::response(['message' => 'not found'], 404);
            }

            $method = $request['method'] ?? null;
            $params = $request['params'] ?? [];
            $this->chain['rpc_requests'][] = $method;

            $result = match ($method) {
                'eth_getCode' => $this->chain['code'],
                'eth_call' => '0x'.str_pad(substr($this->chain['owner'] ?? $this->deployer(), 2), 64, '0', STR_PAD_LEFT),
                'eth_getTransactionByHash' => $this->chain['txs'][strtolower($params[0] ?? '')] ?? null,
                'eth_getTransactionReceipt' => $this->chain['receipts'][strtolower($params[0] ?? '')] ?? null,
                'eth_blockNumber' => $this->chain['latest'],
                'eth_getBlockByNumber' => $this->chain['blocks'][strtolower($params[0] ?? '')] ?? null,
                default => null,
            };

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
        });
    }

    /** เปิดตัวเลือกในนโยบาย (เหมือนแอดมินกด) */
    protected function setPolicy(array $overrides): void
    {
        $policy = app(TokenFactoryPolicy::class);
        $effective = $policy->effective();

        $snapshot = array_intersect_key($effective, array_flip([
            'creation_enabled', 'fee_wallet', 'required_confirmations', 'quote_ttl_minutes', 'daily_limit_per_wallet',
            'name_max_length', 'symbol_max_length', 'auto_list_on_deploy', 'require_kyc', 'reserved_symbols',
        ]));
        foreach ($effective['types'] as $key => $type) {
            $snapshot['types'][$key] = array_intersect_key($type, array_flip(['state', 'fee', 'max_supply', 'decimals_min', 'decimals_max', 'auto_approve', 'require_kyc']));
            $snapshot['types'][$key]['state'] = $type['state'] === 'on' ? 'on' : 'off';
        }
        foreach ($effective['options'] as $key => $option) {
            $snapshot['options'][$key] = ['state' => $option['state'] === 'on' ? 'on' : 'off', 'fee' => $option['fee']];
        }

        $policy->save(array_replace_recursive($snapshot, $overrides));
    }

    /** ธุรกรรมค่าธรรมเนียมบนเชนปลอม */
    protected function putFeeTx(string $hash, array $tx = [], array $receipt = [], ?int $timestamp = null, int $block = 90): void
    {
        $blockHex = '0x'.dechex($block);
        $hash = strtolower($hash);

        $this->chain['txs'][$hash] = array_merge([
            'hash' => $hash,
            'from' => $this->creator,
            'to' => $this->feeWallet,
            'value' => self::tpixHex('50'), // ค่าธรรมเนียมเหรียญมาตรฐานตั้งต้น = 50 TPIX
            'chainId' => '0x10c1',          // 4289
            'blockNumber' => $blockHex,
        ], $tx);

        if ($receipt !== ['__none__' => true]) {
            $this->chain['receipts'][$hash] = array_merge([
                'transactionHash' => $hash,
                'status' => '0x1',
                'blockNumber' => $blockHex,
            ], $receipt);
        }

        $this->chain['blocks'][$blockHex] = ['number' => $blockHex, 'timestamp' => '0x'.dechex($timestamp ?? now()->addSeconds(5)->getTimestamp())];
    }

    /** จำนวน TPIX → hex wei */
    protected static function tpixHex(string $amount): string
    {
        $wei = bcmul($amount, '1000000000000000000', 0);
        $hex = '';
        while (bccomp($wei, '0', 0) > 0) {
            $hex = dechex((int) bcmod($wei, '16')).$hex;
            $wei = bcdiv($wei, '16', 0);
        }

        return '0x'.($hex === '' ? '0' : $hex);
    }

    /** ฟอร์มสร้างเหรียญมาตรฐานที่ผ่านทุกด่าน */
    protected function tokenForm(array $overrides = []): array
    {
        return array_merge([
            'wallet_address' => $this->creator,
            'creator_address' => $this->creator,
            'name' => 'Good Coin',
            'symbol' => 'GOOD',
            'decimals' => 18,
            'total_supply' => '1000000',
            'token_type' => 'standard',
            'chain_id' => 4289,
            'sub_options' => [],
            'risk_ack' => [],
        ], $overrides);
    }
}
