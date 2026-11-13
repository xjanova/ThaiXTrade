<?php

namespace Tests\Feature\Api;

use App\Models\AiCopyFee;
use App\Models\AiCopyFollow;
use App\Models\AiCopyMaster;
use App\Models\AiCopyMedia;
use App\Services\AiBot\Copy\CopyTradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Api\Concerns\CopyTradeFixtures;
use Tests\TestCase;

/**
 * TPIX TRADE — ไลฟ์/คลิปของมาสเตอร์.
 *
 * สิ่งที่ชุดนี้ยึดไว้:
 *   - แปะได้เมื่อระดับถึงเกณฑ์ "และ" ทำยอดให้แพลตฟอร์มถึงเกณฑ์เท่านั้น · ทีมงานถอนสิทธิ์ได้
 *   - สิทธิ์เป็นของตอนนี้: ระดับตก/ถูกถอนสิทธิ์ ลิงก์หายจากหน้าสาธารณะทันที
 *   - รับเฉพาะลิงก์ของผู้ให้บริการที่อนุญาต และสิ่งที่ส่งออกคือลิงก์ที่ระบบประกอบเอง ไม่ใช่ที่ผู้ใช้พิมพ์
 *   - ชื่อเรื่องเป็นข้อความล้วน · มีเพดานจำนวน · แปะซ้ำไม่ได้ · ลบของคนอื่นไม่ได้
 *
 * Developed by Xman Studio.
 */
class AiCopyMediaTest extends TestCase
{
    use CopyTradeFixtures;
    use RefreshDatabase;

    private const YT = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

    /** มาสเตอร์ที่ปลดล็อกแล้ว: ระดับถึงเกณฑ์ และมีผู้ตามทำกำไรจนแพลตฟอร์มได้ส่วนแบ่งถึงเกณฑ์ */
    private function unlockedMaster(): AiCopyMaster
    {
        $master = $this->registerMaster();
        config(['aibot.copy_trade.media.min_tier' => $master->rank_tier, 'aibot.copy_trade.media.min_platform_revenue_usd' => 50]);

        $this->follow($master)->assertCreated();
        $follow = AiCopyFollow::firstOrFail();
        // กำไรผู้ตาม 1000 → ค่าบริการ 20% = 200 → แพลตฟอร์ม 30% = 60
        $this->followerSell($follow, 1000.0);
        app(CopyTradeService::class)->settleFees($follow);

        return $master->fresh();
    }

    private function add(array $body = [], string $wallet = self::MASTER)
    {
        return $this->postJson('/api/v1/ai-bot/copy/master/media', $body + ['wallet_address' => $wallet, 'kind' => 'clip', 'url' => self::YT, 'title' => 'สอนอ่านเทรนด์']);
    }

    private function publicItems(AiCopyMaster $master): array
    {
        return $this->getJson("/api/v1/ai-bot/copy/masters/{$master->id}/media")->assertOk()->json('data.items');
    }

    #[Test]
    public function media_stays_locked_until_both_the_rank_and_the_revenue_thresholds_are_met(): void
    {
        $master = $this->registerMaster();
        config(['aibot.copy_trade.media.min_tier' => $master->rank_tier, 'aibot.copy_trade.media.min_platform_revenue_usd' => 50]);

        // ระดับถึง แต่ยังไม่ได้ทำยอดให้แพลตฟอร์ม
        $this->add()->assertStatus(422)->assertJsonPath('error.code', 'COPY_MEDIA_LOCKED');

        $state = $this->getJson('/api/v1/ai-bot/copy/me?wallet_address='.self::MASTER)->json('data.master.media');
        $this->assertFalse($state['unlocked']);
        $this->assertSame(['need' => 50, 'have' => 0], $state['revenue_usd']);

        // ทำยอดถึงแล้ว แต่ระดับไม่ถึง
        $this->follow($master)->assertCreated();
        $follow = AiCopyFollow::firstOrFail();
        $this->followerSell($follow, 1000.0);
        app(CopyTradeService::class)->settleFees($follow);
        config(['aibot.copy_trade.media.min_tier' => $master->rank_tier + 1]);
        $this->add()->assertStatus(422)->assertJsonPath('error.code', 'COPY_MEDIA_LOCKED');

        config(['aibot.copy_trade.media.min_tier' => $master->rank_tier]);
        $this->add()->assertCreated()->assertJsonPath('data.unlocked', true);
        $this->assertDatabaseCount('ai_copy_media', 1);
    }

    #[Test]
    public function voided_fees_do_not_count_as_revenue(): void
    {
        $master = $this->unlockedMaster();
        AiCopyFee::query()->update(['status' => AiCopyFee::STATUS_VOID]);

        $this->add()->assertStatus(422)->assertJsonPath('error.code', 'COPY_MEDIA_LOCKED');
        $this->assertNotNull($master);
    }

    #[Test]
    public function what_is_stored_and_shown_is_rebuilt_from_the_video_id(): void
    {
        $master = $this->unlockedMaster();

        $this->add(['url' => self::YT.'&list=PLevil&utm=x#frag', 'title' => '<b>ไลฟ์</b><script>x()</script> วันศุกร์', 'kind' => 'live'])->assertCreated();

        $row = AiCopyMedia::firstOrFail();
        $this->assertSame(['youtube', 'dQw4w9WgXcQ', 'live', 'ไลฟ์x() วันศุกร์'], [$row->provider, $row->external_id, $row->kind, $row->title]);

        $item = $this->publicItems($master)[0];
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $item['url']);
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $item['embed_url']);
        $this->assertStringNotContainsString('PLevil', json_encode($item));
        $this->assertStringNotContainsString('<', $item['title']);

        $board = collect($this->getJson('/api/v1/ai-bot/copy/masters')->json('data.masters'))->firstWhere('id', $master->id);
        $this->assertSame(1, $board['media_count']);
    }

    #[Test]
    public function links_outside_the_whitelist_are_rejected(): void
    {
        $this->unlockedMaster();

        foreach (['javascript:alert(1)', 'https://evil.example/watch?v=dQw4w9WgXcQ', 'https://www.youtube.com.evil.example/watch?v=dQw4w9WgXcQ', 'http://youtu.be/dQw4w9WgXcQ', 'https://vm.tiktok.com/ZSabc/', 'https://vimeo.com/123456'] as $url) {
            $this->add(['url' => $url])->assertStatus(422)->assertJsonPath('error.code', 'COPY_MEDIA_INVALID_LINK');
        }

        $this->add(['kind' => 'podcast'])->assertStatus(422)->assertJsonValidationErrors('kind');
        $this->assertDatabaseCount('ai_copy_media', 0);
    }

    #[Test]
    public function the_same_video_cannot_be_added_twice_and_the_list_is_capped(): void
    {
        $this->unlockedMaster();
        config(['aibot.copy_trade.media.max_items' => 2]);

        $this->add()->assertCreated();
        // ลิงก์คนละรูปของวิดีโอเดียวกัน
        $this->add(['url' => 'https://youtu.be/dQw4w9WgXcQ'])->assertStatus(422)->assertJsonPath('error.code', 'COPY_MEDIA_DUPLICATE');

        $this->add(['url' => 'https://www.facebook.com/watch/?v=1234567890123'])->assertCreated();
        $this->add(['url' => 'https://www.tiktok.com/@a/video/7300000000000000000'])->assertStatus(422)->assertJsonPath('error.code', 'COPY_MEDIA_LIMIT');

        $this->assertDatabaseCount('ai_copy_media', 2);
    }

    #[Test]
    public function media_disappears_from_public_view_when_the_master_loses_the_unlock(): void
    {
        $master = $this->unlockedMaster();
        $this->add()->assertCreated();
        $this->assertCount(1, $this->publicItems($master));

        // ระดับตกต่ำกว่าเกณฑ์ — ลิงก์ไม่ถูกลบ แต่ไม่โชว์
        $master->update(['rank_tier' => 0]);
        $this->assertSame([], $this->publicItems($master));
        $this->assertSame(0, collect($this->getJson('/api/v1/ai-bot/copy/masters')->json('data.masters'))->firstWhere('id', $master->id)['media_count']);
        $this->assertDatabaseCount('ai_copy_media', 1);

        // เจ้าของยังเห็นของตัวเองและรู้ว่าทำไมไม่โชว์
        $own = $this->getJson('/api/v1/ai-bot/copy/me?wallet_address='.self::MASTER)->json('data.master.media');
        $this->assertFalse($own['unlocked']);
        $this->assertCount(1, $own['items']);
    }

    #[Test]
    public function staff_can_revoke_a_masters_media_and_hide_a_single_item(): void
    {
        $master = $this->unlockedMaster();
        $this->add()->assertCreated();
        $this->add(['url' => 'https://www.facebook.com/watch/?v=1234567890123'])->assertCreated();
        $first = AiCopyMedia::orderBy('id')->firstOrFail();

        $this->assertSame(0, Artisan::call('aibot:copy-media', ['--item' => $first->id, '--hide' => true]));
        $this->assertCount(1, $this->publicItems($master));

        $this->assertSame(0, Artisan::call('aibot:copy-media', ['master' => $master->id, '--revoke' => true]));
        $this->assertSame([], $this->publicItems($master));
        $this->add(['url' => 'https://www.tiktok.com/@a/video/7300000000000000000'])->assertStatus(422)->assertJsonPath('error.code', 'COPY_MEDIA_LOCKED');
        $this->assertTrue($this->getJson('/api/v1/ai-bot/copy/me?wallet_address='.self::MASTER)->json('data.master.media.revoked'));

        $this->assertSame(0, Artisan::call('aibot:copy-media', ['master' => $master->id, '--restore' => true]));
        $this->assertCount(1, $this->publicItems($master));

        $this->assertSame(1, Artisan::call('aibot:copy-media', ['master' => $master->id]), 'ต้องระบุ --revoke หรือ --restore');
    }

    #[Test]
    public function only_the_owner_can_remove_a_link_and_removal_has_no_gate(): void
    {
        $master = $this->unlockedMaster();
        $this->add()->assertCreated();
        $item = AiCopyMedia::firstOrFail();

        $this->deleteJson("/api/v1/ai-bot/copy/master/media/{$item->id}", ['wallet_address' => self::FOLLOWER])->assertStatus(404);
        $this->assertDatabaseCount('ai_copy_media', 1);

        // ถูกถอนสิทธิ์ + ระบบปิด ก็ยังลบของตัวเองได้
        $master->update(['media_revoked_at' => now()]);
        config(['aibot.copy_trade.enabled' => false]);

        $this->deleteJson("/api/v1/ai-bot/copy/master/media/{$item->id}", ['wallet_address' => self::MASTER])->assertOk();
        $this->assertDatabaseCount('ai_copy_media', 0);
    }

    #[Test]
    public function a_wallet_that_is_not_a_master_cannot_add_media(): void
    {
        $this->unlockedMaster();

        $this->add([], self::OTHER)->assertStatus(404)->assertJsonPath('error.code', 'COPY_MASTER_NOT_FOUND');
    }
}
