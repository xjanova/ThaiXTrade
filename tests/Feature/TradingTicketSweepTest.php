<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\TradingFeeTier;
use App\Models\TradingOrderTicket;
use App\Services\Trading\OrderTicketService;
use App\Services\Trading\TradingCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — ตัวเก็บกวาดใบอนุญาตที่หมดอายุ.
 *
 * ⚠️ นี่คือตาข่ายรองสุดท้ายของเงินผู้ใช้
 *    หน้าเว็บคืนเงินให้เองเมื่อไม้ไม่ลง แต่ผู้ใช้ปิดแท็บกลางคัน / เน็ตหลุด /
 *    เบราว์เซอร์ crash ได้เสมอ — ตั๋วจะค้างโดยที่เงินถูกหักไปแล้ว
 *
 *    ถ้าคำสั่งนี้ไม่เดิน ผู้ใช้จะเสีย TPIX ทีละนิดโดยไม่มีทางรู้ตัว
 *    และเป็นความเสียหายที่ไม่มีใครมารายงาน เพราะไม่มีอะไรแสดงว่าผิดปกติ
 *
 * Developed by Xman Studio.
 */
class TradingTicketSweepTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    private TradingCreditService $credits;

    private OrderTicketService $tickets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credits = app(TradingCreditService::class);
        $this->tickets = app(OrderTicketService::class);

        SiteSetting::set('trading', 'tpix_fee_enabled', '1', 'boolean');
        TradingFeeTier::create(['min_order_usd' => 0, 'max_order_usd' => null, 'fee_tpix' => 2, 'is_active' => true]);

        $this->credits->topup(self::WALLET, 20, '0x'.str_repeat('a', 64));
    }

    private function staleTicket(): TradingOrderTicket
    {
        $ticket = $this->tickets->issueWithCredit(self::WALLET, 'BTC/USDT', 'buy', 250, 56);
        $ticket->update(['expires_at' => now()->subMinutes(30)]);

        return $ticket;
    }

    #[Test]
    public function คำสั่งเก็บกวาดคืนเงินให้ตั๋วที่หมดอายุ(): void
    {
        $ticket = $this->staleTicket();
        $this->assertSame(18.0, $this->credits->balanceFor(self::WALLET));

        $this->artisan('trading:expire-tickets')
            ->expectsOutputToContain('1')
            ->assertSuccessful();

        $this->assertSame(20.0, $this->credits->balanceFor(self::WALLET));
        $this->assertSame(TradingOrderTicket::STATUS_REFUNDED, $ticket->fresh()->status);
    }

    /** เดินซ้ำต้องไม่คืนเงินสองรอบ — cron ยิงทุกนาที ชนกันเองได้ */
    #[Test]
    public function เดินซ้ำไม่คืนเงินสองรอบ(): void
    {
        $this->staleTicket();

        $this->artisan('trading:expire-tickets')->assertSuccessful();
        $this->artisan('trading:expire-tickets')->assertSuccessful();
        $this->artisan('trading:expire-tickets')->assertSuccessful();

        $this->assertSame(20.0, $this->credits->balanceFor(self::WALLET));
    }

    #[Test]
    public function ไม่แตะตั๋วที่ยังไม่หมดอายุ(): void
    {
        $this->tickets->issueWithCredit(self::WALLET, 'BTC/USDT', 'buy', 250, 56);

        $this->artisan('trading:expire-tickets')->assertSuccessful();

        $this->assertSame(18.0, $this->credits->balanceFor(self::WALLET));
    }

    /** ตั๋วที่ใช้ไปแล้วห้ามถูกคืนเงิน แม้จะเลยเวลาหมดอายุ */
    #[Test]
    public function ไม่คืนเงินตั๋วที่วางไม้ไปแล้ว(): void
    {
        $ticket = $this->tickets->issueWithCredit(self::WALLET, 'BTC/USDT', 'buy', 250, 56);
        $this->tickets->consume($ticket->uuid, self::WALLET, '0x'.str_repeat('b', 64));
        $ticket->update(['expires_at' => now()->subMinutes(30)]);

        $this->artisan('trading:expire-tickets')->assertSuccessful();

        $this->assertSame(18.0, $this->credits->balanceFor(self::WALLET));
        $this->assertSame(TradingOrderTicket::STATUS_CONSUMED, $ticket->fresh()->status);
    }

    /** ตั๋วค้างหลายใบจากหลายกระเป๋าต้องถูกเก็บกวาดครบในรอบเดียว */
    #[Test]
    public function เก็บกวาดหลายใบได้ในรอบเดียว(): void
    {
        $other = '0xffffffffffffffffffffffffffffffffffffffff';
        $this->credits->topup($other, 20, '0x'.str_repeat('c', 64));

        $this->staleTicket();
        $this->staleTicket();
        $t3 = $this->tickets->issueWithCredit($other, 'ETH/USDT', 'sell', 250, 56);
        $t3->update(['expires_at' => now()->subMinutes(5)]);

        $this->artisan('trading:expire-tickets')->assertSuccessful();

        $this->assertSame(20.0, $this->credits->balanceFor(self::WALLET));
        $this->assertSame(20.0, $this->credits->balanceFor($other));
        $this->assertSame(0, TradingOrderTicket::issued()->count());
    }
}
