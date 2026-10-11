<?php

namespace Tests\Feature\Bridge\Wtpix;

use App\Models\SiteSetting;
use App\Models\WtpixBridgeTransfer;
use App\Services\Bridge\Abi;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesWtpixBridgeChains;
use Tests\TestCase;

/**
 * API สาธารณะของสะพาน (asset=tpix) — อ่านอย่างเดียว · ตรวจทุกพารามิเตอร์ · ตอบ {success, data|error}
 * และไม่เปลี่ยนพฤติกรรมเดิมของ USDT (ไม่ส่ง asset = usdt).
 */
class WtpixBridgeApiTest extends TestCase
{
    use FakesWtpixBridgeChains;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWtpixBridge();
    }

    #[Test]
    public function info_lists_both_chains_contracts_limits_caps_and_the_token_logo(): void
    {
        $this->getJson('/api/v1/bridge/info?asset=tpix')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.asset', 'tpix')
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.tpix.chain_id', 4289)
            ->assertJsonPath('data.tpix.vault_address', $this->wVault)
            ->assertJsonPath('data.bsc.chain_id', 56)
            ->assertJsonPath('data.bsc.wtpix_address', $this->wToken)
            ->assertJsonPath('data.bsc.minter_address', $this->wMinter)
            ->assertJsonPath('data.token.symbol', 'wTPIX')
            ->assertJsonPath('data.token.decimals', 18)
            ->assertJsonPath('data.token.logo', 'https://tpix.online/images/brand/tpix-coin.png?v=2')
            ->assertJsonPath('data.limits.min_lock', '10')
            ->assertJsonPath('data.limits.lock_fee', '1')
            ->assertJsonPath('data.vault.max_lock', '100000')
            ->assertJsonPath('data.vault.paused', false)
            ->assertJsonPath('data.minter.daily_cap', '500000');
    }

    #[Test]
    public function info_reports_closed_while_the_vault_is_paused_or_the_bridge_is_off(): void
    {
        $this->chain[4289]['vault']['paused'] = true;
        $this->getJson('/api/v1/bridge/info?asset=tpix')->assertJsonPath('data.enabled', false);

        $this->chain[4289]['vault']['paused'] = false;
        config(['wtpix_bridge.enabled' => false]);
        Cache::flush();
        $this->getJson('/api/v1/bridge/info?asset=tpix')->assertJsonPath('data.enabled', false)->assertJsonPath('data.installed', false);
    }

    #[Test]
    public function the_vault_registry_disagreeing_with_env_closes_the_bridge(): void
    {
        SiteSetting::set('contracts', 'wtpix_bridge_vault', '0x7a170000000000000000000000000000000000ee');
        Cache::flush();

        $this->getJson('/api/v1/bridge/info?asset=tpix')->assertJsonPath('data.enabled', false);
    }

    #[Test]
    public function an_unknown_asset_is_rejected_on_every_endpoint(): void
    {
        $hash = '0x'.str_repeat('a', 64);

        foreach ([
            '/api/v1/bridge/info?asset=btc',
            "/api/v1/bridge/status/4289/{$hash}?asset=TPIX",
            "/api/v1/bridge/withdrawals/{$hash}?asset=eth",
            '/api/v1/bridge/history/'.$this->alice.'?asset=x',
            '/api/v1/bridge/reserves?asset[]=tpix',
        ] as $url) {
            $this->getJson($url)->assertStatus(422)->assertJsonPath('success', false)->assertJsonPath('error.code', 'INVALID_ASSET');
        }
    }

    #[Test]
    public function status_validates_chain_and_hash_for_the_tpix_asset(): void
    {
        $hash = '0x'.str_repeat('a', 64);

        // เชน USDT อื่น ๆ (เช่น Ethereum) ไม่ใช่ต้นทางของสะพานนี้
        $this->getJson("/api/v1/bridge/status/1/{$hash}?asset=tpix")->assertStatus(422)->assertJsonPath('error.code', 'UNSUPPORTED_CHAIN');
        $this->getJson("/api/v1/bridge/status/99999999999999999999/{$hash}?asset=tpix")->assertStatus(422)->assertJsonPath('error.code', 'UNSUPPORTED_CHAIN');
        $this->getJson('/api/v1/bridge/status/4289/0x1234?asset=tpix')->assertStatus(422)->assertJsonPath('error.code', 'INVALID_TX_HASH');
        $this->getJson('/api/v1/bridge/history/0x1234?asset=tpix')->assertStatus(422)->assertJsonPath('error.code', 'INVALID_ADDRESS');
    }

    #[Test]
    public function status_of_a_recorded_lock_links_to_the_mint_on_bsc(): void
    {
        $tx = '0x'.str_repeat('c', 64);
        WtpixBridgeTransfer::create([
            'direction' => WtpixBridgeTransfer::TO_BSC, 'source_chain_id' => 4289, 'source_contract' => $this->wVault,
            'tx_hash' => $tx, 'log_index' => 0, 'block_number' => 1990, 'from_address' => $this->alice, 'recipient' => $this->alice,
            'amount_wei' => $this->tpix('100'), 'fee_wei' => $this->tpix('1'), 'payout_wei' => $this->tpix('99'),
            'deposit_id' => Abi::depositId(4289, $tx, 0), 'status' => WtpixBridgeTransfer::STATUS_COMPLETED,
            'dest_chain_id' => 56, 'dest_contract' => $this->wMinter, 'dest_tx_hash' => '0x'.str_repeat('d', 64),
            'dest_raw_tx' => '0xf86c-secretish-raw',
        ]);

        $response = $this->getJson("/api/v1/bridge/status/4289/{$tx}?asset=tpix")
            ->assertOk()
            ->assertJsonPath('data.state', 'minted')
            ->assertJsonPath('data.direction', 'to_bsc')
            ->assertJsonPath('data.transfers.0.receive_amount', '99')
            ->assertJsonPath('data.transfers.0.dest_url', 'https://bscscan.com/tx/0x'.str_repeat('d', 64));

        $this->assertStringNotContainsString('secretish', $response->getContent(), 'raw tx never leaves the server');
    }

    #[Test]
    public function status_of_an_unscanned_lock_and_burn_is_read_live_from_the_chain(): void
    {
        $lock = $this->fakeLock($this->alice, $this->tpix('100'), 1999);
        $burn = $this->fakeBurnWtpix($this->alice, $this->tpix('40'), 4995);

        $this->getJson("/api/v1/bridge/status/4289/{$lock}?asset=tpix")
            ->assertOk()->assertJsonPath('data.state', 'confirming')->assertJsonPath('data.amount', '100')->assertJsonPath('data.final', false);

        $this->getJson("/api/v1/bridge/withdrawals/{$burn}?asset=tpix")
            ->assertOk()->assertJsonPath('data.state', 'confirming')->assertJsonPath('data.direction', 'to_tpix')->assertJsonPath('data.amount', '40');

        $other = '0x'.str_repeat('9', 64);
        $this->getJson("/api/v1/bridge/status/56/{$other}?asset=tpix")->assertOk()->assertJsonPath('data.state', 'not_found');
    }

    #[Test]
    public function history_lists_both_directions_for_the_wallet(): void
    {
        foreach ([[WtpixBridgeTransfer::TO_BSC, 4289], [WtpixBridgeTransfer::TO_TPIX, 56]] as $i => [$direction, $chain]) {
            $tx = '0x'.str_repeat((string) ($i + 1), 64);
            WtpixBridgeTransfer::create([
                'direction' => $direction, 'source_chain_id' => $chain, 'source_contract' => $chain === 4289 ? $this->wVault : $this->wToken,
                'tx_hash' => $tx, 'log_index' => 0, 'block_number' => 10, 'from_address' => $this->alice, 'recipient' => $this->alice,
                'amount_wei' => $this->tpix('20'), 'payout_wei' => $this->tpix('20'), 'deposit_id' => Abi::depositId($chain, $tx, 0),
                'status' => WtpixBridgeTransfer::STATUS_CONFIRMED,
            ]);
        }

        $this->getJson('/api/v1/bridge/history/0x'.strtoupper(substr($this->alice, 2)).'?asset=tpix')
            ->assertOk()
            ->assertJsonCount(2, 'data.transfers');
    }

    #[Test]
    public function the_usdt_endpoints_behave_as_before_without_an_asset(): void
    {
        $this->getJson('/api/v1/bridge/info')->assertOk()->assertJsonMissingPath('data.asset')->assertJsonStructure(['data' => ['chains', 'vault_address']]);
        $this->getJson('/api/v1/bridge/info?asset=usdt')->assertOk()->assertJsonStructure(['data' => ['chains', 'vault_address']]);
    }

    #[Test]
    public function the_mobile_fee_config_keeps_the_old_bridge_screen_disabled_even_with_the_wtpix_bridge_on(): void
    {
        $this->getJson('/api/v1/fees')
            ->assertOk()
            ->assertJsonPath('bridge.enabled', false)
            ->assertJsonPath('bridge.feeWallet', '');
    }
}
