<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\TradingFeeTier;
use App\Models\TradingOrderTicket;
use App\Services\Trading\OrderTicketService;
use App\Services\Trading\TradingCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesBscSwapChain;
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
    use FakesBscSwapChain;
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
        $this->fakeBscChain();
    }

    /** ตั๋ว BSC ที่ผูกธุรกรรมของไม้ไว้แล้ว และหมดอายุมา $minutesAgo นาที */
    private function boundTicket(string $hash, int $minutesAgo = 30): TradingOrderTicket
    {
        $ticket = $this->tickets->issueWithCredit(self::WALLET, 'BTC/USDT', 'buy', 250, 56, network: 56);
        $this->tickets->bind($ticket->uuid, self::WALLET, $hash);
        $ticket->update(['expires_at' => now()->subMinutes($minutesAgo)]);

        return $ticket;
    }

    private function swapOnChain(string $hash, string $state): void
    {
        $this->bscSwap($hash, self::WALLET, '0x55d398326f99059ff775485246999027b3197955', '0x7130d2a12b9bcbfae4f2634d864a1ee1ce3ead9c', '1000', '1', $state);
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
        $t3->update(['expires_at' => now()->subMinutes(15)]);

        $this->artisan('trading:expire-tickets')->assertSuccessful();

        $this->assertSame(20.0, $this->credits->balanceFor(self::WALLET));
        $this->assertSame(20.0, $this->credits->balanceFor($other));
        $this->assertSame(0, TradingOrderTicket::issued()->count());
    }

    /** ระยะผ่อนผัน — คำขอผูกธุรกรรมที่ส่งช้า (เน็ตสะดุด) ต้องมาถึงก่อนตัวเก็บกวาดคืนเงิน */
    #[Test]
    public function ตั๋วที่เพิ่งหมดอายุยังไม่ถูกแตะ(): void
    {
        $ticket = $this->tickets->issueWithCredit(self::WALLET, 'BTC/USDT', 'buy', 250, 56);
        $ticket->update(['expires_at' => now()->subMinutes(5)]);

        $this->artisan('trading:expire-tickets')->assertSuccessful();

        $this->assertSame(TradingOrderTicket::STATUS_ISSUED, $ticket->fresh()->status);
        $this->assertSame(18.0, $this->credits->balanceFor(self::WALLET));
    }

    /**
     * ⚠️ ออดิท 2026-10-28 C1: เดิมคืนเงินทุกใบที่หมดอายุ
     *    consume ล้ม (429 / ปิดแท็บ) ทั้งที่ไม้ลงเชนแล้ว = ได้ค่าบริการคืน = เทรดฟรี
     */
    #[Test]
    public function ตั๋วที่ผูกไม้ซึ่งลงเชนแล้วถูกปิด_ไม่คืนเงิน(): void
    {
        $hash = '0x'.str_repeat('1', 64);
        $this->swapOnChain($hash, 'success');
        $ticket = $this->boundTicket($hash);

        $this->artisan('trading:expire-tickets')->assertSuccessful();

        $this->assertSame(TradingOrderTicket::STATUS_CONSUMED, $ticket->fresh()->status);
        $this->assertSame(18.0, $this->credits->balanceFor(self::WALLET));
    }

    #[Test]
    public function ตั๋วที่ผูกไม้ซึ่ง_revert_ได้เงินคืน(): void
    {
        $hash = '0x'.str_repeat('2', 64);
        $this->swapOnChain($hash, 'failed');
        $ticket = $this->boundTicket($hash);

        $this->artisan('trading:expire-tickets')->assertSuccessful();

        $this->assertSame(TradingOrderTicket::STATUS_REFUNDED, $ticket->fresh()->status);
        $this->assertSame(20.0, $this->credits->balanceFor(self::WALLET));
    }

    /** ยังไม่รู้ผล (ค้างใน mempool / RPC ล่ม) = รอ ห้ามเดา */
    #[Test]
    public function ตั๋วที่ไม้ยังไม่ลงบล็อกรอรอบหน้า(): void
    {
        $pending = '0x'.str_repeat('3', 64);
        $this->swapOnChain($pending, 'pending');
        $waiting = $this->boundTicket($pending);

        $this->artisan('trading:expire-tickets')->assertSuccessful();
        $this->assertSame(TradingOrderTicket::STATUS_ISSUED, $waiting->fresh()->status);

        $this->bscMine($pending);
        $this->bscDown();
        $this->artisan('trading:expire-tickets')->assertSuccessful();
        $this->assertSame(TradingOrderTicket::STATUS_ISSUED, $waiting->fresh()->status);

        $this->bscDown(false);
        $this->artisan('trading:expire-tickets')->assertSuccessful();
        $this->assertSame(TradingOrderTicket::STATUS_CONSUMED, $waiting->fresh()->status);
        $this->assertSame(18.0, $this->credits->balanceFor(self::WALLET));
    }

    /** ธุรกรรมที่ไม่โผล่บนเชนเลยหลังหมดอายุเกิน 2 ชม. = ไม่ลงแน่ (deadline ของไม้ผ่านไปนานแล้ว) */
    #[Test]
    public function ธุรกรรมที่หายจากเชนนานเกินกำหนดได้เงินคืน(): void
    {
        $recent = $this->boundTicket('0x'.str_repeat('4', 64), 30);
        $old = $this->boundTicket('0x'.str_repeat('5', 64), 180);

        $this->artisan('trading:expire-tickets')->assertSuccessful();

        $this->assertSame(TradingOrderTicket::STATUS_ISSUED, $recent->fresh()->status);
        $this->assertSame(TradingOrderTicket::STATUS_REFUNDED, $old->fresh()->status);
    }

    /** ตั๋วรุ่นก่อนที่ไม่ได้จดเชนไว้ — ตัดสินบนเชนไม่ได้ ใช้พฤติกรรมเดิม (คืนเงิน) */
    #[Test]
    public function ตั๋วเก่าที่ไม่รู้เชนใช้พฤติกรรมเดิม(): void
    {
        $ticket = $this->tickets->issueWithCredit(self::WALLET, 'BTC/USDT', 'buy', 250, 56);
        $this->tickets->bind($ticket->uuid, self::WALLET, '0x'.str_repeat('6', 64));
        $ticket->update(['expires_at' => now()->subMinutes(30)]);

        $this->artisan('trading:expire-tickets')->assertSuccessful();

        $this->assertSame(TradingOrderTicket::STATUS_REFUNDED, $ticket->fresh()->status);
    }
}
