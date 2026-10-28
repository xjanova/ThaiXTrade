<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Services\Learn\LearnCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TPIX TRADE — Public Article API
 * แสดงบทความที่ publish แล้วสำหรับหน้า Blog.
 */
class ArticleController extends Controller
{
    public function __construct(private LearnCatalog $learn) {}

    /**
     * รายการบทความที่ publish แล้ว.
     */
    public function index(Request $request): JsonResponse
    {
        $articles = Article::published()
            ->when($request->category, fn ($q, $c) => $q->byCategory($c))
            ->when($request->language, fn ($q, $l) => $q->byLanguage($l))
            ->select(['id', 'title', 'slug', 'summary', 'cover_image', 'category', 'tags', 'language', 'published_at', 'views', 'likes', 'author_name', 'source_key'])
            ->latest('published_at')
            ->paginate(12);

        // บทเรียนวิดีโอ: การ์ดแสดงปุ่มเล่น + ความยาวคลิป · source_key เป็นของภายใน ไม่ส่งออก
        $items = array_map(function (Article $article) {
            $lesson = $this->learn->forArticle($article);

            return $article->makeHidden('source_key')->toArray()
                + ['video_duration' => $lesson !== null ? (int) $lesson['duration'] : null];
        }, $articles->items());

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $articles->currentPage(),
                'last_page' => $articles->lastPage(),
                'total' => $articles->total(),
            ],
        ]);
    }

    /**
     * แสดงบทความเดี่ยว.
     */
    public function show(string $slug): JsonResponse
    {
        $article = Article::published()->where('slug', $slug)->first();

        if (! $article) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Article not found'],
            ], 404);
        }

        $article->incrementViews();

        $lesson = $this->learn->forArticle($article);

        return response()->json([
            'success' => true,
            'data' => $article->toArray() + [
                'lesson' => $lesson !== null ? $this->learn->player($lesson, (string) $article->language) : null,
            ],
        ]);
    }
}
