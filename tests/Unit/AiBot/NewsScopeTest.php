<?php

namespace Tests\Unit\AiBot;

use App\Services\AiBot\NewsFeedService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — ข่าวไหน "ระดับตลาด" ข่าวไหน "โปรโตคอลเดียว".
 *
 * ตัวแยกนี้ตัดสินว่าข่าวที่ไม่ได้แท็กเหรียญจะลากบอททุกตัว หรือแค่ทำให้ระวัง
 * พาดหัวในเทสต์คือพาดหัวจริงจาก prod ที่เคยสั่งเทออกผิด (ดู config/aibot_risk.php)
 *
 * Developed by Xman Studio.
 */
class NewsScopeTest extends TestCase
{
    private NewsFeedService $news;

    protected function setUp(): void
    {
        parent::setUp();
        $this->news = app(NewsFeedService::class);
    }

    public static function marketHeadlines(): array
    {
        return [
            'exchange hack' => ['Major exchange hack drains user funds'],
            'exchange halts' => ['Exchange halts withdrawals amid insolvency fears'],
            'named exchange' => ['Binance faces outage as traders rush to exit'],
            'stablecoin' => ['Stablecoin depeg sparks liquidations across markets'],
            'regulator' => ['SEC charges crypto lender with fraud'],
            'macro' => ['Fed signals rate hike; risk assets plunge'],
        ];
    }

    public static function localHeadlines(): array
    {
        return [
            'chain halt (Cronos)' => ['Cronos halts blockchain after $75 million lending exploit hits lending app Tectonic'],
            'neobank card hack' => ['A $1.1 million crypto card hack crashed a neobank\'s token 49%'],
            'single protocol' => ['Term Finance loses estimated $8.5M in vault governance exploit'],
            'meme collapse' => ['Real Trump Coins denies launching GOLD after token collapse'],
            'gaming bridge' => ['The Sandbox pledges 1:1 repayment after $700K bridge exploit'],
        ];
    }

    #[Test]
    #[DataProvider('marketHeadlines')]
    public function ข่าวระดับตลาดถูกจัดเป็น_market(string $headline): void
    {
        $this->assertSame('market', $this->news->scopeOf($headline), $headline);
    }

    #[Test]
    #[DataProvider('localHeadlines')]
    public function ข่าวโปรโตคอลเดียวถูกจัดเป็น_local(string $headline): void
    {
        $this->assertSame('local', $this->news->scopeOf($headline), $headline);
    }

    /** คำว่า "crypto" กับ "token" อยู่ในแทบทุกพาดหัว — ต้องไม่ทำให้ทุกข่าวกลายเป็นระดับตลาด */
    #[Test]
    public function คำกว้างๆอย่าง_crypto_ไม่ทำให้ข่าวกลายเป็นระดับตลาด(): void
    {
        $this->assertSame('local', $this->news->scopeOf('Small crypto project loses tokens in wallet bug'));
    }

    /** score() ต้องส่ง scope ติดไปด้วย ให้ผู้เก็บข่าวใช้ได้โดยไม่ต้องคำนวณซ้ำ */
    #[Test]
    public function score_แนบ_scope_มาให้ด้วย(): void
    {
        $scored = $this->news->score('Major exchange hack drains user funds');

        $this->assertSame('market', $scored['scope']);
    }
}
