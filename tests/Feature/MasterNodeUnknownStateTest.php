<?php

namespace Tests\Feature;

use App\Services\MasterNode\NodeRegistryContract;
use App\Services\MasterNode\NodeRegistryService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "อ่านเชนไม่ได้" ต้องไม่ถูกตีความเป็น "ยังไม่ deploy" หรือ "เลขศูนย์".
 *
 * บั๊กที่เทสต์ชุดนี้กัน:
 *   1. isDeployed() cache ผล RPC ล้มเป็น false ไว้ 5 นาที → หน้าเว็บขึ้น "สัญญายังไม่ติดตั้ง"
 *      และ /config ตอบ registry_live=false ทั้งที่สัญญาอยู่บนเชนจริง
 *   2. ช่วง 5 นาทีนั้น allowlist ตกไป fallback เช็ก balance → ใครถือเหรียญครบขั้นต่ำ
 *      (ไม่ได้วางค้ำ) ก็ได้ IP เข้า Cloudflare allowlist
 *   3. stats อ่านเชนไม่ได้แต่ส่ง "0 โหนด / พูล 0" ออกไปเหมือนอ่านมาแล้ว
 */
class MasterNodeUnknownStateTest extends TestCase
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

    private function rpcResult(string $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'result' => $result];
    }

    private function uint(int $value): string
    {
        return str_pad(dechex($value), 64, '0', STR_PAD_LEFT);
    }

    public function test_rpc_failure_is_unknown_not_cached_as_not_deployed(): void
    {
        $rpcUp = false;
        Http::fake(function ($request) use (&$rpcUp) {
            if (! $rpcUp) {
                return Http::response('upstream down', 502);
            }

            return $this->rpcResult('0x60806040');
        });

        $registry = app(NodeRegistryContract::class);

        $this->assertNull($registry->deploymentState(), 'RPC ล้ม = ไม่ทราบ ไม่ใช่ "ยังไม่ deploy"');
        $this->assertFalse($registry->isDeployed());

        // RPC กลับมาแล้ว — ต้องไม่ติดค้าง "ไม่ได้ deploy" ไปอีก 5 นาที
        $rpcUp = true;
        $this->travel(16)->seconds();

        $this->assertTrue($registry->deploymentState());
        $this->assertTrue($registry->isDeployed());
    }

    public function test_missing_bytecode_is_still_a_definite_false(): void
    {
        Http::fake(['*' => Http::response($this->rpcResult('0x'))]);

        $this->assertFalse(app(NodeRegistryContract::class)->deploymentState());
    }

    public function test_allowlist_fails_closed_when_the_registry_state_is_unknown(): void
    {
        // eth_getCode ล้ม แต่ eth_getBalance ตอบได้และยอดมหาศาล
        // เดิม: isDeployed() = false → fallback balance → ได้ Guardian ทั้งที่ไม่ได้วางค้ำ
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);

            return match ($body['method']) {
                'eth_getCode' => Http::response('upstream down', 502),
                'eth_getBalance' => $this->rpcResult('0x33b2e3c9fd0803ce8000000'),
                default => $this->rpcResult('0x'),
            };
        });

        $this->assertNull(
            app(NodeRegistryService::class)->lookup(self::OPERATOR),
            'ตั้งที่อยู่สัญญาไว้แต่อ่านเชนไม่ได้ = ปฏิเสธ ห้ามตกไปเช็ก balance'
        );
    }

    public function test_config_api_reports_unknown_as_null(): void
    {
        Http::fake(['*' => Http::response('upstream down', 502)]);

        $this->getJson('/api/v1/masternode/config')
            ->assertOk()
            ->assertJsonPath('data.registry_address', self::REGISTRY)
            ->assertJsonPath('data.registry_live', null)
            ->assertJsonPath('data.registration_paused', null)
            ->assertJsonPath('data.chain_id', 4289);
    }

    public function test_config_api_shape_matches_what_the_desktop_app_parses(): void
    {
        // electron/registry-discovery.js ใน TPIX-Masternode: success === true, data.chain_id = 4289,
        // data.registry_address, data.registry_live === true, data.registration_paused (bool), data.kyc_contract
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);

            return match ($body['method']) {
                'eth_getCode' => $this->rpcResult('0x60806040'),
                // registrationPaused() = false, kycContract() = 0x0 → null
                default => $this->rpcResult('0x'.$this->uint(0)),
            };
        });

        $this->getJson('/api/v1/masternode/config')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.chain_id', 4289)
            ->assertJsonPath('data.registry_address', self::REGISTRY)
            ->assertJsonPath('data.registry_live', true)
            ->assertJsonPath('data.registration_paused', false)
            ->assertJsonPath('data.kyc_contract', null);
    }

    public function test_stats_flag_numbers_that_were_not_read_from_chain(): void
    {
        // สัญญามีโค้ด แต่ eth_call ทุกตัวล้ม → ตัวเลขทั้งหมดเป็นค่าตั้งต้น ต้องติดธงบอก
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);

            return match ($body['method']) {
                'eth_blockNumber' => $this->rpcResult('0x2a'),
                'eth_getCode' => $this->rpcResult('0x60806040'),
                default => Http::response('upstream down', 502),
            };
        });

        $this->getJson('/api/v1/masternode/stats')
            ->assertOk()
            ->assertJsonPath('data.registry_deployed', true)
            ->assertJsonPath('data.rpc_connected', true)
            ->assertJsonPath('data.stats_available', false)
            ->assertJsonPath('data.reward_pool_known', false);
    }

    public function test_stats_read_from_chain_report_an_unfunded_pool_as_known_zero(): void
    {
        // สถานะจริงวันนี้: สัญญา deploy แล้ว ไม่มีโหนด พูลรางวัลยังไม่ถูกเติมเงิน
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);

            return match ($body['method']) {
                'eth_blockNumber' => $this->rpcResult('0x2a'),
                'eth_getCode' => $this->rpcResult('0x60806040'),
                default => $this->rpcResult('0x'.str_repeat($this->uint(0), 6)),
            };
        });

        $this->getJson('/api/v1/masternode/stats')
            ->assertOk()
            ->assertJsonPath('data.stats_available', true)
            ->assertJsonPath('data.reward_pool_known', true)
            ->assertJsonPath('data.reward_pool_available', '0')
            ->assertJsonPath('data.total_nodes', 0);
    }
}
