<?php

namespace Tests\Feature\Bridge\Wtpix;

use App\Models\SystemAlert;
use App\Models\WtpixBridgeSnapshot;
use App\Models\WtpixBridgeTransfer;
use App\Services\Bridge\Abi;
use App\Services\Bridge\Wtpix\WtpixBridgeConfig;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesWtpixBridgeChains;
use Tests\TestCase;

/**
 * กระทบยอด — wTPIX supply ต้องไม่เกิน TPIX ในคลังสุทธิ และทุก mint/ปล่อยต้องมาจากระบบ.
 */
class WtpixBridgeReconcileTest extends TestCase
{
    use FakesWtpixBridgeChains;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWtpixBridge();

        // สภาพตั้งต้น: ล็อก 1,000 → mint 999 (ค่าธรรมเนียม 1 ค้างในคลัง)
        $this->chain[4289]['native'][$this->wVault] = $this->tpix('1000');
        $this->chain[4289]['vault']['total_locked'] = $this->tpix('1000');
        $this->chain[56]['token']['supply'] = $this->tpix('999');
        $this->chain[56]['minter']['total_minted'] = $this->tpix('999');
        $this->row(WtpixBridgeTransfer::TO_BSC, '1000', '999', WtpixBridgeTransfer::STATUS_COMPLETED, ['dest_contract' => $this->wMinter]);
    }

    private function row(string $direction, string $amount, string $payout, string $status, array $extra = []): WtpixBridgeTransfer
    {
        static $n = 0;
        $n++;
        $chain = $direction === WtpixBridgeTransfer::TO_BSC ? 4289 : 56;
        $tx = '0x'.hash('sha256', "wtpix-reconcile-{$n}");

        return WtpixBridgeTransfer::create(array_merge([
            'direction' => $direction, 'source_chain_id' => $chain,
            'source_contract' => $direction === WtpixBridgeTransfer::TO_BSC ? $this->wVault : $this->wToken,
            'tx_hash' => $tx, 'log_index' => 0, 'block_number' => 100 + $n, 'from_address' => $this->alice, 'recipient' => $this->alice,
            'amount_wei' => $this->tpix($amount), 'fee_wei' => '0', 'payout_wei' => $this->tpix($payout),
            'deposit_id' => Abi::depositId($chain, $tx, 0), 'status' => $status,
        ], $extra));
    }

    private function reconcile(): WtpixBridgeSnapshot
    {
        $this->artisan('bridge:wtpix-reconcile')->assertSuccessful();

        return WtpixBridgeSnapshot::latest('id')->first();
    }

    private function guardianPauses(): array
    {
        return array_values(array_filter($this->signCallsFor($this->wGuardian), fn ($c) => $c['BRIDGE_DATA'] === Abi::selector('pause()')));
    }

    #[Test]
    public function a_fully_backed_supply_is_ok_and_nothing_is_paused(): void
    {
        $s = $this->reconcile();

        $this->assertSame(WtpixBridgeSnapshot::STATUS_OK, $s->status);
        $this->assertSame($this->tpix('1000'), $s->backing_wei);
        $this->assertSame('-'.$this->tpix('1'), $s->deficit_wei, 'the lock fee stays in the vault as surplus');
        $this->assertSame([], $this->signCalls);
        $this->assertTrue(app(WtpixBridgeConfig::class)->siteSwitchOn());
    }

    #[Test]
    public function owed_releases_reduce_backing_and_a_landed_release_is_not_counted_twice(): void
    {
        // เผา 500 แล้ว (supply ลด) ยังไม่ได้ปล่อย → ค้าง 500
        $this->chain[56]['token']['supply'] = $this->tpix('499');
        $burn = $this->row(WtpixBridgeTransfer::TO_TPIX, '500', '500', WtpixBridgeTransfer::STATUS_CONFIRMED);

        $s = $this->reconcile();
        $this->assertSame(WtpixBridgeSnapshot::STATUS_OK, $s->status);
        $this->assertSame($this->tpix('500'), $s->owed_wei);
        $this->assertSame($this->tpix('500'), $s->backing_wei);

        // ปล่อยลงบล็อกแล้ว แต่ระบบยังไม่ได้ตามผล (sending) — ต้องไม่นับเป็นภาระซ้ำ ไม่งั้นขาดปลอม
        $burn->update(['status' => WtpixBridgeTransfer::STATUS_SENDING, 'dest_tx_hash' => '0x'.str_repeat('e', 64), 'dest_contract' => $this->wVault]);
        $this->chain[4289]['vault']['processed'][$burn->deposit_id] = true;
        $this->chain[4289]['vault']['total_released'] = $this->tpix('500');
        $this->chain[4289]['native'][$this->wVault] = $this->tpix('500');

        $s = $this->reconcile();
        $this->assertSame(WtpixBridgeSnapshot::STATUS_OK, $s->status);
        $this->assertSame('0', $s->owed_wei);
        $this->assertSame('0', $s->unexplained_release_wei);
    }

    #[Test]
    public function a_deficit_warns_first_and_pauses_both_contracts_on_the_second_consecutive_run(): void
    {
        $this->chain[4289]['native'][$this->wVault] = $this->tpix('900'); // คลังหาย 100

        $first = $this->reconcile();
        $this->assertSame(WtpixBridgeSnapshot::STATUS_DEFICIT, $first->status);
        $this->assertSame('deficit_warning', $first->action);
        $this->assertSame([], $this->signCalls);
        $this->assertTrue(app(WtpixBridgeConfig::class)->siteSwitchOn());

        $second = $this->reconcile();
        $this->assertStringContainsString('site_disabled', $second->action);
        $this->assertStringContainsString('minter_paused', $second->action);
        $this->assertStringContainsString('vault_paused', $second->action);
        $this->assertCount(2, $this->guardianPauses());
        $this->assertFalse(app(WtpixBridgeConfig::class)->siteSwitchOn());
        $this->assertTrue(SystemAlert::where('alert_key', 'wtpix_bridge_reserve_deficit')->where('severity', 'critical')->exists());
    }

    #[Test]
    public function a_mint_through_the_minter_the_system_did_not_order_halts_at_once(): void
    {
        $this->chain[56]['minter']['total_minted'] = $this->tpix('1999');
        $this->chain[56]['token']['supply'] = $this->tpix('1999');

        $s = $this->reconcile();

        $this->assertSame($this->tpix('1000'), $s->unexplained_mint_wei);
        $this->assertStringContainsString('minter_paused', (string) $s->action);
        $this->assertFalse(app(WtpixBridgeConfig::class)->siteSwitchOn());
        $this->assertTrue(SystemAlert::where('alert_key', 'wtpix_bridge_unexplained_mint')->exists());
    }

    #[Test]
    public function wtpix_supply_above_what_the_minter_ever_minted_means_a_foreign_minter_and_halts_at_once(): void
    {
        $this->chain[56]['token']['supply'] = $this->tpix('5999');

        $s = $this->reconcile();

        $this->assertSame($this->tpix('5000'), $s->foreign_supply_wei);
        $this->assertCount(2, $this->guardianPauses());
        $this->assertTrue(SystemAlert::where('alert_key', 'wtpix_bridge_foreign_mint')->exists());
    }

    #[Test]
    public function a_vault_release_the_system_did_not_order_halts_at_once(): void
    {
        $this->chain[4289]['vault']['total_released'] = $this->tpix('100');
        $this->chain[4289]['native'][$this->wVault] = $this->tpix('900');

        $s = $this->reconcile();

        $this->assertSame($this->tpix('100'), $s->unexplained_release_wei);
        $this->assertFalse(app(WtpixBridgeConfig::class)->siteSwitchOn());
        $this->assertTrue(SystemAlert::where('alert_key', 'wtpix_bridge_unexplained_release')->exists());
    }

    #[Test]
    public function an_unreadable_chain_is_incomplete_and_never_pauses(): void
    {
        $this->chain[4289]['native'][$this->wVault] = $this->tpix('1'); // ดูเหมือนขาดหนัก แต่ BSC อ่านไม่ได้
        $this->chain[56]['down'] = true;

        $this->reconcile();
        $s = $this->reconcile();

        $this->assertSame(WtpixBridgeSnapshot::STATUS_INCOMPLETE, $s->status);
        $this->assertSame([], $this->signCalls);
        $this->assertTrue(app(WtpixBridgeConfig::class)->siteSwitchOn());
    }

    #[Test]
    public function without_a_guardian_a_halt_turns_the_switch_off_and_alerts(): void
    {
        config(['wtpix_bridge.signers.guardian.keystore' => '']);
        $this->chain[4289]['vault']['total_released'] = $this->tpix('100');

        $s = $this->reconcile();

        $this->assertStringContainsString('no_guardian', (string) $s->action);
        $this->assertSame([], $this->signCalls);
        $this->assertFalse(app(WtpixBridgeConfig::class)->siteSwitchOn());
    }

    #[Test]
    public function the_public_reserves_api_shows_the_latest_snapshot(): void
    {
        $this->reconcile();

        $this->getJson('/api/v1/bridge/reserves?asset=tpix')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.asset', 'tpix')
            ->assertJsonPath('data.snapshot.status', 'ok')
            ->assertJsonPath('data.snapshot.supply', '999')
            ->assertJsonPath('data.snapshot.vault', '1000')
            ->assertJsonPath('data.tpix.vault_address', $this->wVault)
            ->assertJsonPath('data.bsc.wtpix_address', $this->wToken);
    }
}
