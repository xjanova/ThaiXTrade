<?php

namespace Tests\Feature\Content;

use App\Models\Article;
use Database\Seeders\LearnLessonsSeeder;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * TPIX TRADE — หมวด "เรียนรู้" (เจ้าของ: "เพิ่มหมวดเรียนรู้เพื่อกดดูได้ ไว้ในบทความด้วยบรรยายไว้").
 *
 * ห้ามหลุด:
 *   1. deploy ซ้ำกี่รอบก็ได้บทความเดิม (ไม่งอกซ้ำ) และไม่รีเซ็ตยอดวิว/วันเผยแพร่
 *   2. แอดมินซ่อน (ร่าง) หรือลบบทความ → deploy รอบหน้าไม่เผยแพร่กลับเอง
 *   3. หน้าบทความได้ตัวเล่นวิดีโอ + บทในภาษาของบทความ · การ์ดในหน้ารวมรู้ว่าเป็นคลิป
 *   4. หน้าดาวน์โหลดได้คลิปสอนของทุกแอป
 *
 * Developed by Xman Studio.
 */
class LearnLessonsTest extends TestCase
{
    private function seedLessons(): void
    {
        $this->seed(LearnLessonsSeeder::class);
    }

    public function test_every_lesson_becomes_a_published_thai_and_english_article(): void
    {
        $this->seedLessons();

        $lessons = config('learn.lessons');
        $this->assertSame(count($lessons) * 2, Article::where('category', 'learn')->count());

        foreach ($lessons as $lesson) {
            foreach (['th', 'en'] as $lang) {
                $article = Article::where('slug', $lesson['slug'][$lang])->firstOrFail();
                $this->assertSame('published', $article->status);
                $this->assertSame($lang, $article->language);
                $this->assertSame('learn:'.$lesson['code'], $article->source_key);
                $this->assertSame('/videos/learn/'.$lesson['poster'], $article->cover_image);
                $this->assertNotEmpty(strip_tags($article->content));
            }
        }
    }

    public function test_reseeding_updates_content_but_keeps_views_dates_and_admin_choices(): void
    {
        $this->seedLessons();
        $install = Article::where('slug', 'learn-install-tpix-apps-android')->firstOrFail();
        $install->update(['views' => 42, 'content' => '<p>แก้มือ</p>']);
        $publishedAt = $install->published_at->toDateTimeString();

        $hidden = Article::where('slug', 'learn-tpix-trade-basics')->firstOrFail();
        $hidden->update(['status' => 'draft']);
        Article::where('slug', 'learn-tpix-wallet-basics')->firstOrFail()->delete();

        $this->travel(2)->days();
        $this->seedLessons();

        $install->refresh();
        $this->assertSame(42, $install->views);
        $this->assertSame($publishedAt, $install->published_at->toDateTimeString());
        $this->assertStringNotContainsString('แก้มือ', $install->content);

        $this->assertSame('draft', $hidden->fresh()->status);
        $this->assertNull(Article::where('slug', 'learn-tpix-wallet-basics')->first());
        $this->assertSame(1, Article::withTrashed()->where('slug', 'learn-tpix-wallet-basics')->count());
        $this->assertSame(count(config('learn.lessons')) * 2, Article::withTrashed()->where('category', 'learn')->count());
    }

    public function test_article_api_returns_the_player_with_chapters_in_the_article_language(): void
    {
        $this->seedLessons();

        $th = $this->getJson('/api/v1/articles/learn-tpix-wallet-basics')->assertOk()->json('data.lesson');
        $this->assertSame('/videos/learn/tpix-wallet-basics.mp4', $th['video']);
        $this->assertSame('/videos/learn/tpix-wallet-basics.jpg', $th['poster']);
        $this->assertSame(['t' => 15, 'label' => 'รับเหรียญ'], $th['chapters'][2]);

        $en = $this->getJson('/api/v1/articles/learn-tpix-wallet-basics-en')->assertOk()->json('data.lesson');
        $this->assertSame(['t' => 15, 'label' => 'Receive'], $en['chapters'][2]);
    }

    public function test_ordinary_articles_have_no_player(): void
    {
        $article = Article::create([
            'title' => 'ข่าวทั่วไป', 'content' => '<p>x</p>', 'language' => 'th', 'category' => 'news',
            'status' => 'published', 'published_at' => now()->subMinute(),
        ]);

        $this->getJson('/api/v1/articles/'.$article->slug)->assertOk()->assertJsonPath('data.lesson', null);
    }

    public function test_learn_category_lists_lessons_newest_first_with_duration_and_no_internal_keys(): void
    {
        $this->seedLessons();

        $data = $this->getJson('/api/v1/articles?category=learn&language=th')->assertOk()->json('data');

        $this->assertSame(
            ['learn-install-tpix-apps-android', 'learn-tpix-trade-basics', 'learn-tpix-wallet-basics'],
            array_column($data, 'slug'),
        );
        $this->assertSame(274, $data[0]['video_duration']);
        $this->assertArrayNotHasKey('source_key', $data[0]);
    }

    public function test_download_page_carries_every_lesson_video(): void
    {
        $this->withoutVite();
        Http::fake(); // หน้าดาวน์โหลดถามรุ่นแอปล่าสุด — ไม่ออกเน็ตจริงในเทสต์

        $this->get('/download')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Download')
            ->has('lessons', count(config('learn.lessons')))
            ->where('lessons.0.code', 'install-android')
            ->where('lessons.0.video', '/videos/learn/install-android.mp4')
            ->where('lessons.1.url.th', '/blog/learn-tpix-trade-basics')
            ->where('lessons.2.url.en', '/blog/learn-tpix-wallet-basics-en'));
    }
}
