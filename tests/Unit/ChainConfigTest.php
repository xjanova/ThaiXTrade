<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ThaiXTrade - Chain Configuration Tests
 * Developed by Xman Studio.
 */
class ChainConfigTest extends TestCase
{
    private array $chains;

    protected function setUp(): void
    {
        parent::setUp();
        $configFile = dirname(__DIR__, 2).'/config/chains.php';
        $this->chains = require $configFile;
    }

    /**
     * Test chains config file exists.
     */
    public function test_chains_config_exists(): void
    {
        $configFile = dirname(__DIR__, 2).'/config/chains.php';
        $this->assertFileExists($configFile);
    }

    /**
     * Test chains config has required keys.
     */
    public function test_chains_config_has_required_keys(): void
    {
        $this->assertArrayHasKey('default', $this->chains);
        $this->assertArrayHasKey('chains', $this->chains);
    }

    /**
     * Test default chain is set.
     */
    public function test_default_chain_is_set(): void
    {
        $this->assertNotEmpty($this->chains['default']);
        $this->assertIsInt($this->chains['default']);
    }

    /**
     * Test BSC chain is configured.
     */
    public function test_bsc_chain_is_configured(): void
    {
        $this->assertArrayHasKey(56, $this->chains['chains']);

        $bsc = $this->chains['chains'][56];
        $this->assertEquals('BNB Smart Chain', $bsc['name']);
        $this->assertEquals('BSC', $bsc['shortName']);
        $this->assertEquals(56, $bsc['chainId']);
    }

    /**
     * Test Ethereum chain is configured.
     */
    public function test_ethereum_chain_is_configured(): void
    {
        $this->assertArrayHasKey(1, $this->chains['chains']);

        $eth = $this->chains['chains'][1];
        $this->assertEquals('Ethereum', $eth['name']);
        $this->assertEquals('ETH', $eth['shortName']);
        $this->assertEquals(1, $eth['chainId']);
    }

    /**
     * Test all chains have required fields.
     */
    public function test_all_chains_have_required_fields(): void
    {
        $requiredFields = ['name', 'shortName', 'chainId', 'rpc', 'explorer', 'nativeCurrency'];

        foreach ($this->chains['chains'] as $chainId => $chain) {
            foreach ($requiredFields as $field) {
                $this->assertArrayHasKey(
                    $field,
                    $chain,
                    "Chain {$chainId} is missing required field: {$field}"
                );
            }
        }
    }

    /**
     * Test all chains have valid RPC endpoints.
     */
    public function test_all_chains_have_valid_rpc(): void
    {
        foreach ($this->chains['chains'] as $chainId => $chain) {
            $this->assertIsArray($chain['rpc']);
            $this->assertNotEmpty($chain['rpc'], "Chain {$chainId} has no RPC endpoints");

            foreach ($chain['rpc'] as $rpc) {
                $this->assertMatchesRegularExpression(
                    '/^https?:\/\//',
                    $rpc,
                    "Chain {$chainId} has invalid RPC: {$rpc}"
                );
            }
        }
    }

    /**
     * Test native currency has required fields.
     */
    public function test_native_currency_has_required_fields(): void
    {
        foreach ($this->chains['chains'] as $chainId => $chain) {
            $this->assertArrayHasKey('name', $chain['nativeCurrency']);
            $this->assertArrayHasKey('symbol', $chain['nativeCurrency']);
            $this->assertArrayHasKey('decimals', $chain['nativeCurrency']);
            $this->assertEquals(18, $chain['nativeCurrency']['decimals']);
        }
    }

    /**
     * Test minimum chains count.
     */
    public function test_minimum_chains_configured(): void
    {
        $this->assertGreaterThanOrEqual(
            5,
            count($this->chains['chains']),
            'At least 5 chains should be configured'
        );
    }
}
