<?php

namespace App\Services\AiBot\Advisor;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — ฐานร่วมของที่ปรึกษาที่คุยผ่าน HTTP (Gemini / OpenAI).
 *
 * สองเจ้าต่างกันแค่รูปแบบ request/response ส่วนที่เหลือเหมือนกันหมด:
 * สร้าง prompt จากบริบทเดียวกัน · นับโควตาต่อวัน · จับ error ไม่ให้ล้มทั้งระบบ
 *
 * ที่ปรึกษาล้มเหลวต้องไม่ทำให้บอทหยุดเทรด — บอทตัดสินใจจากกฎอยู่แล้ว
 * คำแนะนำเป็นของแถม ไม่ใช่ของที่ขาดไม่ได้
 *
 * Developed by Xman Studio.
 */
abstract class HttpAdvisor implements Advisor
{
    public function __construct(protected readonly array $config) {}

    abstract public function name(): string;

    /** ยิงไปยังผู้ให้บริการแล้วคืนข้อความล้วน (null = ล้มเหลว) */
    abstract protected function send(string $prompt): ?string;

    public function isAvailable(): bool
    {
        return ($this->config['api_key'] ?? '') !== '' && $this->callsToday() < $this->dailyLimit();
    }

    public function advise(array $context): array
    {
        if (($this->config['api_key'] ?? '') === '') {
            return $this->fail('ยังไม่ได้ตั้งคีย์ของ '.$this->name());
        }

        if ($this->callsToday() >= $this->dailyLimit()) {
            return $this->fail('ใช้โควตาคำแนะนำของวันนี้ครบแล้ว');
        }

        try {
            $text = $this->send($this->buildPrompt($context));
        } catch (\Throwable $e) {
            // ผู้ให้บริการล่มไม่ใช่เรื่องที่ผู้ใช้ต้องรับรู้เป็น error หน้าจอ
            Log::warning('AI advisor failed', ['provider' => $this->name(), 'error' => $e->getMessage()]);

            return $this->fail('ขอคำแนะนำไม่สำเร็จ ลองใหม่อีกครั้ง');
        }

        if ($text === null || trim($text) === '') {
            return $this->fail('ไม่ได้รับคำตอบจากที่ปรึกษา');
        }

        $this->countCall();

        return ['ok' => true, 'provider' => $this->name(), 'text' => trim($text)];
    }

    /**
     * ประกอบคำถามจากบริบท.
     *
     * ⚠️ ห้ามใส่ที่อยู่กระเป๋าหรืออะไรที่ระบุตัวผู้ใช้ลงไป — ข้อมูลนี้ออกไปนอกระบบเรา
     */
    protected function buildPrompt(array $context): string
    {
        $lines = [
            'คุณเป็นที่ปรึกษาการเทรดคริปโตที่พูดตรงไปตรงมาและไม่ขายฝัน',
            'วิเคราะห์ผลงานย้อนหลังของกลยุทธ์ด้านล่าง แล้วให้คำแนะนำสั้นๆ เป็นภาษาไทย',
            '',
            'กติกาที่ต้องทำตาม:',
            '- ตอบไม่เกิน 6 บรรทัด',
            '- ถ้าข้อมูลน้อยเกินจะสรุปได้ ให้บอกตรงๆ ว่ายังไม่พอ อย่าเดา',
            '- ห้ามรับประกันผลตอบแทน และห้ามบอกว่า "ควรซื้อ/ขายตอนนี้"',
            '- ให้ความเห็นเชิงการปรับพารามิเตอร์และการจัดการความเสี่ยงแทน',
            '',
        ];

        if (! empty($context['pair'])) {
            $lines[] = 'คู่เทรด: '.$context['pair'];
        }

        if (! empty($context['strategy'])) {
            $lines[] = 'กลยุทธ์: '.$context['strategy'];
        }

        if (! empty($context['stats'])) {
            $lines[] = 'สถิติย้อนหลัง: '.json_encode($context['stats'], JSON_UNESCAPED_UNICODE);
        }

        if (! empty($context['risk'])) {
            $lines[] = 'ความเสี่ยงตลาดตอนนี้: '.json_encode($context['risk'], JSON_UNESCAPED_UNICODE);
        }

        if (! empty($context['headlines'])) {
            $limit = (int) config('aibot_advisor.max_headlines_in_context', 5);
            $lines[] = 'พาดหัวข่าวที่เกี่ยวข้อง:';
            foreach (array_slice($context['headlines'], 0, $limit) as $headline) {
                $lines[] = '  - '.$headline;
            }
        }

        if (! empty($context['recent_trades'])) {
            $limit = (int) config('aibot_advisor.max_trades_in_context', 40);
            $lines[] = 'ไม้ล่าสุด: '.json_encode(array_slice($context['recent_trades'], 0, $limit), JSON_UNESCAPED_UNICODE);
        }

        return implode("\n", $lines);
    }

    protected function fail(string $reason): array
    {
        return ['ok' => false, 'provider' => $this->name(), 'text' => '', 'reason' => $reason];
    }

    protected function http()
    {
        return Http::timeout((int) ($this->config['timeout'] ?? 20))->acceptJson();
    }

    // ── โควตาต่อวัน ─────────────────────────────────────────────────────────

    private function quotaKey(): string
    {
        return 'aibot:advisor:'.$this->name().':'.now()->toDateString();
    }

    private function dailyLimit(): int
    {
        return (int) ($this->config['max_calls_per_day'] ?? 50);
    }

    protected function callsToday(): int
    {
        return (int) Cache::get($this->quotaKey(), 0);
    }

    private function countCall(): void
    {
        // หมดอายุสิ้นวัน — ไม่ต้องมีงานมาล้างทีหลัง
        Cache::put($this->quotaKey(), $this->callsToday() + 1, now()->endOfDay());
    }
}
