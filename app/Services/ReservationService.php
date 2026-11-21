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

        $busyTableIds = DB::table('reservation_restaurant_table as rrt')
            ->join('reservations as r', 'r.id', '=', 'rrt.reservation_id')
            ->where('r.branch_id', $branch->id)
            ->where('r.reservation_date', $date)
            ->where('r.status', '!=', 'cancelled')
            ->where('r.start_time', '<', $endTime)
            ->where('r.end_time', '>', $startTime)
            ->pluck('rrt.restaurant_table_id');

        $query = RestaurantTable::where('branch_id', $branch->id)
            ->where('is_active', true)
            ->whereNotIn('id', $busyTableIds);

        if ($seatingPreference !== 'any') {
            $query->where('seating_type', $seatingPreference);
        }

        $freeTables = $query->orderBy('capacity')->get();

        $single = $freeTables->first(fn(RestaurantTable $t) => $t->capacity >= $partySize);
        if ($single) {
            return collect([$single]);
        }

        foreach ($freeTables as $i => $first) {
            foreach ($freeTables->slice($i + 1) as $second) {
                if ($first->capacity + $second->capacity >= $partySize) {
                    return collect([$first, $second]);
                }
            }
        }

        return null;
    }

    /**
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
     * Tạo đơn đặt bàn thật sự, bọc trong Database Transaction + lockForUpdate (Quy tắc A.5).
     */
    public function createReservation(array $data, Collection $tables): Reservation
    {
        return DB::transaction(function () use ($data, $tables) {
            $tableIds = $tables->pluck('id');

            RestaurantTable::whereIn('id', $tableIds)->lockForUpdate()->get();

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

    /**
     * Huỷ đơn — đơn cancelled không còn tính là đang chiếm bàn (Quy tắc A.6).
     */
    public function cancelReservation(Reservation $reservation): Reservation
    {
        $reservation->update(['status' => 'cancelled']);

        return $reservation;
    }

    /**
     * Đổi giờ: tạm "thả" bàn cũ (đánh dấu cancelled) để tìm bàn ở giờ mới không bị
     * chính đơn này cản trở, nếu không tìm được thì khôi phục lại trạng thái ban đầu.
     */
    public function rescheduleReservation(Reservation $reservation, string $newTime): Reservation
    {
        return DB::transaction(function () use ($reservation, $newTime) {
            $originalStatus = $reservation->status;
            $reservation->update(['status' => 'cancelled']);

            $tables = $this->findAvailableTables(
                $reservation->branch,
                $reservation->reservation_date->toDateString(),
                $newTime,
                $reservation->party_size,
                $reservation->seating_preference
            );

            if (! $tables) {
                $reservation->update(['status' => $originalStatus]);
                throw new \RuntimeException('Không còn bàn trống ở khung giờ mới, giữ nguyên giờ cũ.');
            }

            $reservation->tables()->sync($tables->pluck('id'));
            $reservation->update([
                'start_time' => $newTime,
                'end_time' => $this->addMinutes($newTime, self::DURATION_MINUTES),
                'status' => 'confirmed',
            ]);

            return $reservation->fresh('tables');
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
