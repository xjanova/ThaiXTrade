<?php

namespace Tests\Feature;

use App\Services\MasterNode\NodeRegistryContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * runtimeCodeHash / owner — ด่านของงาน uptime oracle ก่อนเซ็นอะไรเข้าสัญญา.
 *
 * keccak ต้องคิดแบบเดียวกับ ethers keccak256(code) ที่แอป/กระเป๋าใช้ปัก hash
 * (เช็กกับสัญญาจริง 0x44c4…F70E เมื่อ 2026-10-22: 0x1c49f5e7…16fb ตรงกับที่ปักไว้)
 */
class MasterNodeRegistryCodeHashTest extends TestCase
{
    use RefreshDatabase;

    private const REGISTRY = '0x44c497C67B4F756a7769F11c8BEc26439C31F70E';

    protected function setUp(): void
    {
        parent::setUp();
        config(['masternode.registry.address' => self::REGISTRY, 'masternode.registry.rpc_url' => 'https://rpc.test']);
    }

    public function test_code_hash_matches_ethers_keccak256_of_the_bytecode(): void
    {
        Http::fake(['rpc.test*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x608060405234801561001057600080fd5b50'])]);

        // ethers: keccak256('0x608060405234801561001057600080fd5b50')
        $this->assertSame(
            '0x725967456576a059bfb03e05bb4b594f603ce2617eaa298d674781e715397861',
            app(NodeRegistryContract::class)->runtimeCodeHash(),
        );
    }

    public function test_no_code_or_rpc_failure_is_null(): void
    {
        Http::fake(['rpc.test*' => Http::sequence()
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'])
            ->push('down', 502)]);
        $registry = app(NodeRegistryContract::class);

        $this->assertNull($registry->runtimeCodeHash());
        $this->assertNull($registry->runtimeCodeHash());
    }

    public function test_owner_is_read_from_the_contract(): void
    {
        Http::fake(['rpc.test*' => Http::response([
            'jsonrpc' => '2.0', 'id' => 1,
            'result' => '0x000000000000000000000000d2eab07809921fcb36c7ab72d7b5d8d2c12a67d7',
        ])]);

        $this->assertSame('0xd2eab07809921fcb36c7ab72d7b5d8d2c12a67d7', app(NodeRegistryContract::class)->owner());
    }
}
