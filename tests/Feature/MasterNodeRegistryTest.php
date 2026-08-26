<?php

namespace Tests\Feature;

use App\Services\MasterNode\NodeRegistryContract;
use App\Services\MasterNode\NodeRegistryService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ตรวจว่าเว็บคุยกับ NodeRegistryV2 ถูกสัญญาจริง.
 *
 * บั๊กที่เทสต์ชุดนี้กันไม่ให้กลับมา (ทั้งหมดเคยหลุดขึ้น production มาแล้ว):
 *   1. selector ผิด → eth_call ยิงไปที่ฟังก์ชันที่ไม่มีอยู่ แล้ว catch เงียบเป็น 0
 *   2. ลำดับ tier สลับ → คนซื้อ Validator ได้ Guardian / คนซื้อ Light โดน revert
 *   3. ถอด struct ผิดออฟเซ็ต → โหนดที่มีอยู่จริงอ่านออกมาเป็น "ไม่มีโหนด"
 *   4. ไม่ส่ง User-Agent → Cloudflare ตอบ 403 ทุก request
 *   5. สัญญา deploy แล้วแต่ยัง fallback ไปเช็ก balance → ใครถือเหรียญก็ได้ allowlist
 */
class MasterNodeRegistryTest extends TestCase
{
    private const REGISTRY = '0x1234567890AbcdEF1234567890aBcdef12345678';

    private const OPERATOR = '0x1111111111111111111111111111111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'masternode.registry.address' => self::REGISTRY,
            'masternode.registry.rpc_url' => 'https://rpc.tpix.test',
            'blockchain.masternode_registry' => self::REGISTRY,
            'masternode.genesis_operators' => [],
        ]);
    }

    /** ต่อ word ให้เป็นผลลัพธ์ eth_call */
    private function words(array $hexWords): string
    {
        return '0x'.implode('', array_map(fn ($w) => str_pad(ltrim($w, '0x'), 64, '0', STR_PAD_LEFT), $hexWords));
    }

    private function uint(string|int $value): string
    {
        return str_pad(is_int($value) ? dechex($value) : $value, 64, '0', STR_PAD_LEFT);
    }

    /**
     * getNodeInfo() ที่ ABI-encode มาจาก ethers จริง (ดู contracts/artifacts NodeRegistryV2)
     * โหนด: Light (tier 2), Active (status 1), stake 10,000 TPIX, endpoint "203.0.113.10:8545".
     */
    private function nodeInfoPayload(): string
    {
        return '0x'
            .$this->uint('20')                                    // offset ไปหัว struct
            .str_pad(substr(self::OPERATOR, 2), 64, '0', STR_PAD_LEFT) // operator
            .$this->uint(2)                                       // tier = Light
            .$this->uint(1)                                       // status = Active
            .$this->uint('21e19e0c9bab2400000')                   // stakedAmount = 10,000e18
            .$this->uint('6553f100')                              // registeredAt
            .$this->uint('655d2b80')                              // unlockAt
            .$this->uint('6553f100')                              // lastRewardAt
            .$this->uint(5)                                       // totalRewards
            .$this->uint('2710')                                  // uptime = 10000 bp
            .str_repeat('ab', 32)                                 // nodeId
            .$this->uint('1a0')                                   // offset ของ endpoint
            .$this->uint(7)                                       // rewardDebt
            .$this->uint(3)                                       // pendingUnclaimed
            .$this->uint('11')                                    // ความยาว endpoint = 17
            .str_pad(bin2hex('203.0.113.10:8545'), 64, '0', STR_PAD_RIGHT);
    }

    public function test_registry_reads_node_struct_at_the_right_offsets(): void
    {
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);
            if ($body['method'] === 'eth_getCode') {
                return ['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x60806040'];
            }

            return ['jsonrpc' => '2.0', 'id' => 1, 'result' => $this->nodeInfoPayload()];
        });

        $node = app(NodeRegistryContract::class)->nodeInfo(self::OPERATOR);

        $this->assertNotNull($node, 'โหนดที่มีอยู่จริงต้องอ่านออกมาได้ ไม่ใช่ null');
        $this->assertSame('Light', $node['tier'], 'tier 2 ต้องเป็น Light (Guardian=0 ไม่ใช่ Validator=0)');
        $this->assertSame(2, $node['tier_id']);
        $this->assertSame('active', $node['status']);
        $this->assertSame('10000', $node['stake_amount']);
        $this->assertSame('203.0.113.10:8545', $node['endpoint'], 'endpoint เป็น dynamic string ต้องตามออฟเซ็ตไปอ่าน');
        $this->assertSame(10000, $node['uptime'], 'uptime เก็บเป็น basis points (10000 = 100%)');
    }

    public function test_tier_indexes_match_the_contract_enum(): void
    {
        // enum NodeTier { Guardian, Sentinel, Light, Validator } — Guardian=0 เพื่อเข้ากันได้กับ V1
        $this->assertSame('Guardian', NodeRegistryContract::TIERS[0]);
        $this->assertSame('Sentinel', NodeRegistryContract::TIERS[1]);
        $this->assertSame('Light', NodeRegistryContract::TIERS[2]);
        $this->assertSame('Validator', NodeRegistryContract::TIERS[3]);

        $this->assertSame(
            ['Guardian' => 0, 'Sentinel' => 1, 'Light' => 2, 'Validator' => 3],
            config('masternode.tier_index'),
            'config ต้องตรงกับ enum ในสัญญา ไม่งั้นคนซื้อได้ชั้นผิด'
        );
    }

    public function test_every_rpc_request_carries_a_user_agent(): void
    {
        // rpc.tpix.online อยู่หลัง Cloudflare bot rule ที่ตอบ 403 ให้ client ที่ไม่มี UA
        Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'.$this->uint(0)])]);

        app(NodeRegistryContract::class)->blockNumber();

        Http::assertSent(fn ($request) => ! empty($request->header('User-Agent')[0]));
    }

    public function test_missing_bytecode_reports_not_deployed(): void
    {
        // เชน regenesis แล้ว .env ยังค้างที่อยู่เก่า → address มี แต่ไม่มีโค้ด
        Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'])]);

        $registry = app(NodeRegistryContract::class);

        $this->assertSame(self::REGISTRY, $registry->address());
        $this->assertFalse($registry->isDeployed(), 'eth_getCode = 0x ต้องนับว่ายังไม่ deploy');
    }

    public function test_network_stats_decode_in_declared_order(): void
    {
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);

            return match ($body['method']) {
                'eth_getCode' => ['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x60806040'],
                default => ['jsonrpc' => '2.0', 'id' => 1, 'result' => $this->words([
                    '21e19e0c9bab2400000', // totalStaked = 10,000e18
                    '3',                   // totalActiveNodes
                    '0',                   // totalRewardsDistributed
                    '0',                   // remainingRewards
                    '0',                   // currentRewardPerSecond
                    '0',                   // currentYear (นับจาก 0)
                ])],
            };
        });

        $stats = app(NodeRegistryContract::class)->networkStats();

        $this->assertSame('10000000000000000000000', $stats['total_staked_wei']);
        $this->assertSame(3, $stats['total_active_nodes']);
        $this->assertSame(0, $stats['current_year_index']);
    }

    public function test_allowlist_rejects_a_wallet_that_only_holds_tpix(): void
    {
        // สัญญา deploy แล้ว → ต้องยึดสัญญาเป็นคำตอบสุดท้าย
        // ห้ามตกไป fallback balance ไม่งั้นยืมเหรียญมาถือแป๊บเดียวก็ขอ allowlist ได้
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);

            return match ($body['method']) {
                'eth_getCode' => ['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x60806040'],
                // getNodeInfo คืน struct ศูนย์ = ไม่เคยลงทะเบียน
                'eth_call' => ['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'.$this->uint('20').str_repeat($this->uint(0), 15)],
                // ยอดคงเหลือมหาศาล — ต้องไม่ช่วยให้ผ่าน
                'eth_getBalance' => ['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x33b2e3c9fd0803ce8000000'],
                default => ['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'],
            };
        });

        $this->assertNull(
            app(NodeRegistryService::class)->lookup(self::OPERATOR),
            'สัญญา deploy แล้ว + ไม่ได้ลงทะเบียน = ปฏิเสธ ต่อให้ถือเหรียญเยอะแค่ไหน'
        );
    }

    public function test_allowlist_accepts_an_active_registered_node(): void
    {
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);

            return match ($body['method']) {
                'eth_getCode' => ['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x60806040'],
                default => ['jsonrpc' => '2.0', 'id' => 1, 'result' => $this->nodeInfoPayload()],
            };
        });

        $result = app(NodeRegistryService::class)->lookup(self::OPERATOR);

        $this->assertNotNull($result);
        $this->assertSame('Light', $result['tier']);
        $this->assertSame('registry', $result['source']);
    }

    public function test_allowlist_rejects_a_slashed_node(): void
    {
        $slashed = str_replace(
            $this->uint(2).$this->uint(1),   // tier=Light, status=Active
            $this->uint(2).$this->uint(2),   // tier=Light, status=Slashed
            $this->nodeInfoPayload()
        );

        Http::fake(function ($request) use ($slashed) {
            $body = json_decode($request->body(), true);

            return match ($body['method']) {
                'eth_getCode' => ['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x60806040'],
                default => ['jsonrpc' => '2.0', 'id' => 1, 'result' => $slashed],
            };
        });

        $this->assertNull(
            app(NodeRegistryService::class)->lookup(self::OPERATOR),
            'โหนดที่ถูกปรับไม่ควรได้ allowlist ต่อ'
        );
    }

    public function test_balance_fallback_still_works_before_the_contract_is_deployed(): void
    {
        config(['masternode.registry.address' => null]);

        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);

            // 1,000,000 TPIX → ชั้น Guardian
            return $body['method'] === 'eth_getBalance'
                ? ['jsonrpc' => '2.0', 'id' => 1, 'result' => '0xd3c21bcecceda1000000']
                : ['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'];
        });

        $result = app(NodeRegistryService::class)->lookup(self::OPERATOR);

        $this->assertNotNull($result);
        $this->assertSame('Guardian', $result['tier']);
        $this->assertSame('balance', $result['source']);
    }

    public function test_my_nodes_endpoint_reports_lock_state(): void
    {
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);

            return match ($body['method']) {
                'eth_getCode' => ['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x60806040'],
                default => ['jsonrpc' => '2.0', 'id' => 1, 'result' => $this->nodeInfoPayload()],
            };
        });

        $node = app(NodeRegistryContract::class)->nodeInfo(self::OPERATOR);

        // unlockAt = 0x655d2b80 (พ.ย. 2023) ซึ่งผ่านมานานแล้ว → ต้องถอนได้
        $this->assertLessThan(time(), $node['unlock_at']);
    }
}
