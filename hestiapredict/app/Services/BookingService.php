<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ReservationAudit;
use App\Models\Payment;
use App\Models\Organization;
use App\Models\Room;
use App\Models\User;
use App\Support\PhoneNumber;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingService
{
    private const BOOKING_ROOM_PRICE_ARIARY = 162500;
    private const MAX_EXTRA_BEDS_PER_NIGHT = 6;
    private const MAX_EXTRA_MATTRESSES_PER_NIGHT = 6;

    public function __construct(
        private readonly AvailabilityService $availabilityService,
    ) {
    }

    public function createBooking(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $userId = null;
            if (!empty($data['receptionist_name'])) {
                $userId = User::query()
                    ->where('name', $data['receptionist_name'])
                    ->value('id');
            }

            $source = $data['source'] ?? 'Appel';
            // La réservation ne force plus le mode de facturation:
            // on garde grouped par défaut et le choix se fait au moment de la facture.
            $billingMode = in_array($data['billing_mode'] ?? 'grouped', ['grouped', 'per_room'], true)
                ? ($data['billing_mode'] ?? 'grouped')
                : 'grouped';
            $organization = null;
            $organizationName = trim((string) ($data['organization_name'] ?? ''));
            if ($organizationName !== '') {
                $organization = Organization::query()->updateOrCreate(
                    ['name' => $organizationName],
                    [
                        'phone' => PhoneNumber::normalize($data['organization_phone'] ?? null),
                        'contact_name' => $data['organization_contact_name'] ?? null,
                        'contact_phone' => PhoneNumber::normalize($data['organization_contact_phone'] ?? null),
                        'contact_email' => $data['organization_contact_email'] ?? null,
                        'email' => $data['organization_email'] ?? null,
                        'billing_address' => $data['organization_billing_address'] ?? null,
                        'nif' => $data['organization_nif'] ?? ($data['organization_tax_id'] ?? null),
                        'stat' => $data['organization_stat'] ?? null,
                        'tax_id' => $data['organization_nif'] ?? ($data['organization_tax_id'] ?? null),
                    ],
                );
            }
            $arrivalMode = (string) ($data['organization_arrival_mode'] ?? 'same_time');
            $roomIds = collect($data['room_ids'] ?? [])
                ->map(fn ($roomId) => (int) $roomId)
                ->filter(fn (int $roomId) => $roomId > 0)
                ->values();
            $roomSegments = $this->normalizeRoomSegments($data, $data['check_in'], $data['check_out'], $roomIds);

            if ($organization && $arrivalMode === 'separate' && $roomSegments->count() > 1) {
                $reservations = collect();

                foreach ($roomSegments as $segment) {
                    $reservations->push(
                        $this->createBookingReservation(
                            $this->singleSegmentBookingPayload($data, $segment, $arrivalMode),
                            $organization,
                            $userId,
                            $source,
                            $billingMode,
                        )
                    );
                }

                return [
                    'reservations' => $reservations,
                    'primary_reservation' => $reservations->first(),
                ];
            }

            $reservation = $this->createBookingReservation(
                [
                    ...$data,
                    'organization_arrival_mode' => $arrivalMode,
                ],
                $organization,
                $userId,
                $source,
                $billingMode,
            );

            return [
                'reservations' => collect([$reservation]),
                'primary_reservation' => $reservation,
            ];
        });
    }

    private function createBookingReservation(
        array $data,
        ?Organization $organization,
        ?int $userId,
        string $source,
        string $billingMode,
    ): Reservation {
        $customerPhone = PhoneNumber::normalize($data['customer_phone'] ?? null);
        $roomIds = collect($data['room_ids'] ?? [])
            ->map(fn ($roomId) => (int) $roomId)
            ->filter(fn (int $roomId) => $roomId > 0)
            ->values();
        $roomSegments = $this->normalizeRoomSegments($data, $data['check_in'], $data['check_out'], $roomIds);
        $hasCustomSegments = filled($data['room_segments'] ?? null);

        $this->assertSegmentsDoNotOverlap($roomSegments);
        $conflictingRoomNumbers = $this->conflictingRoomNumbersForSegments($roomSegments);

        if ($conflictingRoomNumbers->isNotEmpty()) {
            throw ValidationException::withMessages([
                'room_ids' => 'Chambre(s) déjà occupée(s) : ' . $conflictingRoomNumbers->implode(', '),
            ]);
        }

        if ($source === 'Booking') {
            $invalidBookingRoomNumbers = Room::query()
                ->whereIn('id', $roomSegments->pluck('room_id')->unique()->values())
                ->where(function ($query) {
                    $query
                        ->where('type', '!=', 'Chambre Double')
                        ->orWhere('model', 'not like', '%Supérieure%');
                })
                ->orderBy('room_number')
                ->pluck('room_number');

            if ($invalidBookingRoomNumbers->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'room_ids' => 'Booking est limité aux chambres doubles supérieures : ' . $invalidBookingRoomNumbers->implode(', '),
                ]);
            }
        }

        $this->assertExtraCapacityWithinLimit(
            $data['check_in'],
            $data['check_out'],
            $hasCustomSegments
                ? $roomSegments->sum(fn (array $segment) => (int) ($segment['segment_extra_beds'] ?? 0))
                : (int) ($data['extra_beds'] ?? 0),
            $hasCustomSegments
                ? $roomSegments->sum(fn (array $segment) => (int) ($segment['segment_extra_mattresses'] ?? 0))
                : (int) ($data['extra_mattresses'] ?? 0),
        );

        $reservation = Reservation::query()->create([
            'client_name' => $data['client_name'],
            'client_phone' => $customerPhone ?? '000000000',
            'customer_phone' => $customerPhone,
            'customer_email' => $data['customer_email'] ?? null,
            'organization_id' => $organization?->id,
            'booking_reference' => 'RES-' . strtoupper(bin2hex(random_bytes(3))),
            'booking_type' => $organization ? 'organization' : 'individual',
            'billing_mode' => $billingMode,
            'source' => $source,
            'is_booking_com' => $source === 'Booking',
            'check_in_date' => $data['check_in'],
            'check_out_date' => $data['check_out'],
            'status' => 'en_attente',
            'payment_status' => 'unbilled',
            'user_id' => $userId,
            'extra_beds' => $hasCustomSegments
                ? $roomSegments->sum(fn (array $segment) => (int) ($segment['segment_extra_beds'] ?? 0))
                : (int) ($data['extra_beds'] ?? 0),
            'extra_mattresses' => $hasCustomSegments
                ? $roomSegments->sum(fn (array $segment) => (int) ($segment['segment_extra_mattresses'] ?? 0))
                : (int) ($data['extra_mattresses'] ?? 0),
        ]);

        $providedPrices = collect($data['room_prices'] ?? [])
            ->mapWithKeys(fn (array $roomPrice) => [$roomPrice['id'] => $roomPrice['price']]);

        Room::query()
            ->whereIn('id', $roomSegments->pluck('room_id')->unique()->values())
            ->get()
            ->each(function (Room $room) use ($reservation, $providedPrices, $source, $roomSegments) {
                $segment = $roomSegments->firstWhere('room_id', $room->id);
                $price = $source === 'Booking'
                    ? self::BOOKING_ROOM_PRICE_ARIARY
                    : ($room->is_fixed_price
                        ? $room->base_price_ariary
                        : $providedPrices->get($room->id, $room->base_price_ariary));

                $reservation->rooms()->attach($room->id, [
                    'price_snapshot_ariary' => $price,
                    'segment_start_date' => $segment['segment_start_date'] ?? $reservation->check_in_date,
                    'segment_end_date' => $segment['segment_end_date'] ?? $reservation->check_out_date,
                    'segment_extra_beds' => (int) ($segment['segment_extra_beds'] ?? 0),
                    'segment_extra_mattresses' => (int) ($segment['segment_extra_mattresses'] ?? 0),
                ]);
            });

        ReservationAudit::create([
            'reservation_id' => $reservation->id,
            'action' => 'booked',
            'actor_name' => $data['receptionist_name'] ?? null,
            'actor_role' => $data['actor_role'] ?? 'receptionist',
            'details' => [
                'room_ids' => $roomSegments->pluck('room_id')->all(),
                'room_segments' => $roomSegments->values()->all(),
                'check_in' => $data['check_in'],
                'check_out' => $data['check_out'],
                'source' => $source,
                'booking_type' => $reservation->booking_type,
                'billing_mode' => $reservation->billing_mode,
                'organization_name' => $organization?->name,
                'organization_arrival_mode' => $data['organization_arrival_mode'] ?? 'same_time',
            ],
        ]);

        return $reservation->load('rooms', 'organization');
    }

    private function singleSegmentBookingPayload(array $data, array $segment, string $arrivalMode): array
    {
        return [
            ...$data,
            'check_in' => $segment['segment_start_date'] ?? $data['check_in'],
            'check_out' => $segment['segment_end_date'] ?? $data['check_out'],
            'room_ids' => [$segment['room_id']],
            'room_segments' => [$segment],
            'extra_beds' => (int) ($segment['segment_extra_beds'] ?? 0),
            'extra_mattresses' => (int) ($segment['segment_extra_mattresses'] ?? 0),
            'organization_arrival_mode' => $arrivalMode,
        ];
    }

    public function updateStatus(array $data): ?Reservation
    {
        $reservation = Reservation::query()
            ->with(['invoice'])
            ->when(!empty($data['id']), fn ($query) => $query->where('id', $data['id']))
            ->when(empty($data['id']) && !empty($data['reference']), fn ($query) => $query->where('booking_reference', $data['reference']))
            ->first();

        if (!$reservation) {
            return null;
        }

        $status = $data['status'];
        $paymentStatus = $data['payment_status'] ?? null;

        if (in_array($status, ['arrive_paid', 'arrive_unpaid'], true)) {
            $paymentStatus = $status === 'arrive_paid' ? 'paid' : 'unpaid';
            $status = 'arrive';
        }

        if (
            $status === 'annule'
            && !in_array($data['cancelled_by_role'] ?? null, ['admin', 'superadmin'], true)
            && ($reservation->status === 'arrive' || $this->reservationHasAdvancePayment($reservation))
        ) {
            throw ValidationException::withMessages([
                'status' => 'Après check-in ou acompte, seule une annulation par un administrateur ou superadmin est autorisée.',
            ]);
        }

        $updateData = ['status' => $status];
        if ($status === 'annule') {
            if (Schema::hasColumn('reservations', 'cancelled_by_name')) {
                $updateData['cancelled_by_name'] = $data['cancelled_by_name'] ?? null;
            }
            if (Schema::hasColumn('reservations', 'cancelled_at')) {
                $updateData['cancelled_at'] = now();
            }
        }
        if ($paymentStatus !== null) {
            $updateData['payment_status'] = $paymentStatus;
        }

        $reservation->update($updateData);

        if ($status === 'annule') {
            ReservationAudit::create([
                'reservation_id' => $reservation->id,
                'action' => 'cancelled',
                'actor_name' => $data['cancelled_by_name'] ?? null,
                'actor_role' => $data['cancelled_by_role'] ?? null,
                'details' => [
                    'status' => $status,
                    'payment_status' => $paymentStatus,
                ],
            ]);
        }

        return $reservation;
    }

    private function reservationHasAdvancePayment(Reservation $reservation): bool
    {
        $reservation->loadMissing('invoice');
        $invoice = $reservation->invoice;

        if (!$invoice) {
            return false;
        }

        return in_array((string) ($reservation->payment_status ?? ''), ['partial', 'paid'], true)
            || (int) ($invoice->deposit_amount_ariary ?? 0) > 0
            || (int) ($invoice->paid_amount_ariary ?? 0) > 0;
    }

    public function updateReservation(int $id, array $data): ?Reservation
    {
        $reservation = Reservation::query()
            ->with('rooms')
            ->find($id);

        if (!$reservation) {
            return null;
        }

        if (Carbon::parse($reservation->check_out_date)->lt(now()->startOfDay())) {
            throw ValidationException::withMessages([
                'reservation' => 'Seules les réservations non terminées peuvent être modifiées.',
            ]);
        }

        $isCheckedIn = $reservation->status === 'arrive';
        $currentCheckIn = Carbon::parse($reservation->check_in_date)->toDateString();
        $currentCheckOut = Carbon::parse($reservation->check_out_date)->toDateString();
        $submittedCheckIn = (string) ($data['check_in'] ?? $currentCheckIn);
        $submittedCheckOut = (string) ($data['check_out'] ?? $currentCheckOut);
        $checkIn = $isCheckedIn
            ? $currentCheckIn
            : $submittedCheckIn;
        $checkOut = $submittedCheckOut;
        $roomIds = collect($data['room_ids'])->map(fn ($roomId) => (int) $roomId)->values();
        $roomSegments = $this->normalizeRoomSegments($data, $checkIn, $checkOut, $roomIds);
        $hasCustomSegments = filled($data['room_segments'] ?? null);
        if ($isCheckedIn && Carbon::parse($checkOut)->gt(Carbon::parse($currentCheckOut))) {
            $roomSegments = $this->extendLatestSegmentsToCheckOut($roomSegments, $checkOut);
        }

        if ($isCheckedIn) {
            $submittedName = trim((string) ($data['client_name'] ?? ''));
            $submittedPhone = PhoneNumber::normalize($data['customer_phone'] ?? null);
            $submittedEmail = trim((string) ($data['customer_email'] ?? ''));
            $currentEmail = trim((string) ($reservation->customer_email ?? ''));

            $blockedChanges = [];
            if ($submittedName !== '' && $submittedName !== trim((string) $reservation->client_name)) {
                $blockedChanges[] = 'client_name';
            }
            if ($submittedPhone !== null && $submittedPhone !== ($reservation->customer_phone ?? null)) {
                $blockedChanges[] = 'customer_phone';
            }
            if ($submittedEmail !== '' && $submittedEmail !== $currentEmail) {
                $blockedChanges[] = 'customer_email';
            }
            if ($submittedCheckIn !== $currentCheckIn) {
                $blockedChanges[] = 'check_in';
            }
            if (Carbon::parse($checkOut)->lt(Carbon::parse($currentCheckOut))) {
                $blockedChanges[] = 'check_out';
            }

            if (!empty($blockedChanges)) {
                throw ValidationException::withMessages([
                    'reservation' => 'Après check-in, seules les chambres, les suppléments et une prolongation de séjour peuvent être modifiés.',
                ]);
            }

            $roomSegments = $this->retainConsumedRoomSegments($reservation, $roomSegments);
        }

        $this->assertSegmentsDoNotOverlap($roomSegments);
        $conflictingRoomNumbers = $this->conflictingRoomNumbersForSegments(
            $roomSegments,
            $reservation->id
        );

        if ($conflictingRoomNumbers->isNotEmpty()) {
            throw ValidationException::withMessages([
                'room_ids' => 'Chambre(s) déjà occupée(s) : ' . $conflictingRoomNumbers->implode(', '),
            ]);
        }

        if ($reservation->source === 'Booking') {
            $invalidBookingRoomNumbers = Room::query()
                ->whereIn('id', $roomIds)
                ->where(function ($query) {
                    $query
                        ->where('type', '!=', 'Chambre Double')
                        ->orWhere('model', 'not like', '%Supérieure%');
                })
                ->orderBy('room_number')
                ->pluck('room_number');

            if ($invalidBookingRoomNumbers->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'room_ids' => 'Booking est limité aux chambres doubles supérieures : ' . $invalidBookingRoomNumbers->implode(', '),
                ]);
            }
        }

        $segmentBeds = $hasCustomSegments
            ? $roomSegments->sum(fn (array $segment) => (int) ($segment['segment_extra_beds'] ?? 0))
            : (int) ($data['extra_beds'] ?? 0);
        $segmentMattresses = $hasCustomSegments
            ? $roomSegments->sum(fn (array $segment) => (int) ($segment['segment_extra_mattresses'] ?? 0))
            : (int) ($data['extra_mattresses'] ?? 0);
        $this->assertExtraCapacityWithinLimit(
            $checkIn,
            $checkOut,
            $segmentBeds,
            $segmentMattresses,
            $reservation->id,
        );

        return DB::transaction(function () use ($reservation, $data, $roomIds, $isCheckedIn, $roomSegments, $hasCustomSegments, $checkOut) {
            $customerPhone = PhoneNumber::normalize($data['customer_phone'] ?? null);
            $previousPivots = $reservation->rooms
                ->mapWithKeys(fn (Room $room) => [$room->id => $this->pivotSnapshot($room)]);
            $previousRoomIds = $reservation->rooms->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $newRoomIds = $roomSegments->pluck('room_id')->sort()->values()->all();

            $changeSet = [];
            $registerChange = function (string $field, mixed $before, mixed $after) use (&$changeSet): void {
                if ($before !== $after) {
                    $changeSet[$field] = [
                        'before' => $before,
                        'after' => $after,
                    ];
                }
            };

            $updateData = [
                'check_out_date' => $checkOut,
                'extra_beds' => $hasCustomSegments
                    ? $roomSegments->sum(fn (array $segment) => (int) ($segment['segment_extra_beds'] ?? 0))
                    : (int) ($data['extra_beds'] ?? 0),
                'extra_mattresses' => $hasCustomSegments
                    ? $roomSegments->sum(fn (array $segment) => (int) ($segment['segment_extra_mattresses'] ?? 0))
                    : (int) ($data['extra_mattresses'] ?? 0),
            ];

            if (!$isCheckedIn) {
                $updateData = array_merge($updateData, [
                    'client_name' => $data['client_name'],
                    'client_phone' => $customerPhone ?? '000000000',
                    'customer_phone' => $customerPhone,
                    'customer_email' => $data['customer_email'] ?? null,
                    'check_in_date' => $data['check_in'],
                ]);
            }

            $registerChange('check_out', $reservation->check_out_date->toDateString(), $checkOut);
            $registerChange('room_ids', $previousRoomIds, $newRoomIds);
            $registerChange('extra_beds', (int) $reservation->extra_beds, $updateData['extra_beds']);
            $registerChange(
                'extra_mattresses',
                (int) $reservation->extra_mattresses,
                $updateData['extra_mattresses']
            );

            if (!$isCheckedIn) {
                $registerChange('client_name', $reservation->client_name, $data['client_name']);
                $registerChange(
                    'customer_phone',
                    $reservation->customer_phone,
                    $customerPhone
                );
                $registerChange(
                    'customer_email',
                    $reservation->customer_email,
                    $data['customer_email'] ?? null
                );
                $registerChange('check_in', $reservation->check_in_date->toDateString(), $data['check_in']);
            }

            $reservation->update($updateData);
            $this->syncRoomSegments($reservation, $roomSegments, $previousPivots);

            if (!empty($changeSet)) {
                ReservationAudit::create([
                    'reservation_id' => $reservation->id,
                    'action' => 'modified',
                    'actor_name' => $data['modified_by_name'] ?? null,
                    'actor_role' => $data['modified_by_role'] ?? null,
                    'details' => $changeSet,
                ]);
            }

            return $reservation->refresh()->load(['rooms', 'user']);
        });
    }

    public function extraCapacitySummary(
        string $checkIn,
        string $checkOut,
        ?int $excludeReservationId = null,
    ): array {
        $summary = $this->buildExtraCapacitySummary($checkIn, $checkOut, $excludeReservationId);

        return [
            'status' => 'success',
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'max_beds' => self::MAX_EXTRA_BEDS_PER_NIGHT,
            'max_mattresses' => self::MAX_EXTRA_MATTRESSES_PER_NIGHT,
            'remaining_beds' => $summary['remaining_beds'],
            'remaining_mattresses' => $summary['remaining_mattresses'],
            'daily' => $summary['daily'],
        ];
    }

    private function assertExtraCapacityWithinLimit(
        string $checkIn,
        string $checkOut,
        int $extraBeds,
        int $extraMattresses,
        ?int $excludeReservationId = null,
    ): void {
        $summary = $this->buildExtraCapacitySummary($checkIn, $checkOut, $excludeReservationId);

        if ($extraBeds > $summary['remaining_beds']) {
            $firstDay = collect($summary['daily'])
                ->first(fn (array $day) => (int) $day['beds_remaining'] < $extraBeds);
            $remaining = $firstDay['beds_remaining'] ?? 0;
            $date = $firstDay['date'] ?? $checkIn;

            throw ValidationException::withMessages([
                'extra_beds' => sprintf(
                    'Le %s, il ne reste que %d lit(s) supplémentaire(s) disponible(s).',
                    $date,
                    $remaining,
                ),
            ]);
        }

        if ($extraMattresses > $summary['remaining_mattresses']) {
            $firstDay = collect($summary['daily'])
                ->first(fn (array $day) => (int) $day['mattresses_remaining'] < $extraMattresses);
            $remaining = $firstDay['mattresses_remaining'] ?? 0;
            $date = $firstDay['date'] ?? $checkIn;

            throw ValidationException::withMessages([
                'extra_mattresses' => sprintf(
                    'Le %s, il ne reste que %d matelas supplémentaire(s) disponible(s).',
                    $date,
                    $remaining,
                ),
            ]);
        }
    }

    private function buildExtraCapacitySummary(
        string $checkIn,
        string $checkOut,
        ?int $excludeReservationId = null,
    ): array {
        $start = Carbon::parse($checkIn)->startOfDay();
        $end = Carbon::parse($checkOut)->startOfDay();

        $daily = [];
        foreach (CarbonPeriod::create($start, $end->copy()->subDay()) as $date) {
            $daily[$date->toDateString()] = [
                'date' => $date->toDateString(),
                'beds_used' => 0,
                'beds_remaining' => self::MAX_EXTRA_BEDS_PER_NIGHT,
                'mattresses_used' => 0,
                'mattresses_remaining' => self::MAX_EXTRA_MATTRESSES_PER_NIGHT,
            ];
        }

        if (empty($daily)) {
            return [
                'daily' => [],
                'remaining_beds' => self::MAX_EXTRA_BEDS_PER_NIGHT,
                'remaining_mattresses' => self::MAX_EXTRA_MATTRESSES_PER_NIGHT,
            ];
        }

        $reservations = Reservation::query()
            ->with('rooms')
            ->whereIn('status', Reservation::ACTIVE_STATUSES)
            ->where('check_in_date', '<', $checkOut)
            ->where('check_out_date', '>', $checkIn)
            ->when($excludeReservationId, fn ($query) => $query->where('id', '!=', $excludeReservationId))
            ->get();

        foreach ($reservations as $reservation) {
            $usesSegmentExtras = $reservation->rooms->contains(function (Room $room) use ($reservation) {
                $reservationStart = $reservation->check_in_date->toDateString();
                $reservationEnd = $reservation->check_out_date->toDateString();
                $segmentStart = optional($room->pivot->segment_start_date)->toDateString();
                $segmentEnd = optional($room->pivot->segment_end_date)->toDateString();

                return (int) ($room->pivot->segment_extra_beds ?? 0) > 0
                    || (int) ($room->pivot->segment_extra_mattresses ?? 0) > 0
                    || ($segmentStart && $segmentStart !== $reservationStart)
                    || ($segmentEnd && $segmentEnd !== $reservationEnd);
            });

            if ($usesSegmentExtras) {
                foreach ($reservation->rooms as $room) {
                    $segmentStart = Carbon::parse($room->pivot->segment_start_date ?? $reservation->check_in_date)->startOfDay();
                    $segmentEnd = Carbon::parse($room->pivot->segment_end_date ?? $reservation->check_out_date)->startOfDay();
                    if ($segmentStart->lt($start)) {
                        $segmentStart = $start->copy();
                    }
                    if ($segmentEnd->gt($end)) {
                        $segmentEnd = $end->copy();
                    }

                    foreach (CarbonPeriod::create($segmentStart, $segmentEnd->copy()->subDay()) as $date) {
                        $key = $date->toDateString();
                        if (!isset($daily[$key])) {
                            continue;
                        }

                        $daily[$key]['beds_used'] += (int) ($room->pivot->segment_extra_beds ?? 0);
                        $daily[$key]['mattresses_used'] += (int) ($room->pivot->segment_extra_mattresses ?? 0);
                    }
                }
            } else {
                $reservationStart = Carbon::parse($reservation->check_in_date)->startOfDay();
                if ($reservationStart->lt($start)) {
                    $reservationStart = $start->copy();
                }

                $reservationEnd = Carbon::parse($reservation->check_out_date)->startOfDay();
                if ($reservationEnd->gt($end)) {
                    $reservationEnd = $end->copy();
                }

                foreach (CarbonPeriod::create($reservationStart, $reservationEnd->copy()->subDay()) as $date) {
                    $key = $date->toDateString();
                    if (!isset($daily[$key])) {
                        continue;
                    }

                    $daily[$key]['beds_used'] += (int) ($reservation->extra_beds ?? 0);
                    $daily[$key]['mattresses_used'] += (int) ($reservation->extra_mattresses ?? 0);
                }
            }
        }

        $remainingBeds = self::MAX_EXTRA_BEDS_PER_NIGHT;
        $remainingMattresses = self::MAX_EXTRA_MATTRESSES_PER_NIGHT;
        foreach ($daily as &$day) {
            $day['beds_remaining'] = max(0, self::MAX_EXTRA_BEDS_PER_NIGHT - $day['beds_used']);
            $day['mattresses_remaining'] = max(0, self::MAX_EXTRA_MATTRESSES_PER_NIGHT - $day['mattresses_used']);
            $remainingBeds = min($remainingBeds, $day['beds_remaining']);
            $remainingMattresses = min($remainingMattresses, $day['mattresses_remaining']);
        }
        unset($day);

        return [
            'daily' => array_values($daily),
            'remaining_beds' => $remainingBeds,
            'remaining_mattresses' => $remainingMattresses,
        ];
    }

    private function normalizeRoomSegments(array $data, string $checkIn, string $checkOut, Collection $roomIds): Collection
    {
        $segments = collect($data['room_segments'] ?? [])
            ->filter(fn ($segment) => is_array($segment))
            ->values();

        if ($segments->isEmpty()) {
            return $roomIds->map(fn (int $roomId) => [
                'room_id' => $roomId,
                'segment_start_date' => $checkIn,
                'segment_end_date' => $checkOut,
                'segment_extra_beds' => 0,
                'segment_extra_mattresses' => 0,
            ]);
        }

        return $segments
            ->map(function (array $segment) use ($checkIn, $checkOut) {
                $roomId = (int) ($segment['room_id'] ?? 0);
                if ($roomId <= 0) {
                    return null;
                }

                $segmentStart = trim((string) ($segment['segment_start_date'] ?? $checkIn));
                $segmentEnd = trim((string) ($segment['segment_end_date'] ?? $checkOut));

                return [
                    'room_id' => $roomId,
                    'segment_start_date' => $segmentStart !== '' ? $segmentStart : $checkIn,
                    'segment_end_date' => $segmentEnd !== '' ? $segmentEnd : $checkOut,
                    'segment_extra_beds' => (int) ($segment['segment_extra_beds'] ?? 0),
                    'segment_extra_mattresses' => (int) ($segment['segment_extra_mattresses'] ?? 0),
                ];
            })
            ->filter()
            ->values();
    }

    private function assertSegmentsDoNotOverlap(Collection $segments): void
    {
        $byRoom = $segments->groupBy('room_id');

        foreach ($byRoom as $roomSegments) {
            $normalized = $roomSegments
                ->map(fn (array $segment) => [
                    'start' => Carbon::parse($segment['segment_start_date'])->startOfDay(),
                    'end' => Carbon::parse($segment['segment_end_date'])->startOfDay(),
                ])
                ->sortBy('start')
                ->values();

            for ($i = 1; $i < $normalized->count(); $i++) {
                if ($normalized[$i]['start']->lt($normalized[$i - 1]['end'])) {
                    throw ValidationException::withMessages([
                        'room_segments' => 'Le découpage contient des segments qui se chevauchent pour une même chambre.',
                    ]);
                }
            }
        }
    }

    private function extendLatestSegmentsToCheckOut(Collection $segments, string $checkOut): Collection
    {
        if ($segments->isEmpty()) {
            return $segments;
        }

        $latestEnd = $segments
            ->map(fn (array $segment) => Carbon::parse($segment['segment_end_date'])->toDateString())
            ->max();

        if ($latestEnd === null || Carbon::parse($latestEnd)->gte(Carbon::parse($checkOut))) {
            return $segments;
        }

        return $segments
            ->map(function (array $segment) use ($latestEnd, $checkOut) {
                if (Carbon::parse($segment['segment_end_date'])->toDateString() === $latestEnd) {
                    $segment['segment_end_date'] = $checkOut;
                }

                return $segment;
            })
            ->values();
    }

    private function retainConsumedRoomSegments(Reservation $reservation, Collection $segments): Collection
    {
        $stayStart = Carbon::parse($reservation->check_in_date)->startOfDay();
        $stayEnd = Carbon::parse($reservation->check_out_date)->startOfDay();
        $cutoff = now()->startOfDay();

        if ($cutoff->lte($stayStart)) {
            return $segments;
        }

        if ($cutoff->gt($stayEnd)) {
            $cutoff = $stayEnd;
        }

        $existingSegments = $reservation->rooms->map(fn (Room $room) => [
            'room_id' => (int) $room->id,
            'segment_start_date' => Carbon::parse($room->pivot->segment_start_date ?? $reservation->check_in_date)->toDateString(),
            'segment_end_date' => Carbon::parse($room->pivot->segment_end_date ?? $reservation->check_out_date)->toDateString(),
            'segment_extra_beds' => (int) ($room->pivot->segment_extra_beds ?? 0),
            'segment_extra_mattresses' => (int) ($room->pivot->segment_extra_mattresses ?? 0),
        ]);

        $isUnchangedExistingSegment = function (array $segment) use ($existingSegments): bool {
            return $existingSegments->contains(fn (array $existing) => (
                (int) $segment['room_id'] === (int) $existing['room_id']
                && Carbon::parse($segment['segment_start_date'])->toDateString() === $existing['segment_start_date']
                && Carbon::parse($segment['segment_end_date'])->toDateString() === $existing['segment_end_date']
                && (int) ($segment['segment_extra_beds'] ?? 0) === (int) $existing['segment_extra_beds']
                && (int) ($segment['segment_extra_mattresses'] ?? 0) === (int) $existing['segment_extra_mattresses']
            ));
        };

        $mutableSegments = $segments
            ->map(function (array $segment) use ($cutoff, $isUnchangedExistingSegment) {
                if ($isUnchangedExistingSegment($segment)) {
                    return $segment;
                }

                $segmentStart = Carbon::parse($segment['segment_start_date'])->startOfDay();
                $segmentEnd = Carbon::parse($segment['segment_end_date'])->startOfDay();

                if ($segmentEnd->lte($cutoff)) {
                    return null;
                }

                if ($segmentStart->lt($cutoff)) {
                    $segment['segment_start_date'] = $cutoff->toDateString();
                }

                return $segment;
            })
            ->filter()
            ->values();

        $retainedSegments = collect();

        foreach ($reservation->rooms as $room) {
            $segmentStart = Carbon::parse($room->pivot->segment_start_date ?? $reservation->check_in_date)->startOfDay();
            $segmentEnd = Carbon::parse($room->pivot->segment_end_date ?? $reservation->check_out_date)->startOfDay();
            $consumedEnd = $segmentEnd->lt($cutoff) ? $segmentEnd : $cutoff;
            $existingSegment = [
                'room_id' => (int) $room->id,
                'segment_start_date' => $segmentStart->toDateString(),
                'segment_end_date' => $segmentEnd->toDateString(),
                'segment_extra_beds' => (int) ($room->pivot->segment_extra_beds ?? 0),
                'segment_extra_mattresses' => (int) ($room->pivot->segment_extra_mattresses ?? 0),
            ];

            if ($segmentStart->gte($consumedEnd) || $segments->contains(fn (array $segment) => $isUnchangedExistingSegment($segment) && (int) $segment['room_id'] === (int) $existingSegment['room_id'])) {
                continue;
            }

            $retainedSegments->push([
                ...$existingSegment,
                'segment_end_date' => $consumedEnd->toDateString(),
            ]);
        }

        if ($retainedSegments->isEmpty()) {
            return $mutableSegments;
        }

        return $retainedSegments
            ->concat($mutableSegments)
            ->values();
    }

    private function conflictingRoomNumbersForSegments(Collection $segments, ?int $excludeReservationId = null): Collection
    {
        $conflicts = collect();

        foreach ($segments as $segment) {
            $roomId = (int) ($segment['room_id'] ?? 0);
            if ($roomId <= 0) {
                continue;
            }

            $busyRoomIds = $this->availabilityService->busyRoomIdsForPeriod(
                (string) $segment['segment_start_date'],
                (string) $segment['segment_end_date'],
                Reservation::ACTIVE_STATUSES,
                $excludeReservationId,
            );

            if ($busyRoomIds->contains($roomId)) {
                $roomNumber = Room::query()->where('id', $roomId)->value('room_number');
                if ($roomNumber) {
                    $conflicts->push($roomNumber);
                }
            }
        }

        return $conflicts->unique()->sort()->values();
    }

    private function pivotSnapshot(Room $room): array
    {
        return [
            'price_snapshot_ariary' => (int) ($room->pivot->price_snapshot_ariary ?? $room->base_price_ariary),
            'occupant_name' => $room->pivot->occupant_name,
            'occupant_phone' => $room->pivot->occupant_phone,
            'occupant_email' => $room->pivot->occupant_email,
            'occupant_date_of_birth' => optional($room->pivot->occupant_date_of_birth)->toDateString(),
            'occupant_sex' => $room->pivot->occupant_sex,
            'occupant_id_type' => $room->pivot->occupant_id_type,
            'occupant_id_number' => $room->pivot->occupant_id_number,
            'occupant_passport_valid_from' => optional($room->pivot->occupant_passport_valid_from)->toDateString(),
            'occupant_passport_valid_until' => optional($room->pivot->occupant_passport_valid_until)->toDateString(),
            'checked_in_at' => optional($room->pivot->checked_in_at)->toDateTimeString(),
            'checked_in_by_name' => $room->pivot->checked_in_by_name,
            'checked_in_by_role' => $room->pivot->checked_in_by_role,
            'invoice_id' => $room->pivot->invoice_id,
        ];
    }

    private function syncRoomSegments(Reservation $reservation, Collection $segments, Collection $previousPivots): void
    {
        $existingIds = $reservation->rooms->pluck('id')->map(fn ($id) => (int) $id)->all();
        $segmentRoomIds = $segments->pluck('room_id')->map(fn ($id) => (int) $id)->all();

        $reservation->rooms()->detach($existingIds);

        $rooms = Room::query()
            ->whereIn('id', $segmentRoomIds)
            ->get()
            ->keyBy('id');

        foreach ($segments as $segment) {
            $roomId = (int) $segment['room_id'];
            $room = $rooms->get($roomId);
            if (!$room instanceof Room) {
                continue;
            }

            $price = $reservation->source === 'Booking'
                ? self::BOOKING_ROOM_PRICE_ARIARY
                : (int) ($previousPivots->get($roomId)['price_snapshot_ariary'] ?? $room->base_price_ariary);

            $reservation->rooms()->attach($roomId, [
                ...($previousPivots->get($roomId) ?? []),
                'price_snapshot_ariary' => $price,
                'segment_start_date' => $segment['segment_start_date'],
                'segment_end_date' => $segment['segment_end_date'],
                'segment_extra_beds' => (int) ($segment['segment_extra_beds'] ?? 0),
                'segment_extra_mattresses' => (int) ($segment['segment_extra_mattresses'] ?? 0),
            ]);
        }
    }

    public function reservationsForDate(?string $date, string $statusFilter = 'all'): Collection
    {
        return Reservation::query()
            ->with(['rooms', 'user', 'audits', 'invoice.payments', 'latestAudit', 'latestCheckInAudit', 'latestModificationAudit'])
            ->when($date && $date !== 'all', function ($query) use ($date) {
                $query->where('check_in_date', '<=', $date)
                    ->where('check_out_date', '>=', $date);
            })
            ->when($statusFilter === 'pending', fn ($query) => $query->where('status', 'en_attente'))
            ->when($statusFilter === 'unpaid', function ($query) {
                $query->where('status', 'arrive')
                    ->where(function ($query) {
                        $query
                            ->whereIn('payment_status', ['unpaid', 'partial', 'unbilled'])
                            ->orWhereHas('invoice', fn ($invoiceQuery) => $invoiceQuery->whereIn('status', ['open', 'partial']))
                            ->orWhereDoesntHave('invoice');
                    });
            })
            ->when($statusFilter === 'paid', function ($query) {
                $query->where(function ($query) {
                    $query
                        ->where('payment_status', 'paid')
                        ->orWhereHas('invoice', fn ($invoiceQuery) => $invoiceQuery->where('status', 'paid'));
                });
            })
            ->orderBy('check_in_date')
            ->orderBy('created_at')
            ->orderBy('client_name')
            ->orderBy('id')
            ->get()
            ->map(fn (Reservation $reservation) => $this->formatReservation($reservation));
    }

    public function activeReservations(string $date): Collection
    {
        return Reservation::query()
            ->with(['rooms', 'user', 'audits', 'invoice.payments', 'latestAudit', 'latestCheckInAudit', 'latestModificationAudit'])
            ->where('check_in_date', '<=', $date)
            ->where('check_out_date', '>=', $date)
            ->get()
            ->map(function (Reservation $reservation) {
                $formatted = $this->formatReservation($reservation);

                return [
                    ...$formatted,
                    'contact' => $formatted['phone'] !== 'N/A' ? $formatted['phone'] : $formatted['email'],
                    'visit_count' => $this->visitCountForReservation($reservation),
                ];
            });
    }

    private function visitCountForReservation(Reservation $reservation): int
    {
        $signatureParts = [
            Str::lower(Str::ascii(trim((string) ($reservation->customer_phone ?: $reservation->client_phone ?: '')))),
            Str::lower(Str::ascii(trim((string) ($reservation->customer_email ?: '')))),
            Str::lower(Str::ascii(trim((string) ($reservation->client_name ?: '')))),
        ];

        $signature = implode('|', array_filter($signatureParts, fn ($part) => $part !== ''));
        if ($signature === '') {
            return 0;
        }

        $reservations = Reservation::query()
            ->with(['invoice', 'invoice.payments'])
            ->get()
            ->filter(function (Reservation $candidate) {
                $invoice = $candidate->invoice;
                $paymentStatus = (string) ($candidate->payment_status ?? '');
                $invoiceStatus = (string) ($invoice?->status ?? '');
                $balance = (int) ($invoice?->balance_amount_ariary ?? 0);

                return $candidate->status !== 'annule'
                    && (
                        $paymentStatus === 'paid'
                        || $invoiceStatus === 'paid'
                        || $balance <= 0
                    );
            });

        return $reservations->filter(function (Reservation $candidate) use ($signature) {
            $candidateParts = [
                Str::lower(Str::ascii(trim((string) ($candidate->customer_phone ?: $candidate->client_phone ?: '')))),
                Str::lower(Str::ascii(trim((string) ($candidate->customer_email ?: '')))),
                Str::lower(Str::ascii(trim((string) ($candidate->client_name ?: '')))),
            ];

            $candidateSignature = implode('|', array_filter($candidateParts, fn ($part) => $part !== ''));
            return $candidateSignature === $signature;
        })->count();
    }

    public function formatReservation(Reservation $reservation): array
    {
        $rooms = $reservation->rooms;
        $groupedRooms = $rooms
            ->groupBy(fn (Room $room) => $room->type . ' ' . $room->model)
            ->map(fn (Collection $group) => $group->count() . 'x ' . $group->first()->type . ' (' . $group->first()->model . ')')
            ->values()
            ->implode(', ');
        $roomNumbers = $rooms
            ->sortBy('room_number', SORT_NATURAL)
            ->pluck('room_number')
            ->values()
            ->implode(', ');
        $roomDetails = $rooms
            ->sortBy('room_number', SORT_NATURAL)
            ->map(fn (Room $room) => [
                'id' => $room->id,
                'room_number' => $room->room_number,
                'type' => $room->type,
                'model' => $room->model,
                'base_price_ariary' => $room->base_price_ariary,
                'fixed_price_ariary' => $room->base_price_ariary,
                'is_fixed_price' => $room->is_fixed_price,
                'price_snapshot_ariary' => (int) $room->pivot->price_snapshot_ariary,
                'occupant_name' => $room->pivot->occupant_name,
                'occupant_phone' => $room->pivot->occupant_phone,
                'occupant_email' => $room->pivot->occupant_email,
                'occupant_date_of_birth' => optional($room->pivot->occupant_date_of_birth)->toDateString(),
                'occupant_sex' => $room->pivot->occupant_sex,
                'occupant_id_type' => $room->pivot->occupant_id_type,
                'occupant_id_number' => $room->pivot->occupant_id_number,
                'occupant_passport_valid_from' => optional($room->pivot->occupant_passport_valid_from)->toDateString(),
                'occupant_passport_valid_until' => optional($room->pivot->occupant_passport_valid_until)->toDateString(),
                'segment_start_date' => optional($room->pivot->segment_start_date)->toDateString(),
                'segment_end_date' => optional($room->pivot->segment_end_date)->toDateString(),
                'segment_extra_beds' => (int) ($room->pivot->segment_extra_beds ?? 0),
                'segment_extra_mattresses' => (int) ($room->pivot->segment_extra_mattresses ?? 0),
                'checked_in_at' => optional($room->pivot->checked_in_at)->toDateTimeString(),
                'checked_in_by_name' => $room->pivot->checked_in_by_name,
                'checked_in_by_role' => $room->pivot->checked_in_by_role,
                'invoice_id' => $room->pivot->invoice_id,
            ])
            ->values();

        $checkIn = Carbon::parse($reservation->check_in_date);
        $checkOut = Carbon::parse($reservation->check_out_date);
        $nights = max(1, $checkIn->diffInDays($checkOut));

        $extraBeds = $reservation->extra_beds ?? 0;
        $extraMattresses = $reservation->extra_mattresses ?? 0;
        $hasSegmentedPricing = $rooms->contains(function (Room $room) use ($reservation) {
            $segmentStart = optional($room->pivot->segment_start_date)->toDateString();
            $segmentEnd = optional($room->pivot->segment_end_date)->toDateString();

            return (int) ($room->pivot->segment_extra_beds ?? 0) > 0
                || (int) ($room->pivot->segment_extra_mattresses ?? 0) > 0
                || ($segmentStart && $segmentStart !== $reservation->check_in_date->toDateString())
                || ($segmentEnd && $segmentEnd !== $reservation->check_out_date->toDateString());
        });

        if ($hasSegmentedPricing) {
            $totalPrice = 0;
            $fixedTotalPrice = 0;

            foreach ($rooms as $room) {
                $segmentNights = $this->segmentNights($room, $reservation);
                $roomExtras = ((int) ($room->pivot->segment_extra_beds ?? 0) * 50000)
                    + ((int) ($room->pivot->segment_extra_mattresses ?? 0) * 35000);
                $totalPrice += ((int) $room->pivot->price_snapshot_ariary * $segmentNights) + ($roomExtras * $segmentNights);
                $fixedTotalPrice += ((int) $room->base_price_ariary * $segmentNights) + ($roomExtras * $segmentNights);
            }
        } else {
            $extrasPrice = (($extraBeds * 50000) + ($extraMattresses * 35000)) * $nights;
            $totalPrice = ($rooms->sum(fn (Room $room) => (int) $room->pivot->price_snapshot_ariary) * $nights) + $extrasPrice;
            $fixedTotalPrice = ($rooms->sum(fn (Room $room) => (int) $room->base_price_ariary) * $nights) + $extrasPrice;
        }
        $invoice = $reservation->invoice;
        $paymentStatus = $reservation->payment_status ?? 'unbilled';
        $payments = $invoice?->relationLoaded('payments')
            ? $invoice->payments
            : ($invoice ? $invoice->payments()->get() : collect());
        $latestPayment = $payments
            ->where('payment_context', 'payment')
            ->sortByDesc('created_at')
            ->first()
            ?? $payments->sortByDesc('created_at')->first();
        $latestDeposit = $payments
            ->where('payment_context', 'deposit')
            ->sortByDesc('created_at')
            ->first();
        $paymentMethods = $invoice?->relationLoaded('payments')
            ? $invoice->payments->map(fn (Payment $payment) => $this->paymentMethodDisplayLabel($payment))->filter()->unique()->values()
            : collect();
        $paymentMethodsDisplay = $paymentMethods->isNotEmpty()
            ? $paymentMethods->implode(' / ')
            : 'N/A';
        if ($invoice && $paymentStatus === 'unbilled') {
            $paymentStatus = ((int) $invoice->balance_amount_ariary <= 0)
                ? 'paid'
                : (((int) $invoice->paid_amount_ariary > 0) ? 'partial' : 'unpaid');
        }

        $checkInAudit = $reservation->latestCheckInAudit
            ?? ($reservation->relationLoaded('audits')
                ? $reservation->audits
                    ->where('action', 'check_in')
                    ->sortByDesc('created_at')
                    ->first()
                : null);
        $cancelAudit = $reservation->relationLoaded('audits')
            ? $reservation->audits
                ->where('action', 'cancelled')
                ->sortByDesc('created_at')
                ->first()
            : null;
        $modificationAudit = $reservation->latestModificationAudit
            ?? ($reservation->relationLoaded('audits')
                ? $reservation->audits
                    ->where('action', 'modified')
                    ->sortByDesc('created_at')
                    ->first()
                : null);

        return [
            'id' => $reservation->id,
            'reference' => $reservation->booking_reference,
            'client_name' => $reservation->client_name,
            'phone' => ($reservation->customer_phone && $reservation->customer_phone !== '000000000')
                ? $reservation->customer_phone
                : ($reservation->client_phone ?? 'N/A'),
            'email' => $reservation->customer_email ?? 'N/A',
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
            'contact' => ($reservation->customer_phone && $reservation->customer_phone !== '000000000')
                ? $reservation->customer_phone
                : ($reservation->client_phone ?? ($reservation->customer_email ?? 'N/A')),
            'organization_phone' => $reservation->organization?->phone ?? 'N/A',
            'status' => $reservation->status,
            'payment_status' => $paymentStatus,
            'source' => $reservation->source,
            'booking_type' => $reservation->booking_type ?? ($reservation->organization_id ? 'organization' : 'individual'),
            'billing_mode' => $reservation->billing_mode ?? 'grouped',
            'organization' => $reservation->organization ? [
                'id' => $reservation->organization->id,
                'name' => $reservation->organization->name,
                'phone' => $reservation->organization->phone,
                'contact_name' => $reservation->organization->contact_name,
                'contact_phone' => $reservation->organization->contact_phone,
                'contact_email' => $reservation->organization->contact_email,
                'email' => $reservation->organization->email,
                'billing_address' => $reservation->organization->billing_address,
                'nif' => $reservation->organization->nif ?? $reservation->organization->tax_id,
                'stat' => $reservation->organization->stat,
                'tax_id' => $reservation->organization->tax_id,
            ] : null,
            'cancelled_by_name' => $reservation->cancelled_by_name,
            'cancelled_at' => optional($reservation->cancelled_at)->toDateTimeString(),
            'rooms' => $groupedRooms,
            'room_ids' => $rooms->pluck('id')->values(),
            'room_details' => $roomDetails,
            'room_numbers' => $roomNumbers,
            'guest' => $reservation->guest ? [
                'id' => $reservation->guest->id,
                'full_name' => $reservation->guest->full_name,
                'first_name' => $reservation->guest->first_name,
                'last_name' => $reservation->guest->last_name,
                'phone_number' => $reservation->guest->phone_number,
                'sex' => $reservation->guest->sex,
                'date_of_birth' => optional($reservation->guest->date_of_birth)->toDateString(),
                'passport_valid_from' => optional($reservation->guest->passport_valid_from)->toDateString(),
                'passport_valid_until' => optional($reservation->guest->passport_valid_until)->toDateString(),
                'id_type' => $reservation->guest->id_type,
                'id_number' => $reservation->guest->id_number,
                'id_document_number' => $reservation->guest->id_document_number,
                'id_photo_path' => $reservation->guest->id_photo_path,
                'loyalty_count' => (int) $reservation->guest->loyalty_count,
            ] : null,
            'extra_beds' => $extraBeds,
            'extra_mattresses' => $extraMattresses,
            'deposit_amount_ariary' => (int) ($invoice?->deposit_amount_ariary ?? 0),
            'total_price' => (int) $totalPrice,
            'fixed_total_price' => (int) $fixedTotalPrice,
            'paid_amount_ariary' => (int) ($invoice?->paid_amount_ariary ?? 0),
            'balance_amount_ariary' => (int) ($invoice?->balance_amount_ariary ?? 0),
            'is_booking' => $reservation->source === 'Booking',
            'receptionist' => $reservation->user?->name ?? 'N/A',
            'check_in_by' => $checkInAudit?->actor_name ?? 'N/A',
            'check_in_role' => $checkInAudit?->actor_role,
            'check_in_at' => optional($checkInAudit?->created_at)->toDateTimeString(),
            'cancelled_by_role' => $cancelAudit?->actor_role,
            'cancelled_by_name' => $reservation->cancelled_by_name ?? $cancelAudit?->actor_name ?? 'N/A',
            'cancelled_at' => optional($reservation->cancelled_at ?? $cancelAudit?->created_at)->toDateTimeString(),
            'modified_by' => $modificationAudit?->actor_name ?? 'N/A',
            'modified_by_role' => $modificationAudit?->actor_role,
            'modified_at' => optional($modificationAudit?->created_at)->toDateTimeString(),
            'modified_details' => $modificationAudit?->details,
            'last_action' => $reservation->latestAudit?->action,
            'last_action_by' => $reservation->latestAudit?->actor_name,
            'last_action_role' => $reservation->latestAudit?->actor_role,
            'last_action_at' => optional($reservation->latestAudit?->created_at)->toDateTimeString(),
            'last_action_details' => $reservation->latestAudit?->details,
            'invoice_number' => $invoice?->invoice_number,
            'invoice_status' => $invoice?->status ?? 'none',
            'document_type' => $invoice?->document_type ?? 'facture',
            'pdf_url' => $invoice?->pdf_path ? url("/api/invoices/{$invoice->id}/pdf") : null,
            'payments' => $payments->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'amount_ariary' => (int) $payment->amount_ariary,
                'amount_received_ariary' => (int) ($payment->amount_received_ariary ?? $payment->amount_ariary),
                'change_given_ariary' => (int) ($payment->change_given_ariary ?? 0),
                'payment_method' => $payment->payment_method,
                'payment_operator' => $payment->payment_operator,
                'payment_context' => $payment->payment_context ?? 'payment',
                'reference' => $payment->reference,
                'processed_by_name' => $payment->processed_by_name,
                'processed_by_role' => $payment->processed_by_role,
                'created_at' => optional($payment->created_at)->toDateTimeString(),
            ])->values(),
            'latest_payment_processed_by' => $latestPayment?->processed_by_name,
            'latest_payment_processed_by_role' => $latestPayment?->processed_by_role,
            'latest_payment_method' => $latestPayment ? $this->paymentMethodDisplayLabel($latestPayment) : null,
            'latest_payment_operator' => $latestPayment?->payment_operator,
            'latest_deposit_processed_by' => $latestDeposit?->processed_by_name,
            'latest_deposit_processed_by_role' => $latestDeposit?->processed_by_role,
            'latest_deposit_method' => $latestDeposit ? $this->paymentMethodDisplayLabel($latestDeposit) : null,
            'latest_deposit_operator' => $latestDeposit?->payment_operator,
            'payment_methods' => $paymentMethods->all(),
            'payment_methods_display' => $paymentMethodsDisplay,
            'created_at' => optional($reservation->created_at)->toDateTimeString(),
        ];
    }

    private function segmentNights(Room $room, Reservation $reservation): int
    {
        $start = $room->pivot->segment_start_date ?? $reservation->check_in_date;
        $end = $room->pivot->segment_end_date ?? $reservation->check_out_date;

        return max(1, Carbon::parse($start)->diffInDays(Carbon::parse($end)));
    }

    private function paymentMethodDisplayLabel(?Payment $payment): ?string
    {
        if (! $payment) {
            return null;
        }

        $method = trim((string) ($payment->payment_method ?? ''));
        $operator = trim((string) ($payment->payment_operator ?? ''));

        if (preg_match('/^mobile\s*money$/i', $method) && $operator !== '') {
            return $operator;
        }

        return $method !== '' ? $method : ($operator !== '' ? $operator : null);
    }
}
