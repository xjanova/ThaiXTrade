<?php

namespace Tests\Feature\Api;

use App\Models\Chain;
use App\Models\SiteSetting;
use App\Models\Token;
use App\Models\TradingPair;
use App\Services\FeeCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — อัตราค่าธรรมเนียมที่หน้าเทรดโชว์ ต้องเท่ากับที่ระบบหักจริง.
 *
 * เจ้าของสั่งว่า "ต้องคำนวณค่า fee ให้ชัดเจน อย่าให้ผิดพลาดส่วนนี้
 * มีผลกับการเทรดอย่างมาก"
 *
 * ⚠️ อาการเดิม: ฟอร์มดึงอัตราจาก /api/v1/swap/routes (ค่าของการสลับเหรียญ)
 *    แล้วตกมาที่ 0.1% ขณะที่ระบบเก็บจริง 0.3% — ผู้ใช้คำนวณกำไรผิดทุกไม้
 *    และไม่มีทางรู้จนกว่าจะนั่งเทียบยอดย้อนหลัง ไม่มี error ให้เห็นเลยสักบรรทัด
 *
 * ชุดนี้ตรึงว่าเลขที่ API ส่งให้หน้าเว็บ มาจากตัวคำนวณเดียวกับที่หักเงินเสมอ
 *
 * Developed by Xman Studio.
 */
class PairFeeRateTest extends TestCase
{
    use RefreshDatabase;

    private const CHAIN_ID = 56;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function makePair(?float $override = null): TradingPair
    {
        $chain = Chain::firstOrCreate(
            ['chain_id' => self::CHAIN_ID],
            [
                'name' => 'BSC',
                'symbol' => 'BNB',
                'rpc_url' => 'https://bsc.example',
                'native_currency_name' => 'BNB',
                'native_currency_symbol' => 'BNB',
                'is_active' => true,
            ],
        );

        $base = Token::firstOrCreate(
            ['symbol' => 'BTC', 'chain_id' => $chain->id],
            [
                'name' => 'Bitcoin',
                'contract_address' => '0x'.str_repeat('b', 40),
                'decimals' => 18,
                'is_active' => true,
            ],
        );
        $quote = Token::firstOrCreate(
            ['symbol' => 'USDT', 'chain_id' => $chain->id],
            [
                'name' => 'Tether',
                'contract_address' => '0x'.str_repeat('c', 40),
                'decimals' => 18,
                'is_active' => true,
            ],
        );

        return TradingPair::create([
            'symbol' => 'BTC-USDT',
            'base_token_id' => $base->id,
            'quote_token_id' => $quote->id,
            'chain_id' => $chain->id,
            'taker_fee_override' => $override,
            'is_active' => true,
        ]);
    }

    private function pairFeeRateFromApi(): ?float
    {
        $pairs = $this->getJson('/api/v1/market/pairs')->assertOk()->json('data');

        return collect($pairs)->firstWhere('symbol', 'BTC-USDT')['fee_rate'] ?? null;
    }

    /** ไม่มีค่าตั้งทับ ต้องได้ค่าปริยายจริง ไม่ใช่ null ให้หน้าเว็บไปเดาเอง */
    #[Test]
    public function ไม่มีค่าตั้งทับก็ยังบอกอัตราจริงมาให้(): void
    {
        SiteSetting::set('trading', 'default_fee_rate', '0.3');
        $this->makePair();

        $this->assertSame(0.3, $this->pairFeeRateFromApi());
    }

    #[Test]
    public function ค่าตั้งทับรายคู่ชนะค่าปริยาย(): void
    {
        SiteSetting::set('trading', 'default_fee_rate', '0.3');
        $this->makePair(override: 0.05);

        $this->assertSame(0.05, $this->pairFeeRateFromApi());
    }

    /** แอดมินตั้งเพดานไว้ ค่าตั้งทับที่สูงกว่าต้องถูกตัดลงมา ทั้งที่โชว์และที่หัก */
    #[Test]
    public function เพดานของแอดมินคุมทั้งเลขที่โชว์และที่หัก(): void
    {
        SiteSetting::set('trading', 'max_fee_rate', '1.0');
        $pair = $this->makePair(override: 4.5);

        $shown = $this->pairFeeRateFromApi();
        $charged = app(FeeCalculationService::class)
            ->calculateSwapFee(100.0, self::CHAIN_ID, $pair->id)['fee_rate'];

        $this->assertSame(1.0, $shown);
        $this->assertSame($charged, $shown);
    }

    /**
     * ตัวเลขสองฝั่งต้องตรงกันทุกกรณี ไม่ใช่แค่กรณีปริยาย.
     *
     * นี่คือข้อที่กันการถอยหลังจริงๆ — ใครก็ตามที่ไปแก้ลำดับการหาอัตราในอนาคต
     * จะทำให้เทสต์นี้แดงทันที แทนที่จะปล่อยให้สองฝั่งเพี้ยนกันเงียบๆ
     */
    #[Test]
    public function เลขที่โชว์ตรงกับที่หักเสมอ(): void
    {
        $cases = [
            ['default' => '0.3', 'override' => null],
            ['default' => '0.3', 'override' => 0.1],
            ['default' => '0.25', 'override' => 0.0],
            ['default' => '1.5', 'override' => null],
        ];

        foreach ($cases as $case) {
            Cache::flush();
            TradingPair::query()->forceDelete();
            SiteSetting::set('trading', 'default_fee_rate', $case['default']);
            SiteSetting::clearCache();

            $pair = $this->makePair(override: $case['override']);

            $charged = app(FeeCalculationService::class)
                ->calculateSwapFee(100.0, self::CHAIN_ID, $pair->id)['fee_rate'];

            $this->assertSame(
                $charged,
                $this->pairFeeRateFromApi(),
                'เลขที่โชว์กับที่หักไม่ตรงกันที่ default='.$case['default'].' override='.var_export($case['override'], true),
            );
        }
    }

    /**
     * อัตราต่ำมากต้องไม่ปัดลงเป็นศูนย์.
     *
     * ค่าธรรมเนียมที่แสดงเป็น 0 อ่านได้ว่า "เทรดฟรี" ซึ่งเป็นคนละเรื่องกับ
     * "น้อยจนปัดแล้วหาย" — ผู้ใช้ตัดสินใจเรื่องเงินจากตัวเลขนี้
     */
    #[Test]
    public function อัตราน้อยมากยังคืนค่าที่ไม่ใช่ศูนย์(): void
    {
        $this->makePair(override: 0.0001);

        $rate = $this->pairFeeRateFromApi();

        $this->assertNotNull($rate);
        $this->assertGreaterThan(0, $rate);
    }
}
