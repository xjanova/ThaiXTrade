<?php

namespace Tests\Unit\Trading;

use App\Models\SiteSetting;
use App\Models\TradingFeeTier;
use App\Models\TradingOrderTicket;
use App\Services\Trading\OrderTicketService;
use App\Services\Trading\TradingCreditService;
use App\Services\Trading\TradingFeeQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * TPIX TRADE — ใบอนุญาตวางไม้: เก็บก่อน คืนได้ ใช้ซ้ำไม่ได้.
 *
 * เจ้าของกำหนดว่า "การขึ้นออเดอร์ เราจะเก็บตอนนั้นเลย ถ้าไม่มีก็ขึ้นออเดอร์ไม่ได้"
 * และ "ถ้าขายไม่ได้ยกเลิกไม้ ก็คืนให้ แต่หักค่าแก๊สออกก่อนคืน"
 *
 * ⚠️ จุดที่พลาดแล้วเสียหายจริง: ตั๋วใบเดียวใช้ได้สองไม้ · คืนเงินซ้ำ ·
 *    ตั๋วหมดอายุค้างโดยเงินถูกหักไปแล้ว · คืนเงินให้ตั๋วที่ใช้ไปแล้ว
 *
 * Developed by Xman Studio.
 */
class OrderTicketServiceTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0xdddddddddddddddddddddddddddddddddddddddd';

    private OrderTicketService $tickets;

    private TradingCreditService $credits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tickets = app(OrderTicketService::class);
        $this->credits = app(TradingCreditService::class);

        SiteSetting::set('trading', 'tpix_fee_enabled', '1', 'boolean');

        // ขั้นบันไดตัวอย่าง — จำนวน TPIX คงที่ต่อไม้ ไม่ใช่เปอร์เซ็นต์
        TradingFeeTier::create(['label' => 'ไม้เล็ก', 'min_order_usd' => 0, 'max_order_usd' => 100, 'fee_tpix' => 0.5]);
        TradingFeeTier::create(['label' => 'ไม้กลาง', 'min_order_usd' => 100, 'max_order_usd' => 1000, 'fee_tpix' => 2]);
        TradingFeeTier::create(['label' => 'ไม้ใหญ่', 'min_order_usd' => 1000, 'max_order_usd' => null, 'fee_tpix' => 5]);
    }

    private function fund(float $amount): void
    {
        $this->credits->topup(self::WALLET, $amount, '0x'.str_repeat('a', 64));
    }

    private function issue(float $orderValueUsd = 250): TradingOrderTicket
    {
        return $this->tickets->issueWithCredit(self::WALLET, 'BTC/USDT', 'buy', $orderValueUsd, 56);
    }

    // ── ขั้นบันได ────────────────────────────────────────────────────────────

    #[Test]
    public function เลือกขั้นบันไดตามขนาดไม้ถูกต้อง(): void
    {
        $quotes = app(TradingFeeQuoteService::class);

        $this->assertSame(0.5, (float) $quotes->tierFor(50)->fee_tpix);
        $this->assertSame(2.0, (float) $quotes->tierFor(250)->fee_tpix);
        $this->assertSame(5.0, (float) $quotes->tierFor(5000)->fee_tpix);
    }

    /** ค่าตรงรอยต่อต้องตกขั้นบน ไม่ใช่คลุมเครือ */
    #[Test]
    public function ค่าตรงรอยต่อของสองขั้นตกขั้นบน(): void
    {
        $quotes = app(TradingFeeQuoteService::class);

        $this->assertSame(2.0, (float) $quotes->tierFor(100)->fee_tpix);
        $this->assertSame(5.0, (float) $quotes->tierFor(1000)->fee_tpix);
    }

    /**
     * ⚠️ ค่าบริการต้องไม่แปรผันตามมูลค่าไม้ภายในขั้นเดียวกัน.
     *
     * เจ้าของสั่งชัดว่าคิดเป็น % ไม่ได้ เพราะไม้ใหญ่จะจ่ายแพงจนไม่มีใครใช้
     */
    #[Test]
    public function ไม้ใหญ่เล็กในขั้นเดียวกันจ่ายเท่ากัน(): void
    {
        $this->fund(100);

        $small = $this->issue(150);
        $big = $this->issue(999);

        $this->assertSame(2.0, (float) $small->fee_amount);
        $this->assertSame((float) $small->fee_amount, (float) $big->fee_amount);
    }

    // ── ออกตั๋ว ──────────────────────────────────────────────────────────────

    #[Test]
    public function ออกตั๋วแล้วหักเครดิตทันที(): void
    {
        $this->fund(10);

        $ticket = $this->issue(250);

        $this->assertSame(2.0, (float) $ticket->fee_amount);
        $this->assertSame(8.0, $this->credits->balanceFor(self::WALLET));
        $this->assertSame(TradingOrderTicket::STATUS_ISSUED, $ticket->status);
    }

    /** เครดิตไม่พอ = ไม่ได้ตั๋ว และต้องไม่มีตั๋วค้างในระบบ */
    #[Test]
    public function เครดิตไม่พอออกตั๋วไม่ได้และไม่มีตั๋วค้าง(): void
    {
        $this->fund(1);

        try {
            $this->issue(250);
            $this->fail('ควรโยน exception เมื่อเครดิตไม่พอ');
        } catch (RuntimeException) {
            // คาดไว้แล้ว
        }

        $this->assertSame(1.0, $this->credits->balanceFor(self::WALLET));
        $this->assertSame(0, TradingOrderTicket::count());
    }

    // ── ใช้ตั๋ว ──────────────────────────────────────────────────────────────

    #[Test]
    public function ใช้ตั๋วแล้วบันทึกธุรกรรมของไม้(): void
    {
        $this->fund(10);
        $ticket = $this->issue();

        $used = $this->tickets->consume($ticket->uuid, self::WALLET, '0x'.str_repeat('b', 64));

        $this->assertSame(TradingOrderTicket::STATUS_CONSUMED, $used->status);
        $this->assertSame('0x'.str_repeat('b', 64), $used->order_tx_hash);
    }

    /** ⚠️ ตั๋วใบเดียวต้องวางไม้ได้ไม้เดียว ไม่งั้นจ่ายครั้งเดียวเทรดได้ไม่จำกัด */
    #[Test]
    public function ตั๋วที่ใช้แล้วเอาไปคืนเงินไม่ได้(): void
    {
        $this->fund(10);
        $ticket = $this->issue();
        $this->tickets->consume($ticket->uuid, self::WALLET);

        $this->expectException(RuntimeException::class);
        $this->tickets->refund($ticket->uuid, self::WALLET);
    }

    #[Test]
    public function ใช้ตั๋วซ้ำไม่ถือเป็น_error_แต่ไม่เกิดผลใหม่(): void
    {
        $this->fund(10);
        $ticket = $this->issue();

        $this->tickets->consume($ticket->uuid, self::WALLET);
        $again = $this->tickets->consume($ticket->uuid, self::WALLET);

        $this->assertSame(TradingOrderTicket::STATUS_CONSUMED, $again->status);
        $this->assertSame(8.0, $this->credits->balanceFor(self::WALLET));
    }

    /** ตั๋วของคนอื่นเอามาใช้ไม่ได้ */
    #[Test]
    public function ใช้ตั๋วของกระเป๋าอื่นไม่ได้(): void
    {
        $this->fund(10);
        $ticket = $this->issue();

        $this->expectException(RuntimeException::class);
        $this->tickets->consume($ticket->uuid, '0x'.str_repeat('9', 40));
    }

    // ── คืนเงิน ──────────────────────────────────────────────────────────────

    /**
     * จ่ายด้วยคลัง TPIX → คืนเต็มจำนวน ไม่หักค่าแก๊ส
     * (เป็นบัญชีในระบบเรา การคืนไม่ใช่ธุรกรรมบนเชน จึงไม่มีต้นทุน).
     */
    #[Test]
    public function ยกเลิกไม้ที่จ่ายด้วยเครดิตคืนเต็มจำนวน(): void
    {
        $this->fund(10);
        $ticket = $this->issue();
        $this->assertSame(8.0, $this->credits->balanceFor(self::WALLET));

        $refunded = $this->tickets->refund($ticket->uuid, self::WALLET, 'ผู้ใช้ยกเลิก');

        $this->assertSame(10.0, $this->credits->balanceFor(self::WALLET));
        $this->assertSame(2.0, (float) $refunded->refund_amount);
        $this->assertSame(0.0, (float) $refunded->gas_deducted);
    }

    /**
     * จ่ายเป็นเหรียญบนเชน → คืนโดยหักค่าแก๊สตามที่เจ้าของกำหนด
     * (การโอนคืนเป็นธุรกรรมจริงที่เราจ่ายแก๊สเอง).
     */
    #[Test]
    public function ยกเลิกไม้ที่จ่ายบนเชนคืนโดยหักค่าแก๊ส(): void
    {
        SiteSetting::set('trading', 'refund_gas_fee', '0.35');

        $ticket = $this->tickets->issueWithOnchainFee(
            self::WALLET,
            'BTC/USDT',
            'buy',
            250,
            3.0,
            'USDT',
            '0x'.str_repeat('c', 64),
        );

        $refunded = $this->tickets->refund($ticket->uuid, self::WALLET, 'ผู้ใช้ยกเลิก');

        $this->assertSame(2.65, (float) $refunded->refund_amount);
        $this->assertSame(0.35, (float) $refunded->gas_deducted);
    }

    /** ค่าแก๊สมากกว่าค่าบริการ → คืน 0 ไม่ใช่ติดลบ (ห้ามเรียกเก็บเพิ่มตอนคืนเงิน) */
    #[Test]
    public function ค่าแก๊สแพงกว่าค่าบริการคืนศูนย์ไม่ใช่ติดลบ(): void
    {
        SiteSetting::set('trading', 'refund_gas_fee', '10');

        $ticket = $this->tickets->issueWithOnchainFee(
            self::WALLET,
            'BTC/USDT',
            'buy',
            50,
            1.0,
            'USDT',
            '0x'.str_repeat('d', 64),
        );

        $refunded = $this->tickets->refund($ticket->uuid, self::WALLET);

        $this->assertSame(0.0, (float) $refunded->refund_amount);
        $this->assertSame(1.0, (float) $refunded->gas_deducted);
    }

    #[Test]
    public function คืนเงินซ้ำไม่ได้เงินสองรอบ(): void
    {
        $this->fund(10);
        $ticket = $this->issue();

        $this->tickets->refund($ticket->uuid, self::WALLET);
        $this->tickets->refund($ticket->uuid, self::WALLET);

        $this->assertSame(10.0, $this->credits->balanceFor(self::WALLET));
    }

    // ── ตั๋วหมดอายุ ──────────────────────────────────────────────────────────

    /**
     * ⚠️ ตั๋วค้างสถานะ issued คือเงินที่หักไปแล้วแต่ไม่มีใครได้อะไร
     *    ต้องมีตัวเก็บกวาด ไม่ใช่รอให้ผู้ใช้มาทวง.
     */
    #[Test]
    public function ตั๋วหมดอายุถูกเก็บกวาดแล้วคืนเงินเอง(): void
    {
        $this->fund(10);
        $ticket = $this->issue();
        $ticket->update(['expires_at' => now()->subMinute()]);

        $closed = $this->tickets->expireStale();

        $this->assertSame(1, $closed);
        $this->assertSame(10.0, $this->credits->balanceFor(self::WALLET));
        $this->assertSame(TradingOrderTicket::STATUS_REFUNDED, $ticket->fresh()->status);
    }

    #[Test]
    public function ตั๋วที่ยังไม่หมดอายุไม่ถูกเก็บกวาด(): void
    {
        $this->fund(10);
        $this->issue();

        $this->assertSame(0, $this->tickets->expireStale());
        $this->assertSame(8.0, $this->credits->balanceFor(self::WALLET));
    }

    #[Test]
    public function ตั๋วที่ใช้ไปแล้วไม่ถูกเก็บกวาดคืนเงิน(): void
    {
        $this->fund(10);
        $ticket = $this->issue();
        $this->tickets->consume($ticket->uuid, self::WALLET);
        $ticket->update(['expires_at' => now()->subMinute()]);

        $this->assertSame(0, $this->tickets->expireStale());
        $this->assertSame(8.0, $this->credits->balanceFor(self::WALLET));
    }

    /** ธุรกรรมจ่ายค่าบริการใบเดิมขอตั๋วซ้ำไม่ได้ — ไม่งั้นจ่ายครั้งเดียวได้หลายตั๋ว */
    #[Test]
    public function ธุรกรรมจ่ายค่าบริการใบเดิมขอตั๋วซ้ำไม่ได้(): void
    {
        $tx = '0x'.str_repeat('e', 64);

        $first = $this->tickets->issueWithOnchainFee(self::WALLET, 'BTC/USDT', 'buy', 250, 3.0, 'USDT', $tx);
        $second = $this->tickets->issueWithOnchainFee(self::WALLET, 'BTC/USDT', 'buy', 250, 3.0, 'USDT', $tx);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, TradingOrderTicket::count());
    }
}
