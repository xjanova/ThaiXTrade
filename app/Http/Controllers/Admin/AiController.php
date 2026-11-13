<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiAnalysis;
use App\Models\AiNews;
use App\Services\AiTextService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AiController extends Controller
{
    public function __construct(
        private AiTextService $groqService,
    ) {}

    /**
     * Display the AI dashboard with recent analyses and news.
     */
    public function index(): Response
    {
        $recentAnalyses = AiAnalysis::with('createdBy')
            ->latest()
            ->take(10)
            ->get();

        $recentNews = AiNews::with('createdBy')
            ->latest()
            ->take(5)
            ->get();

        $stats = [
            'total_analyses' => AiAnalysis::count(),
            'success_rate' => $this->calculateSuccessRate(),
            'avg_processing_time' => (int) AiAnalysis::completed()->avg('processing_time_ms'),
            'tokens_today' => (int) AiAnalysis::whereDate('created_at', today())->sum('tokens_used'),
        ];

        return Inertia::render('Admin/AI/Index', [
            'recentAnalyses' => $recentAnalyses,
            'recentNews' => $recentNews,
            'stats' => $stats,
            'models' => $this->groqService->getModels(),
        ]);
    }

    /**
     * Run a market analysis via Groq API.
     */
    public function analyze(Request $request)
    {
        /*
         * โมเดลต้องเป็นตัวที่เซิร์ฟเวอร์รู้จัก (รายการจาก config/ai_text.php) หรือไม่ส่งมาเลย
         *
         * เดิมรับสตริงอะไรก็ได้ และหน้าเว็บส่ง `llama-3.3-70b-versatile` (ถูกถอดแล้ว) มาทุกครั้ง
         * ไม่ส่ง/ส่งค่าว่าง = ใช้โมเดลตามค่าตั้งของระบบ ซึ่งเป็นทางที่หน้าเว็บใช้เป็นค่าปริยาย
         */
        $validated = $request->validate([
            'symbol' => 'required|string|max:20',
            'type' => 'required|in:market_analysis,price_prediction,sentiment,technical',
            'model' => ['nullable', 'string', Rule::in(array_keys(AiTextService::modelOptions()))],
        ]);

        $model = $validated['model'] ?? null;

        // Create analysis record
        $analysis = AiAnalysis::create([
            'type' => $validated['type'],
            'symbol' => $validated['symbol'],
            'prompt' => '',
            'response' => '',
            // ยังไม่รู้ว่าเจ้าไหนจะตอบ — เขียนโมเดลจริงทับเมื่อสำเร็จ
            'model' => $model ?: 'auto',
            'status' => 'processing',
            'created_by' => $request->user()?->id,
        ]);

        /*
         * ไม่ว่าจะล้มแบบไหน แถวต้องไม่ค้างสถานะ processing
         *
         * เดิมอ่าน $result['processing_time_ms'] ตอนล้ม ซึ่งไม่มีคีย์นี้เมื่อล้มทุกผู้ให้บริการ
         * → ErrorException กลางทาง หน้าจอได้ 500 และแถวค้าง "กำลังประมวลผล" ตลอดกาล
         */
        try {
            $result = $this->groqService->analyzeMarket($validated['symbol'], $validated['type'], [], $model);
        } catch (\Throwable $e) {
            Log::error('AI analysis threw', ['analysis_id' => $analysis->id, 'error' => $e->getMessage()]);
            $result = ['success' => false, 'error' => __('เกิดข้อผิดพลาดภายในระบบ')];
        }

        if ($result['success'] ?? false) {
            $analysis->update([
                'response' => $result['content'] ?? '',
                'model' => $result['model'] ?? ($model ?: 'auto'),
                'tokens_used' => $result['tokens_used'] ?? null,
                'processing_time_ms' => $result['processing_time_ms'] ?? null,
                'status' => 'completed',
            ]);

            return back()->with('success', __('วิเคราะห์เสร็จเรียบร้อย'))->with('analysis', $analysis->fresh());
        }

        $error = (string) ($result['error'] ?? __('ไม่ทราบสาเหตุ'));

        $analysis->update([
            'status' => 'failed',
            'error_message' => $error,
            'processing_time_ms' => $result['processing_time_ms'] ?? null,
        ]);

        return back()->with('error', __('วิเคราะห์ไม่สำเร็จ: :error', ['error' => $error]));
    }

    /**
     * Display paginated analysis history.
     */
    public function analyzeHistory(): Response
    {
        $analyses = AiAnalysis::with('createdBy')
            ->latest()
            ->paginate(20);

        return Inertia::render('Admin/AI/Index', [
            'analyses' => $analyses,
            'models' => $this->groqService->getModels(),
        ]);
    }

    /**
     * Display AI news management page.
     */
    public function newsIndex(): Response
    {
        $news = AiNews::with('createdBy')
            ->latest()
            ->paginate(20);

        return Inertia::render('Admin/AI/News', [
            'news' => $news,
        ]);
    }

    /**
     * Generate a news article via Groq API.
     */
    public function generateNews(Request $request)
    {
        $validated = $request->validate([
            'topic' => 'required|string|max:500',
            'category' => 'required|in:market_update,analysis,defi,nft,regulation,technology,tutorial',
            'language' => 'nullable|string|in:th,en',
        ]);

        $language = $validated['language'] ?? 'th';

        // แอดมินปิดการสร้างคอนเทนต์ด้วย AI ไว้ (ตั้งค่า > AI) — ไม่ยิง API เลย
        if (! AiTextService::featureEnabled('ai_content_enabled')) {
            return back()->with('error', __('ปิดการสร้างคอนเทนต์ด้วย AI ไว้ชั่วคราว — เปิดได้ที่หน้า ตั้งค่า > AI'));
        }

        $result = $this->groqService->generateNews(
            $validated['topic'],
            $validated['category'],
            $language,
        );

        if (! ($result['success'] ?? false)) {
            return back()->with('error', __('สร้างข่าวไม่สำเร็จ: :error', ['error' => (string) ($result['error'] ?? __('ไม่ทราบสาเหตุ'))]));
        }

        // Attempt to parse JSON response from AI
        $content = $result['content'];
        $parsed = $this->parseNewsResponse($content);

        $news = AiNews::create([
            'title' => $parsed['title'] ?? 'Untitled Article',
            'content' => $parsed['content'] ?? $content,
            'summary' => $parsed['summary'] ?? null,
            'category' => $validated['category'],
            'language_code' => $language,
            'source_prompt' => $validated['topic'],
            'ai_model' => $result['model'],
            'tags' => $parsed['tags'] ?? [],
            'status' => 'draft',
            'created_by' => $request->user()?->id,
        ]);

        return back()->with('success', 'News article generated successfully.')->with('generatedNews', $news->fresh());
    }

    /**
     * Update a news article.
     */
    public function newsUpdate(Request $request, AiNews $news): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'summary' => 'nullable|string',
            'category' => 'required|in:market_update,analysis,defi,nft,regulation,technology,tutorial',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'featured_image' => 'nullable|string|max:255',
            'status' => 'nullable|in:draft,review,published,archived',
        ]);

        $news->update($validated);

        return back()->with('success', 'News article updated successfully.');
    }

    /**
     * Publish or unpublish a news article.
     */
    public function newsPublish(AiNews $news): RedirectResponse
    {
        if ($news->status === 'published') {
            $news->update([
                'status' => 'draft',
                'published_at' => null,
            ]);

            return back()->with('success', 'News article unpublished.');
        }

        $news->update([
            'status' => 'published',
            'published_at' => now(),
        ]);

        return back()->with('success', 'News article published successfully.');
    }

    /**
     * Soft delete a news article.
     */
    public function newsDestroy(AiNews $news): RedirectResponse
    {
        $news->delete();

        return back()->with('success', 'News article deleted successfully.');
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    /**
     * Calculate the success rate of analyses.
     */
    private function calculateSuccessRate(): float
    {
        $total = AiAnalysis::count();
        if ($total === 0) {
            return 100.0;
        }

        $completed = AiAnalysis::completed()->count();

        return round(($completed / $total) * 100, 1);
    }

    /**
     * Parse the AI-generated news response, which may be JSON or plain text.
     */
    private function parseNewsResponse(string $content): array
    {
        // Try to extract JSON from the response
        // The AI may wrap JSON in markdown code blocks
        $jsonContent = $content;

        // Remove markdown code block markers if present
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $content, $matches)) {
            $jsonContent = $matches[1];
        }

        $parsed = json_decode($jsonContent, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            return $parsed;
        }

        // If JSON parsing fails, return the raw content
        return [
            'title' => 'AI Generated Article',
            'content' => $content,
            'summary' => null,
            'tags' => [],
        ];
    }
}
