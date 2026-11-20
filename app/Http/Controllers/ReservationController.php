<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReservationRequest;
use App\Models\Branch;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class ReservationController extends Controller
{
    public function __construct(private readonly ReservationService $reservations) {}

    /**
     * POST /reservations/check-availability
     * Bước 1 trong quy trình xác nhận đặt bàn bằng AI (Quy tắc B.2).
     */
    public function checkAvailability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'reservation_date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i,H:i:s'],
            'party_size' => ['required', 'integer', 'min:1', 'max:20'],
            'seating_preference' => ['nullable', 'in:regular,open_kitchen_counter,quiet_zone,any'],
        ]);

        $startTime = $this->normalizeTime($data['start_time']);
        $branch = Branch::findOrFail($data['branch_id']);
        $seating = $data['seating_preference'] ?? 'any';

        $this->assertWithinOpeningHours($branch, $startTime);

        $tables = $this->reservations->findAvailableTables(
            $branch,
            $data['reservation_date'],
            $startTime,
            $data['party_size'],
            $seating
        );

        if ($tables) {
            return response()->json([
                'available' => true,
                'tables' => $this->tablesToArray($tables),
            ]);
        }

        $suggestions = $this->reservations->suggestNearbySlots(
            $branch,
            $data['reservation_date'],
            $startTime,
            $data['party_size'],
            $seating
        );

        return response()->json([
            'available' => false,
            'suggestions' => collect($suggestions)->map(fn($s) => [
                'time' => $s['time'],
                'tables' => $this->tablesToArray($s['tables']),
            ])->values(),
        ]);
    }

    /**
     * POST /reservations — chỉ được gọi SAU khi khách đã xác nhận rõ ràng (Quy tắc B.4).
     */
    public function store(StoreReservationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $branch = Branch::findOrFail($data['branch_id']);

        $this->assertWithinOpeningHours($branch, $data['start_time']);

        $tables = $this->reservations->findAvailableTables(
            $branch,
            $data['reservation_date'],
            $data['start_time'],
            $data['party_size'],
            $data['seating_preference'] ?? 'any'
        );

        if (! $tables) {
            return response()->json([
                'message' => 'Rất tiếc, khung giờ này vừa hết bàn phù hợp.',
            ], 422);
        }

        $reservation = $this->reservations->createReservation($data + ['source' => 'website'], $tables);
        $reservation->load('tables');

        return response()->json([
            'message' => 'Đặt bàn thành công.',
            'booking_code' => $reservation->booking_code,
            'reservation' => $reservation,
        ], 201);
    }

    private function normalizeTime(string $time): string
    {
        return strlen($time) === 5 ? $time . ':00' : $time;
    }

    private function tablesToArray($tables)
    {
        return $tables->map(fn($t) => Arr::only($t->toArray(), ['id', 'table_code', 'capacity', 'seating_type']))->values();
    }

    /**
     * Quy tắc A.7: giờ đặt (và giờ kết thúc dự kiến) phải nằm trong giờ hoạt động của chi nhánh.
     */
    private function assertWithinOpeningHours(Branch $branch, string $startTime): void
    {
        $endTime = date('H:i:s', strtotime($startTime) + ReservationService::DURATION_MINUTES * 60);

        if ($startTime < $branch->opening_time || $endTime > $branch->closing_time) {
            throw ValidationException::withMessages([
                'start_time' => "Chi nhánh chỉ phục vụ từ {$branch->opening_time} đến {$branch->closing_time}.",
            ]);
        }
    }
}
