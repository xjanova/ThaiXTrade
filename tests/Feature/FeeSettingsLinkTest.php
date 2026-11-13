<?php

namespace Tests\Feature;

use App\Models\Chain;
use App\Models\FeeConfig;
use App\Models\SiteSetting;
use App\Models\Token;
use App\Models\TradingPair;
use App\Services\FeeCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * ค่าธรรมเนียมที่แอดมินตั้งในหลังบ้าน ต้องไปถึงตัวคิดเงินฝั่งผู้ใช้ "ทันที" และ "ตรงกัน".
 *
 * อาการเดิม (ไล่เส้นทาง 2026-11-13):
 *   - ไม่มีใครล้างแคชของ FeeCalculationService — แก้อัตราแล้วมีผลช้าได้ถึง 10 นาที (15 นาทีที่ /api/v1/fees)
 *   - ลบช่อง "เพดานอัตรา" ทิ้งแล้วบันทึก → ค่าธรรมเนียมทุกไม้เป็น 0 เงียบๆ
 *   - อัตราทับรายคู่ของคู่ที่มีเหรียญหลักของเชน (BNB) โชว์ในหน้าเทรดแต่ไม่ถูกคิดจริง
 *   - /api/v1/fees?chain_id=56 เอาเลขเชนจริงไปค้นช่องที่เก็บ PK ของตาราง chains
 *
 * Developed by Xman Studio
 */
class FeeSettingsLinkTest extends TestCase
{
    use RefreshDatabase;

    private const NATIVE_ZERO = '0x0000000000000000000000000000000000000000';

    private const NATIVE_SENTINEL = '0xEeeeeEeeeEeEeeEeEeEeeEEEeeeeEeeeeeeeEEeE';

    private const USDT = '0x55d398326f99059fF775485246999027B3197955';

    private Chain $bsc;

    private FeeCalculationService $fees;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware([ThrottleRequests::class]);

        $this->bsc = Chain::where('chain_id', 56)->firstOrFail();
        $this->bsc->update(['is_active' => true]);
        FeeConfig::query()->delete();
        SiteSetting::set('trading', 'default_fee_rate', '0.3', 'number');
        SiteSetting::set('trading', 'max_fee_rate', '5', 'number');
        Cache::flush();

        $this->fees = app(FeeCalculationService::class);
    }

    private function rate(?int $pairId = null): float
    {
        return $this->fees->calculateSwapFee(100.0, $this->bsc->id, $pairId)['fee_rate'];
    }

    private function bnbPair(?string $override = null): TradingPair
    {
        $bnb = Token::firstOrCreate(
            ['chain_id' => $this->bsc->id, 'contract_address' => self::NATIVE_ZERO],
            ['symbol' => 'BNB', 'name' => 'BNB', 'decimals' => 18, 'is_active' => true],
        );
        $usdt = Token::firstOrCreate(
            ['chain_id' => $this->bsc->id, 'contract_address' => strtolower(self::USDT)],
            ['symbol' => 'USDT', 'name' => 'Tether', 'decimals' => 18, 'is_active' => true],
        );

        return TradingPair::create([
            'base_token_id' => $bnb->id,
            'quote_token_id' => $usdt->id,
            'chain_id' => $this->bsc->id,
            'symbol' => 'BNB-USDT',
            'is_active' => true,
            'execution_mode' => 'onchain',
            'taker_fee_override' => $override,
        ]);
    }

    // ── แอดมินแก้ → มีผลทันที ───────────────────────────────────────────────

    public function test_changing_the_default_rate_takes_effect_on_the_next_quote(): void
    {
        $this->assertSame(0.3, $this->rate());   // อุ่นแคช

        SiteSetting::set('trading', 'default_fee_rate', '0.5', 'number');

        $this->assertSame(0.5, $this->rate());
    }

    public function test_adding_editing_and_removing_a_fee_config_row_takes_effect_immediately(): void
    {
        $this->assertSame(0.3, $this->rate());

        $row = FeeConfig::create([
            'name' => 'BSC swap', 'type' => 'swap', 'maker_fee' => '0.2', 'taker_fee' => '0.2',
            'chain_id' => $this->bsc->id, 'is_active' => true,
        ]);
        $this->assertSame(0.2, $this->rate());

        $row->update(['taker_fee' => '0.25']);
        $this->assertSame(0.25, $this->rate());

        $row->delete();
        $this->assertSame(0.3, $this->rate());
    }

    public function test_changing_a_pair_override_takes_effect_immediately(): void
    {
        $pair = $this->bnbPair('0.1');
        $this->assertSame(0.1, $this->rate($pair->id));

        $pair->update(['taker_fee_override' => '0.15']);
        $this->assertSame(0.15, $this->rate($pair->id));

        $pair->update(['taker_fee_override' => null]);
        $this->assertSame(0.3, $this->rate($pair->id));
    }

    public function test_saving_a_pair_or_setting_that_does_not_touch_fees_keeps_the_cache(): void
    {
        // โหลดใหม่จากฐานข้อมูล — แบบเดียวกับ dex:sync ที่หยิบคู่เดิมขึ้นมาแก้ (ไม่ใช่อินสแตนซ์ที่เพิ่งสร้าง)
        $pair = $this->bnbPair('0.1')->fresh();
        $before = FeeCalculationService::generation();

        // dex:sync แตะคู่เทรดกับราคา TPIX ทุกนาที — ต้องไม่ทิ้งแคชค่าธรรมเนียมทุกนาทีตามไปด้วย
        $pair->update(['sort_order' => 5]);
        $pair->save();
        SiteSetting::set('trading', 'tpix_price', '0.1234', 'number');
        SiteSetting::set('trading', 'default_fee_rate', '0.3', 'number');   // ค่าเดิม ไม่ได้เปลี่ยน

        $this->assertSame($before, FeeCalculationService::generation());
    }

    // ── ค่าที่ว่าง/ผิดรูป ───────────────────────────────────────────────────

    public function test_a_blank_or_invalid_cap_does_not_zero_every_fee(): void
    {
        foreach (['', '0', '-1', 'abc'] as $bad) {
            SiteSetting::set('trading', 'max_fee_rate', $bad, 'number');

            $this->assertSame(0.3, $this->rate(), "max_fee_rate = '{$bad}'");
        }
    }

    public function test_the_cap_still_caps(): void
    {
        SiteSetting::set('trading', 'default_fee_rate', '9', 'number');
        SiteSetting::set('trading', 'max_fee_rate', '2', 'number');

        $this->assertSame(2.0, $this->rate());
    }

    public function test_a_blank_default_rate_means_unset_but_an_explicit_zero_means_free(): void
    {
        SiteSetting::set('trading', 'default_fee_rate', '', 'number');
        $this->assertSame(0.3, $this->rate());

        SiteSetting::set('trading', 'default_fee_rate', '-0.5', 'number');
        $this->assertSame(0.3, $this->rate());

        SiteSetting::set('trading', 'default_fee_rate', '0', 'number');
        $this->assertSame(0.0, $this->rate());
    }

    // ── อัตราทับรายคู่ของคู่ที่มีเหรียญหลักของเชน ────────────────────────────

    public function test_pair_override_applies_whichever_way_the_native_coin_and_addresses_are_written(): void
    {
        $this->bnbPair('0.1');

        $forms = [
            [self::NATIVE_SENTINEL, self::USDT],                       // หน้าเว็บ: BNB เป็น 0xEeee… + checksum
            [self::NATIVE_ZERO, strtolower(self::USDT)],               // รูปที่เก็บในตาราง
            [strtoupper(self::USDT), strtolower(self::NATIVE_SENTINEL)], // ขาย: สลับฝั่ง + ตัวพิมพ์ต่างกัน
        ];

        foreach ($forms as [$from, $to]) {
            $fee = $this->fees->calculateSwapFeeForTokens(100.0, $from, $to, $this->bsc->id);

            $this->assertSame(0.1, $fee['fee_rate'], "{$from} → {$to}");
            $this->assertSame(0.1, $fee['fee_amount']);
        }
    }

    public function test_tokens_of_an_unlisted_pair_fall_back_to_the_chain_rate(): void
    {
        $this->bnbPair('0.1');

        $fee = $this->fees->calculateSwapFeeForTokens(100.0, '0x'.str_repeat('1', 40), self::USDT, $this->bsc->id);

        $this->assertSame(0.3, $fee['fee_rate']);
    }

    // ── ค่าธรรมเนียมของสมุดคำสั่งภายใน (ยังปิดอยู่ — ต้องไม่เป็น 0 เงียบๆ ในวันที่เปิด) ───────

    public function test_order_book_fees_read_the_trading_fee_row_with_a_global_fallback(): void
    {
        $pair = $this->bnbPair();

        // ไม่มีแถวค่าธรรมเนียมเลย — 0 ตามเดิม
        $this->assertSame(['maker_fee' => '0.0000', 'taker_fee' => '0.0000'], $pair->getEffectiveFees());

        // แถวกลาง (ไม่ผูกเชน) — เดิมถูกมองข้ามเพราะค้นด้วย type 'trade' ที่ไม่มีอยู่จริง และไม่มีทางสำรอง
        FeeConfig::create([
            'name' => 'Trading', 'type' => 'trading', 'maker_fee' => '0.1', 'taker_fee' => '0.2',
            'chain_id' => null, 'is_active' => true,
        ]);
        $fees = $pair->getEffectiveFees();
        $this->assertSame(0.1, (float) $fees['maker_fee']);
        $this->assertSame(0.2, (float) $fees['taker_fee']);

        // แถวของเชนนี้ชนะแถวกลาง · แถวชนิดอื่น (swap) ไม่เกี่ยว
        FeeConfig::create([
            'name' => 'BSC trading', 'type' => 'trading', 'maker_fee' => '0.05', 'taker_fee' => '0.15',
            'chain_id' => $this->bsc->id, 'is_active' => true,
        ]);
        FeeConfig::create([
            'name' => 'BSC swap', 'type' => 'swap', 'maker_fee' => '9', 'taker_fee' => '9',
            'chain_id' => $this->bsc->id, 'is_active' => true,
        ]);
        $fees = $pair->getEffectiveFees();
        $this->assertSame(0.05, (float) $fees['maker_fee']);
        $this->assertSame(0.15, (float) $fees['taker_fee']);

        // อัตราทับรายคู่ชนะทุกอย่าง
        $pair->update(['maker_fee_override' => '0.01', 'taker_fee_override' => '0.02']);
        $fees = $pair->fresh()->getEffectiveFees();
        $this->assertSame(0.01, (float) $fees['maker_fee']);
        $this->assertSame(0.02, (float) $fees['taker_fee']);
    }

    // ── /api/v1/fees (แอปมือถือ) ────────────────────────────────────────────

    public function test_fees_endpoint_reads_the_chain_specific_rate_by_real_chain_id(): void
    {
        FeeConfig::create([
            'name' => 'BSC swap', 'type' => 'swap', 'maker_fee' => '0.2', 'taker_fee' => '0.2',
            'chain_id' => $this->bsc->id, 'is_active' => true,
        ]);

        $this->getJson('/api/v1/fees?chain_id=56')
            ->assertOk()
            ->assertJsonPath('swap.feePercent', 0.2)
            ->assertJsonPath('swap.chainId', 56);

        // ไม่ระบุเชน / เชนที่ไม่รู้จัก = อัตรากลาง (และไม่สร้างคีย์แคชต่อเลขที่ยิงมามั่ว)
        $this->getJson('/api/v1/fees')->assertJsonPath('swap.feePercent', 0.3);
        $this->getJson('/api/v1/fees?chain_id=999999')
            ->assertJsonPath('swap.feePercent', 0.3)
            ->assertJsonPath('swap.chainId', null);
    }

    public function test_fees_endpoint_shows_a_newly_set_collector_wallet_and_rate_at_once(): void
    {
        SiteSetting::set('trading', 'fee_collector_wallet', '');
        $this->getJson('/api/v1/fees')->assertJsonPath('swap.enabled', false);

        $wallet = '0x'.str_repeat('f', 40);
        SiteSetting::set('trading', 'fee_collector_wallet', $wallet);
        SiteSetting::set('trading', 'default_fee_rate', '0.4', 'number');

        $this->getJson('/api/v1/fees')
            ->assertJsonPath('swap.enabled', true)
            ->assertJsonPath('swap.feeWallet', $wallet)
            ->assertJsonPath('swap.feePercent', 0.4);
    }
}
