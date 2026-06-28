<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Room;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
            ->whereExists(function ($query) use ($date, $statuses) {
                $query->selectRaw('1')
                    ->from('booking_room')
                    ->join('reservations', 'reservations.id', '=', 'booking_room.reservation_id')
                    ->whereColumn('booking_room.room_id', 'rooms.id')
                    ->whereIn('reservations.status', $statuses)
                    ->whereRaw('COALESCE(booking_room.segment_start_date, reservations.check_in_date) <= ?', [$date])
                    ->whereRaw('COALESCE(booking_room.segment_end_date, reservations.check_out_date) > ?', [$date]);
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
            ->whereExists(function ($query) use ($checkIn, $checkOut, $statuses, $excludeReservationId) {
                $query->selectRaw('1')
                    ->from('booking_room')
                    ->join('reservations', 'reservations.id', '=', 'booking_room.reservation_id')
                    ->whereColumn('booking_room.room_id', 'rooms.id')
                    ->whereIn('reservations.status', $statuses)
                    ->whereRaw('COALESCE(booking_room.segment_start_date, reservations.check_in_date) < ?', [$checkOut])
                    ->whereRaw('COALESCE(booking_room.segment_end_date, reservations.check_out_date) > ?', [$checkIn])
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
                        $availableRooms = $rooms
                            ->whereNotIn('id', $occupiedRoomIds)
                            ->sortBy('room_number')
                            ->values();

                        return [
                            'identifier' => $first->identifier,
                            'type' => $first->type,
                            'model' => $first->model,
                            'base_price' => $first->base_price_ariary,
                            'fixed_price' => $first->base_price_ariary,
                            'is_fixed_price' => $rooms->every(fn (Room $room) => $room->is_fixed_price),
                            'total' => $rooms->count(),
                            'available' => $availableRooms->count(),
                            'available_room_numbers' => $availableRooms
                                ->pluck('room_number')
                                ->values()
                                ->all(),
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

    /**
     * Retourne toutes les chambres avec leurs créneaux libres sur une période donnée.
     *
     * @return array<int, array<string, mixed>>
     */
    public function roomAvailabilitySuggestions(string $checkIn, string $checkOut, ?int $excludeReservationId = null): array
    {
        $cacheVersion = $this->cacheVersion();
        $cacheKey = sprintf(
            'dashboard:room-availability-suggestions:%s:%s:%s:%d',
            $checkIn,
            $checkOut,
            $excludeReservationId ?? 'none',
            $cacheVersion
        );

        return Cache::remember($cacheKey, now()->addSeconds(45), function () use ($checkIn, $checkOut, $excludeReservationId) {
            $rooms = Room::query()
                ->orderBy('room_number')
                ->get();

            $periodStart = Carbon::parse($checkIn)->startOfDay();
            $periodEnd = Carbon::parse($checkOut)->startOfDay();
            $previousNightStart = $periodStart->copy()->subDay()->toDateString();
            $occupiedPreviousNightIds = $this
                ->busyRoomIdsForPeriod(
                    $previousNightStart,
                    $periodStart->toDateString(),
                    Reservation::ACTIVE_STATUSES,
                    $excludeReservationId,
                )
                ->map(fn ($id) => (int) $id)
                ->all();

            $occupancies = DB::table('booking_room')
                ->join('reservations', 'reservations.id', '=', 'booking_room.reservation_id')
                ->select([
                    'booking_room.room_id',
                    DB::raw('COALESCE(booking_room.segment_start_date, reservations.check_in_date) as segment_start_date'),
                    DB::raw('COALESCE(booking_room.segment_end_date, reservations.check_out_date) as segment_end_date'),
                ])
                ->whereIn('reservations.status', Reservation::ACTIVE_STATUSES)
                ->whereRaw('COALESCE(booking_room.segment_start_date, reservations.check_in_date) < ?', [$checkOut])
                ->whereRaw('COALESCE(booking_room.segment_end_date, reservations.check_out_date) > ?', [$checkIn])
                ->when($excludeReservationId, fn ($query) => $query->where('reservations.id', '!=', $excludeReservationId))
                ->orderBy('segment_start_date')
                ->get()
                ->groupBy('room_id');

            return $rooms->map(function (Room $room) use ($occupancies, $periodStart, $periodEnd, $occupiedPreviousNightIds) {
                $roomOccupancies = collect($occupancies->get($room->id, collect()))
                    ->map(function ($row) use ($periodStart, $periodEnd) {
                        $start = Carbon::parse($row->segment_start_date)->startOfDay();
                        $end = Carbon::parse($row->segment_end_date)->startOfDay();

                        if ($start->lt($periodStart)) {
                            $start = $periodStart->copy();
                        }
                        if ($end->gt($periodEnd)) {
                            $end = $periodEnd->copy();
                        }

                        return [
                            'start' => $start,
                            'end' => $end,
                        ];
                    })
                    ->filter(fn (array $interval) => $interval['start']->lt($interval['end']))
                    ->sortBy('start')
                    ->values();

                $freeSegments = [];
                $cursor = $periodStart->copy();

                foreach ($roomOccupancies as $interval) {
                    if ($cursor->lt($interval['start'])) {
                        $freeSegments[] = [
                            'segment_start_date' => $cursor->toDateString(),
                            'segment_end_date' => $interval['start']->toDateString(),
                        ];
                    }

                    if ($cursor->lt($interval['end'])) {
                        $cursor = $interval['end']->copy();
                    }
                }

                if ($cursor->lt($periodEnd)) {
                    $freeSegments[] = [
                        'segment_start_date' => $cursor->toDateString(),
                        'segment_end_date' => $periodEnd->toDateString(),
                    ];
                }

                $freeSegments = array_values(array_filter($freeSegments, function (array $segment) {
                    return Carbon::parse($segment['segment_start_date'])->lt(Carbon::parse($segment['segment_end_date']));
                }));

                return [
                    'id' => $room->id,
                    'room_number' => $room->room_number,
                    'type' => $room->type,
                    'model' => $room->model,
                    'base_price_ariary' => $room->base_price_ariary,
                    'fixed_price_ariary' => $room->base_price_ariary,
                    'is_fixed_price' => $room->is_fixed_price,
                    'availability_segments' => $freeSegments,
                    'occupied_previous_night' => in_array((int) $room->id, $occupiedPreviousNightIds, true),
                    'is_fully_available' => count($freeSegments) === 1
                        && ($freeSegments[0]['segment_start_date'] ?? null) === $periodStart->toDateString()
                        && ($freeSegments[0]['segment_end_date'] ?? null) === $periodEnd->toDateString(),
                    'has_partial_availability' => !empty($freeSegments)
                        && !(
                            count($freeSegments) === 1
                            && ($freeSegments[0]['segment_start_date'] ?? null) === $periodStart->toDateString()
                            && ($freeSegments[0]['segment_end_date'] ?? null) === $periodEnd->toDateString()
                        ),
                ];
            })->values()->all();
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
            ->whereExists(function ($query) use ($date) {
                $query->selectRaw('1')
                    ->from('booking_room')
                    ->join('reservations', 'reservations.id', '=', 'booking_room.reservation_id')
                    ->whereColumn('booking_room.room_id', 'rooms.id')
                    ->whereIn('reservations.status', Reservation::ACTIVE_STATUSES)
                    ->whereRaw('COALESCE(booking_room.segment_start_date, reservations.check_in_date) <= ?', [$date])
                    ->whereRaw('COALESCE(booking_room.segment_end_date, reservations.check_out_date) > ?', [$date]);
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
