<?php

namespace Tests\Feature\Launchpad;

use App\Models\LaunchpadSale;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesBridgeChains;
use Tests\Support\FakesLaunchpad;
use Tests\TestCase;

/**
 * ลงทะเบียนหน้าขาย — ทุกค่ามาจากสัญญา รับเฉพาะของที่ LaunchpadFactory สร้าง และกันเหรียญชื่อซ้ำขึ้นหน้าเว็บ.
 */
class LaunchpadRegisterTest extends TestCase
{
    use FakesBridgeChains;
    use FakesLaunchpad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBridge();
        $this->setUpLaunchpad();
    }

    #[Test]
    public function a_factory_sale_is_registered_from_chain_as_pending_then_approved(): void
    {
        $this->artisan('launchpad:register', ['sale' => $this->lpSale])->assertSuccessful();

        $sale = LaunchpadSale::firstOrFail();
        $this->assertSame(LaunchpadSale::STATUS_PENDING, $sale->status, 'ลงทะเบียนอย่างเดียวต้องยังไม่ขึ้นหน้าเว็บ');
        $this->assertSame($this->lpFeeder, $sale->feeder_address);
        $this->assertSame($this->lpToken, $sale->token_address);
        $this->assertSame($this->lpVesting, $sale->vesting_address);
        $this->assertSame($this->lpCreator, $sale->creator_address);
        $this->assertSame('IDTC', $sale->token_symbol);
        $this->assertSame('IDTC Token', $sale->token_name);
        $this->assertSame(0, $sale->launch_id);
        $this->assertSame(10000, $sale->pool_share_bps);
        $this->assertSame('0.0000015', $sale->priceUsdt());
        $this->getJson('/api/v1/launchpad/sales')->assertJsonCount(0, 'data');

        $this->artisan('launchpad:register', ['sale' => $this->lpSale, '--approve' => true])->assertSuccessful();
        $this->assertSame(1, LaunchpadSale::count(), 'ลงทะเบียนซ้ำ = อัปเดตแถวเดิม');
        $this->assertSame(LaunchpadSale::STATUS_APPROVED, $sale->fresh()->status);
    }

    #[Test]
    public function a_sale_the_factory_did_not_create_is_refused(): void
    {
        $this->artisan('launchpad:register', ['sale' => '0x'.str_repeat('6', 40)])->assertFailed();
        $this->assertSame(0, LaunchpadSale::count());
    }

    #[Test]
    public function a_token_with_risky_switches_is_refused(): void
    {
        $this->lp['token_check'] = '2';

        $this->artisan('launchpad:register', ['sale' => $this->lpSale])->assertFailed();
        $this->assertSame(0, LaunchpadSale::count());
    }

    #[Test]
    public function a_sale_paid_in_something_other_than_usdt_tpix_is_refused(): void
    {
        $this->usdtTpix = '0x'.str_repeat('8', 40);
        $this->artisan('launchpad:register', ['sale' => $this->lpSale])->assertFailed();
        $this->assertSame(0, LaunchpadSale::count());
    }

    #[Test]
    public function a_copycat_symbol_cannot_be_approved_next_to_an_approved_sale(): void
    {
        $this->artisan('launchpad:register', ['sale' => $this->lpSale, '--approve' => true])->assertSuccessful();

        // เหรียญอีกตัว (ที่อยู่ต่างกัน) ใช้สัญลักษณ์เดียวกันตัวพิมพ์ต่างกัน
        $copycat = '0x'.str_repeat('9', 40);
        $this->lp['launch_of'][$copycat] = 2;
        $this->lpSale = $copycat;
        $this->lpToken = '0x'.str_repeat('a', 40);
        $this->lp['symbol'] = 'idtc';
        $this->lp['name'] = 'Something Else';

        $this->artisan('launchpad:register', ['sale' => $copycat, '--approve' => true])->assertFailed();
        $this->assertSame(LaunchpadSale::STATUS_PENDING, LaunchpadSale::where('sale_address', $copycat)->value('status'));
    }
}
