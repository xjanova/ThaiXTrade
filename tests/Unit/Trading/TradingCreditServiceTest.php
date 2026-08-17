<?php

namespace Tests\Unit\Trading;

use App\Models\TradingCredit;
use App\Services\Trading\TradingCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * TPIX TRADE — คลัง TPIX ที่ผู้ใช้เติมไว้จ่ายค่าบริการวางไม้.
 *
 * นี่คือเงินจริงของผู้ใช้ที่เก็บเป็นตัวเลขในฐานข้อมูลเรา — พลาดตรงนี้แปลว่า
 * เงินหายหรือได้ของฟรี ทั้งสองอย่างกู้คืนยากและเสียความเชื่อถือทันที
 *
 * Developed by Xman Studio.
 */
class TradingCreditServiceTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0xcccccccccccccccccccccccccccccccccccccccc';

    private TradingCreditService $credits;

    protected function setUp(): void
    {
        parent::setUp();
        $this->credits = app(TradingCreditService::class);
    }

    #[Test]
    public function กระเป๋าใหม่ยอดเป็นศูนย์(): void
    {
        $this->assertSame(0.0, $this->credits->balanceFor(self::WALLET));
    }

    #[Test]
    public function เติมแล้วหักได้ยอดตรง(): void
    {
        $this->credits->topup(self::WALLET, 100, '0x'.str_repeat('a', 64));
        $this->assertSame(100.0, $this->credits->balanceFor(self::WALLET));

        $this->credits->charge(self::WALLET, 2.5, 'ticket-1');
        $this->assertSame(97.5, $this->credits->balanceFor(self::WALLET));
    }

    /** ⚠️ หักเกินยอดไม่ได้เด็ดขาด — ยอดติดลบคือเราให้เครดิตฟรีโดยไม่ตั้งใจ */
    #[Test]
    public function หักเกินยอดที่มีไม่ได้(): void
    {
        $this->credits->topup(self::WALLET, 5, '0x'.str_repeat('b', 64));

        $this->expectException(RuntimeException::class);
        $this->credits->charge(self::WALLET, 10, 'ticket-over');
    }

    #[Test]
    public function หักจนยอดเหลือศูนย์พอดีได้(): void
    {
        $this->credits->topup(self::WALLET, 3, '0x'.str_repeat('c', 64));
        $this->credits->charge(self::WALLET, 3, 'ticket-exact');

        $this->assertSame(0.0, $this->credits->balanceFor(self::WALLET));
    }

    /**
     * ⚠️ กันหักซ้ำจาก retry ของ client.
     *
     * เน็ตสะดุดแล้ว client ยิงซ้ำเป็นเรื่องปกติ ถ้าหักสองรอบผู้ใช้เสียเงินฟรี
     * โดยไม่มีอะไรผิดปกติให้เห็นในล็อกเลย
     */
    #[Test]
    public function เรียกหักซ้ำด้วยตั๋วใบเดิมหักแค่ครั้งเดียว(): void
    {
        $this->credits->topup(self::WALLET, 50, '0x'.str_repeat('d', 64));

        $this->credits->charge(self::WALLET, 5, 'ticket-same');
        $this->credits->charge(self::WALLET, 5, 'ticket-same');
        $this->credits->charge(self::WALLET, 5, 'ticket-same');

        $this->assertSame(45.0, $this->credits->balanceFor(self::WALLET));
        $this->assertSame(2, TradingCredit::where('wallet_address', self::WALLET)->count());
    }

    /** เติมด้วยธุรกรรมเดิมซ้ำต้องไม่ได้เครดิตสองรอบ */
    #[Test]
    public function เติมด้วยธุรกรรมเดิมซ้ำได้เครดิตครั้งเดียว(): void
    {
        $tx = '0x'.str_repeat('e', 64);

        $this->credits->topup(self::WALLET, 20, $tx);
        $this->credits->topup(self::WALLET, 20, $tx);

        $this->assertSame(20.0, $this->credits->balanceFor(self::WALLET));
    }

    /**
     * ⚠️ คืนเงินต้องใช้ reference คนละตัวกับตอนหัก.
     *
     * ถ้าใช้ตัวเดียวกันจะชน unique index แล้วคืนแถวของการหักกลับมา —
     * กลายเป็น "คืนเงินสำเร็จ" ทั้งที่ยอดไม่ขยับเลยสักบาท
     */
    #[Test]
    public function คืนเงินแล้วยอดกลับมาจริง(): void
    {
        $this->credits->topup(self::WALLET, 10, '0x'.str_repeat('f', 64));
        $this->credits->charge(self::WALLET, 4, 'ticket-refundable');

        $this->assertSame(6.0, $this->credits->balanceFor(self::WALLET));

        $this->credits->refund(self::WALLET, 4, 'ticket-refundable');

        $this->assertSame(10.0, $this->credits->balanceFor(self::WALLET));
    }

    #[Test]
    public function คืนเงินซ้ำไม่ได้เงินสองรอบ(): void
    {
        $this->credits->topup(self::WALLET, 10, '0x'.str_repeat('1', 64));
        $this->credits->charge(self::WALLET, 4, 'ticket-x');

        $this->credits->refund(self::WALLET, 4, 'ticket-x');
        $this->credits->refund(self::WALLET, 4, 'ticket-x');

        $this->assertSame(10.0, $this->credits->balanceFor(self::WALLET));
    }

    /** ที่อยู่กระเป๋าตัวพิมพ์ใหญ่/เล็กต้องเป็นคลังเดียวกัน ไม่ใช่คนละใบ */
    #[Test]
    public function ตัวพิมพ์ใหญ่เล็กของเลขกระเป๋าเป็นคลังเดียวกัน(): void
    {
        $this->credits->topup(strtoupper(self::WALLET), 15, '0x'.str_repeat('2', 64));

        $this->assertSame(15.0, $this->credits->balanceFor(self::WALLET));
        $this->assertSame(15.0, $this->credits->balanceFor(strtoupper(self::WALLET)));
    }

    #[Test]
    public function เศษทศนิยมไม่เพี้ยนสะสม(): void
    {
        $this->credits->topup(self::WALLET, 1, '0x'.str_repeat('3', 64));

        for ($i = 0; $i < 10; $i++) {
            $this->credits->charge(self::WALLET, 0.001, "ticket-frac-{$i}");
        }

        $this->assertSame(0.99, $this->credits->balanceFor(self::WALLET));
    }
}
