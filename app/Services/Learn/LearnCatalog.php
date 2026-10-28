<?php

namespace App\Services\Learn;

use App\Models\Article;

/**
 * บทเรียนหมวด "เรียนรู้" — อ่านจาก config/learn.php ที่เดียว
 * ใช้ทั้งตอนสร้างบทความ (LearnLessonsSeeder) หน้าเว็บ (ตัวเล่นวิดีโอ + บท) และบอท Discord.
 *
 * Developed by Xman Studio
 */
class LearnCatalog
{
    public const CATEGORY = 'learn';

    public const SOURCE_PREFIX = 'learn:';

    public const LANGUAGES = ['th', 'en'];

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_values((array) config('learn.lessons', []));
    }

    /** @return array<string, mixed>|null */
    public function find(string $code): ?array
    {
        foreach ($this->all() as $lesson) {
            if ($lesson['code'] === $code) {
                return $lesson;
            }
        }

        return null;
    }

    public function sourceKey(array $lesson): string
    {
        return self::SOURCE_PREFIX.$lesson['code'];
    }

    /** @return array<string, mixed>|null */
    public function forArticle(Article $article): ?array
    {
        $key = (string) $article->source_key;

        return str_starts_with($key, self::SOURCE_PREFIX)
            ? $this->find(substr($key, strlen(self::SOURCE_PREFIX)))
            : null;
    }

    public function videoUrl(array $lesson): string
    {
        return $this->path().'/'.rawurlencode($lesson['file']);
    }

    public function posterUrl(array $lesson): string
    {
        return $this->path().'/'.rawurlencode($lesson['poster']);
    }

    public function articlePath(array $lesson, string $language): string
    {
        return '/blog/'.$lesson['slug'][$language];
    }

    /**
     * ข้อมูลที่หน้าเว็บใช้เล่นวิดีโอ — บทเป็นภาษาของบทความ.
     *
     * @return array{code: string, video: string, poster: string, duration: int, chapters: list<array{t: int, label: string}>}
     */
    public function player(array $lesson, string $language): array
    {
        $index = $language === 'en' ? 2 : 1;

        return [
            'code' => $lesson['code'],
            'video' => $this->videoUrl($lesson),
            'poster' => $this->posterUrl($lesson),
            'duration' => (int) $lesson['duration'],
            'chapters' => array_map(fn (array $c) => ['t' => (int) $c[0], 'label' => (string) $c[$index]], $lesson['chapters']),
        ];
    }

    /**
     * การ์ดบทเรียนสำหรับหน้าดาวน์โหลด — มีทั้งสองภาษา ให้หน้าเว็บเลือกตามภาษาที่ผู้ใช้ตั้ง.
     *
     * @return list<array<string, mixed>>
     */
    public function cards(): array
    {
        return array_map(fn (array $lesson) => [
            'code' => $lesson['code'],
            'video' => $this->videoUrl($lesson),
            'poster' => $this->posterUrl($lesson),
            'duration' => (int) $lesson['duration'],
            'title' => $lesson['title'],
            'url' => ['th' => $this->articlePath($lesson, 'th'), 'en' => $this->articlePath($lesson, 'en')],
        ], $this->all());
    }

    /**
     * ฟิลด์ของบทความที่มาจากต้นฉบับ — ไม่มีวันเผยแพร่ ยอดวิว ไลก์ (seeder ตั้งเองตอนสร้างครั้งแรก).
     *
     * @return array<string, mixed>
     */
    public function articleAttributes(array $lesson, string $language): array
    {
        return [
            'title' => $lesson['title'][$language],
            'slug' => $lesson['slug'][$language],
            'summary' => $lesson['summary'][$language],
            'content' => trim($lesson['body'][$language]),
            'cover_image' => $this->posterUrl($lesson),
            'category' => self::CATEGORY,
            'tags' => $lesson['tags'],
            'seo_title' => $lesson['title'][$language],
            'seo_description' => $lesson['summary'][$language],
            'author_name' => 'TPIX TRADE',
            'is_ai_generated' => false,
        ];
    }

    private function path(): string
    {
        return rtrim((string) config('learn.path', '/videos/learn'), '/');
    }
}
