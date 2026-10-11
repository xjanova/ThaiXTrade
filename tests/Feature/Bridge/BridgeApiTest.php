<?php

namespace Tests\Feature\Bridge;

use App\Models\BridgeDeposit;
use App\Models\BridgeReserveSnapshot;
use App\Models\BridgeWithdrawal;
use App\Services\Bridge\Abi;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesBridgeChains;
use Tests\TestCase;

/**
 * API สาธารณะของสะพาน — อ่านอย่างเดียว · ตรวจรูปแบบทุกพารามิเตอร์ · ตอบรูปแบบ {success, data|error}.
 */
class BridgeApiTest extends TestCase
{
    use FakesBridgeChains;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBridge();
    }

    #[Test]
    public function info_lists_chains_vault_limits_and_contract_addresses(): void
    {
        $this->getJson('/api/v1/bridge/info')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.vault_address', $this->vault)
            ->assertJsonPath('data.minter_address', $this->minter)
            ->assertJsonPath('data.tpix.usdt_tpix', $this->usdtTpix)
            ->assertJsonPath('data.limits.min_deposit', '1')
            ->assertJsonPath('data.minter.max_per_tx', '1000')
            ->assertJsonPath('data.chains.0.chain_id', 56)
            ->assertJsonPath('data.chains.0.usdt_decimals', 18)
            ->assertJsonPath('data.chains.0.withdraw_fee', '0.3')
            ->assertJsonPath('data.chains.1.withdraw_fee', '1');
    }

    #[Test]
    public function info_reports_the_bridge_closed_until_it_is_installed(): void
    {
        config(['bridge.enabled' => false]);
        Cache::flush();

        $this->getJson('/api/v1/bridge/info')->assertOk()->assertJsonPath('data.enabled', false);
    }

    #[Test]
    public function status_validates_the_chain_and_the_hash(): void
    {
        $this->getJson('/api/v1/bridge/status/999/0x'.str_repeat('a', 64))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'UNSUPPORTED_CHAIN');

        $this->getJson('/api/v1/bridge/status/56/0x1234')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_TX_HASH');

        $this->getJson('/api/v1/bridge/status/abc/0x'.str_repeat('a', 64))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'UNSUPPORTED_CHAIN');
    }

    #[Test]
    public function status_of_a_recorded_deposit_links_to_the_mint_transaction(): void
    {
        $tx = '0x'.str_repeat('c', 64);
        BridgeDeposit::create([
            'source_chain_id' => 56, 'tx_hash' => $tx, 'log_index' => 0, 'block_number' => 900,
            'from_address' => strtolower($this->alice), 'to_address' => $this->vault, 'token_address' => $this->usdtBsc,
            'token_decimals' => 18, 'amount_raw' => '25000000000000000000', 'amount_units' => '25000000',
            'mint_units' => '25000000', 'deposit_id' => Abi::depositId(56, $tx, 0),
            'status' => BridgeDeposit::STATUS_MINTED, 'mint_tx_hash' => '0x'.str_repeat('d', 64),
        ]);

        $this->getJson("/api/v1/bridge/status/56/{$tx}")
            ->assertOk()
            ->assertJsonPath('data.state', 'minted')
            ->assertJsonPath('data.deposits.0.amount', '25')
            ->assertJsonPath('data.deposits.0.mint_url', 'https://explorer.tpix.online/tx/0x'.str_repeat('d', 64))
            ->assertJsonMissingPath('data.deposits.0.mint_raw_tx');
    }

    #[Test]
    public function status_of_an_unscanned_deposit_is_read_live_from_the_chain(): void
    {
        // อยู่บล็อก 995 — ยังไม่ final (หัวปลอดภัย = 985)
        $tx = $this->fakeDeposit(56, $this->alice, '7000000000000000000', 995);

        $this->getJson("/api/v1/bridge/status/56/{$tx}")
            ->assertOk()
            ->assertJsonPath('data.state', 'confirming')
            ->assertJsonPath('data.amount', '7')
            ->assertJsonPath('data.final', false)
            ->assertJsonPath('data.confirmations', 6);

        $this->getJson('/api/v1/bridge/status/56/0x'.str_repeat('e', 64))
            ->assertOk()
            ->assertJsonPath('data.state', 'not_found');
    }

    #[Test]
    public function withdrawal_status_and_history_validate_input_and_return_records(): void
    {
        $this->getJson('/api/v1/bridge/withdrawals/nothex')->assertStatus(422)->assertJsonPath('error.code', 'INVALID_TX_HASH');
        $this->getJson('/api/v1/bridge/history/0x123')->assertStatus(422)->assertJsonPath('error.code', 'INVALID_ADDRESS');

        $burn = '0x'.str_repeat('f', 64);
        BridgeWithdrawal::create([
            'tx_hash' => $burn, 'log_index' => 0, 'block_number' => 1, 'from_address' => strtolower($this->alice),
            'amount_units' => '5000000', 'target_chain_raw' => '56', 'target_chain_id' => 56, 'target_address' => strtolower($this->bob),
            'fee_units' => '300000', 'payout_units' => '4700000', 'payout_amount_raw' => '4700000000000000000',
            'status' => BridgeWithdrawal::STATUS_PAID, 'payout_tx_hash' => '0x'.str_repeat('9', 64),
        ]);

        $this->getJson("/api/v1/bridge/withdrawals/{$burn}")
            ->assertOk()
            ->assertJsonPath('data.state', 'paid')
            ->assertJsonPath('data.withdrawals.0.payout_amount', '4.7')
            ->assertJsonPath('data.withdrawals.0.payout_url', 'https://bscscan.com/tx/0x'.str_repeat('9', 64));

        $this->getJson('/api/v1/bridge/history/'.$this->alice)
            ->assertOk()
            ->assertJsonCount(1, 'data.withdrawals')
            ->assertJsonCount(0, 'data.deposits');
    }

    #[Test]
    public function reserves_expose_the_latest_snapshot(): void
    {
        BridgeReserveSnapshot::create([
            'status' => 'ok', 'supply_units' => '19990000', 'vault_units' => '19990000', 'payout_units' => '0',
            'owed_units' => '0', 'backing_units' => '19990000', 'deficit_units' => '0', 'chains' => [],
        ]);

        $this->getJson('/api/v1/bridge/reserves')
            ->assertOk()
            ->assertJsonPath('data.snapshot.status', 'ok')
            ->assertJsonPath('data.snapshot.supply', '19.99')
            ->assertJsonPath('data.snapshot.backing', '19.99')
            ->assertJsonPath('data.vault_address', $this->vault)
            ->assertJsonPath('data.minter_address', $this->minter);
    }

    #[Test]
    public function there_is_no_write_endpoint_left_from_the_old_bridge(): void
    {
        $this->postJson('/api/v1/bridge/initiate', ['wallet_address' => $this->alice, 'amount' => 100])->assertStatus(404);
        $this->postJson('/api/v1/bridge/1/tx', ['tx_hash' => '0x'.str_repeat('a', 64)])->assertStatus(404);
    }

    #[Test]
    public function the_public_pages_render(): void
    {
        $this->get('/bridge')->assertOk();
        $this->get('/bridge/reserves')->assertOk();
    }
}
