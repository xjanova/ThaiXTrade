<?php

namespace Tests\Feature\Bridge;

use App\Models\BridgeDeposit;
use App\Models\BridgeReserveSnapshot;
use App\Models\BridgeWithdrawal;
use App\Models\SiteSetting;
use App\Services\Bridge\Abi;
use App\Services\Bridge\BridgeConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
    public function a_deposit_on_a_disabled_chain_gets_a_terminal_status_with_refund_guidance_not_a_forever_spinner(): void
    {
        // vault ที่อยู่เดียวกันทุกเชน — ผู้ใช้โอนบน Ethereum ที่ไม่เคยเปิด: เดิมตอบ "final แล้ว รอเข้าคิว" ตลอดไป
        config(['bridge.chains.1.enabled' => false]);
        Cache::flush();
        $tx = $this->fakeDeposit(1, $this->alice, '7000000', 4800);

        $this->getJson("/api/v1/bridge/status/1/{$tx}")
            ->assertOk()
            ->assertJsonPath('data.state', 'chain_disabled')
            ->assertJsonPath('data.refund', true);

        Http::assertNotSent(fn ($r) => parse_url($r->url(), PHP_URL_HOST) === 'rpc.eth.test');
    }

    #[Test]
    public function a_deposit_on_a_temporarily_paused_chain_says_it_will_be_processed_on_reopening(): void
    {
        // เคยสแกนแล้ว (มีเคอร์เซอร์) แล้วแอดมินปิดชั่วคราว — เปิดใหม่ตัวสแกนไล่ต่อจากจุดเดิมเอง
        app(BridgeConfig::class)->setCursor('deposit_56', 900);
        SiteSetting::set(BridgeConfig::SETTINGS_GROUP, 'chain_enabled_56', '0', 'boolean');
        $tx = $this->fakeDeposit(56, $this->alice, '7000000000000000000', 950);

        $this->getJson("/api/v1/bridge/status/56/{$tx}")
            ->assertOk()
            ->assertJsonPath('data.state', 'chain_paused')
            ->assertJsonPath('data.refund', false);
    }

    #[Test]
    public function the_public_pause_reason_is_a_generic_code_without_admin_names_or_incident_notes(): void
    {
        app(BridgeConfig::class)->setSiteSwitch(false, 'ปิดโดย สมชาย: คีย์ relayer อาจหลุด', 'manual');

        $response = $this->getJson('/api/v1/bridge/info')->assertOk()->assertJsonPath('data.paused_reason', 'maintenance');
        $this->assertStringNotContainsString('สมชาย', $response->getContent());
        $this->assertStringNotContainsString('relayer', $response->getContent());

        app(BridgeConfig::class)->setSiteSwitch(false, 'หยุดอัตโนมัติ (reserve_deficit): supply เกินเงินหนุน 80 หน่วย', 'auto');
        $this->getJson('/api/v1/bridge/info')->assertJsonPath('data.paused_reason', 'safety_hold');

        app(BridgeConfig::class)->setSiteSwitch(true);
        $this->getJson('/api/v1/bridge/info')->assertJsonPath('data.paused_reason', null);
    }

    #[Test]
    public function info_reports_the_deposit_cap_that_can_really_be_minted(): void
    {
        // วงเงินเว็บสูงกว่า maxPerTx บนสัญญา (ตั้งไว้ก่อนมีด่านตรวจ) — หน้าเว็บต้องใช้ค่าที่ต่ำกว่า
        SiteSetting::set(BridgeConfig::SETTINGS_GROUP, 'limit_max_deposit_per_tx', '1500', 'string');
        Cache::flush();

        $this->getJson('/api/v1/bridge/info')
            ->assertJsonPath('data.limits.max_deposit_per_tx', '1500')
            ->assertJsonPath('data.deposit_max_per_tx', '1000');
    }

    #[Test]
    public function the_mobile_fee_config_follows_the_bridge_switch_at_once_and_reports_real_eta_and_fees(): void
    {
        $this->getJson('/api/v1/fees')
            ->assertOk()
            ->assertJsonPath('bridge.enabled', false)        // แอปมือถือยังไม่รองรับ — คงเดิม
            ->assertJsonPath('bridge.webEnabled', true)
            ->assertJsonPath('bridge.estimatedMinutes', 2)   // BSC เร็วที่สุดในเชนที่เปิด (เดิมตายตัว 20)
            ->assertJsonPath('bridge.maxAmount', 1000)
            ->assertJsonPath('bridge.chains.0.chainId', 56)
            ->assertJsonPath('bridge.chains.0.withdrawFee', 0.3);

        // หยุดสะพาน → เห็นผลทันที (เดิมค้างในแคช 5 นาที)
        app(BridgeConfig::class)->setSiteSwitch(false, 'test', 'auto');
        $this->getJson('/api/v1/fees')->assertJsonPath('bridge.webEnabled', false);
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

        // ต่ำกว่าขั้นต่ำ: เงินเข้า vault แล้ว ผู้ใช้ต้องเห็นในประวัติ (จะได้รู้ว่าต้องขอคืน) · โอนภายในไม่ใช่ของผู้ใช้
        foreach (['below_minimum' => '1', 'internal_sender' => '2'] as $reason => $c) {
            $tx = '0x'.str_repeat($c, 64);
            BridgeDeposit::create([
                'source_chain_id' => 56, 'tx_hash' => $tx, 'log_index' => 0, 'block_number' => 900,
                'from_address' => strtolower($this->alice), 'to_address' => $this->vault, 'token_address' => $this->usdtBsc,
                'token_decimals' => 18, 'amount_raw' => '500000000000000000', 'amount_units' => '500000',
                'mint_units' => '500000', 'deposit_id' => Abi::depositId(56, $tx, 0),
                'status' => BridgeDeposit::STATUS_IGNORED, 'review_reason' => $reason,
            ]);
        }

        $this->getJson('/api/v1/bridge/history/'.$this->alice)
            ->assertJsonCount(1, 'data.deposits')
            ->assertJsonPath('data.deposits.0.status', 'ignored')
            ->assertJsonPath('data.deposits.0.review_reason', 'below_minimum');
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
