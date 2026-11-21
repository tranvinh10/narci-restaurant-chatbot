<?php

namespace App\Http\Controllers;

use App\Services\GeminiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ChatController extends Controller
{
    public function __construct(private readonly GeminiService $gemini) {}

    /**
     * POST /chat — nhận 1 tin nhắn. Khác với bản OpenAI: ở đây chỉ cần lưu
     * "interaction_id" (1 chuỗi) vào session, vì Gemini tự giữ lịch sử hội thoại
     * phía server (store: true) — không cần tự quản lý cả mảng messages nữa.
     */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $previousInteractionId = session('gemini_interaction_id');

        try {
            $result = $this->gemini->reply($previousInteractionId, $data['message']);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'reply' => 'Xin lỗi, hệ thống tư vấn đang gặp sự cố...',
                'reservation' => null,
                'debug' => $e->getMessage(), // TẠM THỜI để debug, nhớ xoá dòng này sau khi xong
            ]);
        }

        session(['gemini_interaction_id' => $result['interaction_id']]);

        return response()->json([
            'reply' => $result['content'],
            'reservation' => $result['reservation'],
        ]);
    }

    /**
     * POST /chat/reset — bắt đầu cuộc trò chuyện mới.
     */
    public function reset(): JsonResponse
    {
        session()->forget('gemini_interaction_id');

        return response()->json(['message' => 'Đã bắt đầu cuộc trò chuyện mới.']);
    }
}
