<?php

namespace Tests\Feature;

use App\Services\MasterNode\NodeRegistryContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * activeNodes() — ไล่โหนดด้วย getOperatorCount() + operators(i) ไม่ใช่ getActiveOperators().
 *
 * getActiveOperators วน array `operators` ทั้งก้อนสองรอบทุกครั้ง (~9k gas/กระเป๋า) แล้ว eth_call
 * ถูกจำกัดราว 10M gas → พังที่ราว 1,100 กระเป๋า เชนปลอมในเทสต์นี้ตอบ getActiveOperators
 * ด้วย "out of gas" เสมอ งานต้องยังอ่านได้ครบ
 */
class MasterNodeActiveNodesTest extends TestCase
{
    use RefreshDatabase;

    private const REGISTRY = '0x44c497c67b4f756a7769f11c8bec26439c31f70e';

    /** @var array<int,string> */
    private array $operators = [];

    /** @var array<string,int> wallet => status (1 = active, 3 = exited) */
    private array $status = [];

    /** @var array<int,int> จำนวนคำสั่งใน batch แต่ละ request */
    private array $batchSizes = [];

    private array $calledSelectors = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['masternode.registry.address' => self::REGISTRY, 'masternode.registry.rpc_url' => 'https://rpc.test']);
        Cache::flush();

        Http::fake(['rpc.test*' => function ($request) {
            $data = $request->data();
            if (array_is_list($data)) {
                $this->batchSizes[] = count($data);

                return Http::response(array_reverse(array_map(fn ($c) => $this->answer($c), $data)));
            }

            return Http::response($this->answer($data));
        }]);
    }

    public function test_enumerates_every_active_node_without_get_active_operators(): void
    {
        $this->seedOperators(45, exitedEvery: 4);

        $nodes = app(NodeRegistryContract::class)->activeNodes();

        $expected = array_values(array_filter($this->operators, fn ($w) => $this->status[$w] === 1));
        $this->assertSame($expected, array_keys($nodes));
        $this->assertSame(10000, $nodes[$expected[0]]['uptime']);
        $this->assertLessThanOrEqual(20, max($this->batchSizes), 'njs gate รับ batch ไม่เกิน 20');
        $this->assertNotContains('0x783286fb', array_slice($this->calledSelectors, 0, -1), 'ห้ามพึ่ง getActiveOperators ในการไล่รายชื่อ');
    }

    public function test_operator_list_is_cached_and_only_new_indexes_are_read(): void
    {
        $this->seedOperators(30);
        $registry = app(NodeRegistryContract::class);
        $registry->operatorAddresses();

        $this->seedOperators(32);
        $this->calledSelectors = [];
        $list = $registry->operatorAddresses();

        $this->assertCount(32, $list);
        $this->assertSame(
            2,
            count(array_filter($this->calledSelectors, fn ($s) => $s === '0xe28d4906')),
            'ถามเฉพาะ operators(30) กับ operators(31)'
        );
    }

    public function test_any_failed_item_means_no_answer_rather_than_half_a_list(): void
    {
        $this->seedOperators(25);
        $this->operators[22] = 'broken';

        $this->assertNull(app(NodeRegistryContract::class)->activeNodes());
    }

    private function seedOperators(int $count, int $exitedEvery = 0): void
    {
        for ($i = count($this->operators); $i < $count; $i++) {
            $w = '0x'.str_pad(dechex($i + 1), 40, '0', STR_PAD_LEFT);
            $this->operators[$i] = $w;
            $this->status[$w] = $exitedEvery && $i % $exitedEvery === 0 ? 3 : 1;
        }
    }

    private function answer(array $call): array
    {
        $id = $call['id'] ?? 1;
        $data = strtolower($call['params'][0]['data'] ?? '');
        $sel = substr($data, 0, 10);
        $this->calledSelectors[] = $sel;
        $ok = fn ($hex) => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $hex];
        $word = fn (string $hex) => str_pad($hex, 64, '0', STR_PAD_LEFT);

        return match ($sel) {
            '0x1a4a7c61' => $ok('0x'.$word(dechex(count($this->operators)))),
            '0xe28d4906' => (function () use ($data, $ok, $word, $id) {
                $op = $this->operators[hexdec(substr($data, 10))] ?? null;

                return $op === 'broken' || $op === null
                    ? ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32000, 'message' => 'execution reverted']]
                    : $ok('0x'.$word(substr($op, 2)));
            })(),
            '0x582115fb' => $ok($this->nodeInfo('0x'.substr($data, -40))),
            // ตัวที่พังเมื่อกระเป๋าเยอะ — เชนปลอมตอบ out of gas เสมอ
            '0x783286fb' => ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32000, 'message' => 'out of gas']],
            default => ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'unknown']],
        };
    }

    /** ABI ของ struct MasterNode (มี string endpoint ว่างท้าย struct) */
    private function nodeInfo(string $wallet): string
    {
        $word = fn (string $hex) => str_pad($hex, 64, '0', STR_PAD_LEFT);

        return '0x'.implode('', [
            $word('20'),                              // offset ไปหัว struct
            $word(substr($wallet, 2)),                // operator
            $word('2'),                               // tier Light
            $word(dechex($this->status[$wallet])),    // status
            $word(dechex(10000)),                     // stakedAmount
            $word(dechex(1_760_000_000)),             // registeredAt
            $word('0'), $word('0'), $word('0'),       // unlockAt, lastRewardAt, totalRewards
            $word(dechex(10000)),                     // uptime
            $word('ab'),                              // nodeId
            $word(dechex(13 * 32)),                   // offset ของ endpoint
            $word('0'), $word('0'),                   // rewardDebt, pendingUnclaimed
            $word('0'),                               // endpoint ยาว 0
        ]);
    }
}
