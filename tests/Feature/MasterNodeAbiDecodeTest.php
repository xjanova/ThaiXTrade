<?php

namespace Tests\Feature;

use App\Services\MasterNode\NodeRegistryContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ตรวจตัวถอด ABI ฝั่ง PHP กับผลลัพธ์ที่ **ethers เข้ารหัสมาจริง**.
 *
 * ทำไมต้องมีไฟล์นี้แยกจาก MasterNodeRegistryTest:
 * เทสต์อีกไฟล์ใช้ payload ที่เขียนมือ ถ้าคนเขียนเข้าใจ layout ผิด
 * ทั้งตัวถอดและตัวเทสต์ก็จะผิดทางเดียวกันแล้วผ่านฉลุย
 *
 * fixtures ใน tests/Fixtures/node-registry-abi.json สร้างจาก ABI ที่ hardhat
 * คอมไพล์ออกมาจริง (TPIX-Coin/contracts/artifacts/.../NodeRegistryV2.json) ด้วย
 * ethers Interface.encodeFunctionResult() — ถ้าสัญญาเปลี่ยน struct เมื่อไหร่
 * ให้ generate ไฟล์นี้ใหม่แล้วเทสต์จะฟ้องเองว่าตัวถอดตามไม่ทัน
 */
class MasterNodeAbiDecodeTest extends TestCase
{
    private const REGISTRY = '0x1234567890AbcdEF1234567890aBcdef12345678';

    private array $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'masternode.registry.address' => self::REGISTRY,
            'masternode.registry.rpc_url' => 'https://rpc.tpix.test',
        ]);

        $this->fixtures = json_decode(
            file_get_contents(base_path('tests/Fixtures/node-registry-abi.json')),
            true
        );
    }

    /** ตอบ eth_getCode ว่ามีโค้ด แล้วตอบ eth_call ด้วย payload ที่ระบุ */
    private function fakeCall(string $payload): void
    {
        Http::fake(function ($request) use ($payload) {
            $body = json_decode($request->body(), true);

            return Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => $body['method'] === 'eth_getCode' ? '0x60806040' : $payload,
            ]);
        });
    }

    public function test_decodes_a_real_ethers_encoded_node_struct(): void
    {
        $this->fakeCall($this->fixtures['nodeInfo']);

        $node = app(NodeRegistryContract::class)->nodeInfo('0x1111111111111111111111111111111111111111');

        $this->assertNotNull($node);
        $this->assertSame('0x1111111111111111111111111111111111111111', $node['operator']);
        $this->assertSame(2, $node['tier_id']);
        $this->assertSame('Light', $node['tier']);
        $this->assertSame('active', $node['status']);
        $this->assertSame('10000', $node['stake_amount']);
        $this->assertSame('10000000000000000000000', $node['stake_amount_wei']);
        $this->assertSame(1700000000, $node['registered_at']);
        $this->assertSame(1700604800, $node['unlock_at']);
        $this->assertSame(10000, $node['uptime']);
        $this->assertSame('0x'.str_repeat('ab', 32), $node['node_id']);
        $this->assertSame('203.0.113.10:8545', $node['endpoint']);
        $this->assertSame('3', $node['pending_unclaimed_wei']);
    }

    public function test_decodes_an_endpoint_longer_than_one_word(): void
    {
        // endpoint 58 ตัวอักษร = กิน 2 word — ถ้าตัวถอดอ่านแค่ word เดียวจะได้สตริงขาด
        $this->fakeCall($this->fixtures['longEndpoint']);

        $node = app(NodeRegistryContract::class)->nodeInfo('0x2222222222222222222222222222222222222222');

        $this->assertNotNull($node);
        $this->assertSame('a-very-long-node-endpoint-name.validators.tpix.online:30303', $node['endpoint']);
        $this->assertSame(3, $node['tier_id']);
        $this->assertSame('Validator', $node['tier']);
        $this->assertSame(9900, $node['uptime']);
    }

    public function test_decodes_real_network_stats(): void
    {
        $this->fakeCall($this->fixtures['netStats']);

        $stats = app(NodeRegistryContract::class)->networkStats();

        $this->assertSame('10000000000000000000000', $stats['total_staked_wei']);
        $this->assertSame(3, $stats['total_active_nodes']);
        $this->assertSame('0', $stats['total_rewards_distributed_wei']);
        $this->assertSame('1400000000000000000000000000', $stats['remaining_rewards_wei']);
        $this->assertSame('19', $stats['reward_per_second_wei']);
        $this->assertSame(0, $stats['current_year_index']);
    }

    public function test_decodes_real_tier_info(): void
    {
        $this->fakeCall($this->fixtures['tierInfo']);

        $tier = app(NodeRegistryContract::class)->tierInfo(2);

        $this->assertSame('Light', $tier['name']);
        $this->assertSame('10000', $tier['min_stake']);
        $this->assertSame(0, $tier['max_nodes'], 'Light ไม่จำกัดจำนวน (0 = unlimited)');
        $this->assertSame(7, $tier['active_nodes']);
        $this->assertSame(7, $tier['lock_days']);
        $this->assertSame(1500, $tier['reward_share'], 'ส่วนแบ่ง 15% เก็บเป็น basis points');
    }

    public function test_decodes_real_reward_pool_status(): void
    {
        $this->fakeCall($this->fixtures['poolStatus']);

        $pool = app(NodeRegistryContract::class)->rewardPoolStatus();

        $this->assertSame('123000000000000000000', $pool['funded_balance_wei']);
        $this->assertSame('456000000000000000000', $pool['total_funded_wei']);
        $this->assertSame('7000000000000000000', $pool['distributed_wei']);
        $this->assertSame('1400000000000000000000000000', $pool['schedule_cap_wei']);
    }

    public function test_zero_struct_means_never_registered(): void
    {
        // struct ศูนย์ทั้งก้อน — ห้ามตีความว่า "tier 0 = Guardian" ที่ active อยู่
        $zero = '0x'.str_pad('20', 64, '0', STR_PAD_LEFT).str_repeat(str_repeat('0', 64), 13);
        $this->fakeCall($zero);

        $this->assertNull(
            app(NodeRegistryContract::class)->nodeInfo('0x3333333333333333333333333333333333333333')
        );
    }
}
