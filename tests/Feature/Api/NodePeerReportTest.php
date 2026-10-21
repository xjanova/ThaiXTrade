<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * POST /api/infra/node-peers — bootnode รายงาน peer ที่ต่ออยู่ (หลักฐานว่ารันโหนดจริง).
 */
class NodePeerReportTest extends TestCase
{
    use RefreshDatabase;

    private const PEER_A = '16Uiu2HAmLhLvBoYaoZfaMUKuibM6ac163GwKY74c5kiSLg5KvLpY';
    private const PEER_B = '16Uiu2HAm5SczivB6BNkSBw9svc1ejopedyTYvoT6Brw9QkDudayt';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['masternode.node_peers_token' => 'peers-secret']);
    }

    public function test_disabled_without_a_token(): void
    {
        config(['masternode.node_peers_token' => null]);

        $this->postJson('/api/infra/node-peers', ['source' => 'tpix-bootnode', 'peers' => []])
            ->assertStatus(503);
    }

    public function test_rejects_wrong_token(): void
    {
        $this->withToken('nope')
            ->postJson('/api/infra/node-peers', ['source' => 'tpix-bootnode', 'peers' => [self::PEER_A]])
            ->assertStatus(401);

        $this->assertDatabaseCount('masternode_peer_sightings', 0);
    }

    public function test_records_valid_peers_for_the_current_slot_and_drops_junk(): void
    {
        $res = $this->withToken('peers-secret')->postJson('/api/infra/node-peers', [
            'source' => 'tpix-bootnode',
            'peers' => [self::PEER_A, self::PEER_B, self::PEER_A, '12D3KooWD3eckifWpRn9wQpMG9R9hX3sD158z7EqHWmweQAJU5SA', 'junk'],
        ]);

        $slot = intdiv(now()->timestamp, 300);
        $res->assertOk()->assertJson(['ok' => true, 'slot' => $slot, 'accepted' => 2]);
        $this->assertDatabaseCount('masternode_peer_sightings', 2);
        $this->assertDatabaseHas('masternode_peer_reports', ['source' => 'tpix-bootnode', 'slot' => $slot, 'reports' => 1, 'peers' => 2]);
    }

    public function test_repeated_reports_in_one_slot_count_minutes_not_rows(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->withToken('peers-secret')
                ->postJson('/api/infra/node-peers', ['source' => 'tpix-bootnode', 'peers' => [self::PEER_A]])
                ->assertOk();
        }

        $this->assertDatabaseCount('masternode_peer_sightings', 1);
        $this->assertDatabaseHas('masternode_peer_reports', ['source' => 'tpix-bootnode', 'reports' => 3]);
    }

    public function test_empty_list_still_counts_as_a_report(): void
    {
        // bootnode ทำงานแต่ไม่มีใครต่อ = ข้อมูลครบ (ทุกคนได้ 0 อย่างถูกต้อง) ไม่ใช่ "ข้อมูลขาด"
        $this->withToken('peers-secret')
            ->postJson('/api/infra/node-peers', ['source' => 'tpix-bootnode', 'peers' => []])
            ->assertOk();

        $this->assertDatabaseHas('masternode_peer_reports', ['source' => 'tpix-bootnode', 'reports' => 1, 'peers' => 0]);
    }
}
