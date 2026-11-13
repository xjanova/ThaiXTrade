<?php

namespace Tests\Feature\Api;

use App\Models\AiCopyFollow;
use App\Models\AiCopyMaster;
use App\Models\AiCopyMedia;
use App\Services\AiBot\Copy\CopyTradeService;
use App\Services\AiBot\Copy\MediaLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Api\Concerns\CopyTradeFixtures;
use Tests\TestCase;

/**
 * TPIX TRADE — หน้าแนะนำตัวมาสเตอร์ + คลิปแนะนำ (เจ้าของสั่ง 2026-11-13: "มีคลิป แนะนำได้ 3 คลิป").
 *
 * สิ่งที่ชุดนี้ยึดไว้:
 *   - หน้าโปรไฟล์เป็นสาธารณะ แต่ไม่มีกฎของบอทและไม่มีที่อยู่กระเป๋าเต็ม
 *   - ข้อความแนะนำตัวเป็นข้อความล้วน
 *   - มาสเตอร์ทุกคนตั้งคลิปแนะนำได้โดยไม่ต้องรอปลดล็อก แต่ไม่เกินเพดาน และผ่าน MediaLink เหมือนลิงก์อื่น
 *   - ทีมงานถอนสิทธิ์/ซ่อนคลิปแนะนำได้ และคลิปหายจากหน้าสาธารณะทันที
 *   - ต้นทางเครื่องเล่นที่ CSP อนุญาตตรงกับที่ MediaLink ประกอบ ทั้งสองที่
 *
 * Developed by Xman Studio.
 */
class AiCopyProfileTest extends TestCase
{
    use CopyTradeFixtures;
    use RefreshDatabase;

    private const CLIPS = [
        'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        'https://www.facebook.com/watch/?v=1234567890123',
        'https://www.tiktok.com/@a/video/7300000000000000000',
        'https://youtu.be/aaaaaaaaaaa',
    ];

    private function feature(string $url, array $body = [], string $wallet = self::MASTER)
    {
        return $this->postJson('/api/v1/ai-bot/copy/master/media', $body + ['wallet_address' => $wallet, 'kind' => 'clip', 'url' => $url, 'title' => 'แนะนำตัว', 'featured' => true]);
    }

    private function page(AiCopyMaster $master)
    {
        return $this->getJson("/api/v1/ai-bot/copy/masters/{$master->id}");
    }

    #[Test]
    public function the_profile_page_is_public_and_shows_results_without_rules_or_the_full_wallet(): void
    {
        $master = $this->registerMaster(['intro' => "เทรดตามเทรนด์ 4 ชม.\nตัดขาดทุนทุกไม้"]);

        $data = $this->page($master)->assertOk()->json('data');

        $this->assertSame('Trend Rider', $data['master']['display_name']);
        $this->assertSame("เทรดตามเทรนด์ 4 ชม.\nตัดขาดทุนทุกไม้", $data['master']['intro']);
        $this->assertSame([], $data['master']['featured']);
        $this->assertTrue($data['master']['embed']);
        $this->assertSame(24, $data['master']['record']['closed']);
        $this->assertArrayHasKey('rank', $data['master']);
        $this->assertTrue($data['enabled']);

        $raw = json_encode($data);
        $this->assertStringNotContainsString(self::MASTER, $raw);
        $this->assertStringNotContainsString('do-not-leak', $raw);
        $this->assertStringNotContainsString('fast_ema', $raw);
        $this->assertArrayNotHasKey('bot_id', $data['master']);
        $this->assertArrayNotHasKey('earnings', $data['master']);
    }

    #[Test]
    public function a_closed_or_unknown_master_and_a_disabled_feature_have_no_profile_page(): void
    {
        $master = $this->registerMaster();

        $this->getJson('/api/v1/ai-bot/copy/masters/999999')->assertStatus(404)->assertJsonPath('error.code', 'COPY_MASTER_NOT_FOUND');

        app(CopyTradeService::class)->closeMaster($master);
        $this->page($master)->assertStatus(404);

        config(['aibot.copy_trade.enabled' => false]);
        $this->page($master)->assertStatus(404);
    }

    #[Test]
    public function the_intro_is_stored_as_plain_text_and_is_length_limited(): void
    {
        $master = $this->registerMaster();

        $this->putJson('/api/v1/ai-bot/copy/master', ['wallet_address' => self::MASTER, 'intro' => "<b>สวัสดี</b><script>alert(1)</script>\nผมเทรด   BTC"])->assertOk()
            ->assertJsonPath('data.intro', "สวัสดีalert(1)\nผมเทรด BTC");

        $this->assertStringNotContainsString('<', (string) $this->page($master)->json('data.master.intro'));

        $this->putJson('/api/v1/ai-bot/copy/master', ['wallet_address' => self::MASTER, 'intro' => str_repeat('ก', 1501)])->assertStatus(422)->assertJsonValidationErrors('intro');

        // แก้อย่างอื่นโดยไม่ส่ง intro = ข้อความเดิมยังอยู่ · ส่งค่าว่าง = ลบ
        $this->putJson('/api/v1/ai-bot/copy/master', ['wallet_address' => self::MASTER, 'bio' => 'ใหม่'])->assertOk();
        $this->assertNotNull($master->fresh()->intro);

        $this->putJson('/api/v1/ai-bot/copy/master', ['wallet_address' => self::MASTER, 'intro' => null])->assertOk();
        $this->assertNull($master->fresh()->intro);
    }

    #[Test]
    public function any_master_can_feature_up_to_three_clips_without_the_media_unlock(): void
    {
        $master = $this->registerMaster();
        // ยังไม่ปลดล็อกไลฟ์/คลิป: ระดับไม่ถึงและยังไม่ได้ทำยอดให้แพลตฟอร์ม
        config(['aibot.copy_trade.media.min_tier' => 5, 'aibot.copy_trade.media.min_platform_revenue_usd' => 50]);

        // ลิงก์ธรรมดายังถูกล็อกเหมือนเดิม
        $this->feature(self::CLIPS[0], ['featured' => false])->assertStatus(422)->assertJsonPath('error.code', 'COPY_MEDIA_LOCKED');

        foreach ([0, 1, 2] as $i) {
            $this->feature(self::CLIPS[$i])->assertCreated()->assertJsonPath('data.featured_max', 3);
        }

        $this->feature(self::CLIPS[3])->assertStatus(422)->assertJsonPath('error.code', 'COPY_MEDIA_FEATURED_LIMIT');
        $this->assertSame(3, AiCopyMedia::whereNotNull('featured_at')->count());

        // หน้าโปรไฟล์โชว์ครบสามคลิป ตามลำดับที่ตั้ง และเป็นลิงก์ที่ระบบประกอบเอง
        $featured = $this->page($master)->json('data.master.featured');
        $this->assertSame(['youtube', 'facebook', 'tiktok'], array_column($featured, 'provider'));
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $featured[0]['embed_url']);
        $this->assertTrue($featured[0]['featured']);

        // กระดานนับคลิปแนะนำด้วย และรายการสาธารณะเห็นเฉพาะคลิปแนะนำ (ยังไม่ปลดล็อก)
        $this->assertSame(3, collect($this->getJson('/api/v1/ai-bot/copy/masters')->json('data.masters'))->firstWhere('id', $master->id)['media_count']);
        $this->assertCount(3, $this->getJson("/api/v1/ai-bot/copy/masters/{$master->id}/media")->json('data.items'));
    }

    #[Test]
    public function a_featured_item_is_always_a_clip_and_goes_through_the_same_link_whitelist(): void
    {
        $this->registerMaster();

        $this->feature(self::CLIPS[0], ['kind' => 'live', 'title' => '<i>คลิป</i>แรก'])->assertCreated();
        $row = AiCopyMedia::firstOrFail();
        $this->assertSame(['clip', 'คลิปแรก'], [$row->kind, $row->title]);

        $this->feature('https://evil.example/watch?v=dQw4w9WgXcQ')->assertStatus(422)->assertJsonPath('error.code', 'COPY_MEDIA_INVALID_LINK');
        $this->feature('https://youtu.be/dQw4w9WgXcQ')->assertStatus(422)->assertJsonPath('error.code', 'COPY_MEDIA_DUPLICATE');
        $this->feature(self::CLIPS[1], [], self::OTHER)->assertStatus(404);
        $this->assertDatabaseCount('ai_copy_media', 1);
    }

    #[Test]
    public function featured_clips_do_not_use_up_the_library_limit_and_can_be_switched_on_existing_clips(): void
    {
        $master = $this->registerMaster();
        // ปลดล็อกคลังไลฟ์/คลิป: ระดับถึง + ทำยอดให้แพลตฟอร์มถึง
        config(['aibot.copy_trade.media.min_tier' => $master->rank_tier, 'aibot.copy_trade.media.min_platform_revenue_usd' => 50, 'aibot.copy_trade.media.max_items' => 1]);
        $this->follow($master)->assertCreated();
        $follow = AiCopyFollow::firstOrFail();
        $this->followerSell($follow, 1000.0);
        app(CopyTradeService::class)->settleFees($follow);

        $this->feature(self::CLIPS[0])->assertCreated();
        $this->feature(self::CLIPS[1], ['featured' => false])->assertCreated();
        $this->feature(self::CLIPS[2], ['featured' => false])->assertStatus(422)->assertJsonPath('error.code', 'COPY_MEDIA_LIMIT');
        $this->feature(self::CLIPS[3], ['featured' => false, 'kind' => 'live'])->assertStatus(422);

        $regular = AiCopyMedia::whereNull('featured_at')->firstOrFail();
        $toggle = fn (int $id, bool $on, string $wallet = self::MASTER) => $this->putJson("/api/v1/ai-bot/copy/master/media/{$id}", ['wallet_address' => $wallet, 'featured' => $on]);

        // ของคนอื่นแตะไม่ได้
        $toggle($regular->id, true, self::FOLLOWER)->assertStatus(404);

        $toggle($regular->id, true)->assertOk();
        $this->assertCount(2, $this->page($master)->json('data.master.featured'));

        $toggle($regular->id, false)->assertOk();
        $this->assertCount(1, $this->page($master)->json('data.master.featured'));

        // ไลฟ์ตั้งเป็นคลิปแนะนำไม่ได้
        config(['aibot.copy_trade.media.max_items' => 5]);
        $this->feature(self::CLIPS[3], ['featured' => false, 'kind' => 'live'])->assertCreated();
        $live = AiCopyMedia::where('kind', 'live')->firstOrFail();
        $toggle($live->id, true)->assertStatus(422)->assertJsonPath('error.code', 'COPY_MEDIA_FEATURED_KIND');
    }

    #[Test]
    public function staff_can_take_featured_clips_down(): void
    {
        $master = $this->registerMaster();
        $this->feature(self::CLIPS[0])->assertCreated();
        $this->feature(self::CLIPS[1])->assertCreated();
        $first = AiCopyMedia::orderBy('id')->firstOrFail();

        // ซ่อนรายการเดียว
        $this->assertSame(0, Artisan::call('aibot:copy-media', ['--item' => $first->id, '--hide' => true]));
        $this->assertSame(['facebook'], array_column($this->page($master)->json('data.master.featured'), 'provider'));

        // ถอนสิทธิ์ทั้งโปรไฟล์: คลิปหายหมด และตั้งเพิ่มไม่ได้
        $this->assertSame(0, Artisan::call('aibot:copy-media', ['master' => $master->id, '--revoke' => true]));
        $this->assertSame([], $this->page($master)->json('data.master.featured'));
        $this->feature(self::CLIPS[2])->assertStatus(422)->assertJsonPath('error.code', 'COPY_MEDIA_REVOKED');

        // เจ้าของยังเห็นของตัวเองและลบได้
        $own = $this->getJson('/api/v1/ai-bot/copy/me?wallet_address='.self::MASTER)->json('data.master.media');
        $this->assertTrue($own['revoked']);
        $this->assertCount(2, $own['items']);
        $this->deleteJson("/api/v1/ai-bot/copy/master/media/{$first->id}", ['wallet_address' => self::MASTER])->assertOk();
    }

    #[Test]
    public function the_profile_page_route_renders_for_a_numeric_id_only(): void
    {
        $this->get('/ai-trade/master/42')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->component('AiTradeMaster')->where('masterId', 42)
        );

        $this->get('/ai-trade/master/abc')->assertNotFound();
        // รหัสที่ยาวเกิน int ต้องเป็น 404 ไม่ใช่ 500
        $this->get('/ai-trade/master/99999999999999999999')->assertNotFound();
        $this->getJson('/api/v1/ai-bot/copy/masters/99999999999999999999')->assertNotFound();
    }

    #[Test]
    public function both_content_security_policies_allow_exactly_the_player_origins_media_links_use(): void
    {
        $origins = [];

        foreach ([['youtube', 'dQw4w9WgXcQ'], ['facebook', '1234567890123'], ['tiktok', '7300000000000000000']] as [$provider, $id]) {
            $parts = parse_url(MediaLink::build($provider, $id)['embed_url']);
            $origins[] = $parts['scheme'].'://'.$parts['host'];
        }

        foreach (['app/Http/Middleware/SecurityHeaders.php', 'public_html/.htaccess'] as $file) {
            preg_match('/frame-src ([^;"]+)/', (string) file_get_contents(base_path($file)), $match);
            $allowed = preg_split('/\s+/', trim($match[1] ?? ''));

            foreach ($origins as $origin) {
                $this->assertContains($origin, $allowed, "{$file} ต้องอนุญาต {$origin} ใน frame-src");
            }

            // ไม่มีการเปิดกว้าง — ไม่มี wildcard และไม่มี https: ทั้ง scheme
            $this->assertNotContains('*', $allowed, $file);
            $this->assertNotContains('https:', $allowed, $file);
        }
    }
}
