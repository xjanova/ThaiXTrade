<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Services\ContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TPIX TRADE — Admin Content Controller
 * จัดการบทความ: สร้างด้วย AI, แก้ไข, ตั้งเวลา, publish.
 */
class ContentController extends Controller
{
    public function __construct(
        private ContentService $contentService,
    ) {}

    /**
     * แสดงรายการบทความทั้งหมด.
     */
    public function index(Request $request): Response
    {
        $articles = Article::query()
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->language, fn ($q, $l) => $q->where('language', $l))
            ->when($request->search, fn ($q, $s) => $q->where('title', 'like', "%{$s}%"))
            ->latest()
            ->paginate(15);

        $stats = [
            'total' => Article::count(),
            'published' => Article::where('status', 'published')->count(),
            'draft' => Article::where('status', 'draft')->count(),
            'scheduled' => Article::where('status', 'scheduled')->count(),
            'ai_generated' => Article::where('is_ai_generated', true)->count(),
            'total_views' => Article::sum('views'),
        ];

        return Inertia::render('Admin/Content/Index', [
            'articles' => $articles,
            'stats' => $stats,
            'filters' => $request->only(['status', 'language', 'search']),
        ]);
    }

    /**
     * แสดงหน้า Editor สำหรับแก้ไขบทความ.
     */
    public function edit(int $id): Response
    {
        $article = Article::findOrFail($id);

        return Inertia::render('Admin/Content/Edit', [
            'article' => $article,
        ]);
    }

    /**
     * สร้างบทความด้วย AI.
     */
    public function generate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'topic' => 'required|string|max:500',
            'category' => 'required|string|in:news,analysis,tutorial,tpix_chain,defi,technology',
            'language' => 'required|string|in:th,en',
            'model' => 'nullable|string',
        ]);

        try {
            $article = $this->contentService->generateArticle(
                $validated['topic'],
                $validated['category'],
                $validated['language'],
                $validated['model'] ?? null,
            );

            return back()->with('success', "สร้างบทความ \"{$article->title}\" สำเร็จ!");
        } catch (\Exception $e) {
            return back()->with('error', 'สร้างบทความไม่สำเร็จ: '.$e->getMessage());
        }
    }

    /**
     * อัปเดตบทความ.
     */
    public function update(Request $request, int $id): RedirectResponse
    {
        $article = Article::findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'summary' => 'nullable|string',
            'category' => 'nullable|string',
            'language' => 'nullable|string|in:th,en',
            'tags' => 'nullable|array',
            'status' => 'nullable|in:draft,scheduled,published,archived',
            'scheduled_at' => 'nullable|date',
            'seo_title' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:500',
        ]);

        if (isset($validated['status']) && $validated['status'] === 'published' && ! $article->published_at) {
            $validated['published_at'] = now();
        }

        $article->update($validated);

        return back()->with('success', 'บทความอัปเดตเรียบร้อย');
    }

    /**
     * ลบบทความ.
     */
    public function destroy(int $id): RedirectResponse
    {
        Article::findOrFail($id)->delete();

        return back()->with('success', 'ลบบทความเรียบร้อย');
    }

    /**
     * สร้างภาพ AI สำหรับบทความ.
     */
    public function generateImage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => 'required|string|max:500',
            'article_id' => 'nullable|integer|exists:articles,id',
        ]);

        $image = $this->contentService->generateImage($validated['prompt']);

        if ($image) {
            // ผูกรูปกับ article ถ้าส่ง article_id มา
            if (! empty($validated['article_id'])) {
                Article::where('id', $validated['article_id'])->update([
                    'cover_image' => $image,
                    'ai_image_prompt' => $validated['prompt'],
                ]);
            }

            return response()->json(['success' => true, 'image_url' => $image]);
        }

        return response()->json(['success' => false, 'error' => 'Image generation failed'], 500);
    }
}
