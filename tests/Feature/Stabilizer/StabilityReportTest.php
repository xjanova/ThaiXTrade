<?php

namespace Tests\Feature\Stabilizer;

use App\Models\Article;
use App\Models\StabilityDecision;
use App\Models\StabilityReport;
use App\Models\StabilityTransaction;
use App\Services\AiTextService;
use App\Services\Stabilizer\StabilityAnnouncement;
use App\Support\Wei;
use Carbon\Carbon;
use Mockery\MockInterface;
use Tests\Support\FakesStabilizerChain;
use Tests\TestCase;

/**
 * รายงานรายวัน (AI เขียนสรุปเท่านั้น) + ร่างประกาศ (ห้ามเผยแพร่เอง).
 */
class StabilityReportTest extends TestCase
{
    use FakesStabilizerChain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-11 09:00:00', config('app.timezone')));
        $this->setUpStabilizer();
        $this->setPool('100000', '10000');
    }

    protected function tearDown(): void
    {
        $this->tearDownStabilizerKeystore();
        parent::tearDown();
    }

    private function seedYesterday(): void
    {
        $at = Carbon::parse('2026-10-10 10:00:00', config('app.timezone'));
        foreach (['0.1', '0.08', '0.1'] as $i => $price) {
            StabilityDecision::create([
                'decided_at' => $at->copy()->addMinutes($i * 5),
                'action' => $price === '0.08' ? 'buy' : 'none',
                'reason_code' => $price === '0.08' ? 'below_band' : 'in_band',
                'pool_price' => $price, 'reference_price' => '0.1', 'band_low' => '0.085', 'band_high' => '0.115',
            ]);
        }
        StabilityTransaction::create([
            'purpose' => 'swap', 'side' => 'buy', 'token_in' => 'usdt',
            'amount_in_wei' => Wei::toWei('40', 6), 'actual_out_wei' => Wei::toWei('495', 18),
            'nonce' => 0, 'tx_hash' => '0x'.str_repeat('f', 64), 'raw_tx' => '0x01',
            'status' => 'confirmed', 'block_number' => 10, 'signed_at' => $at->copy()->addMinutes(5),
        ]);
    }

    private function fakeAi(array $result): void
    {
        config(['stabilizer.report.ai_enabled' => true]);
        $this->mock(AiTextService::class, fn (MockInterface $m) => $m->shouldReceive('chat')->andReturn($result));
    }

    public function test_report_has_computed_facts_without_ai(): void
    {
        $this->seedYesterday();

        $this->artisan('stabilizer:report')->assertSuccessful();

        $report = StabilityReport::firstOrFail();
        $this->assertSame('2026-10-10', $report->report_date->format('Y-m-d'));
        $this->assertSame(3, $report->stats['decisions']);
        $this->assertSame(1, $report->stats['buys']);
        $this->assertSame('40', $report->stats['usdt_spent']);
        $this->assertSame('495', $report->stats['tpix_bought']);
        $this->assertEqualsWithDelta(66.7, $report->stats['inside_band_pct'], 0.1);
        $this->assertStringContainsString('40 USDT', $report->facts_en);
        $this->assertStringContainsString('40 USDT', $report->facts_th);
        $this->assertNull($report->summary_en);
    }

    public function test_ai_summary_is_published_when_it_passes_the_checks(): void
    {
        $this->seedYesterday();
        $this->fakeAi(['success' => true, 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'content' => json_encode([
            'th' => 'เมื่อวานราคาหลุดกรอบล่างหนึ่งครั้ง ระบบซื้อ TPIX ตามกฎ 40 USDT',
            'en' => 'Yesterday the price dipped below the band once and the rules bought TPIX with 40 USDT.',
        ])]);

        $this->artisan('stabilizer:report', ['--date' => '2026-10-10'])->assertSuccessful();

        $report = StabilityReport::firstOrFail();
        $this->assertStringContainsString('40 USDT', $report->summary_en);
        $this->assertSame('openai', $report->ai_provider);
        $this->assertStringContainsString('40 USDT', $report->summary_th);
    }

    public function test_ai_summary_with_a_price_promise_is_thrown_away(): void
    {
        $this->seedYesterday();
        $this->fakeAi(['success' => true, 'content' => json_encode([
            'th' => 'ราคาจะขึ้นแน่นอน',
            'en' => 'The reserve guarantees the price will rise.',
        ])]);

        $this->artisan('stabilizer:report', ['--date' => '2026-10-10'])->assertSuccessful();

        $report = StabilityReport::firstOrFail();
        $this->assertNull($report->summary_en);
        $this->assertNull($report->summary_th);
        $this->assertNotEmpty($report->facts_en);
    }

    public function test_no_ai_call_on_a_day_without_activity(): void
    {
        config(['stabilizer.report.ai_enabled' => true]);
        $this->mock(AiTextService::class, fn (MockInterface $m) => $m->shouldNotReceive('chat'));

        $this->artisan('stabilizer:report', ['--date' => '2026-10-10'])->assertSuccessful();

        $this->assertStringContainsString('did not run', StabilityReport::firstOrFail()->facts_en);
    }

    public function test_announcement_is_created_as_unpublished_drafts_only(): void
    {
        $this->artisan('stabilizer:draft-announcement')->assertSuccessful();
        $this->artisan('stabilizer:draft-announcement')->assertSuccessful();

        $drafts = Article::where('source_key', StabilityAnnouncement::SOURCE_KEY)->get();
        $this->assertCount(2, $drafts);
        $this->assertEqualsCanonicalizing(['th', 'en'], $drafts->pluck('language')->all());
        $this->assertTrue($drafts->every(fn ($a) => $a->status === 'draft' && $a->published_at === null));
        $this->assertSame(0, Article::published()->count());

        $en = $drafts->firstWhere('language', 'en');
        $th = $drafts->firstWhere('language', 'th');
        $this->assertStringContainsString('No price guarantee', $en->content);
        $this->assertStringContainsString('AI does not decide trades or control the price', $en->content);
        $this->assertStringContainsString('not live yet', $en->content);
        $this->assertStringContainsString($this->sWallet, $en->content);
        $this->assertStringContainsString('ไม่รับประกันราคา', $th->content);
        $this->assertStringContainsString('±15%', $th->content);
        $this->assertStringNotContainsString('github.com', $en->content.$th->content);
    }
}
