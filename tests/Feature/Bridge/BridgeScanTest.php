<?php

namespace Tests\Feature\Bridge;

use App\Models\BridgeDeposit;
use App\Models\BridgeWithdrawal;
use App\Models\SiteSetting;
use App\Services\Bridge\Abi;
use App\Services\Bridge\BridgeConfig;
use App\Services\Bridge\BridgeRelayer;
use App\Services\ContractRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesBridgeChains;
use Tests\TestCase;

/**
 * ตัวสแกนของสะพาน — บันทึกเฉพาะสิ่งที่เกิดจริงบนเชน ครั้งเดียวต่อหนึ่ง log และไม่ mint เงินที่ยังย้อนได้.
 */
class BridgeScanTest extends TestCase
{
    use FakesBridgeChains;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBridge();

        // เริ่มเคอร์เซอร์ที่บล็อก 900 (BSC) / 4800 (ETH) / 1900 (TPIX) — รอบแรกจริงจะเริ่มที่หัวปัจจุบัน
        $config = app(BridgeConfig::class);
        $config->setCursor('deposit_56', 900);
        $config->setCursor('deposit_1', 4800);
        $config->setCursor('burn_4289', 1900);
    }

    private function scan(): void
    {
        $this->artisan('bridge:scan')->assertSuccessful();
    }

    #[Test]
    public function a_finalized_bsc_deposit_is_recorded_once_with_18_to_6_decimal_conversion(): void
    {
        // 12.3456789 USDT บน BSC (18 ทศนิยม) → mint ได้ 12.345678 เศษ 0.0000009 ค้างใน vault
        $tx = $this->fakeDeposit(56, $this->alice, '12345678900000000000', 950);

        $this->scan();
        $this->scan(); // สแกนซ้ำต้องไม่บันทึกซ้ำ

        $this->assertSame(1, BridgeDeposit::count());
        $d = BridgeDeposit::first();
        $this->assertSame($tx, $d->tx_hash);
        $this->assertSame('12345678', $d->amount_units);
        $this->assertSame('12345678', $d->mint_units);
        $this->assertSame('900000000000', $d->dust_raw);
        $this->assertSame(BridgeDeposit::STATUS_CONFIRMED, $d->status);
        $this->assertSame(Abi::depositId(56, $tx, 0), $d->deposit_id);
        $this->assertSame(strtolower($this->alice), $d->from_address);
    }

    #[Test]
    public function an_unfinalized_deposit_is_not_recorded_until_the_chain_finalizes_it(): void
    {
        // finalized = 990 · latest = 1000 → บล็อก 995 ยังย้อนได้
        $this->fakeDeposit(56, $this->alice, '5000000000000000000', 995);

        $this->scan();
        $this->assertSame(0, BridgeDeposit::count());
        $this->assertSame(986, app(BridgeConfig::class)->cursor('deposit_56'), 'เคอร์เซอร์ต้องหยุดที่หัวที่ปลอดภัย (min(finalized 990, latest−15))');

        $this->setLatest(56, 1100, 1050);
        $this->scan();
        $this->assertSame(1, BridgeDeposit::count());
    }

    #[Test]
    public function the_safe_head_never_trusts_a_finalized_tag_that_equals_latest(): void
    {
        // RPC บางตัวตอบ finalized = latest — ต้องหัก min_confirmations (BSC = 15) อยู่ดี
        $this->setLatest(56, 1000, 1000);
        $this->fakeDeposit(56, $this->alice, '5000000000000000000', 990);

        $this->scan();
        $this->assertSame(0, BridgeDeposit::count());
    }

    #[Test]
    public function an_rpc_failure_does_not_advance_the_cursor_and_the_deposit_is_picked_up_later(): void
    {
        $this->fakeDeposit(56, $this->alice, '5000000000000000000', 950);
        $this->chainState[56]['fail_logs'] = true;

        $this->scan();
        $this->assertSame(0, BridgeDeposit::count());
        $this->assertSame(900, app(BridgeConfig::class)->cursor('deposit_56'));

        $this->chainState[56]['fail_logs'] = false;
        $this->scan();
        $this->assertSame(1, BridgeDeposit::count());
    }

    #[Test]
    public function the_first_run_starts_at_the_head_and_never_backfills_old_vault_transfers(): void
    {
        // เงินหนุนเดิมที่เคยโอนเข้า vault ก่อนเปิดสะพาน — ห้าม mint ซ้ำ
        $config = app(BridgeConfig::class);
        SiteSetting::where('group', BridgeConfig::CURSOR_GROUP)->delete();
        Cache::flush();
        $this->fakeDeposit(56, $this->alice, '19990000000000000000', 500);

        $this->scan();

        $this->assertSame(0, BridgeDeposit::count());
        $this->assertSame(986, $config->cursor('deposit_56'));
    }

    #[Test]
    public function a_log_a_lagging_node_left_out_is_picked_up_by_the_overlap_rescan(): void
    {
        $this->scan();
        $this->assertSame(986, app(BridgeConfig::class)->cursor('deposit_56'));

        // โหนดที่ตามหัวไม่ทันตอบ [] ให้บล็อก 950 ไปแล้ว (เคอร์เซอร์ผ่านไปแล้ว) — log จริงมีอยู่บนเชน
        $tx = $this->fakeDeposit(56, $this->alice, '5000000000000000000', 950);
        $this->setLatest(56, 1010, 1000);
        $this->scan();

        $this->assertSame($tx, BridgeDeposit::sole()->tx_hash, 'รอบถัดไปอ่านย้อนทับแล้วเจอ');
        $this->assertSame(996, app(BridgeConfig::class)->cursor('deposit_56'), 'หัวปลอดภัย min(1000, 1010−15) = 995');
    }

    #[Test]
    public function the_overlap_never_reads_below_the_first_scanned_block(): void
    {
        SiteSetting::where('group', BridgeConfig::CURSOR_GROUP)->delete();
        Cache::flush();
        $this->scan(); // รอบแรกเริ่มที่หัว (986) — floor = 986

        // vault เคยรับเงินหนุนก่อนเปิดสะพาน (บล็อก 980 < floor) — การย้อนทับต้องไม่ไป mint ให้
        $this->fakeDeposit(56, $this->alice, '19990000000000000000', 980);
        $this->setLatest(56, 1100, 1050);
        $this->scan();

        $this->assertSame(0, BridgeDeposit::count());
    }

    #[Test]
    public function a_pruned_history_error_from_the_first_rpc_falls_through_to_the_second(): void
    {
        // เดิม error แบบนี้ถูกถือเป็น "คำตอบจริงของเชน" → ไม่ลองตัวที่สอง → ตัวสแกนค้างที่ช่วงเดิมตลอดไป
        $this->useTwoRpcs();
        $this->rpcFaults['rpc.bsc.test|eth_getLogs'] = 'History has been pruned for this block';
        $this->fakeDeposit(56, $this->alice, '5000000000000000000', 950);

        $this->scan();

        $this->assertSame(1, BridgeDeposit::count());
        $this->assertSame(986, app(BridgeConfig::class)->cursor('deposit_56'));
    }

    #[Test]
    public function zero_value_dust_and_internal_transfers_are_ignored_without_extra_rpc_calls(): void
    {
        $this->fakeDeposit(56, $this->bob, '0', 940);                         // address poisoning
        $this->fakeDeposit(56, $this->bob, '999999999999', 941);              // < 0.000001 USDT
        $this->fakeDeposit(56, $this->bob, '500000000000000000', 942);        // 0.5 USDT < ขั้นต่ำ 1
        $this->fakeDeposit(56, $this->payoutAddr, '50000000000000000000', 943); // กระเป๋าจ่ายเติมเงินหนุน

        $this->scan();

        // ยอดศูนย์/ฝุ่นไม่ถูกบันทึกเลย (ไม่มีอะไรให้ mint หรือให้ตรวจ) · ต่ำกว่าขั้นต่ำ/โอนภายในบันทึกไว้เป็นหลักฐาน
        $rows = BridgeDeposit::orderBy('block_number')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['below_minimum', 'internal_sender'], $rows->pluck('review_reason')->all());
        $this->assertTrue($rows->every(fn ($d) => $d->status === BridgeDeposit::STATUS_IGNORED));

        Http::assertNotSent(fn ($r) => in_array($r->data()['method'] ?? '', ['eth_getCode', 'eth_getTransactionByHash'], true));
    }

    #[Test]
    public function deposits_from_contracts_or_relayed_senders_go_to_review(): void
    {
        $multisig = '0xc0ffee0000000000000000000000000000000001';
        $this->chainState[56]['codes'][$multisig] = '0x6080604052';
        $this->fakeDeposit(56, $multisig, '5000000000000000000', 940);

        // EIP-7702: EOA ที่มอบสิทธิ์ให้โค้ด ยังเป็นกระเป๋าที่เจ้าของถือคีย์ — ผ่านได้
        $delegated = '0xde1e9a7ed0000000000000000000000000000001';
        $this->chainState[56]['codes'][$delegated] = '0xef0100'.str_repeat('ab', 20);
        $this->fakeDeposit(56, $delegated, '5000000000000000000', 941);

        // ตัวกลางโอนแทน (tx.from ไม่ใช่เจ้าของเหรียญ)
        $this->fakeDeposit(56, $this->alice, '5000000000000000000', 942, txFrom: $this->bob);

        $this->scan();

        $rows = BridgeDeposit::orderBy('block_number')->get();
        $this->assertSame(
            [BridgeDeposit::STATUS_NEEDS_REVIEW, BridgeDeposit::STATUS_CONFIRMED, BridgeDeposit::STATUS_NEEDS_REVIEW],
            $rows->pluck('status')->all(),
        );
        $this->assertSame(['sender_is_contract', null, 'sender_mismatch'], $rows->pluck('review_reason')->all());
    }

    #[Test]
    public function over_limit_deposits_go_to_review_and_are_never_auto_confirmed(): void
    {
        // ต่อครั้งเกิน 1,000
        $this->fakeDeposit(1, $this->alice, '1500000000', 4850);
        // ต่อกระเป๋าต่อวันเกิน 2,000: 900 + 900 ผ่าน, 900 ที่สามเกิน
        $this->fakeDeposit(1, $this->bob, '900000000', 4851);
        $this->fakeDeposit(1, $this->bob, '900000000', 4852);
        $this->fakeDeposit(1, $this->bob, '900000000', 4853);

        $this->scan();

        $rows = BridgeDeposit::orderBy('block_number')->get();
        $this->assertSame(
            ['needs_review', 'confirmed', 'confirmed', 'needs_review'],
            $rows->pluck('status')->all(),
        );
        $this->assertSame('over_tx_limit', $rows[0]->review_reason);
        $this->assertSame('over_daily_address_limit', $rows[3]->review_reason);
    }

    #[Test]
    public function transfer_events_from_a_fake_token_contract_are_not_deposits(): void
    {
        $this->fakeDeposit(56, $this->alice, '5000000000000000000', 940, token: '0x1111111111111111111111111111111111111111');

        $this->scan();

        $this->assertSame(0, BridgeDeposit::count());
    }

    #[Test]
    public function a_burn_on_tpix_becomes_a_pending_payout_with_fee_and_target_decimals(): void
    {
        $this->fakeBurn($this->alice, '25000000', '56', '0xb0b0000000000000000000000000000000000b0b', 1950);

        $this->scan();

        $w = BridgeWithdrawal::sole();
        $this->assertSame(BridgeWithdrawal::STATUS_PENDING, $w->status);
        $this->assertSame(56, $w->target_chain_id);
        $this->assertSame('25000000', $w->amount_units);
        $this->assertSame('300000', $w->fee_units);                     // 0.3 USDT บน BSC
        $this->assertSame('24700000', $w->payout_units);
        $this->assertSame('24700000000000000000', $w->payout_amount_raw); // 24.7 × 1e18
        $this->assertSame($this->usdtBsc, $w->payout_token);
    }

    #[Test]
    public function burns_to_unsupported_chains_or_bad_addresses_go_to_review(): void
    {
        $this->fakeBurn($this->alice, '5000000', '999', $this->bob, 1950);
        $this->fakeBurn($this->alice, '5000000', '115792089237316195423570985008687907853269984665640564039457584007913129639935', $this->bob, 1951);
        $this->fakeBurn($this->alice, '5000000', '56', 'TXYZnotAnEvmAddress', 1952);
        // checksum ผิด (ตัวพิมพ์ผสมที่ไม่ตรง EIP-55) = พิมพ์ผิด
        $this->fakeBurn($this->alice, '5000000', '56', '0xF46131C82819d7621163F482b3fe88a228A7807c', 1953);
        // ส่งเข้าตัวสัญญา USDT เอง
        $this->fakeBurn($this->alice, '5000000', '56', $this->usdtBsc, 1954);
        // หักค่าธรรมเนียมแล้วเหลือศูนย์
        $this->fakeBurn($this->alice, '300000', '56', $this->bob, 1955);

        $this->scan();

        $rows = BridgeWithdrawal::orderBy('block_number')->get();
        $this->assertSame(
            ['unsupported_chain', 'unsupported_chain', 'invalid_target_address', 'invalid_target_address', 'suspicious_target', 'below_fee'],
            $rows->pluck('review_reason')->all(),
        );
        $this->assertTrue($rows->take(5)->every(fn ($w) => $w->status === BridgeWithdrawal::STATUS_NEEDS_REVIEW));
        // เผาไม่เกินค่าธรรมเนียม = ไม่มีอะไรให้จ่าย ไม่เข้าคิวตรวจ (กันคนเผาเศษถมคิวแอดมิน)
        $this->assertSame(BridgeWithdrawal::STATUS_IGNORED, $rows[5]->status);
        $this->assertNull($rows[1]->target_chain_id);
    }

    #[Test]
    public function nothing_is_scanned_while_the_bridge_is_not_installed(): void
    {
        config(['bridge.enabled' => false]);
        $this->fakeDeposit(56, $this->alice, '5000000000000000000', 950);

        $this->scan();

        $this->assertSame(0, BridgeDeposit::count());
        Http::assertNothingSent();
    }

    #[Test]
    public function scanning_continues_while_the_site_switch_is_off_so_deposits_are_not_lost(): void
    {
        app(BridgeConfig::class)->setSiteSwitch(false, 'test');
        $this->fakeDeposit(56, $this->alice, '5000000000000000000', 950);

        $this->scan();

        $this->assertSame(BridgeDeposit::STATUS_CONFIRMED, BridgeDeposit::sole()->status);
    }

    #[Test]
    public function withdrawals_are_read_only_from_the_pinned_usdt_tpix_even_if_the_contract_registry_is_repointed(): void
    {
        // a leaked CONTRACT_REGISTRY_TOKEN (force=true) could point usdt_tpix at a contract that emits Burned
        // without burning anything — the payout wallet would then pay real USDT for nothing
        $fake = '0x6666666666666666666666666666666666666666';
        $this->fakeBurn($this->alice, '900000000', '56', $this->bob, 1950);
        $last = count($this->chainState[4289]['logs']) - 1;
        $this->chainState[4289]['logs'][$last]['address'] = $fake;
        SiteSetting::set(ContractRegistry::GROUP, 'usdt_tpix', $fake);
        Cache::flush();

        $real = $this->fakeBurn($this->bob, '5000000', '56', $this->alice, 1951);

        $this->scan();

        $this->assertSame(1, BridgeWithdrawal::count());
        $this->assertSame($real, BridgeWithdrawal::first()->tx_hash);
        $this->assertSame($this->usdtTpix, app(BridgeConfig::class)->usdtTpixAddress());

        // the mismatch itself is a tamper signal: minting stays blocked until someone looks
        $checks = collect(app(BridgeRelayer::class)->readiness()['checks'])->keyBy('key');
        $this->assertFalse($checks['usdt_tpix']['ok']);
    }
}
