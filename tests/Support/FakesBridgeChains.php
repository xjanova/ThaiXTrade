<?php

namespace Tests\Support;

use App\Models\SiteSetting;
use App\Services\Bridge\Abi;
use App\Services\Bridge\BridgeConfig;
use App\Services\ContractRegistry;
use App\Support\Wei;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * เชนจำลองสำหรับเทสต์สะพาน USDT — BSC (18 ทศนิยม) + Ethereum (6 ทศนิยม) + TPIX Chain.
 *
 * ตอบ JSON-RPC ตามเมธอดจริง (eth_getLogs กรองตาม address/topic/ช่วงบล็อก · eth_call ตาม selector
 * ที่คำนวณด้วย keccak) เพื่อให้โค้ดถอด ABI/ตัดสินใจถูกทดสอบจริง ไม่ใช่ mock ผลลัพธ์ทิ้งไป
 *
 * สคริปต์เซ็น (node) ถูกแทนด้วย Process::fake — "เซ็น" ได้ hash ที่คำนวณจากเนื้อธุรกรรม
 * ส่วน "ส่ง" จะ "ลงบล็อก" ให้ทันที (ปิดได้ด้วย $mineOnSend = false) พร้อมผลบนสัญญาจริง:
 * mint → processedTxHashes = true, supply เพิ่ม · transfer → ยอดกระเป๋าจ่ายลด
 */
trait FakesBridgeChains
{
    protected string $vault = '0xf46131c82819d7621163f482b3fe88a228a7807c';

    protected string $usdtBsc = '0x55d398326f99059ff775485246999027b3197955';

    protected string $usdtEth = '0xdac17f958d2ee523a2206206994597c13d831ec7';

    protected string $usdtTpix = '0x0a4ee87551ef041f3eecfbbfdcffc1b46a4878f1';

    protected string $minter = '0x7777777777777777777777777777777777777777';

    protected string $relayerAddr = '0x1000000000000000000000000000000000000001';

    protected string $payoutAddr = '0x2000000000000000000000000000000000000002';

    protected string $guardianAddr = '0x3000000000000000000000000000000000000003';

    protected string $alice = '0xa11ce00000000000000000000000000000000a11';

    protected string $bob = '0xb0b0000000000000000000000000000000000b0b';

    /** @var array<int, array> สถานะของแต่ละเชน */
    protected array $chainState = [];

    /** @var array<int, array> คำสั่ง sign ที่สคริปต์ได้รับ (ไม่เก็บ passphrase) */
    protected array $signCalls = [];

    /** @var array<int, string> raw ที่ถูกส่ง */
    protected array $sendCalls = [];

    protected ?string $signFailCode = null;

    protected ?string $sendFailCode = null;

    protected bool $mineOnSend = true;

    /** เรียกตอนสคริปต์ "เซ็น" — ใช้จำลองเหตุการณ์ที่เกิดระหว่างเซ็น (เช่นแอดมินกดปฏิเสธพอดี) */
    protected ?\Closure $onSign = null;

    /** ใบเสร็จของธุรกรรมที่ลงบล็อกแล้ว revert (hash => true) */
    protected array $revertOnMine = [];

    private array $rawIndex = [];

    private array $fakeNonces = [];

    private array $keystoreFiles = [];

    protected function setUpBridge(bool $siteSwitch = true): void
    {
        config([
            'bridge.enabled' => true,
            'bridge.receipt_wait_seconds' => 0,
            'bridge.ignore_senders' => [],
            'bridge.tpix.rpc' => ['https://rpc.tpix.test'],
            'bridge.tpix.start_block' => null,
            'bridge.chains' => [
                56 => array_merge(config('bridge.chains.56'), [
                    'rpc' => ['https://rpc.bsc.test'],
                    'start_block' => null,
                    'enabled' => true,
                ]),
                1 => array_merge(config('bridge.chains.1'), [
                    'rpc' => ['https://rpc.eth.test'],
                    'start_block' => null,
                    'enabled' => true,
                ]),
            ],
        ]);

        foreach (['relayer' => $this->relayerAddr, 'payout' => $this->payoutAddr, 'guardian' => $this->guardianAddr] as $role => $address) {
            $path = tempnam(sys_get_temp_dir(), "bridge-{$role}-");
            // ไฟล์ keystore จำลอง — มีแค่ฟิลด์ address (เหมือน V3 จริงที่เก็บ address ไม่มี 0x) ไม่มีคีย์ใด ๆ
            file_put_contents($path, json_encode(['address' => substr($address, 2), 'version' => 3]));
            $this->keystoreFiles[] = $path;
            config(["bridge.signers.{$role}.keystore" => $path, "bridge.signers.{$role}.passphrase" => 'test-only-passphrase']);
        }

        SiteSetting::set(ContractRegistry::GROUP, 'usdt_tpix', $this->usdtTpix);
        SiteSetting::set(ContractRegistry::GROUP, 'bridge_minter', $this->minter);
        SiteSetting::set(BridgeConfig::SETTINGS_GROUP, 'enabled', $siteSwitch ? '1' : '0', 'boolean');
        Cache::flush();

        $this->chainState = [
            56 => $this->freshChain(1000, 990),
            1 => $this->freshChain(5000, 4930),
            4289 => $this->freshChain(2000, null) + [
                'supply' => '0',
                'processed' => [],
                'minter_paused' => false,
                'token_paused' => false,
                'minter_is_bridge' => true,
                'relayers' => [strtolower($this->relayerAddr) => true],
                'max_per_tx' => '1000000000',
                'daily_cap' => '5000000000',
                'remaining' => '5000000000',
                'minter_total' => '0',
            ],
        ];

        // กระเป๋าจ่ายมีเงินพอเป็นค่าปริยาย (เทสต์ที่ต้องการ "ไม่พอ" ค่อยตั้งทับ)
        $this->setTokenBalance(56, $this->usdtBsc, $this->payoutAddr, '10000000000000000000000');
        $this->setTokenBalance(1, $this->usdtEth, $this->payoutAddr, '10000000000');

        Http::fake(fn (Request $request) => Http::response($this->answerRpc($request)));
        Process::fake(fn (PendingProcess $process) => $this->answerProcess($process));
    }

    protected function tearDown(): void
    {
        foreach ($this->keystoreFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function freshChain(int $latest, ?int $finalized): array
    {
        return [
            'latest' => $latest,
            'finalized' => $finalized,
            'logs' => [],
            'txs' => [],
            'receipts' => [],
            'codes' => [],
            'balances' => [],
            'native' => [],
            'nonce' => [],
            'gas_price' => '0x3b9aca00',
            'fail_logs' => false,
            'down' => false,
        ];
    }

    // ── ตัวช่วยตั้งสถานะ ──────────────────────────────────────────────────

    protected function setTokenBalance(int $chainId, string $token, string $owner, string $raw): void
    {
        $this->chainState[$chainId]['balances'][strtolower($token)][strtolower($owner)] = $raw;
    }

    protected function setLatest(int $chainId, int $latest, ?int $finalized = null): void
    {
        $this->chainState[$chainId]['latest'] = $latest;
        if ($finalized !== null) {
            $this->chainState[$chainId]['finalized'] = $finalized;
        }
    }

    /** Transfer(from → vault) ของ USDT บนเชนต้นทาง — คืน tx hash */
    protected function fakeDeposit(int $chainId, string $from, string $raw, int $block, ?string $txFrom = null, int $logIndex = 0, ?string $token = null, ?string $txHash = null): string
    {
        $txHash ??= '0x'.hash('sha256', "dep:{$chainId}:{$from}:{$raw}:{$block}:{$logIndex}:".count($this->chainState[$chainId]['logs']));

        return $this->fakeTransfer($chainId, $from, $this->vault, $raw, $block, $txFrom, $logIndex, $token, $txHash);
    }

    /** Transfer(from → to) ของเหรียญ ERC-20 บนเชนต้นทาง (ปริยาย = USDT ของเชนนั้น) — คืน tx hash */
    protected function fakeTransfer(int $chainId, string $from, string $to, string $raw, int $block, ?string $txFrom = null, int $logIndex = 0, ?string $token = null, ?string $txHash = null): string
    {
        $token ??= $chainId === 56 ? $this->usdtBsc : $this->usdtEth;
        $txHash ??= '0x'.hash('sha256', "xfer:{$chainId}:{$from}:{$to}:{$raw}:{$block}:{$logIndex}:".count($this->chainState[$chainId]['logs']));

        $log = [
            'address' => strtolower($token),
            'topics' => [
                Abi::topic(Abi::TRANSFER_EVENT),
                '0x'.Abi::encAddress($from),
                '0x'.Abi::encAddress($to),
            ],
            'data' => '0x'.Abi::encUint($raw),
            'blockNumber' => '0x'.dechex($block),
            'blockHash' => '0x'.hash('sha256', "block:{$block}"),
            'transactionHash' => $txHash,
            'logIndex' => '0x'.dechex($logIndex),
        ];

        $this->chainState[$chainId]['logs'][] = $log;
        $this->chainState[$chainId]['txs'][$txHash] = ['hash' => $txHash, 'from' => strtolower($txFrom ?? $from), 'to' => strtolower($token)];
        $this->chainState[$chainId]['receipts'][$txHash] = [
            'transactionHash' => $txHash, 'status' => '0x1', 'blockNumber' => '0x'.dechex($block), 'logs' => [$log],
        ];

        return $txHash;
    }

    /** Burned(from, amount, targetChainId, targetAddress) บนเชน TPIX — คืน tx hash */
    protected function fakeBurn(string $from, string $units, string $targetChainId, string $target, int $block, int $logIndex = 0): string
    {
        $txHash = '0x'.hash('sha256', "burn:{$from}:{$units}:{$targetChainId}:{$target}:{$block}:".count($this->chainState[4289]['logs']));
        $hex = bin2hex($target);
        $padded = str_pad($hex, (int) (ceil(max(1, strlen($hex)) / 64) * 64), '0', STR_PAD_RIGHT);

        $log = [
            'address' => $this->usdtTpix,
            'topics' => [Abi::topic(Abi::BURNED_EVENT), '0x'.Abi::encAddress($from), '0x'.Abi::encUint($targetChainId)],
            'data' => '0x'.Abi::encUint($units).Abi::encUint('64').Abi::encUint((string) strlen($target)).$padded,
            'blockNumber' => '0x'.dechex($block),
            'transactionHash' => $txHash,
            'logIndex' => '0x'.dechex($logIndex),
        ];

        $this->chainState[4289]['logs'][] = $log;
        $this->chainState[4289]['receipts'][$txHash] = ['transactionHash' => $txHash, 'status' => '0x1', 'blockNumber' => '0x'.dechex($block), 'logs' => [$log]];

        return $txHash;
    }

    /** sign ที่สคริปต์ได้รับ — กรองตามบทบาท (ดูจาก expect address) */
    protected function signCallsFor(string $address): array
    {
        return array_values(array_filter($this->signCalls, fn ($c) => strtolower($c['BRIDGE_EXPECT_ADDRESS']) === strtolower($address)));
    }

    // ── RPC ──────────────────────────────────────────────────────────────

    private function answerRpc(Request $request): array
    {
        $host = parse_url($request->url(), PHP_URL_HOST);
        $chainId = match ($host) {
            'rpc.bsc.test' => 56,
            'rpc.eth.test' => 1,
            'rpc.tpix.test' => 4289,
            default => null,
        };

        $body = $request->data();
        $ok = fn ($result) => ['jsonrpc' => '2.0', 'id' => 1, 'result' => $result];
        $err = fn (string $message) => ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32000, 'message' => $message]];

        if ($chainId === null || $this->chainState[$chainId]['down']) {
            return $err('network unavailable rate limit');
        }

        $s = &$this->chainState[$chainId];
        $params = $body['params'] ?? [];

        switch ($body['method'] ?? '') {
            case 'eth_blockNumber':
                return $ok('0x'.dechex($s['latest']));

            case 'eth_getBlockByNumber':
                $tag = $params[0] ?? 'latest';
                if ($tag === 'finalized') {
                    return $s['finalized'] === null ? $err('Invalid Params') : $ok(['number' => '0x'.dechex($s['finalized'])]);
                }
                $n = $tag === 'latest' ? $s['latest'] : hexdec($tag);

                // time_base ตั้งทับได้ต่อเชน — เทสต์ที่ตัดสินด้วย "เวลาบล็อก" ต้องให้บล็อกตรงกับนาฬิกาของเทสต์
                return $ok(['number' => '0x'.dechex($n), 'timestamp' => '0x'.dechex(($s['time_base'] ?? 1760000000) + $n * 2)]);

            case 'eth_getLogs':
                if ($s['fail_logs']) {
                    return $err('rate limit exceeded');
                }
                $f = $params[0] ?? [];
                $from = hexdec($f['fromBlock']);
                $to = hexdec($f['toBlock']);
                $topics = $f['topics'] ?? [];

                return $ok(array_values(array_filter($s['logs'], function ($log) use ($f, $from, $to, $topics) {
                    $block = hexdec($log['blockNumber']);
                    if (strtolower($log['address']) !== strtolower($f['address']) || $block < $from || $block > $to) {
                        return false;
                    }
                    foreach ($topics as $i => $topic) {
                        if ($topic !== null && strtolower($log['topics'][$i] ?? '') !== strtolower($topic)) {
                            return false;
                        }
                    }

                    return true;
                })));

            case 'eth_getCode':
                return $ok($s['codes'][strtolower($params[0])] ?? '0x');

            case 'eth_getTransactionByHash':
                return $ok($s['txs'][strtolower($params[0])] ?? null);

            case 'eth_getTransactionReceipt':
                return $ok($s['receipts'][strtolower($params[0])] ?? null);

            case 'eth_getBalance':
                return $ok('0x'.Abi::encUint($s['native'][strtolower($params[0])] ?? '1000000000000000000'));

            case 'eth_getTransactionCount':
                return $ok('0x'.dechex($s['nonce'][strtolower($params[0])] ?? 0));

            case 'eth_gasPrice':
                return $ok($s['gas_price']);

            case 'eth_call':
                $result = $this->answerCall($chainId, strtolower($params[0]['to'] ?? ''), strtolower($params[0]['data'] ?? ''));

                return $result === null ? $err('execution reverted') : $ok($result);
        }

        return $err('method not found');
    }

    private function answerCall(int $chainId, string $to, string $data): ?string
    {
        $sel = substr($data, 0, 10);
        $args = str_split(substr($data, 10), 64);
        $s = $this->chainState[$chainId];
        $u = fn (string $v) => '0x'.Abi::encUint($v);
        $b = fn (bool $v) => '0x'.Abi::encUint($v ? '1' : '0');

        if ($sel === Abi::selector('balanceOf(address)')) {
            return $u($s['balances'][$to]['0x'.substr($args[0], 24)] ?? '0');
        }

        if ($chainId !== 4289) {
            return null;
        }

        if ($to === $this->usdtTpix) {
            return match ($sel) {
                Abi::selector('totalSupply()') => $u($s['supply']),
                Abi::selector('paused()') => $b($s['token_paused']),
                Abi::selector('bridges(address)') => $b('0x'.substr($args[0], 24) === $this->minter && $s['minter_is_bridge']),
                Abi::selector('processedTxHashes(bytes32)') => $b(! empty($s['processed']['0x'.$args[0]])),
                default => null,
            };
        }

        if ($to === $this->minter) {
            return match ($sel) {
                Abi::selector('paused()') => $b($s['minter_paused']),
                Abi::selector('relayers(address)') => $b(! empty($s['relayers']['0x'.substr($args[0], 24)])),
                Abi::selector('maxPerTx()') => $u($s['max_per_tx']),
                Abi::selector('dailyCap()') => $u($s['daily_cap']),
                Abi::selector('remainingToday()') => $u($s['remaining']),
                Abi::selector('totalMinted()') => $u($s['minter_total']),
                default => null,
            };
        }

        return null;
    }

    // ── สคริปต์เซ็น (node) ───────────────────────────────────────────────

    private function answerProcess(PendingProcess $process)
    {
        $env = $process->environment;
        $action = $env['BRIDGE_ACTION'] ?? '';
        $chainId = (int) ($env['BRIDGE_CHAIN_ID'] ?? 0);

        if ($action === 'sign') {
            unset($env['BRIDGE_KEYSTORE_PASS']);
            $this->signCalls[] = $env;

            if ($this->onSign) {
                ($this->onSign)($env);
            }

            if ($this->signFailCode !== null) {
                return Process::result(json_encode(['ok' => false, 'code' => $this->signFailCode, 'error' => 'fake failure']), '', 1);
            }

            $from = strtolower($env['BRIDGE_EXPECT_ADDRESS']);
            $key = "{$chainId}:{$from}";
            $nonce = $this->fakeNonces[$key] = ($this->fakeNonces[$key] ?? -1) + 1;
            $hash = '0x'.hash('sha256', "{$chainId}|{$env['BRIDGE_TO']}|{$env['BRIDGE_DATA']}|{$nonce}");
            $raw = '0xf86c'.hash('sha256', "raw:{$hash}");
            $this->rawIndex[$raw] = ['hash' => $hash, 'chain' => $chainId, 'to' => strtolower($env['BRIDGE_TO']), 'data' => strtolower($env['BRIDGE_DATA']), 'from' => $from, 'nonce' => $nonce];

            return Process::result(json_encode([
                'ok' => true, 'address' => $env['BRIDGE_EXPECT_ADDRESS'], 'nonce' => $nonce,
                'txHash' => $hash, 'raw' => $raw, 'gasLimit' => '100000', 'gasPrice' => '0',
            ]));
        }

        if ($action === 'send') {
            $raw = $env['BRIDGE_RAW_TX'] ?? '';
            $this->sendCalls[] = $raw;
            $tx = $this->rawIndex[$raw] ?? null;

            if ($this->sendFailCode !== null || $tx === null) {
                return Process::result(json_encode(['ok' => false, 'code' => $this->sendFailCode ?? 'broadcast_failed', 'error' => 'fake broadcast failure', 'txHash' => $tx['hash'] ?? null]), '', 1);
            }

            if ($this->mineOnSend) {
                $this->mine($tx);
            }

            return Process::result(json_encode(['ok' => true, 'txHash' => $tx['hash'], 'alreadyKnown' => false]));
        }

        return Process::result(json_encode(['ok' => false, 'code' => 'config', 'error' => 'unknown action']), '', 1);
    }

    /** ลงบล็อกธุรกรรม — พร้อมผลบนสัญญาเหมือนของจริง */
    protected function mine(array $tx): void
    {
        $chainId = $tx['chain'];
        $s = &$this->chainState[$chainId];
        $s['latest']++;
        $reverted = ! empty($this->revertOnMine[$tx['hash']]);

        $s['receipts'][$tx['hash']] = ['transactionHash' => $tx['hash'], 'status' => $reverted ? '0x0' : '0x1', 'blockNumber' => '0x'.dechex($s['latest']), 'logs' => []];
        $s['txs'][$tx['hash']] = ['hash' => $tx['hash'], 'from' => $tx['from']];
        $s['nonce'][$tx['from']] = ($s['nonce'][$tx['from']] ?? 0) + 1;

        if ($reverted) {
            return;
        }

        $sel = substr($tx['data'], 0, 10);
        $args = str_split(substr($tx['data'], 10), 64);

        if ($chainId === 4289 && $tx['to'] === $this->minter && $sel === Abi::selector('mint(address,uint256,bytes32)')) {
            $units = Wei::hexToInt('0x'.$args[1]);
            $s['processed']['0x'.$args[2]] = true;
            $s['supply'] = bcadd($s['supply'], $units, 0);
            $s['minter_total'] = bcadd($s['minter_total'], $units, 0);
            $s['remaining'] = bcsub($s['remaining'], $units, 0);
        }

        if ($sel === Abi::selector('transfer(address,uint256)')) {
            $amount = Wei::hexToInt('0x'.$args[1]);
            $balance = $s['balances'][$tx['to']][$tx['from']] ?? '0';
            $s['balances'][$tx['to']][$tx['from']] = bcsub($balance, $amount, 0);
        }

        if ($sel === Abi::selector('pause()') && $tx['to'] === $this->minter) {
            $s['minter_paused'] = true;
        }
    }

    /** ลงบล็อกธุรกรรมที่ส่งค้างไว้ (ตอน $mineOnSend = false) จาก hash */
    protected function mineHash(string $hash): void
    {
        foreach ($this->rawIndex as $tx) {
            if ($tx['hash'] === $hash) {
                $this->mine($tx);

                return;
            }
        }
    }
}
