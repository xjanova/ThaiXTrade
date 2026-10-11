<?php

namespace Tests\Feature\Bridge;

use App\Models\BridgeReserveSnapshot;
use App\Models\BridgeWithdrawal;
use App\Models\SystemAlert;
use App\Services\Bridge\Abi;
use App\Services\Bridge\BridgeConfig;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesBridgeChains;
use Tests\TestCase;

/**
 * กระทบยอด — supply ของ USDT_TPIX ต้องไม่เกินเงินหนุนจริง ถ้าเกินต้องหยุดเอง.
 */
class BridgeReconcileTest extends TestCase
{
    use FakesBridgeChains;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBridge();

        // สภาพจริงตอนนี้: supply 19.99 หนุนด้วย 19.99 USDT (BEP-20) ใน vault
        $this->chainState[4289]['supply'] = '19990000';
        $this->setTokenBalance(56, $this->usdtBsc, $this->vault, '19990000000000000000');
        $this->setTokenBalance(56, $this->usdtBsc, $this->payoutAddr, '0');
        $this->setTokenBalance(1, $this->usdtEth, $this->payoutAddr, '0');
    }

    private function reconcile(): BridgeReserveSnapshot
    {
        $this->artisan('bridge:reconcile')->assertSuccessful();

        return BridgeReserveSnapshot::latest('id')->first();
    }

    #[Test]
    public function a_fully_backed_supply_is_reported_ok_and_nothing_is_paused(): void
    {
        $s = $this->reconcile();

        $this->assertSame(BridgeReserveSnapshot::STATUS_OK, $s->status);
        $this->assertSame('19990000', $s->supply_units);
        $this->assertSame('19990000', $s->backing_units);
        $this->assertSame('0', $s->deficit_units);
        $this->assertSame([], $this->signCalls);
        $this->assertTrue(app(BridgeConfig::class)->siteSwitchOn());

        $bsc = collect($s->chains)->firstWhere('chain_id', 56);
        $this->assertSame('19990000', $bsc['vault_units']);
        $this->assertStringContainsStringIgnoringCase("token/{$this->usdtBsc}?a={$this->vault}", $bsc['vault_url']);
    }

    #[Test]
    public function a_deficit_warns_first_and_pauses_the_minter_on_the_second_consecutive_run(): void
    {
        // มีคน mint เกินเงินหนุน 80 USDT
        $this->chainState[4289]['supply'] = '100000000';

        $first = $this->reconcile();
        $this->assertSame(BridgeReserveSnapshot::STATUS_DEFICIT, $first->status);
        $this->assertSame('deficit_warning', $first->action);
        $this->assertTrue(app(BridgeConfig::class)->siteSwitchOn(), 'รอบแรกยังไม่หยุด (อาจเป็นการอ่านคลาดชั่วคราว)');
        $this->assertSame([], $this->signCalls);

        $second = $this->reconcile();
        $this->assertStringContainsString('site_disabled', $second->action);
        $this->assertStringContainsString('minter_paused', $second->action);
        $this->assertFalse(app(BridgeConfig::class)->siteSwitchOn());
        $this->assertTrue($this->chainState[4289]['minter_paused']);

        $pause = $this->signCallsFor($this->guardianAddr);
        $this->assertCount(1, $pause);
        $this->assertSame($this->minter, strtolower($pause[0]['BRIDGE_TO']));
        $this->assertSame(Abi::selector('pause()'), $pause[0]['BRIDGE_DATA']);

        $this->assertTrue(SystemAlert::where('alert_key', 'bridge_reserve_deficit')->where('severity', 'critical')->exists());

        // รอบที่สาม: minter หยุดอยู่แล้ว — ไม่เซ็นซ้ำ
        $this->reconcile();
        $this->assertCount(1, $this->signCallsFor($this->guardianAddr));
    }

    #[Test]
    public function an_unexplained_mint_halts_the_bridge_immediately(): void
    {
        // minter บอกว่า mint ไปแล้ว 5 USDT แต่ระบบไม่เคยสั่ง (คีย์ relayer อาจหลุด)
        $this->chainState[4289]['minter_total'] = '5000000';

        $s = $this->reconcile();

        $this->assertSame('5000000', $s->unexplained_mint_units);
        $this->assertStringContainsString('minter_paused', $s->action);
        $this->assertFalse(app(BridgeConfig::class)->siteSwitchOn());
        $this->assertTrue(SystemAlert::where('alert_key', 'bridge_unexplained_mint')->exists());
    }

    #[Test]
    public function pending_payouts_are_subtracted_from_the_backing(): void
    {
        // ผู้ใช้เผา 15 USDT_TPIX ไปแล้ว (supply ลดแล้ว) แต่ USDT จริงยังไม่ได้จ่ายออก
        $this->chainState[4289]['supply'] = '4990000';
        BridgeWithdrawal::create([
            'tx_hash' => '0x'.str_repeat('ab', 32), 'log_index' => 0, 'block_number' => 1, 'from_address' => $this->alice,
            'amount_units' => '15000000', 'target_chain_raw' => '56', 'target_chain_id' => 56, 'target_address' => $this->bob,
            'fee_units' => '300000', 'payout_units' => '14700000', 'payout_amount_raw' => '14700000000000000000',
            'status' => BridgeWithdrawal::STATUS_PENDING,
        ]);

        $s = $this->reconcile();

        $this->assertSame('14700000', $s->owed_units);
        $this->assertSame('5290000', $s->backing_units);         // 19.99 − 14.7
        $this->assertSame('-300000', $s->deficit_units);         // ค่าธรรมเนียมถอนค้างเป็นส่วนเกิน
        $this->assertSame(BridgeReserveSnapshot::STATUS_OK, $s->status);
    }

    #[Test]
    public function an_unreadable_chain_never_triggers_a_pause(): void
    {
        $this->chainState[4289]['supply'] = '100000000';
        $this->chainState[1]['down'] = true;

        $this->reconcile();
        $s = $this->reconcile();

        $this->assertSame(BridgeReserveSnapshot::STATUS_INCOMPLETE, $s->status);
        $this->assertNull($s->action);
        $this->assertTrue(app(BridgeConfig::class)->siteSwitchOn());
        $this->assertSame([], $this->signCalls);
    }

    #[Test]
    public function without_a_guardian_key_the_site_switch_is_still_turned_off(): void
    {
        config(['bridge.signers.guardian.keystore' => '', 'bridge.signers.guardian.passphrase' => null]);
        Cache::flush();
        $this->chainState[4289]['supply'] = '100000000';

        $this->reconcile();
        $s = $this->reconcile();

        $this->assertStringContainsString('no_guardian', $s->action);
        $this->assertFalse(app(BridgeConfig::class)->siteSwitchOn());
        $this->assertSame([], $this->signCalls);
    }
}
