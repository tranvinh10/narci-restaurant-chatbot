<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReservationService
{
    public const DURATION_MINUTES = 90;

    /**
     * Tìm (các) bàn trống phù hợp cho 1 khung giờ.
     * Ưu tiên 1 bàn vừa đủ sức chứa; nếu không có mới ghép 2 bàn (Quy tắc A.2).
     *
     * @return Collection<int, RestaurantTable>|null  null nếu không ghép được
     */
    public function findAvailableTables(
        Branch $branch,
        string $date,
        string $startTime,
        int $partySize,
        string $seatingPreference = 'any'
    ): ?Collection {
        $endTime = $this->addMinutes($startTime, self::DURATION_MINUTES);

        // 1. Bàn nào đang bị chiếm trong khung giờ này? (Quy tắc A.4, A.6: đơn cancelled không tính)
        $busyTableIds = DB::table('reservation_restaurant_table as rrt')
            ->join('reservations as r', 'r.id', '=', 'rrt.reservation_id')
            ->where('r.branch_id', $branch->id)
            ->where('r.reservation_date', $date)
            ->where('r.status', '!=', 'cancelled')
            ->where('r.start_time', '<', $endTime)
            ->where('r.end_time', '>', $startTime)
            ->pluck('rrt.restaurant_table_id');

        // 2. Trong số bàn còn trống, lọc theo chi nhánh + loại chỗ ngồi (nếu khách yêu cầu cụ thể)
        $query = RestaurantTable::where('branch_id', $branch->id)
            ->where('is_active', true)
            ->whereNotIn('id', $busyTableIds);

        if ($seatingPreference !== 'any') {
            $query->where('seating_type', $seatingPreference);
        }

        $freeTables = $query->orderBy('capacity')->get();

        // 3. Ưu tiên 1 bàn đủ chỗ, nhỏ nhất có thể (đỡ phí bàn lớn cho nhóm nhỏ)
        $single = $freeTables->first(fn (RestaurantTable $t) => $t->capacity >= $partySize);
        if ($single) {
            return collect([$single]);
        }

        // 4. Không có bàn đơn nào đủ → thử ghép 2 bàn (VD: bàn 2 + bàn 4 cho nhóm 6 người).
        //    Đây là thuật toán ghép đơn giản (heuristic), chưa phải tối ưu toàn cục,
        //    nhưng đủ dùng cho quy mô bàn của 1 chi nhánh thực tế.
        foreach ($freeTables as $i => $first) {
            foreach ($freeTables->slice($i + 1) as $second) {
                if ($first->capacity + $second->capacity >= $partySize) {
                    return collect([$first, $second]);
                }
            }
        }

        return null; // hết bàn thật sự
    }

    /**
     * Khi hết bàn đúng giờ khách chọn, gợi ý các khung giờ lân cận còn trống
     * (+/- 30-60 phút), không vượt quá giờ hoạt động của chi nhánh (Quy tắc A.7).
     *
     * @return array<int, array{time: string, tables: Collection}>
     */
    public function suggestNearbySlots(
        Branch $branch,
        string $date,
        string $startTime,
        int $partySize,
        string $seatingPreference = 'any',
        int $maxSuggestions = 3
    ): array {
        $suggestions = [];

        foreach ([-30, 30, -60, 60] as $offset) {
            $candidateStart = $this->addMinutes($startTime, $offset);
            $candidateEnd = $this->addMinutes($candidateStart, self::DURATION_MINUTES);

            if ($candidateStart < $branch->opening_time || $candidateEnd > $branch->closing_time) {
                continue;
            }

            $tables = $this->findAvailableTables($branch, $date, $candidateStart, $partySize, $seatingPreference);

            if ($tables) {
                $suggestions[] = ['time' => $candidateStart, 'tables' => $tables];
            }

            if (count($suggestions) >= $maxSuggestions) {
                break;
            }
        }

        return $suggestions;
    }

    /**
     * Tạo đơn đặt bàn thật sự. Dùng Database Transaction + khoá dòng (lockForUpdate)
     * để 2 request đặt cùng lúc không thể trúng cùng 1 bàn (Quy tắc A.5).
     */
    public function createReservation(array $data, Collection $tables): Reservation
    {
        return DB::transaction(function () use ($data, $tables) {
            $tableIds = $tables->pluck('id');

            // Khoá các bàn liên quan trong lúc transaction đang chạy — request khác
            // cũng đi qua đường này sẽ phải đợi tới khi transaction này commit xong.
            RestaurantTable::whereIn('id', $tableIds)->lockForUpdate()->get();

            // Kiểm tra lại lần cuối NGAY TRONG transaction: giữa lúc FE gọi
            // check-availability và lúc bấm xác nhận, có thể 1 request khác đã chen vào.
            $stillFree = $this->findAvailableTables(
                Branch::findOrFail($data['branch_id']),
                $data['reservation_date'],
                $data['start_time'],
                $data['party_size'],
                $data['seating_preference'] ?? 'any'
            );

            $stillFreeIds = $stillFree?->pluck('id') ?? collect();
            if ($tableIds->diff($stillFreeIds)->isNotEmpty()) {
                throw new \RuntimeException('Bàn vừa được người khác đặt mất, vui lòng thử lại khung giờ khác.');
            }

            $reservation = Reservation::create([
                'booking_code' => $this->generateBookingCode(),
                'branch_id' => $data['branch_id'],
                'customer_name' => $data['customer_name'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'reservation_date' => $data['reservation_date'],
                'start_time' => $data['start_time'],
                'end_time' => $this->addMinutes($data['start_time'], self::DURATION_MINUTES),
                'party_size' => $data['party_size'],
                'seating_preference' => $data['seating_preference'] ?? 'any',
                'occasion' => $data['occasion'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'confirmed',
                'source' => $data['source'] ?? 'website',
            ]);

            $reservation->tables()->attach($tableIds);

            return $reservation;
        });
    }

    private function generateBookingCode(): string
    {
        do {
            $code = 'RES-' . strtoupper(Str::random(4));
        } while (Reservation::where('booking_code', $code)->exists());

        return $code;
    }

    private function addMinutes(string $time, int $minutes): string
    {
        return date('H:i:s', strtotime($time) + $minutes * 60);
    }
}
