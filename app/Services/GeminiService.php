<?php

namespace App\Services;

use App\Http\Requests\StoreReservationRequest;
use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\Reservation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class GeminiService
{
    private const MAX_TOOL_ROUNDS = 5; // Quy tắc C.8: giới hạn vòng lặp function calling

    public function __construct(private readonly ReservationService $reservations) {}

    /**
     * @return array{content: string, interaction_id: ?string, reservation: array|null}
     */
    public function reply(?string $previousInteractionId, string $userMessage): array
    {
        $reservationCard = null;
        $input = $userMessage; // Turn đầu tiên (hoặc tin nhắn thường): input là 1 chuỗi text
        $previousId = $previousInteractionId;
        $lastInteractionId = $previousInteractionId;

        for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
            $response = $this->callGemini($input, $previousId);
            $lastInteractionId = $response['id'] ?? $lastInteractionId;

            $steps = $response['steps'] ?? [];
            $functionCalls = array_values(array_filter(
                $steps,
                fn($s) => ($s['type'] ?? null) === 'function_call'
            ));

            if (empty($functionCalls)) {
                return [
                    'content' => $this->extractText($steps),
                    'interaction_id' => $lastInteractionId,
                    'reservation' => $reservationCard,
                ];
            }

            // Gemini có thể gọi song song nhiều tool trong 1 lượt (parallel function calling)
            $resultSteps = [];
            foreach ($functionCalls as $call) {
                $name = $call['name'] ?? '';
                $args = $call['arguments'] ?? []; // Gemini trả sẵn dạng mảng, KHÔNG phải chuỗi JSON như OpenAI
                $result = $this->dispatchTool($name, is_array($args) ? $args : []);

                if ($name === 'create_reservation' && ! isset($result['error'])) {
                    $reservationCard = $result;
                }

                $resultSteps[] = [
                    'type' => 'function_result',
                    'name' => $name,
                    'call_id' => $call['id'] ?? '', // phải khớp đúng "id" của function_call tương ứng
                    'result' => [['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_UNICODE)]],
                ];
            }

            // Lượt tiếp theo: nối tiếp interaction vừa rồi, input là kết quả các tool
            $previousId = $lastInteractionId;
            $input = $resultSteps;
        }

        throw new \RuntimeException('Vượt quá số vòng lặp xử lý function calling cho phép.');
    }

    private function callGemini(string|array $input, ?string $previousInteractionId): array
    {
        $payload = [
            'model' => config('services.gemini.model', 'gemini-3.8-flash'),
            'input' => $input,
            'system_instruction' => $this->systemPrompt(),
            'tools' => $this->toolDefinitions(),
            'store' => true, // Google giữ lịch sử hội thoại hộ mình, chỉ cần lưu lại "id" để nối tiếp
            'generation_config' => ['thinking_level' => 'low'], // giảm "suy nghĩ ẩn" để trả lời nhanh hơn
        ];

        if ($previousInteractionId) {
            $payload['previous_interaction_id'] = $previousInteractionId;
        }

        $maxAttempts = 3;
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::withHeaders(['x-goog-api-key' => config('services.gemini.key')])
                    ->timeout(45)
                    ->post('https://generativelanguage.googleapis.com/v1beta/interactions', $payload);
            } catch (ConnectionException $e) {
                throw new \RuntimeException('Không kết nối được tới Gemini (timeout).', previous: $e);
            }

            if ($response->successful()) {
                $json = $response->json();
                if (! is_array($json)) {
                    throw new \RuntimeException('Gemini trả về dữ liệu không phải JSON hợp lệ.');
                }

                return $json;
            }

            // 429 (vượt rate limit) / 503 (Google đang quá tải) là lỗi TẠM THỜI, đáng thử lại
            if (in_array($response->status(), [429, 503]) && $attempt < $maxAttempts) {
                $lastError = $response->body();
                sleep($attempt * 2); // chờ 2s rồi 4s trước khi thử lại
                continue;
            }

            throw new \RuntimeException('Gemini API lỗi: ' . $response->status() . ' ' . $response->body());
        }

        throw new \RuntimeException("Gemini API lỗi sau {$maxAttempts} lần thử: {$lastError}");
    }

    /**
     * Câu trả lời cuối cùng không nằm ở field "output_text" (đó chỉ là tiện ích SDK Python/JS
     * tự tính, không có trong JSON thô) — phải tự gom từ các step "model_output".
     */
    private function extractText(array $steps): string
    {
        $text = '';

        foreach ($steps as $step) {
            if (($step['type'] ?? null) === 'model_output') {
                foreach ($step['content'] ?? [] as $content) {
                    if (($content['type'] ?? null) === 'text') {
                        $text .= $content['text'] ?? '';
                    }
                }
            }
        }

        return $text;
    }

    private function dispatchTool(string $name, array $args): array
    {
        try {
            return match ($name) {
                'search_menu' => $this->searchMenu($args),
                'check_table_availability' => $this->checkAvailability($args),
                'create_reservation' => $this->createReservation($args),
                'manage_reservation' => $this->manageReservation($args),
                default => ['error' => "Không hỗ trợ tool: {$name}"],
            };
        } catch (ValidationException $e) {
            return ['error' => 'Thiếu/sai tham số: ' . collect($e->errors())->flatten()->implode(' ')];
        } catch (\Throwable $e) {
            report($e);

            return ['error' => 'Có lỗi khi xử lý yêu cầu này, vui lòng thử lại.'];
        }
    }

    // ---- 4 tool thật — y nguyên logic từ bản OpenAI, không đổi gì (Quy tắc C.2) ----

    private function searchMenu(array $args): array
    {
        Validator::make($args, [
            'category' => ['nullable', 'in:pizza,pasta,appetizer,cheese,dessert,drink'],
            'dietary_tag' => ['nullable', 'in:vegetarian,no_spicy,no_seafood'],
            'keyword' => ['nullable', 'string', 'max:100'],
        ])->validate();

        $query = MenuItem::query()->where('is_available', true)->category($args['category'] ?? null);

        match ($args['dietary_tag'] ?? null) {
            'vegetarian' => $query->vegetarian(),
            'no_spicy' => $query->notSpicy(),
            'no_seafood' => $query->noSeafood(),
            default => null,
        };

        if (! empty($args['keyword'])) {
            $query->where('name', 'like', '%' . $args['keyword'] . '%');
        }

        return $query->get(['name', 'description', 'price', 'allergens', 'allow_half_half'])->toArray();
    }

    private function checkAvailability(array $args): array
    {
        Validator::make($args, [
            'branch_id' => ['required', 'exists:branches,id'],
            'date' => ['required', 'date', 'after_or_equal:today'],
            'time' => ['required', 'date_format:H:i,H:i:s'],
            'party_size' => ['required', 'integer', 'min:1', 'max:20'],
            'seating_preference' => ['nullable', 'in:regular,open_kitchen_counter,quiet_zone,any'],
        ])->validate();

        $branch = Branch::findOrFail($args['branch_id']);
        $time = strlen($args['time']) === 5 ? $args['time'] . ':00' : $args['time'];
        $seating = $args['seating_preference'] ?? 'any';

        $tables = $this->reservations->findAvailableTables($branch, $args['date'], $time, $args['party_size'], $seating);

        if ($tables) {
            return ['available' => true, 'tables' => $tables->pluck('table_code')->values()->toArray()];
        }

        $suggestions = $this->reservations->suggestNearbySlots($branch, $args['date'], $time, $args['party_size'], $seating);

        return [
            'available' => false,
            'suggestions' => collect($suggestions)
                ->map(fn($s) => ['time' => $s['time'], 'tables' => $s['tables']->pluck('table_code')->values()->toArray()])
                ->values()->toArray(),
        ];
    }

    private function createReservation(array $args): array
    {
        $data = [
            'branch_id' => $args['branch_id'] ?? null,
            'customer_name' => $args['customer_name'] ?? null,
            'phone' => $args['phone'] ?? null,
            'email' => $args['email'] ?? null,
            'reservation_date' => $args['date'] ?? null,
            'start_time' => isset($args['time']) && strlen($args['time']) === 5 ? $args['time'] . ':00' : ($args['time'] ?? null),
            'party_size' => $args['party_size'] ?? null,
            'seating_preference' => $args['seating_preference'] ?? 'any',
            'occasion' => $args['occasion'] ?? null,
            'notes' => $args['notes'] ?? null,
        ];

        Validator::make($data, (new StoreReservationRequest())->rules())->validate();

        $branch = Branch::findOrFail($data['branch_id']);
        $tables = $this->reservations->findAvailableTables(
            $branch,
            $data['reservation_date'],
            $data['start_time'],
            $data['party_size'],
            $data['seating_preference']
        );

        if (! $tables) {
            return ['error' => 'Hết bàn phù hợp đúng khung giờ này, hãy gọi lại check_table_availability để lấy gợi ý giờ khác.'];
        }

        $reservation = $this->reservations->createReservation($data + ['source' => 'ai_chatbot'], $tables);

        return [
            'booking_code' => $reservation->booking_code,
            'branch' => $branch->name,
            'date' => $data['reservation_date'],
            'time' => $data['start_time'],
            'party_size' => $data['party_size'],
            'tables' => $tables->pluck('table_code')->values()->toArray(),
        ];
    }

    private function manageReservation(array $args): array
    {
        Validator::make($args, [
            'booking_code' => ['required', 'string'],
            'phone' => ['required', 'string'],
            'action' => ['required', 'in:lookup,reschedule,cancel'],
            'new_time' => ['required_if:action,reschedule', 'nullable', 'date_format:H:i,H:i:s'],
        ])->validate();

        $reservation = Reservation::where('booking_code', $args['booking_code'])
            ->where('phone', $args['phone'])
            ->first();

        if (! $reservation) {
            return ['error' => 'Không tìm thấy đơn đặt bàn khớp với mã và số điện thoại đã cho.'];
        }

        return match ($args['action']) {
            'lookup' => [
                'booking_code' => $reservation->booking_code,
                'status' => $reservation->status,
                'date' => $reservation->reservation_date->toDateString(),
                'time' => $reservation->start_time,
                'party_size' => $reservation->party_size,
            ],
            'cancel' => $this->handleCancel($reservation),
            'reschedule' => $this->handleReschedule($reservation, $args['new_time']),
        };
    }

    private function handleCancel(Reservation $reservation): array
    {
        $this->reservations->cancelReservation($reservation);

        return ['message' => 'Đã huỷ đơn ' . $reservation->booking_code . '.'];
    }

    private function handleReschedule(Reservation $reservation, string $newTime): array
    {
        $newTime = strlen($newTime) === 5 ? $newTime . ':00' : $newTime;

        try {
            $updated = $this->reservations->rescheduleReservation($reservation, $newTime);

            return ['message' => 'Đã đổi giờ thành công.', 'booking_code' => $updated->booking_code, 'time' => $updated->start_time];
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    // ---- Persona + 4 tool declaration (dạng phẳng, đúng chuẩn Gemini) ----

    private function systemPrompt(): string
    {
        $branches = Branch::where('is_active', true)->get()
            ->map(fn($b) => "- ID {$b->id}: {$b->name} ({$b->city}), mở cửa {$b->opening_time} - {$b->closing_time}")
            ->implode("\n");

        $today = now()->format('Y-m-d (l)');

        return <<<PROMPT
            Bạn là nhân viên tư vấn ẩm thực của Narci Restaurant — nhà hàng pizza thủ công phong cách Ý-Nhật,
            nổi bật với phô mai tươi tự làm (Burrata, Mozzarella, Camembert). Trả lời ngắn gọn, ấm áp, chuyên nghiệp.

            Hôm nay là: {$today}

            CHÍNH SÁCH NHÀ HÀNG:
            - Giữ bàn tối đa 15 phút kể từ giờ đặt.
            - Hỗ trợ trang trí bánh sinh nhật miễn phí nếu khách báo trước.
            - Có phụ thu nếu khách mang rượu ngoài vào.

            DANH SÁCH CHI NHÁNH HIỆN TẠI:
            {$branches}

            QUY TRÌNH ĐẶT BÀN (bắt buộc đúng thứ tự):
            1. Hỏi đủ: chi nhánh, ngày, giờ, số khách, sở thích chỗ ngồi.
            2. Gọi check_table_availability để kiểm tra TRƯỚC, không tự đoán còn bàn hay không.
            3. Đọc lại đầy đủ thông tin để khách xác nhận rõ ràng.
            4. CHỈ gọi create_reservation sau khi khách đã xác nhận "đồng ý"/"ok".
            5. Chỉ báo đặt bàn thành công khi tool trả về booking_code thật, không tự bịa mã.
            6. Khi khách muốn đổi giờ/huỷ: luôn hỏi mã đặt bàn (RES-XXXX) + số điện thoại để xác minh trước khi gọi manage_reservation.

            QUY TẮC DỊ ỨNG — RẤT QUAN TRỌNG:
            - Chỉ dựa vào trường "allergens" mà search_menu trả về, không tự suy luận nguyên liệu.
            - Nếu khách nêu dị ứng cụ thể mà dữ liệu không đủ chi tiết để chắc chắn, PHẢI nói sẽ nhờ nhân viên
              xác nhận lại trực tiếp trước khi phục vụ.
            - Không bao giờ đề xuất món có allergen khách đã xác định là dị ứng.
            PROMPT;
    }

    private function toolDefinitions(): array
    {
        return [
            [
                'type' => 'function',
                'name' => 'search_menu',
                'description' => 'Tìm món trong thực đơn theo danh mục, nhu cầu ăn kiêng hoặc từ khoá.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'category' => ['type' => 'string', 'enum' => ['pizza', 'pasta', 'appetizer', 'cheese', 'dessert', 'drink']],
                        'dietary_tag' => ['type' => 'string', 'enum' => ['vegetarian', 'no_spicy', 'no_seafood']],
                        'keyword' => ['type' => 'string'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'name' => 'check_table_availability',
                'description' => 'Kiểm tra bàn trống tại 1 chi nhánh vào 1 ngày giờ cụ thể cho 1 nhóm khách.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'branch_id' => ['type' => 'integer', 'description' => 'ID chi nhánh, lấy đúng từ danh sách chi nhánh đã cho'],
                        'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                        'time' => ['type' => 'string', 'description' => 'HH:mm (24h)'],
                        'party_size' => ['type' => 'integer'],
                        'seating_preference' => ['type' => 'string', 'enum' => ['regular', 'open_kitchen_counter', 'quiet_zone', 'any']],
                    ],
                    'required' => ['branch_id', 'date', 'time', 'party_size'],
                ],
            ],
            [
                'type' => 'function',
                'name' => 'create_reservation',
                'description' => 'Tạo đơn đặt bàn CHÍNH THỨC. Chỉ gọi sau khi đã check_table_availability và khách đã xác nhận.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'branch_id' => ['type' => 'integer'],
                        'customer_name' => ['type' => 'string'],
                        'phone' => ['type' => 'string'],
                        'date' => ['type' => 'string'],
                        'time' => ['type' => 'string'],
                        'party_size' => ['type' => 'integer'],
                        'seating_preference' => ['type' => 'string', 'enum' => ['regular', 'open_kitchen_counter', 'quiet_zone', 'any']],
                        'occasion' => ['type' => 'string'],
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => ['branch_id', 'customer_name', 'phone', 'date', 'time', 'party_size'],
                ],
            ],
            [
                'type' => 'function',
                'name' => 'manage_reservation',
                'description' => 'Tra cứu, đổi giờ hoặc huỷ đơn đã có, xác minh bằng mã đặt bàn + số điện thoại.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'booking_code' => ['type' => 'string'],
                        'phone' => ['type' => 'string'],
                        'action' => ['type' => 'string', 'enum' => ['lookup', 'reschedule', 'cancel']],
                        'new_time' => ['type' => 'string', 'description' => 'HH:mm, bắt buộc nếu action=reschedule'],
                    ],
                    'required' => ['booking_code', 'phone', 'action'],
                ],
            ],
        ];
    }
}
