<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/v1/masternode/config — ค่าที่แอปมาสเตอร์โหนดใช้ซิงก์เชนและมอบอำนาจกุญแจโหนด.
 */
class MasternodeConfigBootnodesTest extends TestCase
{
    use RefreshDatabase;

    private const BOOTNODE = '/ip4/123.253.62.252/tcp/30301/p2p/16Uiu2HAm5SczivB6BNkSBw9svc1ejopedyTYvoT6Brw9QkDudayt';

    public function test_publishes_only_well_formed_bootnodes_and_the_delegation_cap(): void
    {
        config([
            'masternode.registry.address' => null, // ยังไม่ deploy — ไม่ต้องถามเชน
            'masternode.public_bootnodes' => [
                self::BOOTNODE,
                '/dns4/bootnode.tpix.online/tcp/30301/p2p/16Uiu2HAm5SczivB6BNkSBw9svc1ejopedyTYvoT6Brw9QkDudayt',
                '/ip4/123.253.62.252/tcp/30301',
            ],
            'masternode.delegation.max_lifetime_seconds' => 15552000,
        ]);
        cache()->forget('masternode:config:public');

        $this->getJson('/api/v1/masternode/config')
            ->assertOk()
            ->assertJsonPath('data.bootnodes', [self::BOOTNODE])
            ->assertJsonPath('data.delegation_max_lifetime_seconds', 15552000);
    }

    public function test_no_bootnodes_until_the_owner_opens_one(): void
    {
        config(['masternode.registry.address' => null, 'masternode.public_bootnodes' => []]);
        cache()->forget('masternode:config:public');

        $this->getJson('/api/v1/masternode/config')->assertOk()->assertJsonPath('data.bootnodes', []);
    }
}
