<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * TPIX TRADE - TPIX Chain Configuration Tests
 * ทดสอบ config TPIX Chain (Chain ID 4289) ในระบบ
 * Developed by Xman Studio.
 */
class TpixChainConfigTest extends TestCase
{
    private array $chains;

    protected function setUp(): void
    {
        parent::setUp();
        $configFile = dirname(__DIR__, 2).'/config/chains.php';
        $this->chains = require $configFile;
    }

    /**
     * ทดสอบว่า TPIX Chain (4289) ถูก config ไว้แล้ว.
     */
    public function test_tpix_chain_is_configured(): void
    {
        $this->assertArrayHasKey(4289, $this->chains['chains']);

        $tpix = $this->chains['chains'][4289];
        $this->assertEquals('TPIX Chain', $tpix['name']);
        $this->assertEquals('TPIX', $tpix['shortName']);
        $this->assertEquals(4289, $tpix['chainId']);
    }

    /**
     * ทดสอบ native currency ของ TPIX Chain.
     */
    public function test_tpix_native_currency(): void
    {
        $tpix = $this->chains['chains'][4289];

        $this->assertEquals('TPIX', $tpix['nativeCurrency']['name']);
        $this->assertEquals('TPIX', $tpix['nativeCurrency']['symbol']);
        $this->assertEquals(18, $tpix['nativeCurrency']['decimals']);
    }

    /**
     * ทดสอบว่า TPIX Chain มี RPC endpoint.
     */
    public function test_tpix_chain_has_rpc(): void
    {
        $tpix = $this->chains['chains'][4289];

        $this->assertIsArray($tpix['rpc']);
        $this->assertNotEmpty($tpix['rpc']);
    }

    /**
     * ทดสอบว่า TPIX Chain มี explorer URL.
     */
    public function test_tpix_chain_has_explorer(): void
    {
        $tpix = $this->chains['chains'][4289];

        $this->assertArrayHasKey('explorer', $tpix);
        $this->assertNotEmpty($tpix['explorer']);
    }

    /**
     * ทดสอบ TPIX Testnet (4290) ถ้ามี.
     */
    public function test_tpix_testnet_if_exists(): void
    {
        if (! isset($this->chains['testnets'][4290])) {
            $this->markTestSkipped('TPIX Testnet (4290) not configured.');
        }

        $testnet = $this->chains['testnets'][4290];
        $this->assertEquals(4290, $testnet['chainId']);
        $this->assertStringContainsString('Testnet', $testnet['name']);
    }
}
