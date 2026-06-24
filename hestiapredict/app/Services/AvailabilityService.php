<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Room;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class AvailabilityService
{
    private const CACHE_VERSION_KEY = 'dashboard:availability-cache-version';

    public function invalidateCaches(): void
    {
        $current = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        Cache::put(self::CACHE_VERSION_KEY, $current + 1);
    }

    private function cacheVersion(): int
    {
        return (int) Cache::get(self::CACHE_VERSION_KEY, 1);
    }

    public function getCacheVersion(): int
    {
        return $this->cacheVersion();
    }

    public function occupiedRoomIdsForDate(string $date, array $statuses = Reservation::ACTIVE_STATUSES): Collection
    {
        return Room::query()
            ->whereHas('reservations', function ($query) use ($date, $statuses) {
                $query->whereIn('reservations.status', $statuses)
                    ->where('reservations.check_in_date', '<=', $date)
                    ->where('reservations.check_out_date', '>', $date);
            })
            ->pluck('id');
    }

    public function busyRoomIdsForPeriod(
        string $checkIn,
        string $checkOut,
        array $statuses = Reservation::ACTIVE_STATUSES,
        ?int $excludeReservationId = null,
    ): Collection
    {
        return Room::query()
            ->whereHas('reservations', function ($query) use ($checkIn, $checkOut, $statuses, $excludeReservationId) {
                $query->whereIn('reservations.status', $statuses)
                    ->where('reservations.check_in_date', '<', $checkOut)
                    ->where('reservations.check_out_date', '>', $checkIn)
                    ->when($excludeReservationId, fn ($query) => $query->where('reservations.id', '!=', $excludeReservationId));
            })
            ->pluck('id');
    }

    public function liveSummary(string $date): array
    {
        $occupiedRoomIds = $this->occupiedRoomIdsForDate($date)->all();
        $cacheVersion = $this->cacheVersion();

        return Cache::remember(
            "dashboard:live-availability:$cacheVersion:$date",
            now()->addSeconds(45),
            function () use ($occupiedRoomIds) {
                return Room::query()
                    ->orderBy('type')
                    ->orderBy('model')
                    ->get()
                    ->groupBy(fn (Room $room) => $room->type . ' (' . $room->model . ')')
                    ->sortBy(fn (Collection $rooms) => $this->roomCategorySortKey($rooms->first()))
                    ->map(function (Collection $rooms) use ($occupiedRoomIds) {
                        $first = $rooms->first();

                        return [
                            'identifier' => $first->identifier,
                            'type' => $first->type,
                            'model' => $first->model,
                            'base_price' => $first->base_price_ariary,
                            'fixed_price' => $first->base_price_ariary,
                            'is_fixed_price' => $rooms->every(fn (Room $room) => $room->is_fixed_price),
                            'total' => $rooms->count(),
                            'available' => $rooms->whereNotIn('id', $occupiedRoomIds)->count(),
                        ];
                    })
                    ->values()
                    ->all();
            }
        );
    }

    public function availableRooms(string $checkIn, string $checkOut, ?int $excludeReservationId = null): Collection
    {
        $cacheVersion = $this->cacheVersion();
        $cacheKey = sprintf(
            'dashboard:available-rooms:%s:%s:%s:%d',
            $checkIn,
            $checkOut,
            $excludeReservationId ?? 'none',
            $cacheVersion
        );

        return Cache::remember($cacheKey, now()->addSeconds(45), function () use ($checkIn, $checkOut, $excludeReservationId) {
            $busyRoomIds = $this->busyRoomIdsForPeriod($checkIn, $checkOut, Reservation::ACTIVE_STATUSES, $excludeReservationId);

            return Room::query()
                ->whereNotIn('id', $busyRoomIds)
                ->orderBy('room_number')
                ->get();
        });
    }

    public function occupiedRoomCount(string $date, array $statuses = Reservation::ACTIVE_STATUSES): int
    {
        return $this->occupiedRoomIdsForDate($date, $statuses)->count();
    }

    public function categoryOccupiedCount(string $date, string $type, string $model): int
    {
        return Room::query()
            ->where('type', $type)
            ->where('model', $model)
            ->whereHas('reservations', function ($query) use ($date) {
                $query->whereIn('reservations.status', Reservation::ACTIVE_STATUSES)
                    ->where('reservations.check_in_date', '<=', $date)
                    ->where('reservations.check_out_date', '>', $date);
            })
            ->count();
    }

    private function roomCategorySortKey(?Room $room): array
    {
        if (!$room) {
            return [99, '', ''];
        }

        $label = mb_strtolower(trim($room->type . ' ' . $room->model));
        $order = 99;

        if (str_contains($label, 'double')) {
            $order = 0;
        } elseif (str_contains($label, 'twin')) {
            $order = 1;
        } elseif (str_contains($label, 'triple')) {
            $order = 2;
        } elseif (str_contains($label, 'famil')) {
            $order = 3;
        } elseif (str_contains($label, 'suite')) {
            $order = 4;
        }

        return [$order, $room->type, $room->model];
    }

    public function occupancyIndexForPeriod(
        string $startDate,
        int $days,
        array $statuses = Reservation::ACTIVE_STATUSES,
    ): array {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = $start->copy()->addDays(max(1, $days));
        $index = [];
        $global = [];

        Reservation::query()
            ->with('rooms')
            ->whereIn('status', $statuses)
            ->where('check_in_date', '<', $end->toDateString())
            ->where('check_out_date', '>', $start->toDateString())
            ->get()
            ->each(function (Reservation $reservation) use ($start, $end, &$index, &$global) {
                $periodStart = Carbon::parse($reservation->check_in_date)->max($start);
                $periodEnd = Carbon::parse($reservation->check_out_date)->min($end);

                if ($periodStart->gte($periodEnd)) {
                    return;
                }

                foreach (CarbonPeriod::create($periodStart, $periodEnd->copy()->subDay()) as $date) {
                    $dateKey = $date->toDateString();

                    foreach ($reservation->rooms as $room) {
                        $identifier = $room->identifier;
                        $index[$dateKey][$identifier] = ($index[$dateKey][$identifier] ?? 0) + 1;
                        $global[$dateKey] = ($global[$dateKey] ?? 0) + 1;
                    }
                }
            });

        return [
            'by_category' => $index,
            'global' => $global,
        ];
    }
}
