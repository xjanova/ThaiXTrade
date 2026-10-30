<?php

namespace Tests\Feature\Api;

use App\Models\MasternodeNode;
use App\Services\MasterNode\CloudflareWafService;
use App\Services\MasterNode\GeoIpService;
use Elliptic\EC;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use kornrunner\Keccak;
use Mockery;
use Tests\TestCase;

/**
 * POST /api/v1/node/heartbeat รุ่น 2 — ใช้วัด uptime ป้อน uptimeOracle.
 *
 * กุญแจโหนดในเทสต์คือเวกเตอร์ secp256k1 ของสเปก libp2p — peer ID คิดแยกด้วย ethers
 */
class NodeHeartbeatV2Test extends TestCase
{
    use RefreshDatabase;

    private const WALLET_PRIV = '4f3edf983ac636a65a842ce7c78d9aa706d3b113bce9c46f30d7d21715b23b1d';
    private const WALLET = '0x90f8bf6a479f320ead074411a4b0e7944ea8c9c1';

    private const WALLET2_PRIV = '6370fd033278c143179d81c5526140625662b8daa446c22ee2d73db3707e620c';
    private const WALLET2 = '0x22d491bde2303f2f43325b2108d26f1eaba1e32b';

    // libp2p spec key → peer ID / ที่อยู่ (ethers)
    private const NODE_PRIV = '53dadf1d5a164d6b4acdb15e24aa4c5b1d3461bdbd42abedb0a4404d56ced8fb';
    private const NODE_PEER = '16Uiu2HAmLhLvBoYaoZfaMUKuibM6ac163GwKY74c5kiSLg5KvLpY';
    private const NODE_ADDR = '0x8b805bf52e9ce863375c5105e1158d519537f5b3';

    private const NODE2_PRIV = '6cbed15c793ce57650b9877cf6fa156fbef513c4e6134f022a85b1ffdd59b2a1';
    private const NODE2_PEER = '16Uiu2HAm5SczivB6BNkSBw9svc1ejopedyTYvoT6Brw9QkDudayt';
    private const NODE2_ADDR = '0xffcf8fdee72ac11b5c542428b35eef5769c409f0';

    private const HEAD = 2_800_000;

    private string $canonicalHash;

    private bool $rpcDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);

        $cf = Mockery::mock(CloudflareWafService::class);
        $cf->shouldReceive('addAllowRule')->andReturn(['id' => 'rule-1']);
        $cf->shouldReceive('deleteRule')->andReturn(true);
        $this->app->instance(CloudflareWafService::class, $cf);

        config([
            'masternode.genesis_operators' => [self::WALLET => 'Sentinel', self::WALLET2 => 'Light'],
            'masternode.registry.rpc_url' => 'https://rpc.test',
            'masternode.uptime.max_block_lag' => 60,
        ]);

        $this->canonicalHash = '0x'.hash('sha256', 'block');
        Http::fake(['rpc.test*' => function ($request) {
            if ($this->rpcDown) {
                return Http::response('down', 502);
            }

            return match ($request->data()['method']) {
                'eth_blockNumber' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x'.dechex(self::HEAD)]),
                'eth_getBlockByNumber' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => [
                    'number' => $request->data()['params'][0],
                    'hash' => $this->canonicalHash,
                ]]),
                default => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['message' => 'nope']]),
            };
        }]);
    }

    public function test_synced_node_is_recorded_for_the_current_slot(): void
    {
        $res = $this->postJson('/api/v1/node/heartbeat', $this->payload());

        $res->assertOk()
            ->assertJsonPath('uptime.check', 'ok')
            ->assertJsonPath('uptime.block_ok', true)
            ->assertJsonPath('uptime.block_lag', 2);

        $this->assertDatabaseHas('masternode_nodes', [
            'wallet_address' => self::WALLET,
            'peer_id' => self::NODE_PEER,
            'node_address' => self::NODE_ADDR,
            'tier' => 'Sentinel',
            'last_check' => 'ok',
        ]);
        $this->assertDatabaseHas('masternode_uptime_slots', [
            'wallet_address' => self::WALLET,
            'slot' => intdiv(now()->timestamp, 300),
            'peer_id' => self::NODE_PEER,
            'block_ok' => true,
        ]);
    }

    public function test_renewal_after_the_minimum_interval_is_accepted(): void
    {
        // regression: Carbon 3 diffInSeconds ติดลบ → ครั้งที่ 2 เป็นต้นไปเคยโดน 429 ตลอด
        $this->postJson('/api/v1/node/heartbeat', $this->payload())->assertOk();

        $this->travel(30)->seconds();
        $this->postJson('/api/v1/node/heartbeat', $this->payload())->assertStatus(429);

        $this->travel(31)->seconds();
        $this->postJson('/api/v1/node/heartbeat', $this->payload())->assertOk();
    }

    public function test_wrong_block_hash_is_accepted_but_not_credited(): void
    {
        $res = $this->postJson('/api/v1/node/heartbeat', $this->payload(hash: '0x'.str_repeat('ab', 32)));

        $res->assertOk()->assertJsonPath('uptime.check', 'mismatch')->assertJsonPath('uptime.block_ok', false);
        $this->assertDatabaseHas('masternode_uptime_slots', ['wallet_address' => self::WALLET, 'block_ok' => false]);
    }

    public function test_lagging_node_is_stale(): void
    {
        $res = $this->postJson('/api/v1/node/heartbeat', $this->payload(block: self::HEAD - 500));

        $res->assertOk()->assertJsonPath('uptime.check', 'stale')->assertJsonPath('uptime.block_lag', 500);
    }

    public function test_block_far_ahead_of_the_chain_is_rejected_as_fabricated(): void
    {
        $res = $this->postJson('/api/v1/node/heartbeat', $this->payload(block: self::HEAD + 50));

        $res->assertOk()->assertJsonPath('uptime.check', 'ahead')->assertJsonPath('uptime.block_ok', false);
    }

    public function test_our_rpc_outage_is_unverified_not_a_failure(): void
    {
        $this->rpcDown = true;

        $res = $this->postJson('/api/v1/node/heartbeat', $this->payload());

        $res->assertOk()->assertJsonPath('uptime.check', 'unverified')->assertJsonPath('uptime.block_ok', null);
    }

    public function test_delegate_must_be_the_key_of_the_peer_id(): void
    {
        // ลงนามด้วยกุญแจตัวที่ 2 แต่อ้าง peer ID ของตัวที่ 1
        $p = $this->payload(nodePriv: self::NODE2_PRIV, nodeAddr: self::NODE2_ADDR);

        $this->postJson('/api/v1/node/heartbeat', $p)
            ->assertStatus(401)
            ->assertJsonFragment(['error' => 'node_key_mismatch']);
    }

    public function test_block_numbers_are_covered_by_the_signature(): void
    {
        $p = $this->payload();
        $p['block_number'] = self::HEAD - 1; // แก้หลังลงนาม

        $this->postJson('/api/v1/node/heartbeat', $p)
            ->assertStatus(401)
            ->assertJsonFragment(['error' => 'invalid_heartbeat_signature']);
        $this->assertDatabaseCount('masternode_uptime_slots', 0);
    }

    public function test_one_node_key_cannot_report_for_two_wallets(): void
    {
        $this->postJson('/api/v1/node/heartbeat', $this->payload())->assertOk();

        $this->postJson('/api/v1/node/heartbeat', $this->payload(walletPriv: self::WALLET2_PRIV, wallet: self::WALLET2))
            ->assertStatus(409)
            ->assertJsonFragment(['error' => 'peer_bound_to_other_wallet']);
    }

    public function test_node_key_can_move_to_another_wallet_after_a_day_of_silence(): void
    {
        $this->postJson('/api/v1/node/heartbeat', $this->payload())->assertOk();
        MasternodeNode::where('wallet_address', self::WALLET)->update(['last_seen_at' => now()->subDays(2)]);

        $this->postJson('/api/v1/node/heartbeat', $this->payload(walletPriv: self::WALLET2_PRIV, wallet: self::WALLET2))
            ->assertOk();

        $this->assertSame(self::WALLET2, MasternodeNode::where('peer_id', self::NODE_PEER)->value('wallet_address'));
    }

    public function test_newer_delegation_moves_the_wallet_to_a_new_machine_and_locks_out_the_old_one(): void
    {
        $this->postJson('/api/v1/node/heartbeat', $this->payload(expiresIn: 100 * 86400))->assertOk();

        // เครื่องใหม่ มอบอำนาจใหม่กว่า (หมดอายุทีหลัง) → ย้ายได้
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/node/heartbeat', $this->payload(
            nodePriv: self::NODE2_PRIV,
            nodeAddr: self::NODE2_ADDR,
            peer: self::NODE2_PEER,
            expiresIn: 150 * 86400,
        ))->assertOk();
        $this->assertSame(self::NODE2_PEER, MasternodeNode::where('wallet_address', self::WALLET)->value('peer_id'));

        // เครื่องเก่ายังเปิดอยู่ ถือมอบอำนาจที่เก่ากว่า → ไม่ได้
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/node/heartbeat', $this->payload(expiresIn: 100 * 86400))
            ->assertStatus(409)
            ->assertJsonFragment(['error' => 'stale_delegation']);
    }

    public function test_wallet_can_move_to_a_new_machine_with_a_shorter_delegation_once_the_old_one_is_silent(): void
    {
        $this->postJson('/api/v1/node/heartbeat', $this->payload(expiresIn: 150 * 86400))->assertOk();

        // เว็บลดเพดานเหลือ 30 วัน → เครื่องใหม่ได้วันหมดอายุที่เก่ากว่า แต่เครื่องเก่าเงียบไปแล้ว 2 วัน
        MasternodeNode::where('wallet_address', self::WALLET)->update(['last_seen_at' => now()->subDays(2)]);
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/node/heartbeat', $this->payload(
            nodePriv: self::NODE2_PRIV,
            nodeAddr: self::NODE2_ADDR,
            peer: self::NODE2_PEER,
            expiresIn: 30 * 86400,
        ))->assertOk();

        $this->assertSame(self::NODE2_PEER, MasternodeNode::where('wallet_address', self::WALLET)->value('peer_id'));
    }

    public function test_country_and_city_are_stored_without_the_ip(): void
    {
        $this->app->instance(GeoIpService::class, new GeoIpService(fn () => [
            'country' => ['iso_code' => 'TH', 'names' => ['en' => 'Thailand']],
            'city' => ['names' => ['en' => 'Chiang Mai']],
            'location' => ['latitude' => 18.79, 'longitude' => 98.98],
        ]));

        $res = $this->withServerVariables(['REMOTE_ADDR' => '49.228.10.20'])
            ->postJson('/api/v1/node/heartbeat', $this->payload());

        $res->assertOk()->assertJsonPath('location.city', 'Chiang Mai');
        $node = MasternodeNode::where('wallet_address', self::WALLET)->first();
        $this->assertSame('TH', $node->country_code);
        $this->assertSame(18.8, $node->latitude);
        $this->assertNotSame('49.228.10.20', $node->geo_ip_hash);
        $this->assertStringNotContainsString('49.228.10.20', json_encode(DB::table('masternode_nodes')->get()));
    }

    public function test_slot_counts_as_synced_if_any_heartbeat_in_it_was(): void
    {
        Cache::flush();
        $this->postJson('/api/v1/node/heartbeat', $this->payload(hash: '0x'.str_repeat('ab', 32)))->assertOk();
        $this->travel(61)->seconds();
        // ยังอยู่ช่องเดิมได้หรือไม่ขึ้นกับวินาทีตอนรัน — บังคับให้เป็นช่องเดียวกันด้วยการย้อนแถวแรก
        DB::table('masternode_uptime_slots')->update(['slot' => intdiv(now()->timestamp, 300)]);
        $this->postJson('/api/v1/node/heartbeat', $this->payload())->assertOk();

        $row = DB::table('masternode_uptime_slots')->where('wallet_address', self::WALLET)->first();
        $this->assertEquals(1, $row->block_ok);
        $this->assertEquals(2, $row->heartbeats);
    }

    private function payload(
        ?int $block = null,
        ?string $hash = null,
        string $walletPriv = self::WALLET_PRIV,
        string $wallet = self::WALLET,
        string $nodePriv = self::NODE_PRIV,
        string $nodeAddr = self::NODE_ADDR,
        string $peer = self::NODE_PEER,
        int $expiresIn = 90 * 86400,
    ): array {
        $block ??= self::HEAD - 2;
        $hash ??= $this->canonicalHash;
        $expires = now()->timestamp + $expiresIn;
        $ts = now()->timestamp;

        $delegation = sprintf('tpix-masternode-delegate:%s:%s:%d', $wallet, $nodeAddr, $expires);
        $message = sprintf('tpix-masternode-heartbeat:v2:%s:%d:%s:%d:%s', $wallet, $ts, $peer, $block, $hash);

        return [
            'wallet' => $wallet,
            'delegate_address' => $nodeAddr,
            'delegation_signature' => $this->sign($delegation, $walletPriv),
            'delegation_expires_at' => $expires,
            'timestamp' => $ts,
            'peer_id' => $peer,
            'block_number' => $block,
            'block_hash' => $hash,
            'app_version' => '1.15.0',
            'peer_count' => 3,
            'signature' => $this->sign($message, $nodePriv),
        ];
    }

    private function sign(string $message, string $privHex): string
    {
        $hash = Keccak::hash("\x19Ethereum Signed Message:\n".strlen($message).$message, 256);
        $sig = (new EC('secp256k1'))->keyFromPrivate($privHex)->sign($hash, ['canonical' => true]);

        return '0x'.str_pad($sig->r->toString('hex'), 64, '0', STR_PAD_LEFT)
            .str_pad($sig->s->toString('hex'), 64, '0', STR_PAD_LEFT)
            .sprintf('%02x', 27 + ($sig->recoveryParam ?? 0));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
