<?php

namespace Tests\Feature\Api;

use App\Models\AiBotConfig;
use App\Models\AiBotDecision;
use Database\Seeders\AiBotPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * TPIX TRADE — ฟีดการตัดสินใจของบอท (GET /api/v1/ai-bot/decisions).
 *
 * เป็นข้อมูลที่เจ้าของบอทใช้ "มอนิเตอร์" ว่าบอทคิดอะไรอยู่ ไม่ใช่แค่ผลลัพธ์
 * สิ่งที่เทสต์ชุดนี้กันไว้คือความผิดพลาดที่เห็นยากแต่เสียหายจริง:
 * เห็นการตัดสินใจของกระเป๋าคนอื่น · ชนิดข้อมูลเพี้ยน · เลื่อนหน้าแล้วข้อมูลซ้ำหรือหาย
 *
 * Developed by Xman Studio.
 */
class AiBotDecisionFeedTest extends TestCase
{
    use RefreshDatabase;

    private string $wallet = '0x1111111111111111111111111111111111111111';

    private string $stranger = '0x2222222222222222222222222222222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(AiBotPlanSeeder::class);
        $this->verifyWallet($this->wallet);
    }

    /** จำลอง session ที่ผ่าน VerifyWalletOwnership แล้ว (ปกติมาจากการเซ็นข้อความ) */
    private function verifyWallet(string $wallet): void
    {
        Cache::put('wallet_verified:'.strtolower($wallet), [
            'ip' => '127.0.0.1',
            'verified_at' => now()->toIso8601String(),
        ], now()->addHours(4));
    }

    private function makeBot(string $wallet, string $name = 'บอททดสอบ'): AiBotConfig
    {
        return AiBotConfig::create([
            'wallet_address' => strtolower($wallet),
            'name' => $name,
            'pair' => 'BTC-USDT',
            'strategy' => 'grid',
            'timeframe' => '15m',
            'mode' => 'demo',
            'status' => 'running',
            'params' => [],
            'risk' => [],
        ]);
    }

    private function makeDecision(AiBotConfig $bot, array $overrides = []): AiBotDecision
    {
        return AiBotDecision::create(array_merge([
            'ai_bot_config_id' => $bot->id,
            'wallet_address' => $bot->wallet_address,
            'strategy' => $bot->strategy,
            'pair' => $bot->pair,
            'timeframe' => $bot->timeframe,
            'mode' => 'demo',
            'action' => 'hold',
            'reason' => 'ราคายังอยู่กลางกรอบ ยังไม่ถึงชั้นซื้อ',
            'risk_level' => 'calm',
            'price' => '65000.12345678',
            'budget' => '100.00000000',
            'has_position' => false,
        ], $overrides));
    }

    private function feed(string $query = ''): TestResponse
    {
        return $this->getJson('/api/v1/ai-bot/decisions?wallet_address='.$this->wallet.$query);
    }

    // ── ด่านความปลอดภัย ─────────────────────────────────────────────────────

    public function test_requires_verified_wallet(): void
    {
        $bot = $this->makeBot($this->stranger);
        $this->makeDecision($bot);

        // กระเป๋านี้ไม่เคยเซ็นยืนยัน — ต้องโดนปฏิเสธ ไม่ใช่ได้ข้อมูลเปล่าแบบเงียบๆ
        $this->getJson('/api/v1/ai-bot/decisions?wallet_address='.$this->stranger)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'WALLET_NOT_VERIFIED');
    }

    public function test_never_returns_another_wallets_decisions(): void
    {
        $mine = $this->makeBot($this->wallet, 'ของฉัน');
        $theirs = $this->makeBot($this->stranger, 'ของคนอื่น');

        $this->makeDecision($mine, ['reason' => 'รอบของฉัน']);
        $this->makeDecision($theirs, ['reason' => 'รอบของคนอื่น']);

        $response = $this->feed()
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.decisions')
            ->assertJsonPath('data.decisions.0.reason', 'รอบของฉัน');

        $this->assertStringNotContainsString('รอบของคนอื่น', $response->getContent());
    }

    /**
     * ส่ง bot_id ของคนอื่นมาต้องได้ผลว่าง ไม่ใช่ข้อมูลของเขา.
     *
     * ตัวกรองที่วางผิดลำดับ (กรอง bot_id ก่อนแล้วลืมผูก wallet) คือช่องโหว่ที่
     * หน้าเว็บมองไม่เห็นเลย เพราะหน้าจอส่งแต่ id ของตัวเองอยู่แล้วทุกครั้ง
     */
    public function test_bot_id_filter_cannot_reach_across_wallets(): void
    {
        $theirs = $this->makeBot($this->stranger);
        $this->makeDecision($theirs, ['reason' => 'ความลับของคนอื่น']);

        $this->feed('&bot_id='.$theirs->id)
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.decisions');
    }

    // ── รูปร่างข้อมูล ───────────────────────────────────────────────────────

    /**
     * decimal ของ Laravel คืนค่าเป็น string — ส่งดิบไปแล้วฝั่งแอพเอาไปเทียบกับ
     * ตัวเลข ผลจะเพี้ยนเงียบๆ ตรงนี้จึงต้องเป็นตัวเลขจริงเสมอ.
     */
    public function test_numeric_fields_are_numbers_not_strings(): void
    {
        $bot = $this->makeBot($this->wallet);
        $this->makeDecision($bot);

        $row = $this->feed()->assertStatus(200)->json('data.decisions.0');

        /*
         * เช็ค "ไม่ใช่ string" ไม่ใช่ "เป็น float"
         *
         * ค่าที่ลงตัวพอดีอย่าง 100.0 ถูก json_encode เขียนเป็น `100` แล้วถอดกลับมา
         * เป็น int — ซึ่งถูกต้องแล้วสำหรับฝั่งที่รับ สิ่งที่ต้องกันจริงคือ "0.00000000"
         * ที่หลุดออกไปเป็นข้อความ แล้วฝั่งแอพเอาไปเทียบกับตัวเลขจนผลเพี้ยนเงียบๆ
         */
        $this->assertIsNumeric($row['price']);
        $this->assertIsNumeric($row['budget']);
        $this->assertIsNotString($row['price']);
        $this->assertIsNotString($row['budget']);
        $this->assertIsBool($row['has_position']);
        $this->assertEqualsWithDelta(65000.12345678, $row['price'], 0.00000001);
        $this->assertEqualsWithDelta(100.0, $row['budget'], 0.00000001);
    }

    public function test_null_price_stays_null_instead_of_becoming_zero(): void
    {
        $bot = $this->makeBot($this->wallet);
        $this->makeDecision($bot, ['price' => null, 'budget' => null]);

        $row = $this->feed()->json('data.decisions.0');

        $this->assertNull($row['price']);
        $this->assertNull($row['budget']);
    }

    public function test_includes_bot_name_and_localized_strategy_name(): void
    {
        $bot = $this->makeBot($this->wallet, 'บอทกริดตัวแรก');
        $this->makeDecision($bot);

        $this->feed()
            ->assertStatus(200)
            ->assertJsonPath('data.decisions.0.bot_name', 'บอทกริดตัวแรก')
            ->assertJsonPath('data.decisions.0.strategy', 'grid')
            ->assertJsonPath('data.decisions.0.strategy_name', 'Grid Trading')
            ->assertJsonPath('data.decisions.0.strategy_name_th', 'ตารางเทรด (Grid)');
    }

    /** ค่าตั้งบอทไม่ต้องส่งซ้ำทุกแถว — หน้าจอมีอยู่แล้วจาก /bots */
    public function test_does_not_ship_bot_params_on_every_row(): void
    {
        $bot = $this->makeBot($this->wallet);
        $this->makeDecision($bot, ['params' => ['levels' => 8, 'range_pct' => 4]]);

        $row = $this->feed()->json('data.decisions.0');

        $this->assertArrayNotHasKey('params', $row);
    }

    // ── การเรียงและการเลื่อนหน้า ────────────────────────────────────────────

    public function test_returns_newest_first(): void
    {
        $bot = $this->makeBot($this->wallet);
        $this->makeDecision($bot, ['reason' => 'รอบเก่า']);
        $this->makeDecision($bot, ['reason' => 'รอบใหม่']);

        $this->feed()
            ->assertJsonPath('data.decisions.0.reason', 'รอบใหม่')
            ->assertJsonPath('data.decisions.1.reason', 'รอบเก่า');
    }

    /**
     * เลื่อนหน้าต้องไม่ซ้ำและไม่หาย.
     *
     * ใช้ cursor ตาม id ไม่ใช่ offset — ตารางนี้มีแถวใหม่เข้ามาระหว่างที่ผู้ใช้
     * กำลังเลื่อนดูอยู่ตลอด ถ้าใช้ offset แถวจะเลื่อนตำแหน่งแล้วเห็นซ้ำหรือข้าม
     */
    public function test_cursor_paging_has_no_gaps_or_duplicates(): void
    {
        $bot = $this->makeBot($this->wallet);
        for ($i = 1; $i <= 5; $i++) {
            $this->makeDecision($bot, ['reason' => 'รอบที่ '.$i]);
        }

        $first = $this->feed('&limit=2')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.decisions')
            ->assertJsonPath('data.has_more', true)
            ->json('data');

        $second = $this->feed('&limit=2&before_id='.$first['next_cursor'])
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.decisions')
            ->json('data');

        $third = $this->feed('&limit=2&before_id='.$second['next_cursor'])
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.decisions')
            ->assertJsonPath('data.has_more', false)
            ->assertJsonPath('data.next_cursor', null)
            ->json('data');

        $seen = array_merge(
            array_column($first['decisions'], 'id'),
            array_column($second['decisions'], 'id'),
            array_column($third['decisions'], 'id'),
        );

        $this->assertCount(5, $seen, 'ต้องได้ครบ 5 แถว ไม่ขาดไม่เกิน');
        $this->assertCount(5, array_unique($seen), 'ต้องไม่มีแถวซ้ำข้ามหน้า');
    }

    public function test_limit_is_capped_to_protect_the_server(): void
    {
        $bot = $this->makeBot($this->wallet);
        $this->makeDecision($bot);

        $this->feed('&limit=5000')->assertStatus(422);
    }

    // ── ตัวกรอง ─────────────────────────────────────────────────────────────

    public function test_acted_only_hides_rounds_where_the_bot_did_nothing(): void
    {
        $bot = $this->makeBot($this->wallet);
        $this->makeDecision($bot, ['action' => 'hold', 'reason' => 'ไม่ทำอะไร']);
        $this->makeDecision($bot, ['action' => 'buy', 'reason' => 'เข้าไม้']);

        $this->feed('&acted_only=1')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.decisions')
            ->assertJsonPath('data.decisions.0.action', 'buy');
    }

    public function test_mode_filter_separates_demo_from_live(): void
    {
        $bot = $this->makeBot($this->wallet);
        $this->makeDecision($bot, ['mode' => 'demo', 'reason' => 'รอบทดลอง']);
        $this->makeDecision($bot, ['mode' => 'live', 'reason' => 'รอบจริง']);

        $this->feed('&mode=live')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.decisions')
            ->assertJsonPath('data.decisions.0.reason', 'รอบจริง');
    }

    public function test_rejects_unknown_mode(): void
    {
        $this->feed('&mode=ทุกโหมด')->assertStatus(422);
    }

    public function test_empty_feed_is_a_success_not_an_error(): void
    {
        $this->feed()
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data.decisions')
            ->assertJsonPath('data.has_more', false);
    }
}
