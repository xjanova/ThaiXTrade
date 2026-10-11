<?php

namespace Tests\Support;

use App\Models\SiteSetting;
use App\Services\Bridge\Abi;
use App\Services\Bridge\Wtpix\WtpixAbi;
use App\Services\Bridge\Wtpix\WtpixBridgeConfig;
use App\Support\Wei;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * เชนจำลองสำหรับเทสต์สะพาน TPIX ⇄ wTPIX — เชน 4289 (TPIXBridgeVault) + BSC 56 (wTPIX + WTPIXBridgeMinter).
 *
 * ตอบ JSON-RPC ตามเมธอดจริง (eth_getLogs กรอง address/topic รวมถึง topic แบบ OR · eth_call ตาม selector
 * ที่คำนวณด้วย keccak) และ "ลงบล็อก" ธุรกรรมพร้อมผลบนสัญญาเหมือนของจริง:
 *   mint    → processed + supply + totalMinted + โควตาวันนี้ + log Transfer(0x0, to)
 *   release → processed + TPIX ออกจากคลังไปผู้รับ + totalReleased + log Released
 *   pause() → paused
 * สคริปต์เซ็น (node) ถูกแทนด้วย Process::fake — ไม่มีคีย์จริงใด ๆ ในเทสต์
 */
trait FakesWtpixBridgeChains
{
    protected string $wVault = '0x7a17000000000000000000000000000000000001';

    protected string $wToken = '0x7a17000000000000000000000000000000000002';

    protected string $wMinter = '0x7a17000000000000000000000000000000000003';

    protected string $wRelayer = '0x7a17000000000000000000000000000000000004';

    protected string $wGuardian = '0x7a17000000000000000000000000000000000005';

    protected string $alice = '0xa11ce00000000000000000000000000000000a11';

    protected string $bob = '0xb0b0000000000000000000000000000000000b0b';

    /** @var array<int, array> */
    protected array $chain = [];

    protected array $signCalls = [];

    protected array $sendCalls = [];

    protected ?string $signFailCode = null;

    protected ?string $sendFailCode = null;

    protected bool $mineOnSend = true;

    protected ?\Closure $onSign = null;

    protected array $revertOnMine = [];

    private array $rawIndex = [];

    private array $fakeNonces = [];

    private array $keystoreFiles = [];

    private int $txSeq = 0;

    protected function setUpWtpixBridge(bool $siteSwitch = true): void
    {
        config([
            'wtpix_bridge.enabled' => true,
            'wtpix_bridge.receipt_wait_seconds' => 0,
            'wtpix_bridge.vault_address' => $this->wVault,
            'wtpix_bridge.wtpix_address' => $this->wToken,
            'wtpix_bridge.minter_address' => $this->wMinter,
            'wtpix_bridge.ignore_senders' => [],
            'wtpix_bridge.tpix.rpc' => ['https://rpc.tpix.test'],
            'wtpix_bridge.tpix.start_block' => null,
            'wtpix_bridge.bsc.chain_id' => 56,
            'wtpix_bridge.bsc.name' => 'BNB Smart Chain',
            'wtpix_bridge.bsc.rpc' => ['https://rpc.bsc.test'],
            'wtpix_bridge.bsc.public_rpc' => 'https://rpc.bsc.test',
            'wtpix_bridge.bsc.explorer' => 'https://bscscan.com',
            'wtpix_bridge.bsc.start_block' => null,
            'wtpix_bridge.reconcile.auto_pause' => true,
        ]);

        foreach (['relayer' => $this->wRelayer, 'guardian' => $this->wGuardian] as $role => $address) {
            $path = tempnam(sys_get_temp_dir(), "wtpix-{$role}-");
            // keystore จำลอง — มีแค่ฟิลด์ address (แบบ V3 จริงที่ไม่มี 0x) ไม่มีคีย์ใด ๆ
            file_put_contents($path, json_encode(['address' => substr($address, 2), 'version' => 3]));
            $this->keystoreFiles[] = $path;
            config(["wtpix_bridge.signers.{$role}.keystore" => $path, "wtpix_bridge.signers.{$role}.passphrase" => 'test-only-passphrase']);
        }

        SiteSetting::set(WtpixBridgeConfig::SETTINGS_GROUP, 'enabled', $siteSwitch ? '1' : '0', 'boolean');
        Cache::flush();

        $this->chain = [
            4289 => $this->freshChain(2000, null) + [
                'vault' => [
                    'paused' => false,
                    'relayers' => [$this->wRelayer => true],
                    'guardians' => [$this->wGuardian => true],
                    'processed' => [],
                    'max_per_tx' => $this->tpix('100000'),
                    'daily_cap' => $this->tpix('500000'),
                    'remaining' => $this->tpix('500000'),
                    'min_lock' => $this->tpix('10'),
                    'max_lock' => $this->tpix('100000'),
                    'total_locked' => '0',
                    'total_released' => '0',
                ],
            ],
            56 => $this->freshChain(5000, 4990) + [
                'token' => [
                    'supply' => '0',
                    'paused' => false,
                    'minters' => [$this->wMinter => true],
                    'initial_minter_set' => true,
                    'balances' => [],
                ],
                'minter' => [
                    'token' => $this->wToken,
                    'paused' => false,
                    'relayers' => [$this->wRelayer => true],
                    'guardians' => [$this->wGuardian => true],
                    'processed' => [],
                    'max_per_tx' => $this->tpix('100000'),
                    'daily_cap' => $this->tpix('500000'),
                    'remaining' => $this->tpix('500000'),
                    'total_minted' => '0',
                ],
            ],
        ];

        // สัญญามีโค้ด · relayer/guardian มี BNB พอจ่ายแก๊ส
        $this->chain[4289]['codes'][$this->wVault] = '0x6080';
        $this->chain[56]['codes'][$this->wToken] = '0x6080';
        $this->chain[56]['codes'][$this->wMinter] = '0x6080';
        $this->chain[56]['native'][$this->wRelayer] = $this->tpix('1');
        $this->chain[56]['native'][$this->wGuardian] = $this->tpix('1');

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

    protected function tpix(string $amount): string
    {
        return Wei::toWei($amount, 18);
    }

    private function freshChain(int $latest, ?int $finalized): array
    {
        return [
            'latest' => $latest, 'finalized' => $finalized, 'logs' => [], 'txs' => [], 'receipts' => [],
            'codes' => [], 'native' => [], 'nonce' => [], 'gas_price' => '0x3b9aca00', 'fail_logs' => false, 'down' => false,
        ];
    }

    private function nextHash(string $seed): string
    {
        return '0x'.hash('sha256', $seed.':'.(++$this->txSeq));
    }

    private function addLog(int $chainId, string $txHash, array $log, ?string $txFrom = null, ?string $txTo = null): void
    {
        $this->chain[$chainId]['logs'][] = $log;
        $this->chain[$chainId]['txs'][$txHash] ??= ['hash' => $txHash, 'from' => strtolower((string) $txFrom), 'to' => strtolower((string) $txTo)];
        $receipt = $this->chain[$chainId]['receipts'][$txHash] ?? ['transactionHash' => $txHash, 'status' => '0x1', 'blockNumber' => $log['blockNumber'], 'logs' => []];
        $receipt['logs'][] = $log;
        $this->chain[$chainId]['receipts'][$txHash] = $receipt;
    }

    // ── ตัวช่วยสร้างเหตุการณ์บนเชน ─────────────────────────────────────────

    /** ล็อก TPIX เข้าคลัง (Locked) — คืน tx hash */
    protected function fakeLock(string $from, string $wei, int $block, ?string $txFrom = null, int $logIndex = 0, ?string $contract = null): string
    {
        $tx = $this->nextHash("lock:{$from}:{$wei}:{$block}");
        $this->addLog(4289, $tx, [
            'address' => $contract ?? $this->wVault,
            'topics' => [Abi::topic(WtpixAbi::LOCKED_EVENT), '0x'.Abi::encAddress($from), '0x'.Abi::encUint((string) $this->txSeq)],
            'data' => '0x'.Abi::encUint($wei),
            'blockNumber' => '0x'.dechex($block),
            'blockHash' => '0x'.hash('sha256', "block:{$block}"),
            'transactionHash' => $tx,
            'logIndex' => '0x'.dechex($logIndex),
        ], $txFrom ?? $from, $this->wVault);

        if ($contract === null) {
            $v = &$this->chain[4289];
            $v['native'][$this->wVault] = bcadd($v['native'][$this->wVault] ?? '0', $wei, 0);
            $v['vault']['total_locked'] = bcadd($v['vault']['total_locked'], $wei, 0);
        }

        return $tx;
    }

    /** เผา wTPIX บน BSC (Transfer(from, 0x0)) — คืน tx hash */
    protected function fakeBurnWtpix(string $from, string $wei, int $block, ?string $txFrom = null, int $logIndex = 0): string
    {
        $tx = $this->nextHash("burn:{$from}:{$wei}:{$block}");
        $this->addLog(56, $tx, [
            'address' => $this->wToken,
            'topics' => [Abi::topic(Abi::TRANSFER_EVENT), '0x'.Abi::encAddress($from), WtpixAbi::zeroTopic()],
            'data' => '0x'.Abi::encUint($wei),
            'blockNumber' => '0x'.dechex($block),
            'transactionHash' => $tx,
            'logIndex' => '0x'.dechex($logIndex),
        ], $txFrom ?? $from, $this->wToken);

        $this->chain[56]['token']['supply'] = bcsub($this->chain[56]['token']['supply'], $wei, 0);

        return $tx;
    }

    /** mint wTPIX ที่ระบบไม่ได้สั่ง (เช่น minter อื่น) — Transfer(0x0, to) */
    protected function fakeForeignMint(string $to, string $wei, int $block): string
    {
        $tx = $this->nextHash("foreign-mint:{$to}:{$wei}");
        $this->addLog(56, $tx, [
            'address' => $this->wToken,
            'topics' => [Abi::topic(Abi::TRANSFER_EVENT), WtpixAbi::zeroTopic(), '0x'.Abi::encAddress($to)],
            'data' => '0x'.Abi::encUint($wei),
            'blockNumber' => '0x'.dechex($block),
            'transactionHash' => $tx,
            'logIndex' => '0x0',
        ], $to, $this->wToken);
        $this->chain[56]['token']['supply'] = bcadd($this->chain[56]['token']['supply'], $wei, 0);

        return $tx;
    }

    /** MinterQueued(minter, executeAfter) หรือ MinterSet(minter, status) บน wTPIX */
    protected function fakeMinterEvent(string $event, string $minter, int $block, bool $status = true): string
    {
        $tx = $this->nextHash("minter:{$event}:{$minter}");
        $signature = $event === 'queued' ? WtpixAbi::MINTER_QUEUED_EVENT : WtpixAbi::MINTER_SET_EVENT;
        $data = $event === 'queued' ? Abi::encUint((string) (1760000000 + 2 * 86400)) : Abi::encUint($status ? '1' : '0');

        $this->addLog(56, $tx, [
            'address' => $this->wToken,
            'topics' => [Abi::topic($signature), '0x'.Abi::encAddress($minter)],
            'data' => '0x'.$data,
            'blockNumber' => '0x'.dechex($block),
            'transactionHash' => $tx,
            'logIndex' => '0x0',
        ], '0x9999999999999999999999999999999999999999', $this->wToken);

        return $tx;
    }

    /** คลังปล่อย TPIX ในธุรกรรมที่ระบบไม่ได้ส่ง (คีย์ relayer หลุด) */
    protected function fakeForeignRelease(string $to, string $wei, int $block): string
    {
        $tx = $this->nextHash("foreign-release:{$to}:{$wei}");
        $this->addLog(4289, $tx, [
            'address' => $this->wVault,
            'topics' => [Abi::topic(WtpixAbi::RELEASED_EVENT), '0x'.Abi::encAddress($this->wRelayer), '0x'.Abi::encAddress($to), '0x'.str_repeat('ab', 32)],
            'data' => '0x'.Abi::encUint($wei).Abi::encUint('20000').Abi::encUint($wei),
            'blockNumber' => '0x'.dechex($block),
            'transactionHash' => $tx,
            'logIndex' => '0x0',
        ], $this->wRelayer, $this->wVault);

        $v = &$this->chain[4289];
        $v['native'][$this->wVault] = bcsub($v['native'][$this->wVault] ?? '0', $wei, 0);
        $v['vault']['total_released'] = bcadd($v['vault']['total_released'], $wei, 0);

        return $tx;
    }

    protected function signCallsFor(string $address, ?int $chainId = null): array
    {
        return array_values(array_filter($this->signCalls, fn ($c) => strtolower($c['BRIDGE_EXPECT_ADDRESS']) === strtolower($address)
            && ($chainId === null || (int) $c['BRIDGE_CHAIN_ID'] === $chainId)));
    }

    // ── RPC ──────────────────────────────────────────────────────────────

    private function answerRpc(Request $request): array
    {
        $chainId = match (parse_url($request->url(), PHP_URL_HOST)) {
            'rpc.tpix.test' => 4289,
            'rpc.bsc.test' => 56,
            default => null,
        };

        $body = $request->data();
        $ok = fn ($result) => ['jsonrpc' => '2.0', 'id' => 1, 'result' => $result];
        $err = fn (string $message) => ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32000, 'message' => $message]];

        if ($chainId === null || $this->chain[$chainId]['down']) {
            return $err('network unavailable rate limit');
        }

        $s = $this->chain[$chainId];
        $params = $body['params'] ?? [];

        switch ($body['method'] ?? '') {
            case 'eth_chainId':
                return $ok('0x'.dechex($chainId));

            case 'eth_blockNumber':
                return $ok('0x'.dechex($s['latest']));

            case 'eth_getBlockByNumber':
                $tag = $params[0] ?? 'latest';
                if ($tag === 'finalized') {
                    return $s['finalized'] === null ? $err('Invalid Params') : $ok(['number' => '0x'.dechex($s['finalized'])]);
                }
                $n = $tag === 'latest' ? $s['latest'] : hexdec($tag);

                return $ok(['number' => '0x'.dechex($n), 'timestamp' => '0x'.dechex(1760000000 + $n * 2)]);

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
                        if ($topic === null) {
                            continue;
                        }
                        $have = strtolower($log['topics'][$i] ?? '');
                        $want = array_map('strtolower', (array) $topic);
                        if (! in_array($have, $want, true)) {
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
                return $ok('0x'.Abi::encUint($s['native'][strtolower($params[0])] ?? '0'));

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
        $arg = fn (int $i) => '0x'.substr($args[$i] ?? '', 24);
        $word = fn (int $i) => '0x'.($args[$i] ?? '');
        $u = fn (string $v) => '0x'.Abi::encUint($v);
        $b = fn (bool $v) => '0x'.Abi::encUint($v ? '1' : '0');
        $s = $this->chain[$chainId];

        if ($chainId === 4289 && $to === $this->wVault) {
            $v = $s['vault'];

            return match ($sel) {
                Abi::selector('paused()') => $b($v['paused']),
                Abi::selector('relayers(address)') => $b(! empty($v['relayers'][$arg(0)])),
                Abi::selector('guardians(address)') => $b(! empty($v['guardians'][$arg(0)])),
                Abi::selector('processed(bytes32)') => $b(! empty($v['processed'][$word(0)])),
                Abi::selector('maxPerTx()') => $u($v['max_per_tx']),
                Abi::selector('dailyCap()') => $u($v['daily_cap']),
                Abi::selector('remainingToday()') => $u($v['remaining']),
                Abi::selector('minLock()') => $u($v['min_lock']),
                Abi::selector('maxLock()') => $u($v['max_lock']),
                Abi::selector('totalLocked()') => $u($v['total_locked']),
                Abi::selector('totalReleased()') => $u($v['total_released']),
                default => null,
            };
        }

        if ($chainId === 56 && $to === $this->wToken) {
            $tk = $s['token'];

            return match ($sel) {
                Abi::selector('totalSupply()') => $u($tk['supply']),
                Abi::selector('paused()') => $b($tk['paused']),
                Abi::selector('minters(address)') => $b(! empty($tk['minters'][$arg(0)])),
                Abi::selector('initialMinterSet()') => $b($tk['initial_minter_set']),
                Abi::selector('balanceOf(address)') => $u($tk['balances'][$arg(0)] ?? '0'),
                default => null,
            };
        }

        if ($chainId === 56 && $to === $this->wMinter) {
            $m = $s['minter'];

            return match ($sel) {
                Abi::selector('token()') => '0x'.Abi::encAddress($m['token']),
                Abi::selector('paused()') => $b($m['paused']),
                Abi::selector('relayers(address)') => $b(! empty($m['relayers'][$arg(0)])),
                Abi::selector('guardians(address)') => $b(! empty($m['guardians'][$arg(0)])),
                Abi::selector('processed(bytes32)') => $b(! empty($m['processed'][$word(0)])),
                Abi::selector('maxPerTx()') => $u($m['max_per_tx']),
                Abi::selector('dailyCap()') => $u($m['daily_cap']),
                Abi::selector('remainingToday()') => $u($m['remaining']),
                Abi::selector('totalMinted()') => $u($m['total_minted']),
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
                'txHash' => $hash, 'raw' => $raw, 'gasLimit' => '100000', 'gasPrice' => $env['BRIDGE_GAS_PRICE_WEI'] ?? '0',
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
        $s = &$this->chain[$chainId];
        $s['latest']++;
        if ($s['finalized'] !== null) {
            $s['finalized']++;
        }
        $reverted = ! empty($this->revertOnMine[$tx['hash']]);
        $block = '0x'.dechex($s['latest']);

        $s['receipts'][$tx['hash']] = ['transactionHash' => $tx['hash'], 'status' => $reverted ? '0x0' : '0x1', 'blockNumber' => $block, 'logs' => []];
        $s['txs'][$tx['hash']] = ['hash' => $tx['hash'], 'from' => $tx['from'], 'to' => $tx['to']];
        $s['nonce'][$tx['from']] = ($s['nonce'][$tx['from']] ?? 0) + 1;

        if ($reverted) {
            return;
        }

        $sel = substr($tx['data'], 0, 10);
        $args = str_split(substr($tx['data'], 10), 64);

        if ($sel === Abi::selector('pause()')) {
            if ($chainId === 56 && $tx['to'] === $this->wMinter) {
                $s['minter']['paused'] = true;
            }
            if ($chainId === 4289 && $tx['to'] === $this->wVault) {
                $s['vault']['paused'] = true;
            }

            return;
        }

        if ($chainId === 56 && $tx['to'] === $this->wMinter && $sel === Abi::selector('mint(address,uint256,bytes32)')) {
            $to = '0x'.substr($args[0], 24);
            $wei = Wei::hexToInt('0x'.$args[1]);
            $s['minter']['processed']['0x'.$args[2]] = true;
            $s['minter']['total_minted'] = bcadd($s['minter']['total_minted'], $wei, 0);
            $s['minter']['remaining'] = bcsub($s['minter']['remaining'], $wei, 0);
            $s['token']['supply'] = bcadd($s['token']['supply'], $wei, 0);
            $s['token']['balances'][$to] = bcadd($s['token']['balances'][$to] ?? '0', $wei, 0);
            $log = ['address' => $this->wToken, 'topics' => [Abi::topic(Abi::TRANSFER_EVENT), WtpixAbi::zeroTopic(), '0x'.Abi::encAddress($to)],
                'data' => '0x'.Abi::encUint($wei), 'blockNumber' => $block, 'transactionHash' => $tx['hash'], 'logIndex' => '0x0'];
            $s['logs'][] = $log;
            $s['receipts'][$tx['hash']]['logs'][] = $log;
        }

        if ($chainId === 4289 && $tx['to'] === $this->wVault && $sel === Abi::selector('release(address,uint256,bytes32)')) {
            $to = '0x'.substr($args[0], 24);
            $wei = Wei::hexToInt('0x'.$args[1]);
            $s['vault']['processed']['0x'.$args[2]] = true;
            $s['vault']['total_released'] = bcadd($s['vault']['total_released'], $wei, 0);
            $s['vault']['remaining'] = bcsub($s['vault']['remaining'], $wei, 0);
            $s['native'][$this->wVault] = bcsub($s['native'][$this->wVault] ?? '0', $wei, 0);
            $s['native'][$to] = bcadd($s['native'][$to] ?? '0', $wei, 0);
            $log = ['address' => $this->wVault, 'topics' => [Abi::topic(WtpixAbi::RELEASED_EVENT), '0x'.Abi::encAddress($tx['from']), '0x'.Abi::encAddress($to), '0x'.$args[2]],
                'data' => '0x'.Abi::encUint($wei).Abi::encUint('20000').Abi::encUint($wei), 'blockNumber' => $block, 'transactionHash' => $tx['hash'], 'logIndex' => '0x0'];
            $s['logs'][] = $log;
            $s['receipts'][$tx['hash']]['logs'][] = $log;
        }
    }

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
