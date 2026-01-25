<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * ThaiXTrade - Chain API Tests
 * Developed by Xman Studio
 */
class ChainApiTest extends TestCase
{
    /**
     * Test get all supported chains.
     */
    public function test_get_all_chains(): void
    {
        $response = $this->getJson('/api/v1/chains');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'success',
                     'data' => [
                         '*' => [
                             'chainId',
                             'name',
                             'shortName',
                             'nativeCurrency',
                         ]
                     ]
                 ]);
    }

    /**
     * Test get specific chain by ID.
     */
    public function test_get_chain_by_id(): void
    {
        $response = $this->getJson('/api/v1/chains/56'); // BSC

        $response->assertStatus(200)
                 ->assertJsonPath('data.chainId', 56)
                 ->assertJsonPath('data.shortName', 'BSC');
    }

    /**
     * Test get non-existent chain returns 404.
     */
    public function test_get_nonexistent_chain_returns_404(): void
    {
        $response = $this->getJson('/api/v1/chains/999999');

        $response->assertStatus(404);
    }
}
