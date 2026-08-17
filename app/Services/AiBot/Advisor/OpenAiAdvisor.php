<?php

namespace App\Services\AiBot\Advisor;

/**
 * TPIX TRADE — ที่ปรึกษาสำหรับแพลนที่เสียเงิน (OpenAI).
 *
 * ค่า API มาจากค่าเช่าที่ผู้ใช้จ่าย จึงให้คุณภาพสูงกว่าของแพลนฟรีได้
 *
 * Developed by Xman Studio.
 */
class OpenAiAdvisor extends HttpAdvisor
{
    public function name(): string
    {
        return 'openai';
    }

    protected function send(string $prompt): ?string
    {
        $response = $this->http()
            ->withToken($this->config['api_key'])
            ->post($this->config['endpoint'], [
                'model' => $this->config['model'] ?? 'gpt-4o-mini',
                'temperature' => 0.4,
                'max_tokens' => 500,
                'messages' => [
                    ['role' => 'system', 'content' => 'คุณเป็นที่ปรึกษาการเทรดที่ตรงไปตรงมา ตอบเป็นภาษาไทยเสมอ'],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException("OpenAI HTTP {$response->status()}");
        }

        return $response->json('choices.0.message.content');
    }
}
