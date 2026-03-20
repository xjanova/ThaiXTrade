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
        ]);

        $result = $this->chatbot->chat(
            $validated['message'],
            $validated['language'] ?? 'th',
        );

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }
}
