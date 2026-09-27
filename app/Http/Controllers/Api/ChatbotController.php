<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ChatbotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $result = $this->chatbot->chat(
            $validated['message'],
            $validated['language'] ?? 'th',
            $validated['history'] ?? [],
            $validated['persona'] ?? 'assistant',
        );

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }
}
