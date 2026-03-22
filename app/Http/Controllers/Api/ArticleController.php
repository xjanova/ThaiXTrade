<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TPIX TRADE — Public Article API
 * แสดงบทความที่ publish แล้วสำหรับหน้า Blog.
 */
class ArticleController extends Controller
{
    /**
     * รายการบทความที่ publish แล้ว.
     */
    public function index(Request $request): JsonResponse
    {
        $articles = Article::published()
            ->when($request->category, fn ($q, $c) => $q->byCategory($c))
            ->when($request->language, fn ($q, $l) => $q->byLanguage($l))
            ->select(['id', 'title', 'slug', 'summary', 'cover_image', 'category', 'tags', 'language', 'published_at', 'views', 'likes', 'author_name'])
            ->latest('published_at')
            ->paginate(12);

        return response()->json([
            'success' => true,
            'data' => $articles->items(),
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

        return response()->json([
            'success' => true,
            'data' => $article,
        ]);
    }
}
