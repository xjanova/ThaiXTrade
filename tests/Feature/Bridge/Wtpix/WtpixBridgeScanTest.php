<?php

namespace Tests\Feature\Bridge\Wtpix;

use App\Models\SiteSetting;
use App\Models\SystemAlert;
use App\Models\WtpixBridgeTransfer;
use App\Services\Bridge\Abi;
use App\Services\Bridge\Wtpix\WtpixBridgeConfig;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesWtpixBridgeChains;
use Tests\TestCase;

/**
 * ตัวสแกนของสะพาน TPIX ⇄ wTPIX — บันทึกครั้งเดียวต่อ log · รอ finality · ไม่ไล่ประวัติ · เฝ้าเหตุที่ระบบไม่ได้สั่ง.
 */
class WtpixBridgeScanTest extends TestCase
{
    use FakesWtpixBridgeChains;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWtpixBridge();
    }

    private function scan(): void
    {
        $this->artisan('bridge:wtpix-scan')->assertSuccessful();
    }

    /** รอบแรกตั้งเคอร์เซอร์ที่หัวปัจจุบัน — ใช้ก่อนสร้างเหตุการณ์ใหม่ทุกเทสต์ */
    private function prime(): void
    {
        $this->scan();
    }

    #[Test]
    public function the_first_run_starts_at_the_current_head_and_never_bridges_history(): void
    {
        $this->fakeLock($this->alice, $this->tpix('500'), 1500);
        $this->fakeBurnWtpix($this->alice, $this->tpix('50'), 4000);

        $this->scan();

        $this->assertSame(0, WtpixBridgeTransfer::count());
        $config = app(WtpixBridgeConfig::class);
        $this->assertSame(1999, $config->cursor($config->lockCursorKey()));
        $this->assertSame(4986, $config->cursor($config->burnCursorKey()));

        // เริ่มที่หัวเพราะไม่ได้ตั้ง START_BLOCK = ประวัติก่อนหน้าไม่ถูกบันทึก — ต้องดังให้แอดมินเห็น ไม่เงียบ
        $alert = SystemAlert::where('alert_key', 'wtpix_bridge_start_block_missing')->firstOrFail();
        $this->assertSame('warning', $alert->severity);
        $this->assertStringContainsString('START_BLOCK', $alert->message);
    }

    #[Test]
    public function an_explicit_start_block_is_used_on_the_first_run_without_a_warning(): void
    {
        config(['wtpix_bridge.tpix.start_block' => 1400, 'wtpix_bridge.bsc.start_block' => 3900]);
        $this->fakeLock($this->alice, $this->tpix('500'), 1500);

        $this->scan();

        $this->assertSame(1, WtpixBridgeTransfer::where('direction', WtpixBridgeTransfer::TO_BSC)->count());
        $this->assertFalse(SystemAlert::where('alert_key', 'wtpix_bridge_start_block_missing')->exists());
    }

    #[Test]
    public function a_lock_is_recorded_once_after_two_confirmations_with_the_fee_taken_off(): void
    {
        $this->prime();
        $tx = $this->fakeLock($this->alice, $this->tpix('500'), 2001);

        $this->chain[4289]['latest'] = 2002; // ยังไม่ครบ 2 confirmation
        $this->scan();
        $this->assertSame(0, WtpixBridgeTransfer::count());

        $this->chain[4289]['latest'] = 2003;
        $this->scan();
        $this->scan(); // สแกนซ้ำต้องไม่บันทึกซ้ำ

        $this->assertSame(1, WtpixBridgeTransfer::count());
        $row = WtpixBridgeTransfer::first();
        $this->assertSame(WtpixBridgeTransfer::TO_BSC, $row->direction);
        $this->assertSame(4289, $row->source_chain_id);
        $this->assertSame($this->wVault, $row->source_contract);
        $this->assertSame($this->alice, $row->recipient);
        $this->assertSame($this->tpix('500'), $row->amount_wei);
        $this->assertSame($this->tpix('1'), $row->fee_wei);
        $this->assertSame($this->tpix('499'), $row->payout_wei);
        $this->assertSame(Abi::depositId(4289, $tx, 0), $row->deposit_id);
        $this->assertSame(WtpixBridgeTransfer::STATUS_CONFIRMED, $row->status);
        $this->assertSame(56, $row->dest_chain_id);
    }

    #[Test]
    public function a_burn_on_bsc_waits_for_the_finalized_head_and_releases_to_the_burner(): void
    {
        $this->prime();
        $tx = $this->fakeBurnWtpix($this->alice, $this->tpix('250'), 4990);

        $this->scan(); // หัวปลอดภัย = min(finalized 4990, 5000 − 15) = 4985
        $this->assertSame(0, WtpixBridgeTransfer::count());

        $this->chain[56]['latest'] = 5010;
        $this->chain[56]['finalized'] = 5005;
        $this->scan();

        $row = WtpixBridgeTransfer::firstOrFail();
        $this->assertSame(WtpixBridgeTransfer::TO_TPIX, $row->direction);
        $this->assertSame(56, $row->source_chain_id);
        $this->assertSame($this->wToken, $row->source_contract);
        $this->assertSame($this->alice, $row->recipient);
        $this->assertSame($this->tpix('250'), $row->payout_wei, 'burn fee defaults to zero');
        $this->assertSame(Abi::depositId(56, $tx, 0), $row->deposit_id);
        $this->assertSame(WtpixBridgeTransfer::STATUS_CONFIRMED, $row->status);
    }

    #[Test]
    public function contract_senders_and_relayed_burns_are_held_for_review(): void
    {
        $this->prime();
        // ผู้ล็อกเป็นสัญญา — ที่อยู่เดียวกันบน BSC อาจไม่มีใครคุม
        $this->chain[4289]['codes'][$this->bob] = '0x6080604052';
        $this->fakeLock($this->bob, $this->tpix('100'), 2001);
        // burnFrom โดยคนอื่น — ผู้กดส่งไม่ใช่เจ้าของเหรียญ
        $this->fakeBurnWtpix($this->alice, $this->tpix('100'), 4986, txFrom: '0xc0ffee0000000000000000000000000000000001');
        $this->chain[4289]['latest'] = 2005;
        $this->chain[56]['latest'] = 5010;
        $this->chain[56]['finalized'] = 5005;

        $this->scan();

        $lock = WtpixBridgeTransfer::where('direction', WtpixBridgeTransfer::TO_BSC)->firstOrFail();
        $burn = WtpixBridgeTransfer::where('direction', WtpixBridgeTransfer::TO_TPIX)->firstOrFail();
        $this->assertSame([WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, 'sender_is_contract'], [$lock->status, $lock->review_reason]);
        $this->assertSame([WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, 'sender_mismatch'], [$burn->status, $burn->review_reason]);
    }

    #[Test]
    public function limits_send_large_or_repeated_locks_to_review_and_ignore_dust(): void
    {
        SiteSetting::set(WtpixBridgeConfig::SETTINGS_GROUP, 'limit_max_lock_per_tx', '1000', 'string');
        SiteSetting::set(WtpixBridgeConfig::SETTINGS_GROUP, 'limit_max_lock_per_address_daily', '1500', 'string');
        $this->prime();

        $this->fakeLock($this->alice, $this->tpix('5000'), 2001);           // เกินต่อครั้ง
        $this->fakeLock($this->bob, $this->tpix('900'), 2001, logIndex: 1);  // ผ่าน
        $this->fakeLock($this->bob, $this->tpix('900'), 2001, logIndex: 2);  // รวม 1800 > 1500 ต่อวัน
        $this->fakeLock($this->alice, $this->tpix('5'), 2001, logIndex: 3);  // ต่ำกว่าขั้นต่ำ 10
        $this->fakeLock($this->alice, $this->tpix('0.5'), 2001, logIndex: 4); // ไม่เกินค่าธรรมเนียม 1
        $this->chain[4289]['latest'] = 2005;

        $this->scan();

        $rows = WtpixBridgeTransfer::orderBy('id')->get(['status', 'review_reason'])->map(fn ($r) => [$r->status, $r->review_reason])->all();
        $this->assertSame([
            ['needs_review', 'over_tx_limit'],
            ['confirmed', null],
            ['needs_review', 'over_daily_address_limit'],
            ['ignored', 'below_minimum'],
            ['ignored', 'below_fee'],
        ], $rows);
    }

    #[Test]
    public function an_unreadable_chain_never_moves_its_cursor_and_does_not_stop_the_other_chain(): void
    {
        $this->prime();
        $config = app(WtpixBridgeConfig::class);
        $before = $config->cursor($config->burnCursorKey());

        $this->fakeLock($this->alice, $this->tpix('100'), 2001);
        $this->fakeBurnWtpix($this->alice, $this->tpix('100'), 4990);
        $this->chain[4289]['latest'] = 2005;
        $this->chain[56]['latest'] = 5010;
        $this->chain[56]['finalized'] = 5005;
        $this->chain[56]['fail_logs'] = true;

        $this->scan();

        $this->assertSame($before, $config->cursor($config->burnCursorKey()));
        $this->assertSame(1, WtpixBridgeTransfer::where('direction', WtpixBridgeTransfer::TO_BSC)->count());

        $this->chain[56]['fail_logs'] = false;
        $this->scan();
        $this->assertSame(1, WtpixBridgeTransfer::where('direction', WtpixBridgeTransfer::TO_TPIX)->count());
    }

    #[Test]
    public function a_removed_log_from_a_reorg_is_never_recorded(): void
    {
        $this->prime();
        $this->fakeLock($this->alice, $this->tpix('100'), 2001);
        $this->chain[4289]['logs'][0]['removed'] = true;
        $this->chain[4289]['latest'] = 2005;

        $this->scan();

        $this->assertSame(0, WtpixBridgeTransfer::count());
    }

    #[Test]
    public function locks_are_still_recorded_while_the_site_switch_is_off(): void
    {
        app(WtpixBridgeConfig::class)->setSiteSwitch(false, 'test');
        $this->prime();
        $this->fakeLock($this->alice, $this->tpix('100'), 2001);
        $this->chain[4289]['latest'] = 2005;

        $this->scan();

        $this->assertSame(1, WtpixBridgeTransfer::count());
        $this->assertSame([], $this->signCalls);
    }

    #[Test]
    public function nothing_runs_while_the_bridge_is_not_installed(): void
    {
        config(['wtpix_bridge.enabled' => false]);

        $this->scan();

        $this->assertSame([], $this->signCalls);
        $this->assertNull(app(WtpixBridgeConfig::class)->cursor(app(WtpixBridgeConfig::class)->lockCursorKey()));
    }

    // ── ตัวเฝ้าเหตุ ─────────────────────────────────────────────────────────

    private function assertHaltedWithBothPauses(string $alertKey): void
    {
        $this->assertFalse(app(WtpixBridgeConfig::class)->siteSwitchOn(), 'the site switch is turned off');
        $pauses = array_filter($this->signCallsFor($this->wGuardian), fn ($c) => $c['BRIDGE_DATA'] === Abi::selector('pause()'));
        $this->assertEqualsCanonicalizing(
            [[56, $this->wMinter], [4289, $this->wVault]],
            array_values(array_map(fn ($c) => [(int) $c['BRIDGE_CHAIN_ID'], strtolower($c['BRIDGE_TO'])], $pauses)),
        );
        $this->assertTrue($this->chain[56]['minter']['paused']);
        $this->assertTrue($this->chain[4289]['vault']['paused']);
        $this->assertSame('0', collect($this->signCallsFor($this->wGuardian, 4289))->first()['BRIDGE_GAS_PRICE_WEI'], 'TPIX Chain pause is legacy gasPrice 0');
        $this->assertTrue(SystemAlert::where('alert_key', $alertKey)->where('severity', 'critical')->exists());
    }

    #[Test]
    public function queueing_a_foreign_minter_on_wtpix_halts_the_bridge_and_pauses_both_contracts(): void
    {
        $this->prime();
        $this->fakeMinterEvent('queued', $this->bob, 4986);
        $this->chain[56]['latest'] = 5010;
        $this->chain[56]['finalized'] = 5005;

        $this->scan();

        $this->assertHaltedWithBothPauses('wtpix_bridge_minter_queued');
    }

    #[Test]
    public function adding_a_foreign_minter_halts_the_bridge(): void
    {
        $this->prime();
        $this->fakeMinterEvent('set', $this->bob, 4986, true);
        $this->chain[56]['latest'] = 5010;
        $this->chain[56]['finalized'] = 5005;

        $this->scan();

        $this->assertHaltedWithBothPauses('wtpix_bridge_minter_added');
    }

    #[Test]
    public function queueing_or_setting_our_own_minter_is_not_an_incident(): void
    {
        $this->prime();
        $this->fakeMinterEvent('queued', $this->wMinter, 4986);
        $this->fakeMinterEvent('set', $this->wMinter, 4987, true);
        $this->chain[56]['latest'] = 5010;
        $this->chain[56]['finalized'] = 5005;

        $this->scan();

        $this->assertTrue(app(WtpixBridgeConfig::class)->siteSwitchOn());
        $this->assertSame([], $this->signCalls);
    }

    #[Test]
    public function a_wtpix_mint_the_system_did_not_send_halts_the_bridge(): void
    {
        $this->prime();
        $this->fakeForeignMint($this->bob, $this->tpix('1000000'), 4986);
        $this->chain[56]['latest'] = 5010;
        $this->chain[56]['finalized'] = 5005;

        $this->scan();

        $this->assertHaltedWithBothPauses('wtpix_bridge_unordered_mint');
    }

    #[Test]
    public function a_vault_release_the_system_did_not_send_halts_the_bridge(): void
    {
        $this->chain[4289]['native'][$this->wVault] = $this->tpix('1000');
        $this->prime();
        $this->fakeForeignRelease($this->bob, $this->tpix('900'), 2001);
        $this->chain[4289]['latest'] = 2005;

        $this->scan();

        $this->assertHaltedWithBothPauses('wtpix_bridge_unordered_release');
    }

    #[Test]
    public function without_a_guardian_an_incident_still_switches_the_bridge_off_and_raises_a_critical_alert(): void
    {
        config(['wtpix_bridge.signers.guardian.keystore' => '']);
        $this->prime();
        $this->fakeForeignMint($this->bob, $this->tpix('10'), 4986);
        $this->chain[56]['latest'] = 5010;
        $this->chain[56]['finalized'] = 5005;

        $this->scan();

        $this->assertFalse(app(WtpixBridgeConfig::class)->siteSwitchOn());
        $this->assertSame([], $this->signCalls);
        $alert = SystemAlert::where('alert_key', 'wtpix_bridge_unordered_mint')->firstOrFail();
        $this->assertSame('critical', $alert->severity);
        $this->assertStringContainsString('no_guardian', $alert->message);
    }
}
