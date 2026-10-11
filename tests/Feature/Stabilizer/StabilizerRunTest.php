<?php

namespace Tests\Feature\Stabilizer;

use App\Models\SiteSetting;
use App\Models\StabilityDecision;
use App\Models\StabilityDeposit;
use App\Models\StabilityTransaction;
use App\Services\Stabilizer\BridgeReserveSource;
use App\Services\Stabilizer\PoolMath;
use App\Services\Stabilizer\SwapCalldata;
use App\Services\TpixDexService;
use App\Support\Wei;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Tests\Support\FakesStabilizerChain;
use Tests\TestCase;

/**
 * TPIX Stability Reserve — กฎทุกข้อที่ประกาศต่อสาธารณะต้องเป็นจริงในโค้ด.
 *
 * เชนปลอมทั้งหมด (Http::fake) + ตัวเซ็นปลอม — ไม่มีคีย์จริง ไม่มีธุรกรรมจริงออกไป
 *
 * ค่าตั้งต้นของทุกเทสต์: พูล 100,000 TPIX / 10,000 USDT (ราคา 0.1) · ประวัติ 1 วันที่ 0.1
 * → กรอบ ±15% = 0.085 – 0.115 · กระเป๋ามี 100,000 TPIX / 1,000 USDT · ฝาก USDT ไว้ 1,000
 */
class StabilizerRunTest extends TestCase
{
    use FakesStabilizerChain;

    protected function setUp(): void
    {
        parent::setUp();
        // ตรึงเวลาไว้กลางวัน — เทสต์งบรายวันต้องไม่พังเพราะรันตอนตีหนึ่ง (ไม้ "3 ชม.ก่อน" กลายเป็นเมื่อวาน)
        $this->travelTo(Carbon::parse('2026-10-11 12:00:00', config('app.timezone')));
        $this->setUpStabilizer();
        $this->setParam('lookback_days', 1);
        $this->setPool('100000', '10000');
        $this->setWallet('100000', '1000');
        $this->seedHistory('0.1');
        $this->switchOn();

        StabilityDeposit::create([
            'token' => 'usdt',
            'amount_wei' => Wei::toWei('1000', 6),
            'tx_hash' => '0x'.str_repeat('d', 64),
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownStabilizerKeystore();
        parent::tearDown();
    }

    private function runOnce(): void
    {
        $this->artisan('stabilizer:run')->assertSuccessful();
    }

    private function lastDecision(): StabilityDecision
    {
        return StabilityDecision::latest('id')->firstOrFail();
    }

    /** @return array<int, string> คำ 32 ไบต์ของ calldata (ตัด selector ออก) */
    private static function args(string $data): array
    {
        return str_split(substr($data, 10), 64);
    }

    private function pastSwap(string $side, string $amountWei, int $minutesAgo, string $status = StabilityTransaction::STATUS_CONFIRMED, int $nonce = 0): StabilityTransaction
    {
        // ไม้ที่ลงบล็อกแล้วใช้ nonce ไปแล้ว — เชนจำลองต้องนับตามให้ตรงกับของจริง
        if ($status === StabilityTransaction::STATUS_CONFIRMED) {
            $this->chainNonce = max($this->chainNonce, $nonce + 1);
        }

        return StabilityTransaction::create([
            'purpose' => 'swap',
            'side' => $side,
            'token_in' => $side === 'buy' ? 'usdt' : 'tpix',
            'amount_in_wei' => $amountWei,
            'nonce' => $nonce,
            'tx_hash' => '0x'.substr(hash('sha256', $side.$minutesAgo.$amountWei.$nonce), 0, 64),
            'raw_tx' => '0x01',
            'status' => $status,
            'block_number' => $status === StabilityTransaction::STATUS_CONFIRMED ? 4000 : null,
            'signed_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    // ── อยู่ในกรอบ ───────────────────────────────────────────────────────

    public function test_inside_band_does_nothing_and_records_why(): void
    {
        $this->runOnce();

        $d = $this->lastDecision();
        $this->assertSame('none', $d->action);
        $this->assertSame('in_band', $d->reason_code);
        $this->assertSame(0, bccomp((string) $d->band_low, '0.085', 6));
        $this->assertSame(0, bccomp((string) $d->band_high, '0.115', 6));
        $this->assertSame(15, $d->details['params']['band_pct']);
        $this->assertSame([], $this->signer->signed);
    }

    // ── ต่ำกว่ากรอบ → ซื้อ ─────────────────────────────────────────────

    public function test_below_band_buys_with_price_impact_capped_at_one_percent(): void
    {
        $this->setParam('max_trade_usdt', 500);
        $this->setPool('125000', '10000'); // 0.08 — ต่ำกว่าขอบล่าง 0.085

        $this->runOnce();

        $d = $this->lastDecision();
        $this->assertSame('buy', $d->action);
        $this->assertSame('below_band', $d->reason_code);
        $this->assertSame('impact_and_band', $d->details['limited_by']);

        $this->assertCount(1, $this->signer->signed);
        $call = $this->signer->signed[0];
        $this->assertSame($this->sRouter, $call['to']);
        $this->assertSame('0', $call['value']);
        $this->assertStringStartsWith(SwapCalldata::selector(SwapCalldata::SIG_BUY), $call['data']);

        $tx = StabilityTransaction::firstOrFail();
        $args = self::args($call['data']);
        // amountIn ใน calldata = จำนวนที่บันทึก · ผู้รับ = กระเป๋ากองทุนเอง · path USDT → WTPIX
        $this->assertSame($tx->amount_in_wei, Wei::hexToInt('0x'.$args[0]));
        $this->assertSame($tx->min_out_wei, Wei::hexToInt('0x'.$args[1]));
        $this->assertSame(strtolower($this->sWallet), '0x'.substr($args[3], 24));
        $this->assertSame($this->sUsdt, '0x'.substr($args[6], 24));
        $this->assertSame($this->sWtpix, '0x'.substr($args[7], 24));

        // ราคาหลังซื้อขึ้นไม่เกิน 1% และใช้เพดานนั้นเกือบเต็ม (ไม่ใช่ไม้จิ๋วที่ไม่มีผล)
        $after = PoolMath::quoteBuy($tx->amount_in_wei, Wei::toWei('125000', 18), Wei::toWei('10000', 6), 6)['price_after'];
        $this->assertLessThanOrEqual(1.0, PoolMath::changePct('0.08', $after));
        $this->assertGreaterThan(0.95, PoolMath::changePct('0.08', $after));
        $this->assertLessThan(0, bccomp($tx->amount_in_wei, Wei::toWei('500', 6), 0));

        // ส่งออกไปแล้ว + ผูกกับการตัดสินใจ
        $this->assertSame(StabilityTransaction::STATUS_BROADCAST, $tx->status);
        $this->assertSame($tx->id, $d->transaction_id);
        $this->assertSame($this->chainNonce, $tx->nonce);
    }

    public function test_buy_never_pushes_price_past_the_lower_band_edge(): void
    {
        $this->setParam('max_trade_usdt', 500);
        $this->setPool('100000', '8450'); // 0.0845 — ต่ำกว่าขอบล่างแค่นิดเดียว

        $this->runOnce();

        $tx = StabilityTransaction::firstOrFail();
        $after = (string) $tx->price_after_expected;
        $this->assertLessThanOrEqual(0, bccomp($after, '0.085', 18), "ราคาหลังซื้อ {$after} เกินขอบล่างของกรอบ");
        // ไม่ได้ใช้เพดาน 1% เต็ม เพราะขอบกรอบมาก่อน
        $this->assertLessThan(0.6, PoolMath::changePct('0.0845', $after));
    }

    public function test_router_is_approved_for_one_max_trade_before_the_first_buy(): void
    {
        $this->setWallet('100000', '1000', '0');
        $this->setPool('125000', '10000');

        $this->runOnce();

        $d = $this->lastDecision();
        $this->assertSame('approve', $d->action);
        $this->assertCount(1, $this->signer->signed);
        $call = $this->signer->signed[0];
        $this->assertSame($this->sUsdt, $call['to']);
        $this->assertStringStartsWith(SwapCalldata::selector(SwapCalldata::SIG_APPROVE), $call['data']);
        $args = self::args($call['data']);
        $this->assertSame($this->sRouter, '0x'.substr($args[0], 24));
        // ขอเท่าเพดานต่อไม้ (50 USDT) ไม่ใช่ไม่จำกัด
        $this->assertSame(Wei::toWei('50', 6), Wei::hexToInt('0x'.$args[1]));
        $this->assertSame('approve', StabilityTransaction::firstOrFail()->purpose);
    }

    // ── สูงกว่ากรอบ → ขาย ─────────────────────────────────────────────

    public function test_above_band_sells_tpix_within_the_impact_cap(): void
    {
        $this->setParam('max_trade_tpix', 5000);
        $this->setPool('100000', '12000'); // 0.12 — สูงกว่าขอบบน 0.115

        $this->runOnce();

        $d = $this->lastDecision();
        $this->assertSame('sell', $d->action);
        $this->assertSame('above_band', $d->reason_code);

        $call = $this->signer->signed[0];
        $tx = StabilityTransaction::firstOrFail();
        $this->assertSame($this->sRouter, $call['to']);
        $this->assertStringStartsWith(SwapCalldata::selector(SwapCalldata::SIG_SELL), $call['data']);
        // ขาย TPIX native = จำนวนอยู่ใน value ของธุรกรรม
        $this->assertSame($tx->amount_in_wei, $call['value']);
        $args = self::args($call['data']);
        $this->assertSame(strtolower($this->sWallet), '0x'.substr($args[2], 24));

        $after = (string) $tx->price_after_expected;
        $this->assertGreaterThanOrEqual(-1.0, PoolMath::changePct('0.12', $after));
        $this->assertGreaterThanOrEqual(0, bccomp($after, '0.115', 18));
    }

    public function test_sell_is_capped_by_max_trade_size(): void
    {
        $this->setPool('100000', '12000');

        $this->runOnce();

        $tx = StabilityTransaction::firstOrFail();
        // ปริยาย 500 TPIX ต่อไม้ — น้อยกว่าที่ 1% ต้องใช้ (~500.6) จึงโดนเพดานขนาดไม้
        $this->assertSame(Wei::toWei('500', 18), $tx->amount_in_wei);
        $this->assertSame('max_trade', $this->lastDecision()->details['limited_by']);
    }

    // ── กัน wash trade / จังหวะเวลา ──────────────────────────────────

    public function test_no_sell_within_cooldown_after_a_buy(): void
    {
        $this->pastSwap('buy', Wei::toWei('20', 6), 120); // ซื้อไป 2 ชม. ก่อน (พัก 6 ชม.)
        $this->setPool('100000', '12000');

        $this->runOnce();

        $d = $this->lastDecision();
        $this->assertSame('cooldown_opposite', $d->reason_code);
        $this->assertSame([], $this->signer->signed);
    }

    public function test_only_one_trade_per_interval(): void
    {
        $this->pastSwap('sell', Wei::toWei('100', 18), 10); // ขายไป 10 นาทีก่อน (ห่าง 30 นาที)
        $this->setPool('100000', '12000');

        $this->runOnce();

        $this->assertSame('interval_wait', $this->lastDecision()->reason_code);
        $this->assertSame([], $this->signer->signed);
    }

    public function test_opposite_side_allowed_again_after_cooldown(): void
    {
        $this->pastSwap('buy', Wei::toWei('20', 6), 7 * 60);
        $this->setPool('100000', '12000');

        $this->runOnce();

        $this->assertSame('sell', $this->lastDecision()->action);
    }

    // ── งบ ──────────────────────────────────────────────────────────

    public function test_daily_budget_exhausted_blocks_buys(): void
    {
        $this->pastSwap('buy', Wei::toWei('200', 6), 60 * 3);
        $this->setPool('125000', '10000');

        $this->runOnce();

        $this->assertSame('budget_daily_exhausted', $this->lastDecision()->reason_code);
        $this->assertSame([], $this->signer->signed);
    }

    public function test_remaining_daily_budget_caps_the_trade(): void
    {
        $this->pastSwap('buy', Wei::toWei('195', 6), 60 * 3);
        $this->setPool('125000', '10000');

        $this->runOnce();

        $this->assertSame('daily_left', $this->lastDecision()->details['limited_by']);
        $this->assertSame(Wei::toWei('5', 6), StabilityTransaction::where('status', 'broadcast')->firstOrFail()->amount_in_wei);
    }

    public function test_without_bridge_proof_only_recorded_deposits_can_be_spent(): void
    {
        // ใช้ไปครบ 1,000 ที่ฝากไว้แล้ว (เมื่อวาน) — ในกระเป๋ายังมี USDT แต่ไม่มีที่มาที่บันทึกไว้
        $this->pastSwap('buy', Wei::toWei('1000', 6), 60 * 25);
        $this->setPool('125000', '10000');

        $this->runOnce();

        $this->assertSame('budget_deposits_exhausted', $this->lastDecision()->reason_code);
        $this->assertSame([], $this->signer->signed);
    }

    public function test_bridge_reserves_that_do_not_back_usdt_block_buys(): void
    {
        config(['stabilizer.bridge_reserves.enabled' => true]);
        $this->app->instance(BridgeReserveSource::class, new class() implements BridgeReserveSource
        {
            public function status(): array
            {
                return ['available' => true, 'backed' => false, 'reserve_usdt' => '900', 'supply_usdt' => '1000', 'checked_at' => now()->toIso8601String(), 'source' => 'test'];
            }
        });
        $this->setPool('125000', '10000');

        $this->runOnce();

        $this->assertSame('usdt_unbacked', $this->lastDecision()->reason_code);
        $this->assertSame([], $this->signer->signed);
    }

    // ── สวิตช์ ──────────────────────────────────────────────────────

    public function test_kill_switch_off_means_no_decision_and_no_signing(): void
    {
        SiteSetting::set('stabilizer', 'stabilizer_enabled', '0', 'boolean');
        $this->setPool('125000', '10000');

        $this->runOnce();

        $this->assertSame(0, StabilityDecision::count());
        $this->assertSame([], $this->signer->signed);
    }

    public function test_env_master_switch_off_overrides_the_admin_switch(): void
    {
        config(['stabilizer.enabled' => false]);
        $this->setPool('125000', '10000');

        $this->runOnce();

        $this->assertSame(0, StabilityDecision::count());
        $this->assertSame([], $this->signer->signed);
        $this->assertSame([], $this->signer->sent);
    }

    // ── ด่านความปลอดภัย ─────────────────────────────────────────────

    public function test_thin_pool_does_nothing(): void
    {
        $this->setPool('199.9', '19.99'); // พูลจริงตอนนี้ — บางเกินจะแตะ

        $this->runOnce();

        $this->assertSame('pool_thin', $this->lastDecision()->reason_code);
        $this->assertSame([], $this->signer->signed);
    }

    public function test_stale_price_data_does_nothing(): void
    {
        $this->seedHistory('0.1', 1440, 30); // แท่งล่าสุดเก่า 30 นาที (เพดาน 15)
        $this->setPool('125000', '10000');

        $this->runOnce();

        $this->assertSame('price_data_stale', $this->lastDecision()->reason_code);
        $this->assertSame([], $this->signer->signed);
    }

    public function test_halted_chain_does_nothing(): void
    {
        $this->chainHeadTime = now()->subMinutes(40)->getTimestamp();
        $this->setPool('125000', '10000');

        $this->runOnce();

        $this->assertSame('chain_stale', $this->lastDecision()->reason_code);
        $this->assertSame([], $this->signer->signed);
    }

    public function test_not_enough_history_does_nothing(): void
    {
        $this->seedHistory('0.1', 120); // 120 จาก 1,440 นาที = 8% (ต้อง 60%)
        $this->setPool('125000', '10000');

        $this->runOnce();

        $this->assertSame('history_insufficient', $this->lastDecision()->reason_code);
    }

    public function test_dry_run_records_and_signs_nothing(): void
    {
        $this->setPool('125000', '10000');

        $this->artisan('stabilizer:run', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, StabilityDecision::count());
        $this->assertSame([], $this->signer->signed);
    }

    // ── ห้ามส่งซ้ำ ───────────────────────────────────────────────────

    public function test_tx_hash_is_persisted_before_broadcast(): void
    {
        $this->setPool('125000', '10000');

        $this->runOnce();

        $this->assertCount(1, $this->signer->sent);
        $this->assertSame([], $this->signer->sentBeforePersist);
    }

    public function test_open_transaction_blocks_any_new_signing(): void
    {
        $this->setPool('125000', '10000');
        $this->runOnce();
        $tx = StabilityTransaction::firstOrFail();
        $this->chainKnownTxs[$tx->tx_hash] = ['hash' => $tx->tx_hash, 'blockNumber' => null];

        $this->travel(40)->minutes();
        $this->chainHeadTime = null;
        $this->runOnce();

        $this->assertCount(1, $this->signer->signed, 'ห้ามเซ็นใหม่ขณะที่ธุรกรรมเดิมยังไม่จบ');
        $this->assertSame('pending_tx', $this->lastDecision()->reason_code);
    }

    public function test_failed_broadcast_is_retried_with_the_same_raw_never_resigned(): void
    {
        $this->setPool('125000', '10000');
        $this->signer->sendResult = 'broadcast_failed';
        $this->runOnce();

        $tx = StabilityTransaction::firstOrFail();
        $this->assertSame(StabilityTransaction::STATUS_SIGNED, $tx->status, 'ส่งไม่สำเร็จต้องยังไม่นับว่าล้ม — โหนดอาจรับไปแล้ว');

        $this->signer->sendResult = 'ok';
        $this->travel(5)->minutes();
        $this->runOnce();

        $this->assertCount(1, $this->signer->signed);
        $this->assertCount(2, $this->signer->sent);
        $this->assertSame($this->signer->sent[0], $this->signer->sent[1], 'ต้องส่ง raw เดิม (nonce เดิม) ไม่ใช่เซ็นใหม่');
        $this->assertSame(StabilityTransaction::STATUS_BROADCAST, $tx->fresh()->status);
    }

    public function test_receipt_confirms_the_trade_and_records_actual_output(): void
    {
        $this->setPool('125000', '10000');
        $this->runOnce();
        $tx = StabilityTransaction::firstOrFail();

        // event Swap ของพูล: ซื้อ = USDT เข้า (amount1In) TPIX ออก (amount0Out)
        $dex = app(TpixDexService::class);
        $this->receiptFor($tx->tx_hash);
        $this->chainReceipts[$tx->tx_hash]['logs'] = [[
            'address' => $this->sPair,
            'topics' => [$dex->eventTopic('Swap(address,uint256,uint256,uint256,uint256,address)')],
            'data' => '0x'.TpixDexService::encUint('0').TpixDexService::encUint($tx->amount_in_wei)
                .TpixDexService::encUint('123456789000000000000').TpixDexService::encUint('0'),
        ]];

        $this->travel(5)->minutes();
        $this->runOnce();

        $tx->refresh();
        $this->assertSame(StabilityTransaction::STATUS_CONFIRMED, $tx->status);
        $this->assertSame('123456789000000000000', $tx->actual_out_wei);
        $this->assertSame(4990, $tx->block_number);
        // ไม้ถัดไปต้องรอครบช่วงพัก (30 นาที) ก่อน
        $this->assertSame('interval_wait', $this->lastDecision()->reason_code);
        $this->assertCount(1, $this->signer->signed);
    }

    public function test_reverted_transaction_pauses_the_reserve(): void
    {
        $this->setPool('125000', '10000');
        $this->runOnce();
        $tx = StabilityTransaction::firstOrFail();
        $this->receiptFor($tx->tx_hash, false);

        $this->travel(5)->minutes();
        $this->runOnce();

        $this->assertSame(StabilityTransaction::STATUS_FAILED, $tx->fresh()->status);
        $this->assertFalse((bool) SiteSetting::get('stabilizer', 'stabilizer_enabled'));
        $this->assertSame('tx_reverted', SiteSetting::get('stabilizer', 'stabilizer_pause_reason'));
        $this->assertCount(1, $this->signer->signed);
    }

    public function test_unseen_transaction_is_dropped_after_timeout_and_its_nonce_reused(): void
    {
        $this->setPool('125000', '10000');
        $this->signer->sendResult = 'broadcast_failed';
        $this->runOnce();
        $first = StabilityTransaction::firstOrFail();

        // 31 นาทีต่อมา: โหนดไม่รู้จัก tx นี้เลย และ nonce บนเชนยังไม่ขยับ
        $this->signer->sendResult = 'ok';
        $this->travel(31)->minutes();
        $this->seedHistory('0.1');
        $this->runOnce();

        $this->assertSame(StabilityTransaction::STATUS_DROPPED, $first->fresh()->status);
        $this->assertCount(2, $this->signer->signed);
        // nonce เดียวกัน = ลงบล็อกได้แค่ตัวเดียว ไม่มีทางเกิดสองไม้
        $this->assertSame($first->nonce, $this->signer->signed[1]['nonce']);
    }

    public function test_waits_when_chain_nonce_has_not_caught_up(): void
    {
        $this->pastSwap('sell', Wei::toWei('10', 18), 60 * 24 * 2, StabilityTransaction::STATUS_CONFIRMED, 5);
        $this->chainNonce = 5; // เชนบอก 5 แต่ไม้ nonce 5 ของเราลงบล็อกแล้ว — ต้องเป็น ≥ 6
        $this->setPool('125000', '10000');

        $this->runOnce();

        $this->assertSame('nonce_behind', $this->lastDecision()->reason_code);
        $this->assertSame([], $this->signer->signed);
    }

    public function test_signing_failure_pauses_the_reserve(): void
    {
        $this->signer->signOk = false;
        $this->setPool('125000', '10000');

        $this->artisan('stabilizer:run')->assertFailed();

        $this->assertSame('sign_failed', $this->lastDecision()->reason_code);
        $this->assertSame(0, StabilityTransaction::count());
        $this->assertSame('sign_failed', SiteSetting::get('stabilizer', 'stabilizer_pause_reason'));
    }

    public function test_unreachable_chain_never_signs(): void
    {
        $this->setPool('125000', '10000');
        $this->chainDown = true;

        $this->runOnce();

        $this->assertSame([], $this->signer->signed);
    }

    public function test_paused_reserve_does_not_rebroadcast_unsent_swaps(): void
    {
        $this->setPool('125000', '10000');
        $this->signer->sendResult = 'broadcast_failed';
        $this->runOnce();

        SiteSetting::set('stabilizer', 'stabilizer_enabled', '0', 'boolean');
        $this->travel(5)->minutes();
        $this->runOnce();

        $this->assertCount(1, $this->signer->sent, 'ปิดสวิตช์แล้วต้องไม่มีไม้ใหม่ขึ้นเชน แม้จะเป็น raw ที่เซ็นไว้ก่อน');
    }

    public function test_scheduler_runs_every_five_minutes_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'stabilizer:run'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }
}
