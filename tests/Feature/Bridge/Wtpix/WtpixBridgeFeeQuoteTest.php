<?php

namespace Tests\Feature\Bridge\Wtpix;

use App\Models\SystemAlert;
use App\Models\WtpixBridgeFeeQuote;
use App\Models\WtpixBridgeTransfer;
use App\Services\Bridge\Abi;
use App\Services\Bridge\Wtpix\WtpixBridgeFeeQuoter;
use App\Services\PriceFeedService;
use App\Services\Stabilizer\StabilizerMarket;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesWtpixBridgeChains;
use Tests\TestCase;

/**
 * ค่าธรรมเนียมขาออก (TPIX → BSC) แบบลอยตัว — ค่าแก๊สจริง + ส่วนเพิ่ม · ปั่นราคายาก · ผู้ใช้ไม่จ่ายเกินที่เห็น.
 *
 * ตัวเลขฐาน: ราคาแก๊ส BSC 1 gwei × 1.2 (ตัวคูณของ relayer) · แก๊ส 150,000 · BNB $600 · ส่วนเพิ่ม 10%
 *   ค่าแก๊ส = 150,000 × 1.2 gwei = 0.00018 BNB = $0.108 → TPIX $0.10 = 1.08 TPIX × 1.1 = 1.188 TPIX
 */
class WtpixBridgeFeeQuoteTest extends TestCase
{
    use FakesWtpixBridgeChains;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWtpixBridge();
        config([
            'wtpix_bridge.limits.lock_fee' => '0.5',
            'wtpix_bridge.limits.lock_fee_max' => '5',
            'wtpix_bridge.fee.markup_bps' => 1000,
            'wtpix_bridge.fee.mint_gas_units' => 150000,
        ]);
        $this->bnb('600');
        $this->market('0.1', '0.1');
    }

    private function bnb(?string $usd): void
    {
        $this->mock(PriceFeedService::class, function (MockInterface $mock) use ($usd) {
            $usd === null
                ? $mock->shouldReceive('getPrice')->with('BNB')->andThrow(new \RuntimeException('feed down'))
                : $mock->shouldReceive('getPrice')->with('BNB')->andReturn((float) $usd);
        });
    }

    /** TWAP สั้น (60 นาที) / ยาว (24 ชม.) ของพูล TPIX-USDT */
    private function market(?string $short, ?string $long, float $coverage = 100.0): void
    {
        $this->mock(StabilizerMarket::class, function (MockInterface $mock) use ($short, $long, $coverage) {
            $mock->shouldReceive('contracts')->andReturn(['router' => '0x1', 'wtpix' => '0x2', 'usdt' => '0x3', 'pair' => '0xpair']);
            $mock->shouldReceive('twap')->andReturnUsing(fn (string $pair, int $minutes) => [
                'price' => $minutes <= 60 ? $short : $long,
                'samples' => 100,
                'coverage_pct' => $coverage,
                'latest_at' => now(),
                'minutes' => $minutes,
            ]);
        });
    }

    private function quoter(): WtpixBridgeFeeQuoter
    {
        return app(WtpixBridgeFeeQuoter::class);
    }

    private function quoteAt(string $fee, Carbon $at): void
    {
        $quote = new WtpixBridgeFeeQuote(['fee_wei' => $this->tpix($fee), 'gas_units' => 150000, 'markup_bps' => 1000, 'source' => 'market']);
        $quote->created_at = $at;
        $quote->updated_at = $at;
        $quote->save();
    }

    #[Test]
    public function the_fee_is_the_real_mint_gas_plus_the_markup_converted_at_the_twap(): void
    {
        $quote = $this->quoter()->refresh();

        $this->assertSame('market', $quote->source);
        $this->assertSame($this->tpix('1.188'), $quote->fee_wei);
        $this->assertSame('1200000000', $quote->gas_price_wei);
        $this->assertSame('600.00000000', $quote->bnb_usd);
    }

    #[Test]
    public function pumping_the_pool_for_an_hour_does_not_make_the_fee_cheaper(): void
    {
        // ราคาสั้นถูกดันขึ้น 5 เท่า แต่ TWAP 24 ชม. ยัง $0.10 — ใช้ตัวที่ต่ำกว่า
        $this->market('0.5', '0.1');

        $this->assertSame($this->tpix('1.188'), $this->quoter()->compute()['fee_wei']);
    }

    #[Test]
    public function a_cheap_mint_never_goes_below_the_floor(): void
    {
        $this->market('1', '1'); // TPIX $1 → ค่าแก๊สจริง 0.1188 TPIX

        $row = $this->quoter()->compute();

        $this->assertSame($this->tpix('0.1188'), $row['raw_fee_wei']);
        $this->assertSame($this->tpix('0.5'), $row['fee_wei']);
    }

    #[Test]
    public function a_crashed_price_is_capped_for_users_and_the_admin_is_told(): void
    {
        $this->market('0.01', '0.01'); // ค่าแก๊สจริง 11.88 TPIX

        $quote = $this->quoter()->refresh();

        $this->assertSame('capped', $quote->source);
        $this->assertSame($this->tpix('5'), $quote->fee_wei);
        $this->assertSame($this->tpix('11.88'), $quote->raw_fee_wei);
        $this->assertTrue(SystemAlert::where('alert_key', WtpixBridgeFeeQuoter::ALERT_CAPPED)->where('status', 'active')->exists());
    }

    #[Test]
    public function missing_market_data_charges_the_ceiling_instead_of_guessing(): void
    {
        $this->market('0.1', '0.1', coverage: 20.0);
        $this->assertSame(['fallback', $this->tpix('5')], [$this->quoter()->compute()['source'], $this->quoter()->compute()['fee_wei']]);

        $this->market('0.1', '0.1');
        $this->bnb(null);
        $this->assertSame('fallback', $this->quoter()->compute()['source']);

        $this->bnb('600');
        $this->chain[56]['down'] = true;
        $quote = $this->quoter()->refresh();
        $this->assertSame('fallback', $quote->source);
        $this->assertSame($this->tpix('5'), $quote->fee_wei);
        $this->assertTrue(SystemAlert::where('alert_key', WtpixBridgeFeeQuoter::ALERT_FALLBACK)->exists());
    }

    #[Test]
    public function the_gas_price_used_is_capped_like_the_relayer_cap(): void
    {
        config(['wtpix_bridge.bsc.max_gas_price_gwei' => '1']);

        $this->assertSame('1000000000', $this->quoter()->compute()['gas_price_wei']);
    }

    #[Test]
    public function gas_units_come_from_the_median_of_real_mint_receipts_once_there_are_enough(): void
    {
        foreach ([70000, 80000, 90000, 100000] as $i => $gas) {
            $this->mintedRow($i, $gas);
        }
        $this->assertSame(150000, $this->quoter()->gasUnits(), 'ตัวอย่างไม่ถึง 5 = ค่าเผื่อจาก config');

        $this->mintedRow(9, 500000);
        $this->assertSame(90000, $this->quoter()->gasUnits(), 'median ไม่ถูกดึงด้วยรายการแพงผิดปกติรายการเดียว');
    }

    private function mintedRow(int $n, int $gasUsed): void
    {
        $tx = '0x'.hash('sha256', "fee-quote-{$n}");
        WtpixBridgeTransfer::create([
            'direction' => WtpixBridgeTransfer::TO_BSC, 'source_chain_id' => 4289, 'source_contract' => $this->wVault,
            'tx_hash' => $tx, 'log_index' => 0, 'block_number' => 1000 + $n, 'from_address' => $this->alice, 'recipient' => $this->alice,
            'amount_wei' => $this->tpix('100'), 'fee_wei' => $this->tpix('1'), 'payout_wei' => $this->tpix('99'),
            'deposit_id' => Abi::depositId(4289, $tx, 0), 'status' => WtpixBridgeTransfer::STATUS_COMPLETED, 'dest_chain_id' => 56,
            'dest_tx_hash' => '0x'.hash('sha256', "dest-{$n}"), 'dest_gas_used' => $gasUsed, 'dest_gas_price_wei' => '1000000000',
        ]);
    }

    #[Test]
    public function a_lock_pays_the_lowest_price_announced_in_the_grace_window_before_its_block(): void
    {
        $block = Carbon::parse('2026-10-27 12:00:00');
        $this->quoteAt('2', $block->copy()->subMinutes(20)); // ประกาศอยู่ตอนต้นช่วง (อายุ 5 นาที)
        $this->quoteAt('3', $block->copy()->subMinutes(10)); // ราคาขึ้นระหว่างที่ผู้ใช้รอ
        $this->quoteAt('4', $block->copy()->addMinute());    // หลังบล็อก — ไม่เกี่ยว

        $this->assertSame($this->tpix('2'), $this->quoter()->feeForLockAt($block->timestamp));

        // ไม่มีราคาที่ยังสดเลย = เพดาน (ตรงกับที่หน้าเว็บโชว์ตอนไม่มีราคาสด)
        $this->assertSame($this->tpix('5'), $this->quoter()->feeForLockAt($block->copy()->addHours(3)->timestamp));
    }

    #[Test]
    public function the_scanner_charges_the_price_announced_at_the_lock_block_not_at_scan_time(): void
    {
        $this->artisan('bridge:wtpix-scan')->assertSuccessful(); // ตั้งเคอร์เซอร์
        $tx = $this->fakeLock($this->alice, $this->tpix('500'), 2001);

        // เวลาบล็อกในเชนจำลอง = 1760000000 + บล็อก × 2
        // (created_at เก็บเป็นเวลาท้องถิ่นของแอป — สร้างในโซนนั้นเหมือนที่ now() สร้างจริง)
        $lockedAt = Carbon::createFromTimestamp(1760000000 + 2001 * 2)->setTimezone(config('app.timezone'));
        $this->quoteAt('1.5', $lockedAt->copy()->subMinutes(3));
        $this->quoteAt('4.5', $lockedAt->copy()->addMinutes(2)); // ราคาขึ้นหลังผู้ใช้ล็อกแล้ว

        $this->chain[4289]['latest'] = 2003;
        Carbon::setTestNow($lockedAt->copy()->addMinutes(10));
        $this->artisan('bridge:wtpix-scan')->assertSuccessful();
        Carbon::setTestNow();

        $row = WtpixBridgeTransfer::where('tx_hash', $tx)->firstOrFail();
        $this->assertSame($this->tpix('1.5'), $row->fee_wei);
        $this->assertSame($this->tpix('498.5'), $row->payout_wei);
    }

    #[Test]
    public function the_relayer_records_the_gas_it_really_paid(): void
    {
        $this->artisan('bridge:wtpix-scan')->assertSuccessful();
        $this->fakeLock($this->alice, $this->tpix('500'), 2001);
        $this->chain[4289]['latest'] = 2003;
        $this->artisan('bridge:wtpix-scan')->assertSuccessful();

        $this->artisan('bridge:wtpix-relay', ['--direction' => 'to_bsc'])->assertSuccessful();

        $row = WtpixBridgeTransfer::firstOrFail();
        $this->assertSame(WtpixBridgeTransfer::STATUS_COMPLETED, $row->status);
        $this->assertSame(90000, $row->dest_gas_used);
        $this->assertSame('1000000000', $row->dest_gas_price_wei);

        $ledger = $this->quoter()->ledger();
        $this->assertSame(1, $ledger['mints']);
        $this->assertSame('0.00009', $ledger['gas_bnb']);
    }

    #[Test]
    public function the_public_info_shows_the_announced_fee_and_how_it_was_made(): void
    {
        $this->quoter()->refresh();

        $this->getJson('/api/v1/bridge/info?asset=tpix')
            ->assertOk()
            ->assertJsonPath('data.limits.lock_fee', '1.188')
            ->assertJsonPath('data.fee_quote.source', 'market')
            ->assertJsonPath('data.fee_quote.floor', '0.5')
            ->assertJsonPath('data.fee_quote.ceiling', '5')
            ->assertJsonPath('data.fee_quote.markup_pct', '10')
            ->assertJsonPath('data.fee_quote.grace_minutes', 15);
    }

    #[Test]
    public function without_a_fresh_quote_the_page_shows_the_ceiling(): void
    {
        $this->quoteAt('1', now()->subHour());

        $this->getJson('/api/v1/bridge/info?asset=tpix')
            ->assertOk()
            ->assertJsonPath('data.limits.lock_fee', '5')
            ->assertJsonPath('data.fee_quote.source', 'fallback');
    }
}
