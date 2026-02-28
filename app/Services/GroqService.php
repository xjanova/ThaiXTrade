<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://api.groq.com/openai/v1';
    protected string $defaultModel = 'llama-3.3-70b-versatile';

    public function __construct()
    {
        $this->apiKey = config('services.groq.api_key', '');
    }

    /**
     * Send a chat completion request to Groq
     */
    public function chat(string $message, string $systemPrompt = '', array $options = []): array
    {
        $model = $options['model'] ?? $this->defaultModel;
        $temperature = $options['temperature'] ?? 0.7;
        $maxTokens = $options['max_tokens'] ?? 4096;

        $messages = [];
        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $message];

        $startTime = microtime(true);

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(60)
                ->post("{$this->baseUrl}/chat/completions", [
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => $temperature,
                    'max_tokens' => $maxTokens,
                ]);

            $processingTime = (int)((microtime(true) - $startTime) * 1000);

            if ($response->failed()) {
                Log::error('Groq API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return [
                    'success' => false,
                    'error' => $response->json('error.message', 'API request failed'),
                    'processing_time_ms' => $processingTime,
                ];
            }

            $data = $response->json();
            return [
                'success' => true,
                'content' => $data['choices'][0]['message']['content'] ?? '',
                'model' => $model,
                'tokens_used' => $data['usage']['total_tokens'] ?? 0,
                'processing_time_ms' => $processingTime,
            ];
        } catch (\Exception $e) {
            Log::error('Groq API exception', ['message' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'processing_time_ms' => (int)((microtime(true) - $startTime) * 1000),
            ];
        }
    }

    /**
     * Analyze market data
     */
    public function analyzeMarket(string $symbol, string $type = 'technical', array $marketData = []): array
    {
        $systemPrompt = "You are an expert cryptocurrency market analyst for TPIX TRADE exchange. Provide professional, data-driven analysis. Always include risk warnings. Format output with clear sections. Respond in the language specified by the user.";

        $dataContext = !empty($marketData) ? "\n\nMarket Data:\n" . json_encode($marketData, JSON_PRETTY_PRINT) : '';

        $prompts = [
            'technical' => "Perform a detailed technical analysis for {$symbol}. Include support/resistance levels, trend analysis, key indicators (RSI, MACD, Moving Averages), and trading signals. {$dataContext}",
            'sentiment' => "Analyze the current market sentiment for {$symbol}. Consider social media trends, news impact, fear & greed index, whale movements, and on-chain metrics. {$dataContext}",
            'price_prediction' => "Provide a price prediction analysis for {$symbol} for the next 24h, 7d, and 30d timeframes. Include bull and bear scenarios with probability estimates. Always include disclaimer about prediction limitations. {$dataContext}",
            'market_analysis' => "Provide a comprehensive market overview for {$symbol}. Include price action analysis, volume analysis, market cap trends, and comparison with overall crypto market. {$dataContext}",
        ];

        $prompt = $prompts[$type] ?? $prompts['market_analysis'];

        return $this->chat($prompt, $systemPrompt, [
            'temperature' => 0.5,
            'max_tokens' => 4096,
        ]);
    }

    /**
     * Generate news article
     */
    public function generateNews(string $topic, string $category = 'market_update', string $language = 'th'): array
    {
        $langName = $language === 'th' ? 'Thai' : 'English';

        $systemPrompt = "You are a professional cryptocurrency news writer for TPIX TRADE. Write well-researched, engaging articles. Include relevant data and analysis. The article should be professional and suitable for a trading platform's news section.";

        $prompt = "Write a comprehensive news article about: {$topic}\n\nCategory: {$category}\nLanguage: {$langName}\n\nFormat the response as JSON with these fields:\n- title: Article headline\n- summary: 2-3 sentence summary\n- content: Full article in HTML format with paragraphs, headings, and emphasis\n- tags: Array of relevant tags (3-5 tags)";

        return $this->chat($prompt, $systemPrompt, [
            'temperature' => 0.7,
            'max_tokens' => 4096,
        ]);
    }

    /**
     * Get available models
     */
    public function getModels(): array
    {
        return [
            'llama-3.3-70b-versatile' => 'Llama 3.3 70B (Versatile)',
            'llama-3.1-8b-instant' => 'Llama 3.1 8B (Fast)',
            'mixtral-8x7b-32768' => 'Mixtral 8x7B',
            'gemma2-9b-it' => 'Gemma 2 9B',
        ];
    }
}
