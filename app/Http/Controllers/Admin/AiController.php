<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiAnalysis;
use App\Models\AiNews;
use App\Services\GroqService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiController extends Controller
{
    public function __construct(
        private GroqService $groqService,
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
        $validated = $request->validate([
            'symbol' => 'required|string|max:20',
            'type' => 'required|in:market_analysis,price_prediction,sentiment,technical',
            'model' => 'nullable|string',
        ]);

        // Create analysis record
        $analysis = AiAnalysis::create([
            'type' => $validated['type'],
            'symbol' => $validated['symbol'],
            'prompt' => '',
            'response' => '',
            'model' => $validated['model'] ?? 'llama-3.3-70b-versatile',
            'status' => 'processing',
            'created_by' => $request->user()?->id,
        ]);

        // Call Groq API
        $result = $this->groqService->analyzeMarket(
            $validated['symbol'],
            $validated['type'],
        );

        if ($result['success']) {
            $analysis->update([
                'response' => $result['content'],
                'model' => $result['model'],
                'tokens_used' => $result['tokens_used'],
                'processing_time_ms' => $result['processing_time_ms'],
                'status' => 'completed',
            ]);

            return back()->with('success', 'Analysis completed successfully.')->with('analysis', $analysis->fresh());
        }

        $analysis->update([
            'status' => 'failed',
            'error_message' => $result['error'],
            'processing_time_ms' => $result['processing_time_ms'],
        ]);

        return back()->with('error', 'Analysis failed: ' . $result['error']);
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

        $result = $this->groqService->generateNews(
            $validated['topic'],
            $validated['category'],
            $language,
        );

        if (! $result['success']) {
            return back()->with('error', 'News generation failed: ' . $result['error']);
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
