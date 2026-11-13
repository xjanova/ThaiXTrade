<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChatbotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — Chatbot API Controller
 * API สำหรับ AI chatbot ลอยหน้าเว็บ.
 */
class ChatbotController extends Controller
{
    public function __construct(
        private ChatbotService $chatbot,
    ) {}

    /**
     * รับข้อความจาก user แล้วตอบกลับ.
     */
    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:1000',
            'language' => 'nullable|string|in:th,en',
            // บุคลิกผู้ตอบ: ผู้ช่วยทั่วไป · น้อง TPIX ในบับเบิ้ล (ตอบสั้น) · น้อง TPIX ในหน้าต่างแชท
            'persona' => 'nullable|string|in:assistant,mascot,mascot-chat',
            // บทสนทนาก่อนหน้าให้ AI ตอบต่อเนื่องได้ — จำกัดจำนวน/ความยาวกันพรอมต์บวม
            'history' => 'nullable|array|max:'.ChatbotService::HISTORY_LIMIT,
            'history.*.role' => 'required|string|in:user,bot',
            'history.*.text' => 'required|string|max:1000',
        ]);

        $language = $validated['language'] ?? 'th';

        // หลัง validate เสมอ — คำขอที่ผิดรูปแบบไม่เรียกโมเดล จึงต้องไม่กินงบของวัน
        if (! $this->reserveDailyBudget($request)) {
            // รูปเดียวกับตอนผู้ให้บริการล่ม (200 + data.success=false) — หน้าแชทแสดง message ให้ผู้ใช้อ่านได้เลย
            return response()->json([
                'success' => true,
                'data' => [
                    'message' => $language === 'th'
                        ? 'วันนี้ผู้ช่วยตอบครบโควตาแล้ว ลองใหม่พรุ่งนี้นะคะ'
                        : 'The assistant has reached today\'s limit. Please try again tomorrow.',
                    'navigation' => null,
                    'success' => false,
                    'limited' => true,
                ],
            ]);
        }

        $result = $this->chatbot->chat(
            $validated['message'],
            $language,
            $validated['history'] ?? [],
            $validated['persona'] ?? 'assistant',
        );

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * จองงบของวันก่อนเรียกโมเดล — เพดานต่อ IP และเพดานรวมทั้งเว็บ (config/ai_text.php → chatbot).
     *
     * ตรวจครบทุกเพดานก่อนแล้วค่อยนับ: IP ที่เกินโควตาของตัวเองต้องไม่กินงบรวมของคนอื่น
     * นับตอนจอง ไม่ใช่ตอนโมเดลตอบสำเร็จ — คำขอที่ผู้ให้บริการล่มกลางทางก็ถูกคิดเงินไปแล้วบางส่วน
     * และถ้านับเฉพาะที่สำเร็จ ช่วงที่ผู้ให้บริการล่มจะกลายเป็นช่วงที่ยิงได้ไม่จำกัด
     */
    private function reserveDailyBudget(Request $request): bool
    {
        $day = now()->timezone('Asia/Bangkok')->toDateString();
        $total = "chatbot:day:{$day}";

        $caps = [
            "{$total}:ip:".sha1((string) $request->ip()) => (int) config('ai_text.chatbot.daily_cap_per_ip', 200),
            $total => (int) config('ai_text.chatbot.daily_cap', 2000),
        ];

        foreach ($caps as $key => $cap) {
            if ($cap > 0 && (int) Cache::get($key, 0) >= $cap) {
                // เพดานรวมเต็ม = ทั้งเว็บใช้ผู้ช่วยไม่ได้จนถึงพรุ่งนี้ — แจ้งใน log ครั้งเดียวต่อวัน ให้รู้ว่าต้องขยับเพดานหรือมีคนยิง
                if ($key === $total && Cache::add("{$total}:alerted", 1, now()->addDay())) {
                    Log::warning('Chatbot daily budget exhausted', ['day' => $day, 'cap' => $cap]);
                }

                return false;
            }
        }

        foreach ($caps as $key => $cap) {
            if ($cap > 0) {
                Cache::add($key, 0, now()->addDay());
                Cache::increment($key);
            }
        }

        return true;
    }
}
