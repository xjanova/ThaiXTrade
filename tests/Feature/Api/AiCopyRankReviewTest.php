<?php

namespace Tests\Feature\Api;

use App\Models\AiCopyFollow;
use App\Models\AiCopyMaster;
use App\Models\AiCopyRankHistory;
use App\Models\AiCopyReview;
use App\Services\AiBot\Copy\CopyTradeService;
use App\Services\AiBot\Copy\RankService;
use App\Services\AiBot\Copy\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Api\Concerns\CopyTradeFixtures;
use Tests\TestCase;

/**
 * TPIX TRADE — rank ของมาสเตอร์และดาวรีวิวจากผู้ตาม.
 *
 * สิ่งที่ชุดนี้ยึดไว้:
 *   - ระดับมาจากผลเงินจริงเท่านั้น — ส่วนแบ่งแพลตฟอร์ม ค่าบริการ และดาวรีวิว ซื้อหรือปั๊มระดับไม่ได้
 *   - ระดับขึ้นทันทีเมื่อผลงานถึง แต่ลงก็ต่อเมื่อต่ำกว่าเกณฑ์ต่อเนื่องครบช่วงผ่อน (ไม่แกว่งเพราะสัปดาห์เดียว)
 *     และทุกการเปลี่ยนระดับมีประวัติ
 *   - รีวิวได้เฉพาะผู้ตามที่ก๊อปปี้ด้วยเงินจริงครบระยะและปิดไม้แล้ว · หนึ่งคนหนึ่งรีวิว แก้ได้
 *   - ข้อความรีวิวเป็นข้อความล้วน · การรายงานไม่ซ่อนรีวิวเอง (ทีมงานตัดสิน)
 *
 * Developed by Xman Studio.
 */
class AiCopyRankReviewTest extends TestCase
{
    use CopyTradeFixtures;
    use RefreshDatabase;

    private function ranks(): RankService
    {
        return app(RankService::class);
    }

    /** ผู้ตามที่มีสิทธิ์รีวิว: ตามมาแล้ว $days วัน และปิดไม้ที่ก๊อปปี้ไปแล้ว */
    private function seasonedFollower(AiCopyMaster $master, string $wallet = self::FOLLOWER, int $days = 20, bool $closedTrade = true): AiCopyFollow
    {
        $this->follow($master, $wallet)->assertCreated();
        $follow = AiCopyFollow::where('follower_wallet', $wallet)->firstOrFail();
        $follow->update(['started_at' => now()->subDays($days)]);

        if ($closedTrade) {
            $this->followerSell($follow, 12.0);
            app(CopyTradeService::class)->settleFees($follow->fresh());
        }

        return $follow->fresh();
    }

    // ── rank ─────────────────────────────────────────────────────────────────

    #[Test]
    public function a_new_master_is_ranked_at_once_from_real_money_results_only(): void
    {
        $master = $this->registerMaster();
        $score = $this->ranks()->score($master);

        $this->assertGreaterThan(0, $master->rank_tier);
        $this->assertEqualsWithDelta($score['score'], $master->rank_score, 0.01);
        $this->assertSame(['return', 'drawdown', 'consistency', 'sample', 'followers'], array_keys($score['components']));
        $this->assertEqualsWithDelta(16.0, $score['components']['return'], 0.01, 'ผลตอบแทน 16% จากเต็ม 40% × น้ำหนัก 40');
        $this->assertEqualsWithDelta(2.4, $score['components']['sample'], 0.01, '24 ไม้จากเต็ม 100 × น้ำหนัก 10');
        $this->assertEqualsWithDelta(5.0, $score['components']['followers'], 0.01, 'ยังไม่มีผู้ตามที่ปิดไม้ = ครึ่งเดียว');

        $row = collect($this->getJson('/api/v1/ai-bot/copy/masters')->json('data.masters'))->firstWhere('id', $master->id);
        $this->assertSame($master->rank_tier, $row['rank']['tier']);
        $this->assertSame('tier_'.$master->rank_tier, $row['rank']['key']);
        $this->assertNotEmpty($row['rank']['name_th']);
    }

    #[Test]
    public function paying_the_platform_more_or_collecting_stars_does_not_move_the_rank(): void
    {
        $master = $this->registerMaster(['platform_share_pct' => 20]);
        $before = $this->ranks()->score($master)['score'];

        $master->update(['platform_share_pct' => 50, 'fee_rate_pct' => 30, 'rating_avg' => 5, 'rating_count' => 999]);

        $this->assertEqualsWithDelta($before, $this->ranks()->score($master->fresh())['score'], 1e-9);
    }

    #[Test]
    public function better_results_earn_a_higher_tier(): void
    {
        $strongBot = $this->bot(self::OTHER);
        $this->trades($strongBot, array_merge(array_fill(0, 56, 9.0), array_fill(0, 4, -5.0)));
        $strong = $this->registerMaster(['wallet_address' => self::OTHER, 'display_name' => 'Strong'], $strongBot);
        $modest = $this->registerMaster();

        $this->assertGreaterThan($modest->rank_score, $strong->rank_score);
        $this->assertGreaterThan($modest->rank_tier, $strong->rank_tier);
    }

    #[Test]
    public function a_rank_does_not_drop_on_a_bad_week_but_does_after_the_grace_period(): void
    {
        $master = $this->registerMaster();
        $start = $master->rank_tier;

        // ผลงานแย่ลงชัดเจน แต่ยังผ่านเกณฑ์มาสเตอร์ (ยังกำไร 10% และ drawdown 10%)
        $this->trades($master->bot, [-20.0, -20.0, -20.0], 2);
        $target = $this->ranks()->tierFor($this->ranks()->score($master)['score'], true);
        $this->assertLessThan($start, $target, 'ฉากนี้ต้องเป็นฉากที่คะแนนตกระดับจริง');

        $first = $this->ranks()->evaluate($master->fresh());
        $this->assertSame($start, $first->rank_tier, 'รอบแรกที่ต่ำกว่าเกณฑ์: เริ่มนับช่วงผ่อน ยังไม่ลด');
        $this->assertNotNull($first->rank_below_since);
        $this->assertSame(1, AiCopyRankHistory::count(), 'มีแค่ประวัติของการได้ระดับตอนเปิดตัว');

        $this->travel(3)->days();
        $this->assertSame($start, $this->ranks()->evaluate($first->fresh())->rank_tier, 'ยังไม่ครบช่วงผ่อน');

        $this->travel(5)->days();
        $after = $this->ranks()->evaluate($first->fresh());

        $this->assertSame($target, $after->rank_tier);
        $this->assertNull($after->rank_below_since);

        $history = AiCopyRankHistory::orderByDesc('id')->firstOrFail();
        $this->assertSame([$start, $target, RankService::REASON_DEMOTED], [$history->from_tier, $history->to_tier, $history->reason]);
        $this->assertNotEmpty($history->components);
    }

    #[Test]
    public function recovering_inside_the_grace_period_cancels_the_demotion(): void
    {
        $master = $this->registerMaster();
        $start = $master->rank_tier;

        $this->trades($master->bot, [-20.0, -20.0, -20.0], 2);
        $this->assertNotNull($this->ranks()->evaluate($master->fresh())->rank_below_since);

        // กลับมาทำกำไรก่อนครบช่วงผ่อน
        $this->travel(3)->days();
        $this->trades($master->bot, array_fill(0, 8, 15.0), 1);
        $recovered = $this->ranks()->evaluate($master->fresh());

        $this->assertNull($recovered->rank_below_since);
        $this->assertGreaterThanOrEqual($start, $recovered->rank_tier);

        $this->travel(10)->days();
        $this->assertGreaterThanOrEqual($start, $this->ranks()->evaluate($recovered->fresh())->rank_tier);
    }

    #[Test]
    public function a_score_just_under_the_threshold_does_not_start_the_countdown(): void
    {
        $master = $this->registerMaster();
        $score = $this->ranks()->score($master)['score'];

        // ขยับเกณฑ์ของระดับปัจจุบันให้สูงกว่าคะแนนนิดเดียว (ภายในระยะผ่อน 5 คะแนน)
        config(["aibot.copy_trade.rank.tiers.{$master->rank_tier}.min_score" => $score + 3]);

        $held = $this->ranks()->evaluate($master->fresh());

        $this->assertSame($master->rank_tier, $held->rank_tier);
        $this->assertNull($held->rank_below_since);
    }

    #[Test]
    public function a_master_who_falls_below_the_floor_loses_the_rank_after_the_grace_period(): void
    {
        $master = $this->registerMaster();
        $this->trades($master->bot, array_fill(0, 10, -40.0), 2);

        $this->ranks()->evaluate($master->fresh());
        $this->travel(8)->days();
        $after = $this->ranks()->evaluate($master->fresh());

        $this->assertSame(0, $after->rank_tier);
        $this->assertSame(RankService::REASON_BELOW_FLOOR, AiCopyRankHistory::orderByDesc('id')->firstOrFail()->reason);
        $this->assertNull(collect($this->getJson('/api/v1/ai-bot/copy/masters')->json('data.masters'))->firstWhere('id', $master->id)['rank']);
    }

    #[Test]
    public function promotion_is_immediate_and_recorded(): void
    {
        $master = $this->registerMaster();
        $start = $master->rank_tier;

        $this->trades($master->bot, array_fill(0, 40, 12.0), 20);
        $after = $this->ranks()->evaluate($master->fresh());

        $this->assertGreaterThan($start, $after->rank_tier);
        $this->assertSame(RankService::REASON_PROMOTED, AiCopyRankHistory::orderByDesc('id')->firstOrFail()->reason);
    }

    #[Test]
    public function the_promoted_strip_needs_a_minimum_rank_first(): void
    {
        $master = $this->registerMaster(['platform_share_pct' => 50]);
        $this->assertSame([$master->id], $this->getJson('/api/v1/ai-bot/copy/masters')->json('data.promoted'));

        // จ่ายส่วนแบ่งสูงสุดแต่ระดับไม่ถึง = ไม่ได้ขึ้นช่องแนะนำ
        config(['aibot.copy_trade.promotion.min_tier' => $master->rank_tier + 1]);
        $this->assertSame([], $this->getJson('/api/v1/ai-bot/copy/masters')->json('data.promoted'));
    }

    #[Test]
    public function the_scheduled_command_evaluates_every_open_master_and_skips_when_disabled(): void
    {
        $master = $this->registerMaster();
        $master->update(['rank_tier' => 0, 'rank_evaluated_at' => null]);

        $this->assertSame(0, Artisan::call('aibot:copy-rank'));
        $this->assertGreaterThan(0, $master->fresh()->rank_tier);
        $this->assertNotNull($master->fresh()->rank_evaluated_at);

        // รีเซ็ตที่ฐานข้อมูลตรงๆ — โมเดลในมือยังจำค่า null จากรอบแรก จะมองว่าไม่มีอะไรเปลี่ยน
        AiCopyMaster::whereKey($master->id)->update(['rank_tier' => 0, 'rank_evaluated_at' => null]);
        config(['aibot.copy_trade.enabled' => false]);

        $this->assertSame(0, Artisan::call('aibot:copy-rank'));
        $this->assertNull($master->fresh()->rank_evaluated_at);
    }

    // ── ดาวรีวิว ─────────────────────────────────────────────────────────────

    #[Test]
    public function only_a_real_money_follower_who_stayed_long_enough_can_review(): void
    {
        $master = $this->registerMaster();
        $review = fn (string $wallet, array $body = ['stars' => 5]) => $this->postJson("/api/v1/ai-bot/copy/masters/{$master->id}/review", $body + ['wallet_address' => $wallet]);

        // ไม่ได้ตาม · มาสเตอร์รีวิวตัวเอง
        $review(self::OTHER)->assertStatus(422)->assertJsonPath('error.code', 'COPY_REVIEW_NOT_ELIGIBLE');
        $review(self::MASTER)->assertStatus(422)->assertJsonPath('error.code', 'COPY_REVIEW_NOT_ELIGIBLE');

        // ตามแล้วแต่ยังไม่ครบระยะ · ครบระยะแต่ยังไม่เคยปิดไม้ที่ก๊อปปี้
        $this->seasonedFollower($master, self::FOLLOWER, days: 3);
        $review(self::FOLLOWER)->assertStatus(422)->assertJsonPath('error.code', 'COPY_REVIEW_NOT_ELIGIBLE');

        $this->seasonedFollower($master, self::OTHER, days: 30, closedTrade: false);
        $review(self::OTHER)->assertStatus(422)->assertJsonPath('error.code', 'COPY_REVIEW_NOT_ELIGIBLE');

        $this->assertDatabaseCount('ai_copy_reviews', 0);

        AiCopyFollow::where('follower_wallet', self::FOLLOWER)->update(['started_at' => now()->subDays(20)]);
        $review(self::FOLLOWER)->assertOk()->assertJsonPath('data.stars', 5);
    }

    #[Test]
    public function a_follower_has_one_review_and_can_edit_it(): void
    {
        $master = $this->registerMaster();
        $this->seasonedFollower($master);
        $url = "/api/v1/ai-bot/copy/masters/{$master->id}/review";

        $this->postJson($url, ['wallet_address' => self::FOLLOWER, 'stars' => 5, 'comment' => 'ดีมาก'])->assertOk();
        $this->postJson($url, ['wallet_address' => self::FOLLOWER, 'stars' => 2, 'comment' => 'ช่วงหลังแย่ลง'])->assertOk();

        $this->assertSame(1, AiCopyReview::count());
        $this->assertSame(2, AiCopyReview::firstOrFail()->stars);
        $this->assertEqualsWithDelta(2.0, $master->fresh()->rating_avg, 1e-9);
        $this->assertSame(1, $master->fresh()->rating_count);

        $mine = $this->getJson('/api/v1/ai-bot/copy/me?wallet_address='.self::FOLLOWER)->json('data.follows.0');
        $this->assertTrue($mine['can_review']);
        $this->assertSame(['stars' => 2, 'comment' => 'ช่วงหลังแย่ลง'], $mine['my_review']);
    }

    #[Test]
    public function stars_must_be_one_to_five_and_comments_are_stored_as_plain_text(): void
    {
        $master = $this->registerMaster();
        $this->seasonedFollower($master);
        $url = "/api/v1/ai-bot/copy/masters/{$master->id}/review";

        foreach ([0, 6, 'five', null] as $bad) {
            $this->postJson($url, ['wallet_address' => self::FOLLOWER, 'stars' => $bad])->assertStatus(422)->assertJsonValidationErrors('stars');
        }

        $this->postJson($url, ['wallet_address' => self::FOLLOWER, 'stars' => 4, 'comment' => str_repeat('x', 501)])
            ->assertStatus(422)->assertJsonValidationErrors('comment');

        $this->postJson($url, ['wallet_address' => self::FOLLOWER, 'stars' => 4, 'comment' => "<img src=x onerror=alert(1)>เข้าไม้ช้า\x07   แต่ <b>ปิดเร็ว</b><script>steal()</script>"])->assertOk();

        $comment = AiCopyReview::firstOrFail()->comment;
        $this->assertSame('เข้าไม้ช้า แต่ ปิดเร็วsteal()', $comment);
        $this->assertStringNotContainsString('<', $comment);

        $this->assertNull(ReviewService::plainText('  <p> </p> '));
    }

    #[Test]
    public function the_average_counts_visible_reviews_and_the_public_list_hides_wallets(): void
    {
        $master = $this->registerMaster();
        $this->seasonedFollower($master, self::FOLLOWER);
        $this->seasonedFollower($master, self::OTHER);
        $url = "/api/v1/ai-bot/copy/masters/{$master->id}/review";

        $this->postJson($url, ['wallet_address' => self::FOLLOWER, 'stars' => 5, 'comment' => 'ok'])->assertOk();
        $this->postJson($url, ['wallet_address' => self::OTHER, 'stars' => 2])->assertOk();

        $response = $this->getJson("/api/v1/ai-bot/copy/masters/{$master->id}/reviews")->assertOk();

        $this->assertEqualsWithDelta(3.5, $response->json('data.rating.avg'), 1e-9);
        $this->assertSame(2, $response->json('data.rating.count'));
        $this->assertCount(2, $response->json('data.reviews'));
        $this->assertStringNotContainsString(self::FOLLOWER, $response->getContent());
        $this->assertSame('0xbbbb…bbbb', collect($response->json('data.reviews'))->firstWhere('stars', 5)['reviewer']);

        $board = collect($this->getJson('/api/v1/ai-bot/copy/masters')->json('data.masters'))->firstWhere('id', $master->id);
        $this->assertSame(['avg' => 3.5, 'count' => 2], $board['rating']);
    }

    #[Test]
    public function reporting_flags_a_review_once_per_wallet_and_never_hides_it(): void
    {
        $master = $this->registerMaster();
        $this->seasonedFollower($master);
        $this->postJson("/api/v1/ai-bot/copy/masters/{$master->id}/review", ['wallet_address' => self::FOLLOWER, 'stars' => 1, 'comment' => 'ขาดทุน'])->assertOk();
        $review = AiCopyReview::firstOrFail();
        $url = "/api/v1/ai-bot/copy/reviews/{$review->id}/report";

        // มาสเตอร์รายงานรีวิวที่ติตัวเองได้ แต่รายงานกี่ครั้งก็นับครั้งเดียวและรีวิวยังอยู่
        $this->postJson($url, ['wallet_address' => self::MASTER, 'reason' => 'ไม่จริง'])->assertOk()->assertJsonPath('data.reported', true);
        $this->postJson($url, ['wallet_address' => self::MASTER])->assertOk();
        $this->postJson($url, ['wallet_address' => self::OTHER])->assertOk();

        $this->assertSame(2, $review->fresh()->reports);
        $this->assertSame(AiCopyReview::STATUS_VISIBLE, $review->fresh()->status);
        $this->assertSame(1, $master->fresh()->rating_count);

        $this->postJson($url, ['wallet_address' => self::FOLLOWER])->assertStatus(422)->assertJsonPath('error.code', 'COPY_REVIEW_OWN');
        $this->postJson('/api/v1/ai-bot/copy/reviews/99999/report', ['wallet_address' => self::OTHER])->assertStatus(404);
    }

    #[Test]
    public function staff_can_hide_a_reported_review_and_the_average_follows(): void
    {
        $master = $this->registerMaster();
        $this->seasonedFollower($master, self::FOLLOWER);
        $this->seasonedFollower($master, self::OTHER);
        $url = "/api/v1/ai-bot/copy/masters/{$master->id}/review";
        $this->postJson($url, ['wallet_address' => self::FOLLOWER, 'stars' => 5])->assertOk();
        $this->postJson($url, ['wallet_address' => self::OTHER, 'stars' => 1, 'comment' => 'spam'])->assertOk();
        $spam = AiCopyReview::where('stars', 1)->firstOrFail();

        $this->assertSame(1, Artisan::call('aibot:copy-review', ['id' => $spam->id]), 'ต้องระบุ --hide หรือ --show');
        $this->assertSame(0, Artisan::call('aibot:copy-review', ['id' => $spam->id, '--hide' => true]));

        $this->assertSame(AiCopyReview::STATUS_HIDDEN, $spam->fresh()->status);
        $this->assertEqualsWithDelta(5.0, $master->fresh()->rating_avg, 1e-9);
        $this->assertSame(1, $master->fresh()->rating_count);
        $this->assertCount(1, $this->getJson("/api/v1/ai-bot/copy/masters/{$master->id}/reviews")->json('data.reviews'));

        // รีวิวที่ถูกซ่อนรายงานซ้ำไม่ได้ (ไม่มีอยู่ในสายตาของผู้ใช้แล้ว)
        $this->postJson("/api/v1/ai-bot/copy/reviews/{$spam->id}/report", ['wallet_address' => self::MASTER])->assertStatus(404);

        $this->assertSame(0, Artisan::call('aibot:copy-review', ['id' => $spam->id, '--show' => true]));
        $this->assertEqualsWithDelta(3.0, $master->fresh()->rating_avg, 1e-9);
    }
}
