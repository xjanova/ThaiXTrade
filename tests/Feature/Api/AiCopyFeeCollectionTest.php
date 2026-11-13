<?php

namespace Tests\Feature\Api;

use App\Models\AiBotConfig;
use App\Models\AiCopyFee;
use App\Models\AiCopyFollow;
use App\Models\AiCopyMaster;
use App\Models\TradingCredit;
use App\Services\AiBot\Copy\CopyTradeService;
use App\Services\AiBot\Copy\FeeCollector;
use App\Services\Trading\TradingCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Api\Concerns\CopyTradeFixtures;
use Tests\TestCase;

/**
 * TPIX TRADE — ค่าบริการ Copy Trade ถูกหักจากคลัง TPIX ที่ผู้ตามเติมไว้ (เจ้าของสั่ง 2026-11-13).
 *
 * สิ่งที่ชุดนี้ยึดไว้ (พังข้อไหน = เงินของผู้ใช้ผิด):
 *   - ยอดไม่พอ = กดตามไม่ได้ และคำเตือนบอกตัวเลขว่าต้องเติมอีกเท่าไหร่
 *   - ไม้หนึ่งไม้ถูกหักครั้งเดียว มาสเตอร์ได้ส่วนของตัวเองครั้งเดียว ไม่ว่าจะถูกไล่เก็บกี่รอบ
 *   - ยอดไม่พอจ่าย = ไม่หักบางส่วน ยอดไม่ติดลบ รายการค้างไว้ บอทหยุดเปิดไม้ใหม่ และเก็บเองเมื่อเติมแล้ว
 *   - ค่าบริการค้างจ่ายกันการไปตามมาสเตอร์คนอื่น
 *   - ปิดธงเก็บค่าบริการ = ไม่มีด่านยอด ไม่มีการหัก
 *
 * ตัวเลขในชุดนี้: ราคา TPIX ปลอม 0.5 USD · อัตรา 20% · แพลตฟอร์ม 30% ของค่าบริการ
 *   กำไรผู้ตาม 1000 USD → ค่าบริการ 200 USD = 400 TPIX → มาสเตอร์ 280 TPIX · แพลตฟอร์ม 120 TPIX
 *
 * Developed by Xman Studio.
 */
class AiCopyFeeCollectionTest extends TestCase
{
    use CopyTradeFixtures;
    use RefreshDatabase;

    private function collecting(array $fees = []): void
    {
        config(['aibot.copy_trade.fees.collect' => true, 'aibot.copy_trade.fees.min_balance_tpix' => 10, 'aibot.copy_trade.fees.reserve_profit_pct' => 5]);

        foreach ($fees as $key => $value) {
            config(["aibot.copy_trade.fees.{$key}" => $value]);
        }
    }

    private function topup(string $wallet, float $amount): void
    {
        app(TradingCreditService::class)->topup($wallet, $amount, '0x'.bin2hex(random_bytes(32)));
    }

    private function balance(string $wallet): float
    {
        return app(TradingCreditService::class)->balanceFor($wallet);
    }

    private function me(string $wallet = self::FOLLOWER): array
    {
        return $this->getJson('/api/v1/ai-bot/copy/me?wallet_address='.$wallet)->assertOk()->json('data');
    }

    /** ผู้ตามที่มียอดในคลังและตามมาสเตอร์อยู่ */
    private function following(float $balance): AiCopyFollow
    {
        $master = $this->registerMaster();
        $this->topup(self::FOLLOWER, $balance);
        $this->follow($master)->assertCreated();

        return AiCopyFollow::firstOrFail();
    }

    // ── ด่านตอนกดตาม ─────────────────────────────────────────────────────────

    #[Test]
    public function following_is_refused_with_a_clear_warning_when_the_prepaid_balance_is_too_low(): void
    {
        $this->collecting();
        $master = $this->registerMaster();
        $this->topup(self::FOLLOWER, 4);

        $response = $this->follow($master)->assertStatus(422)->assertJsonPath('error.code', FeeCollector::ERR_BALANCE_LOW);

        // คำเตือนต้องบอกตัวเลขครบ: ต้องมีเท่าไหร่ มีอยู่เท่าไหร่ ขาดอีกเท่าไหร่
        $this->assertSame(['balance_tpix' => 4, 'required_tpix' => 10, 'shortfall_tpix' => 6, 'owed_tpix' => 0], $response->json('error.balance'));
        $message = $response->json('error.message');
        $this->assertStringContainsString('10 TPIX', $message);
        $this->assertStringContainsString('4 TPIX', $message);
        $this->assertStringContainsString('6 TPIX', $message);

        // ไม่มีบอท ไม่มีแถวการตาม ถูกสร้างค้างไว้
        $this->assertDatabaseCount('ai_copy_follows', 0);
        $this->assertSame(0, AiBotConfig::where('wallet_address', self::FOLLOWER)->count());

        $this->topup(self::FOLLOWER, 6);
        $this->follow($master)->assertCreated();
    }

    #[Test]
    public function the_balance_needed_to_follow_grows_with_the_capital_the_follower_sets(): void
    {
        // ทุน 200 USD · กันไว้เท่ากับค่าบริการของกำไร 50% ของทุน = 200 × 50% × 20% = 20 USD = 40 TPIX
        $this->collecting(['reserve_profit_pct' => 50]);
        $master = $this->registerMaster();
        $this->topup(self::FOLLOWER, 39);

        $this->follow($master)->assertStatus(422)->assertJsonPath('error.balance.required_tpix', 40);

        $this->topup(self::FOLLOWER, 1);
        $this->follow($master)->assertCreated();

        // หน้าจอได้ตัวเลขชุดเดียวกับที่ด่านใช้
        $fees = $this->getJson('/api/v1/ai-bot/copy/masters')->json('data.fees');
        $this->assertSame([true, 10, 50, 0.5], [$fees['collect'], $fees['min_balance_tpix'], $fees['reserve_profit_pct'], $fees['tpix_usd_price']]);
    }

    #[Test]
    public function with_collection_switched_off_there_is_no_balance_gate_and_nothing_is_charged(): void
    {
        $master = $this->registerMaster();
        $this->follow($master)->assertCreated();
        $follow = AiCopyFollow::firstOrFail();

        $this->followerSell($follow, 1000.0);
        app(CopyTradeService::class)->settleFees($follow);

        $this->assertSame(AiCopyFee::STATUS_RECORDED, AiCopyFee::firstOrFail()->status);
        $this->assertDatabaseCount('trading_credits', 0);
        $this->assertNull($this->me()['fee_balance']);
        $this->assertFalse(app(CopyTradeService::class)->signalContext($follow->bot)['fee_blocked']);

        // เปิดเก็บทีหลัง: รายการที่ "ลงบัญชีให้เห็น" ไว้ตอนยังไม่เปิดเก็บไม่ถูกเก็บย้อนหลัง
        $this->collecting();
        $this->topup(self::FOLLOWER, 1000);
        $this->assertSame(0, app(FeeCollector::class)->collect(self::FOLLOWER));
        $this->assertEqualsWithDelta(1000.0, $this->balance(self::FOLLOWER), 1e-6);
    }

    // ── การหัก ───────────────────────────────────────────────────────────────

    #[Test]
    public function the_fee_is_deducted_once_and_the_master_is_credited_once(): void
    {
        $this->collecting();
        $follow = $this->following(500);
        $service = app(CopyTradeService::class);

        $this->followerSell($follow, 1000.0);
        $this->assertSame(1, $service->settleFees($follow));

        $fee = AiCopyFee::firstOrFail();
        $this->assertSame(AiCopyFee::STATUS_SETTLED, $fee->status);
        $this->assertNotNull($fee->settled_at);
        $this->assertEqualsWithDelta(100.0, $this->balance(self::FOLLOWER), 1e-6);
        $this->assertEqualsWithDelta(280.0, $this->balance(self::MASTER), 1e-6);

        // ไล่เก็บซ้ำ (รอบถัดไปของบอท · ผู้ตามเปิดหน้า · เรียกตรง) — ไม่หักซ้ำ ไม่จ่ายซ้ำ
        $service->settleFees($follow->fresh());
        app(FeeCollector::class)->collect(self::FOLLOWER);
        $this->me();

        $this->assertEqualsWithDelta(100.0, $this->balance(self::FOLLOWER), 1e-6);
        $this->assertEqualsWithDelta(280.0, $this->balance(self::MASTER), 1e-6);

        $rows = TradingCredit::whereIn('reference', ["copyfee:{$fee->id}", "copyincome:{$fee->id}"])->orderBy('id')->get();
        $this->assertSame([[self::FOLLOWER, 'charge', -400.0], [self::MASTER, 'bonus', 280.0]], $rows->map(fn (TradingCredit $row) => [$row->wallet_address, $row->type, (float) $row->amount])->all());
        $this->assertSame('copy_trade', $rows[0]->meta['source']);

        // ทั้งสองฝั่งเห็นตัวเลขที่เกิดจริง
        $mine = $this->me();
        $this->assertEqualsWithDelta(400.0, $mine['follows'][0]['fees_paid_tpix'], 1e-6);
        $this->assertEqualsWithDelta(0.0, $mine['follows'][0]['fees_owed_tpix'], 1e-6);
        $this->assertFalse($mine['fee_balance']['blocked']);

        $earnings = $this->me(self::MASTER)['master']['earnings'];
        $this->assertEqualsWithDelta(280.0, $earnings['master_tpix_paid'], 1e-6);
        $this->assertEqualsWithDelta(0.0, $earnings['master_tpix_pending'], 1e-6);
    }

    #[Test]
    public function a_fee_the_balance_cannot_cover_stays_owed_blocks_new_entries_and_is_collected_after_a_top_up(): void
    {
        $this->collecting();
        $follow = $this->following(50);
        $service = app(CopyTradeService::class);

        $this->followerSell($follow, 1000.0);
        $service->settleFees($follow);

        // ไม่หักบางส่วน ยอดไม่ติดลบ มาสเตอร์ยังไม่ได้อะไร
        $this->assertSame(AiCopyFee::STATUS_ACCRUED, AiCopyFee::firstOrFail()->status);
        $this->assertEqualsWithDelta(50.0, $this->balance(self::FOLLOWER), 1e-6);
        $this->assertEqualsWithDelta(0.0, $this->balance(self::MASTER), 1e-6);

        // บอทหยุดเปิดไม้ใหม่ และผู้ตามเห็นคำเตือนพร้อมตัวเลขที่ค้าง
        $this->assertTrue($service->signalContext($follow->bot)['fee_blocked']);
        $state = $this->me()['fee_balance'];
        $this->assertTrue($state['blocked']);
        $this->assertSame([1, 400, 50], [$state['owed_count'], $state['owed_tpix'], $state['balance_tpix']]);
        $this->assertEqualsWithDelta(280.0, $this->me(self::MASTER)['master']['earnings']['master_tpix_pending'], 1e-6);

        // เติมแล้ว รอบถัดไปเก็บเอง — ไม่ต้องมีไม้ใหม่
        $this->topup(self::FOLLOWER, 400);
        $this->assertSame(0, $service->settleFees($follow->fresh()));

        $this->assertSame(AiCopyFee::STATUS_SETTLED, AiCopyFee::firstOrFail()->status);
        $this->assertEqualsWithDelta(50.0, $this->balance(self::FOLLOWER), 1e-6);
        $this->assertEqualsWithDelta(280.0, $this->balance(self::MASTER), 1e-6);
        $this->assertFalse($service->signalContext($follow->bot)['fee_blocked']);
    }

    #[Test]
    public function a_balance_below_the_minimum_stops_new_entries_even_with_nothing_owed(): void
    {
        $this->collecting();
        $follow = $this->following(405);
        $service = app(CopyTradeService::class);

        $this->followerSell($follow, 1000.0);
        $service->settleFees($follow);

        // จ่ายครบแล้ว แต่เหลือ 5 TPIX ต่ำกว่าขั้นต่ำ 10
        $this->assertSame(AiCopyFee::STATUS_SETTLED, AiCopyFee::firstOrFail()->status);
        $this->assertTrue($service->signalContext($follow->bot)['fee_blocked']);

        $state = $this->me()['fee_balance'];
        $this->assertSame([true, 0, 5, 5], [$state['blocked'], $state['owed_count'], $state['balance_tpix'], $state['shortfall_tpix']]);

        $this->topup(self::FOLLOWER, 5);
        $this->assertFalse($service->signalContext($follow->bot)['fee_blocked']);
    }

    #[Test]
    public function a_fee_balance_that_cannot_be_read_stops_new_entries_instead_of_breaking_the_bot(): void
    {
        $this->collecting();
        $follow = $this->following(500);

        $this->mock(FeeCollector::class, function ($mock) {
            $mock->shouldReceive('gate')->andThrow(new \RuntimeException('db down'));
        });

        $context = app(CopyTradeService::class)->signalContext($follow->bot);

        $this->assertSame('ok', $context['state']);
        $this->assertTrue($context['fee_blocked']);
    }

    #[Test]
    public function older_debts_are_collected_first_and_collection_stops_at_the_first_one_that_does_not_fit(): void
    {
        $this->collecting();
        $follow = $this->following(100);
        $service = app(CopyTradeService::class);

        // สามไม้กำไร: ค่าบริการ 40 · 400 · 20 TPIX
        foreach ([100.0, 1000.0, 50.0] as $pnl) {
            $this->followerSell($follow, $pnl);
        }
        $service->settleFees($follow);

        // เก็บได้แค่ใบแรก — ใบที่สองไม่พอ ใบที่สามแม้จะพอก็ไม่ข้ามคิว
        $this->assertSame([AiCopyFee::STATUS_SETTLED, AiCopyFee::STATUS_ACCRUED, AiCopyFee::STATUS_ACCRUED], AiCopyFee::orderBy('id')->pluck('status')->all());
        $this->assertEqualsWithDelta(60.0, $this->balance(self::FOLLOWER), 1e-6);
    }

    #[Test]
    public function unpaid_fees_block_following_another_master(): void
    {
        $this->collecting();
        $follow = $this->following(50);
        $this->followerSell($follow, 1000.0);
        app(CopyTradeService::class)->settleFees($follow);

        $second = $this->registerMaster(['wallet_address' => self::OTHER, 'display_name' => 'Second Master']);

        $response = $this->follow($second)->assertStatus(422)->assertJsonPath('error.code', FeeCollector::ERR_BALANCE_LOW);
        $this->assertStringContainsString('400 TPIX', $response->json('error.message'));
        // ต้องมีพอจ่ายของที่ค้าง 400 + ยอดขั้นต่ำ 10
        $this->assertSame(410, $response->json('error.balance.required_tpix'));
        $this->assertSame(1, AiCopyFollow::count());

        // เติมพอ: ของที่ค้างถูกเก็บก่อน แล้วจึงตามได้
        $this->topup(self::FOLLOWER, 360);
        $this->follow($second)->assertCreated();
        $this->assertSame(AiCopyFee::STATUS_SETTLED, AiCopyFee::firstOrFail()->status);
        $this->assertEqualsWithDelta(10.0, $this->balance(self::FOLLOWER), 1e-6);
    }

    #[Test]
    public function a_fee_recorded_without_a_tpix_price_is_converted_when_it_is_collected(): void
    {
        $this->collecting();
        $follow = $this->following(500);
        $master = AiCopyMaster::firstOrFail();

        // ตอนลงบัญชีไม่มีราคา TPIX — เก็บเป็น USD ไว้ก่อน
        $fee = AiCopyFee::create([
            'ai_copy_follow_id' => $follow->id, 'ai_copy_master_id' => $master->id, 'ai_bot_trade_id' => 999_001,
            'follower_wallet' => self::FOLLOWER, 'master_wallet' => self::MASTER,
            'profit_usd' => 100, 'fee_rate_pct' => 20, 'platform_share_pct' => 30,
            'fee_usd' => 20, 'master_usd' => 14, 'platform_usd' => 6,
            'status' => AiCopyFee::STATUS_ACCRUED, 'charged_at' => now(),
        ]);

        $this->assertSame(1, app(FeeCollector::class)->collect(self::FOLLOWER));

        $fee->refresh();
        $this->assertSame(AiCopyFee::STATUS_SETTLED, $fee->status);
        $this->assertEqualsWithDelta(40.0, $fee->fee_tpix, 1e-6);
        $this->assertEqualsWithDelta(0.5, $fee->tpix_usd_price, 1e-9);
        $this->assertEqualsWithDelta(460.0, $this->balance(self::FOLLOWER), 1e-6);
        $this->assertEqualsWithDelta(28.0, $this->balance(self::MASTER), 1e-6);
    }

    #[Test]
    public function stopping_a_follow_still_settles_what_is_owed_and_never_blocks_leaving(): void
    {
        $this->collecting();
        $follow = $this->following(50);
        $this->followerSell($follow, 1000.0);

        // เลิกตามได้เสมอ แม้ยอดไม่พอจ่าย — ของที่ค้างยังอยู่กับกระเป๋า
        $this->postJson("/api/v1/ai-bot/copy/follow/{$follow->id}/stop", ['wallet_address' => self::FOLLOWER])->assertOk()->assertJsonPath('data.status', 'stopped');
        $this->assertSame(AiCopyFee::STATUS_ACCRUED, AiCopyFee::firstOrFail()->status);

        // เติมแล้วเปิดหน้า Copy Trade = ถูกเก็บ แม้ไม่ได้ตามใครอยู่แล้ว
        $this->topup(self::FOLLOWER, 400);
        $this->me();
        $this->assertSame(AiCopyFee::STATUS_SETTLED, AiCopyFee::firstOrFail()->status);
    }
}
