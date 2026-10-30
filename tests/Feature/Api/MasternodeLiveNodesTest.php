<?php

namespace Tests\Feature\Api;

use App\Models\MasternodeNode;
use App\Models\MasternodeUptimePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/v1/masternode/live-nodes — สาธารณะ: ประเทศ/เมืองเท่านั้น.
 */
class MasternodeLiveNodesTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_recent_nodes_without_ip_wallet_or_peer_id(): void
    {
        MasternodeNode::create([
            'wallet_address' => '0x90f8bf6a479f320ead074411a4b0e7944ea8c9c1',
            'peer_id' => '16Uiu2HAmLhLvBoYaoZfaMUKuibM6ac163GwKY74c5kiSLg5KvLpY',
            'node_address' => '0x8b805bf52e9ce863375c5105e1158d519537f5b3',
            'tier' => 'Sentinel',
            'last_seen_at' => now()->subMinute(),
            'last_check' => 'ok',
            'country_code' => 'TH',
            'country_name' => 'Thailand',
            'city' => 'Bangkok',
            'latitude' => 13.8,
            'longitude' => 100.5,
            'geo_ip_hash' => str_repeat('a', 64),
        ]);
        MasternodeNode::create([
            'wallet_address' => '0x22d491bde2303f2f43325b2108d26f1eaba1e32b',
            'peer_id' => '16Uiu2HAm5SczivB6BNkSBw9svc1ejopedyTYvoT6Brw9QkDudayt',
            'node_address' => '0xffcf8fdee72ac11b5c542428b35eef5769c409f0',
            'tier' => 'Light',
            'last_seen_at' => now()->subHours(3),
            'last_check' => 'stale',
        ]);
        MasternodeNode::create([
            'wallet_address' => '0x0000000000000000000000000000000000000001',
            'peer_id' => '16Uiu2HAmJW8ZxNsu5VokvvGHK7HLYbK5AzF7EobHc9xivZeZh7XY',
            'node_address' => '0x22d491bde2303f2f43325b2108d26f1eaba1e32b',
            'last_seen_at' => now()->subDays(3),
        ]);
        foreach ([9000, 10000] as $i => $bps) {
            MasternodeUptimePeriod::create([
                'wallet_address' => '0x90f8bf6a479f320ead074411a4b0e7944ea8c9c1',
                'period_start' => now()->subHours($i + 1)->startOfHour()->timestamp,
                'slots_expected' => 12, 'slots_ok' => 12, 'uptime_bps' => $bps,
                'target_bps' => $bps, 'status' => 'shadow',
            ]);
        }

        $res = $this->getJson('/api/v1/masternode/live-nodes');

        $res->assertOk()
            ->assertJsonPath('data.online', 1)
            ->assertJsonPath('data.countries', 1)
            ->assertJsonCount(2, 'data.nodes')
            ->assertJsonPath('data.nodes.0.city', 'Bangkok')
            ->assertJsonPath('data.nodes.0.online', true)
            ->assertJsonPath('data.nodes.0.synced', true)
            ->assertJsonPath('data.nodes.0.uptime_24h_bps', 9500)
            ->assertJsonPath('data.nodes.1.online', false)
            ->assertJsonPath('data.nodes.1.uptime_24h_bps', null);

        $body = $res->getContent();
        $this->assertStringNotContainsString('0x90f8bf6a', $body);
        $this->assertStringNotContainsString('16Uiu2', $body);
        $this->assertStringNotContainsString(str_repeat('a', 64), $body);
        $this->assertArrayNotHasKey('ip', $res->json('data.nodes.0'));
    }
}
