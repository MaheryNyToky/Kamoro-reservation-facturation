<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Room;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class YieldService
{
    public function __construct(private readonly AvailabilityService $availabilityService)
    {
    }

    public function predictions(int $days, string $startDate): array
    {
        $historyData = $this->historyData();
        $roomsInfo = $this->roomsInfo();
        $basePrices = $roomsInfo->mapWithKeys(fn ($info) => [$info['identifier'] => $info['base_price']])->all();
        $roomCapacities = $roomsInfo->mapWithKeys(fn ($info) => [$info['identifier'] => $info['capacity']])->all();
        $fixedPriceFlags = $roomsInfo->mapWithKeys(fn ($info) => [$info['identifier'] => $info['is_fixed_price']])->all();
        $roomMetadata = $roomsInfo->mapWithKeys(fn ($info) => [$info['identifier'] => $info])->all();
        $occupancyIndex = $this->availabilityService->occupancyIndexForPeriod($startDate, $days);

        try {
            $response = (new Client([
                'connect_timeout' => 1.0,
                'timeout' => 3.0,
            ]))->post(config('services.ai_engine.url') . '/predict', [
                'json' => [
                    'base_prices' => $basePrices,
                    'days_to_predict' => $days,
                    'start_date' => $startDate,
                    'history' => $historyData,
                    'room_capacities' => $roomCapacities,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return [
                ...$this->alignAiPrices($data, $basePrices, $fixedPriceFlags, $roomMetadata, $days, $startDate, $occupancyIndex),
                'mode' => 'ai',
                'ai_available' => true,
                'is_fallback' => false,
            ];
        } catch (\Throwable) {
            return $this->fallbackPredictions($days, $startDate, $occupancyIndex);
        }
    }

    public function auditDate(string $date): array
    {
        $roomsConfirmed = $this->availabilityService->occupiedRoomCount($date, ['arrive']);
        $roomsEstimated = $this->availabilityService->occupiedRoomCount($date);

        $dailyCaOfficial = $this->revenueForDate($date, ['arrive']);
        $dailyCaPending = $this->revenueForDate($date, ['en_attente']);
        $totalCA = (int) DB::table('booking_room')
            ->join('reservations', 'reservations.id', '=', 'booking_room.reservation_id')
            ->where('reservations.status', 'arrive')
            ->whereRaw('COALESCE(booking_room.segment_start_date, reservations.check_in_date) <= ?', [$date])
            ->whereRaw('COALESCE(booking_room.segment_end_date, reservations.check_out_date) > ?', [$date])
            ->sum('booking_room.price_snapshot_ariary');

        return [
            'status' => 'success',
            'rooms_confirmed' => (int) $roomsConfirmed,
            'rooms_estimated' => (int) $roomsEstimated,
            'daily_ca_official' => (int) $dailyCaOfficial,
            'daily_ca_pending' => (int) $dailyCaPending,
            'total_ca' => (int) $totalCA,
            'period' => 'Depuis le début de l\'année jusqu\'au ' . date('d/m/Y', strtotime($date)),
        ];
    }

    public function aiRevenueSummary(int $days, string $startDate): array
    {
        $predictions = $this->predictions($days, $startDate);
        $priceIndex = $this->aiPriceIndex($predictions['results'] ?? []);
        $rows = [];

        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("$startDate +$i days"));
            $reservations = Reservation::query()
                ->where('check_in_date', '<=', $date)
                ->where('check_out_date', '>', $date)
                ->whereIn('status', Reservation::ACTIVE_STATUSES)
                ->with('rooms')
                ->get();

            $fixedRevenue = 0;
            $aiRevenue = 0;
            $roomCount = 0;

            foreach ($reservations as $reservation) {
                foreach ($reservation->rooms as $room) {
                    $roomCount++;
                    $fixedRevenue += (int) $room->base_price_ariary;
                    $aiRevenue += (int) ($priceIndex[$date][$room->identifier] ?? $room->base_price_ariary);
                }
            }

            $rows[] = [
                'date' => $date,
                'room_count' => $roomCount,
                'fixed_revenue_ariary' => $fixedRevenue,
                'ai_revenue_ariary' => $aiRevenue,
                'delta_ariary' => $aiRevenue - $fixedRevenue,
            ];
        }

        return [
            'status' => 'success',
            'mode' => $predictions['mode'] ?? 'unknown',
            'ai_available' => (bool) ($predictions['ai_available'] ?? false),
            'is_fallback' => (bool) ($predictions['is_fallback'] ?? false),
            'rows' => $rows,
            'totals' => [
                'fixed_revenue_ariary' => array_sum(array_column($rows, 'fixed_revenue_ariary')),
                'ai_revenue_ariary' => array_sum(array_column($rows, 'ai_revenue_ariary')),
                'delta_ariary' => array_sum(array_column($rows, 'delta_ariary')),
            ],
        ];
    }

    private function historyData(): array
    {
        return DB::table('booking_room')
            ->join('reservations', 'reservations.id', '=', 'booking_room.reservation_id')
            ->join('rooms', 'rooms.id', '=', 'booking_room.room_id')
            ->whereIn('reservations.status', Reservation::ACTIVE_STATUSES)
            ->select([
                'booking_room.id as booking_room_id',
                'booking_room.room_id',
                'rooms.type as room_type',
                'rooms.model as room_model',
                DB::raw('COALESCE(booking_room.segment_start_date, reservations.check_in_date) as segment_start_date'),
                DB::raw('COALESCE(booking_room.segment_end_date, reservations.check_out_date) as segment_end_date'),
            ])
            ->get()
            ->flatMap(function ($bookingRoom) {
                $rows = [];
                $start = Carbon::parse($bookingRoom->segment_start_date)->startOfDay();
                $end = Carbon::parse($bookingRoom->segment_end_date)->startOfDay();
                $roomType = trim(($bookingRoom->room_type ?? '') . ' - ' . ($bookingRoom->room_model ?? ''));

                foreach (CarbonPeriod::create($start, $end->copy()->subDay()) as $date) {
                    $rows[] = [
                        'date' => $date->toDateString(),
                        'room_type' => $roomType,
                        'room_id' => (int) $bookingRoom->room_id,
                    ];
                }

                return $rows;
            })
            ->groupBy(fn (array $item) => $item['date'] . '|' . $item['room_type'])
            ->map(function (Collection $items) {
                $first = $items->first();

                return [
                    'date' => $first['date'],
                    'room_type' => $first['room_type'],
                    'rooms_booked' => $items->pluck('room_id')->unique()->count(),
                ];
            })
            ->values()
            ->all();
    }

    private function roomsInfo(): Collection
    {
        return Room::query()
            ->get()
            ->groupBy(fn (Room $room) => $room->identifier)
            ->map(fn (Collection $rooms, string $identifier) => [
                'identifier' => $identifier,
                'base_price' => (int) $rooms->first()->base_price_ariary,
                'capacity' => $rooms->count(),
                'type' => $rooms->first()->type,
                'model' => $rooms->first()->model,
                'is_fixed_price' => $rooms->every(fn (Room $room) => $room->is_fixed_price),
            ])
            ->values();
    }

    private function alignAiPrices(
        array $data,
        array $basePrices,
        array $fixedPriceFlags,
        array $roomMetadata,
        int $days,
        string $startDate,
        ?array $occupancyIndex = null,
    ): array
    {
        $occupancyIndex ??= $this->availabilityService->occupancyIndexForPeriod($startDate, $days);

        if (!isset($data['results'])) {
            return $data;
        }

        $maxMultiplier = 1.0;
        foreach ($data['results'] as $category => $predictions) {
            if ($fixedPriceFlags[$category] ?? false) {
                continue;
            }

            foreach ($predictions as $prediction) {
                if ($prediction['date'] === $startDate && !empty($prediction['base_price'])) {
                    $maxMultiplier = max($maxMultiplier, $prediction['suggested_price_ariary'] / $prediction['base_price']);
                }
            }
        }

        foreach ($data['results'] as $category => &$predictions) {
            $basePrice = $basePrices[$category] ?? 0;
            $isFixedPrice = $fixedPriceFlags[$category] ?? false;
            foreach ($predictions as &$prediction) {
                $adjustedPrice = $basePrice;

                if (!$isFixedPrice) {
                    $realGlobalOccupancy = $occupancyIndex['global'][$prediction['date']] ?? 0;
                    $realMultiplier = $this->realTimeMultiplier($realGlobalOccupancy);
                    $finalMultiplier = min(1.15, max($realMultiplier, $maxMultiplier));
                    $adjustedPrice = (int) round($basePrice * $finalMultiplier, -3);

                    if ($adjustedPrice < $basePrice) {
                        $adjustedPrice = $basePrice;
                    }
                }

                $prediction['fixed_price_ariary'] = $basePrice;
                $prediction['adjusted_price_ariary'] = $adjustedPrice;
                $prediction['suggested_price_ariary'] = $adjustedPrice;
                $prediction['base_price'] = $basePrice;
                $prediction['is_fixed_price'] = $isFixedPrice;
            }
        }

        foreach ($roomMetadata as $category => $room) {
            if (isset($data['results'][$category])) {
                continue;
            }

            $data['results'][$category] = [];
            for ($i = 0; $i < $days; $i++) {
                $date = date('Y-m-d', strtotime("$startDate +$i days"));
                $categoryBookedCount = $occupancyIndex['by_category'][$date][$category] ?? 0;

                $data['results'][$category][] = [
                    'date' => $date,
                    'predicted_occupancy' => $categoryBookedCount,
                    'fixed_price_ariary' => $room['base_price'],
                    'adjusted_price_ariary' => $room['base_price'],
                    'suggested_price_ariary' => $room['base_price'],
                    'base_price' => $room['base_price'],
                    'is_fixed_price' => $room['is_fixed_price'],
                ];
            }
        }

        return $data;
    }

    private function fallbackPredictions(int $days, string $startDate, ?array $occupancyIndex = null): array
    {
        $occupancyIndex ??= $this->availabilityService->occupancyIndexForPeriod($startDate, $days);
        $results = [];

        foreach ($this->roomsInfo() as $room) {
            $key = $room['identifier'];
            $results[$key] = [];

            for ($i = 0; $i < $days; $i++) {
                $date = date('Y-m-d', strtotime("$startDate +$i days"));
                $categoryBookedCount = $occupancyIndex['by_category'][$date][$key] ?? 0;

                $results[$key][] = [
                    'date' => $date,
                    'predicted_occupancy' => $categoryBookedCount,
                    'fixed_price_ariary' => $room['base_price'],
                    'adjusted_price_ariary' => $room['base_price'],
                    'suggested_price_ariary' => $room['base_price'],
                    'base_price' => $room['base_price'],
                    'is_fixed_price' => $room['is_fixed_price'],
                ];
            }
        }

        return [
            'status' => 'success',
            'mode' => 'fallback',
            'ai_available' => false,
            'is_fallback' => true,
            'message' => 'Mode sécurité : IA indisponible, prix de base appliqués',
            'results' => $results,
        ];
    }

    private function aiPriceIndex(array $results): array
    {
        $index = [];

        foreach ($results as $category => $predictions) {
            foreach ($predictions as $prediction) {
                $date = $prediction['date'] ?? null;
                if (!$date) {
                    continue;
                }

                $index[$date][$category] = (int) (
                    $prediction['adjusted_price_ariary']
                    ?? $prediction['suggested_price_ariary']
                    ?? $prediction['fixed_price_ariary']
                    ?? $prediction['base_price']
                    ?? 0
                );
            }
        }

        return $index;
    }

    private function realTimeMultiplier(int $globalOccupancy): float
    {
        return match (true) {
            $globalOccupancy >= 35 => 1.15,
            $globalOccupancy >= 31 => 1.135,
            $globalOccupancy >= 27 => 1.12,
            $globalOccupancy >= 23 => 1.105,
            $globalOccupancy >= 19 => 1.09,
            $globalOccupancy >= 15 => 1.075,
            $globalOccupancy >= 11 => 1.06,
            $globalOccupancy >= 7  => 1.045,
            $globalOccupancy >= 4  => 1.03,
            $globalOccupancy >= 2  => 1.015,
            default => 1.0,
        };
    }

    private function revenueForDate(string $date, array $statuses): int
    {
        return (int) DB::table('booking_room')
            ->join('reservations', 'reservations.id', '=', 'booking_room.reservation_id')
            ->whereIn('reservations.status', $statuses)
            ->whereRaw('COALESCE(booking_room.segment_start_date, reservations.check_in_date) <= ?', [$date])
            ->whereRaw('COALESCE(booking_room.segment_end_date, reservations.check_out_date) > ?', [$date])
            ->sum('booking_room.price_snapshot_ariary');
    }
}
