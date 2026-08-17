<?php

namespace App\Services\AiBot\Advisor;

/**
 * TPIX TRADE — ที่ปรึกษาสำหรับแพลนฟรี (Google Gemini).
 *
 * เลือก Gemini ให้แพลนฟรีเพราะมีโควตาฟรีที่ใช้ได้จริง — ผู้ใช้ที่ยังไม่จ่ายเงิน
 * ก็ได้คำแนะนำพื้นฐาน โดยเราไม่ต้องแบกค่า API ต่อหัว
 *
 * Developed by Xman Studio.
 */
class GeminiAdvisor extends HttpAdvisor
{
    public function name(): string
    {
        return 'gemini';
    }

    protected function send(string $prompt): ?string
    {
        $model = $this->config['model'] ?? 'gemini-2.0-flash';
        $url = rtrim($this->config['endpoint'], '/')."/{$model}:generateContent";

        $response = $this->http()
            // คีย์ไปกับ header ไม่ใช่ query string — กันคีย์ไปโผล่ใน log ของ proxy
            ->withHeaders(['x-goog-api-key' => $this->config['api_key']])
            ->post($url, [
                'contents' => [[
                    'parts' => [['text' => $prompt]],
                ]],
                'generationConfig' => [
                    'temperature' => 0.4,
                    'maxOutputTokens' => 500,
                ],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException("Gemini HTTP {$response->status()}");
        }

        return $response->json('candidates.0.content.parts.0.text');
    }
}
