<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Http\Resources\RoomResource;
use App\Models\Room;
use App\Models\User;
use App\Support\PhoneNumber;
use App\Services\AuthService;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Services\YieldService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class HotelManagementController extends Controller
{
    public function __construct(
        private readonly AvailabilityService $availabilityService,
        private readonly BookingService $bookingService,
        private readonly YieldService $yieldService,
        private readonly AuthService $authService,
    ) {
    }

    public function getLiveAvailability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'nullable|date',
        ]);
        $date = $validated['date'] ?? now()->toDateString();
        return response()->json($this->availabilityService->liveSummary($date));
    }

    public function reservationStatusSummary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'nullable|date',
        ]);

        $date = $validated['date'] ?? now()->toDateString();
        $cacheVersion = $this->availabilityService->getCacheVersion();
        $payload = Cache::remember("dashboard:reservation-status-summary:$cacheVersion:$date", now()->addSeconds(30), function () use ($date) {
            $counts = Reservation::query()
                ->selectRaw('status, COUNT(*) as total')
                ->where('check_in_date', '<=', $date)
                ->where('check_out_date', '>', $date)
                ->whereIn('status', Reservation::ACTIVE_STATUSES)
                ->groupBy('status')
                ->pluck('total', 'status');

            return [
                'date' => $date,
                'pending' => (int) ($counts['en_attente'] ?? 0),
                'arrived' => (int) ($counts['arrive'] ?? 0),
            ];
        });

        return response()->json($payload);
    }

    public function extrasCapacity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'check_in' => 'required|date',
            'check_out' => 'required|date|after:check_in',
            'exclude_reservation_id' => 'nullable|integer|exists:reservations,id',
        ]);

        return response()->json(
            $this->bookingService->extraCapacitySummary(
                $validated['check_in'],
                $validated['check_out'],
                $validated['exclude_reservation_id'] ?? null,
            )
        );
    }

    public function getAvailableRoomsForDates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'check_in' => 'required|date',
            'check_out' => 'required|date|after:check_in',
            'exclude_reservation_id' => 'nullable|integer|exists:reservations,id',
        ]);

        return response()->json(
            RoomResource::collection(
                $this->availabilityService->availableRooms(
                    $validated['check_in'],
                    $validated['check_out'],
                    $validated['exclude_reservation_id'] ?? null,
                )
            )->resolve()
        );
    }

    public function saveBooking(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_name' => 'required|string|max:120',
            'customer_phone' => 'nullable|string|max:40',
            'customer_email' => 'nullable|email|max:190',
            'check_in' => 'required|date',
            'check_out' => 'required|date|after:check_in',
            'room_ids' => 'required|array|min:1',
            'room_ids.*' => 'integer|distinct|exists:rooms,id',
            'room_prices' => 'nullable|array',
            'room_prices.*.id' => 'required_with:room_prices|integer|exists:rooms,id',
            'room_prices.*.price' => 'required_with:room_prices|integer|min:0|max:10000000',
            'extra_beds' => 'nullable|integer|min:0',
            'extra_mattresses' => 'nullable|integer|min:0',
            'source' => 'nullable|string|in:Appel,Mail,Booking',
            'receptionist_name' => 'nullable|string|max:120',
        ]);

        $reservation = $this->bookingService->createBooking($validated);
        $this->availabilityService->invalidateCaches();

        return response()->json([
            'status' => 'success',
            'message' => 'Réservation enregistrée avec succès',
            'reference' => $reservation->booking_reference,
        ], 201);
    }

    public function updateBookingStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'nullable|integer',
            'reference' => 'nullable|string|max:40',
            'status' => 'required|string|in:en_attente,arrive,arrive_paid,arrive_unpaid,annule',
            'cancelled_by_name' => 'nullable|string|max:120',
            'cancelled_by_role' => 'nullable|string|in:admin,receptionist,superadmin',
        ]);

        if (empty($validated['id']) && empty($validated['reference'])) {
            return response()->json(['status' => 'error', 'message' => 'Veuillez fournir un ID ou une référence'], 400);
        }

        $reservation = Reservation::query()
            ->when(!empty($validated['id']), fn ($query) => $query->where('id', $validated['id']))
            ->when(empty($validated['id']) && !empty($validated['reference']), fn ($query) => $query->where('booking_reference', $validated['reference']))
            ->first();

        if (!$reservation) {
            return response()->json(['status' => 'error', 'message' => 'Réservation non trouvée'], 404);
        }

        if (
            $validated['status'] === 'annule'
            && $reservation->status === 'arrive'
            && !in_array($validated['cancelled_by_role'] ?? null, ['admin', 'superadmin'], true)
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'Après check-in, seule une annulation par un administrateur est autorisée.',
            ], 403);
        }

        $reservation = $this->bookingService->updateStatus($validated);

        if (!$reservation) {
            return response()->json(['status' => 'error', 'message' => 'Réservation non trouvée'], 404);
        }

        $this->availabilityService->invalidateCaches();

        return response()->json(['status' => 'success']);
    }

    public function updateReservation(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'client_name' => 'nullable|string|max:120',
            'customer_phone' => 'nullable|string|max:40',
            'customer_email' => 'nullable|email|max:190',
            'check_in' => 'nullable|date',
            'check_out' => 'nullable|date|after:check_in',
            'room_ids' => 'required|array|min:1',
            'room_ids.*' => 'integer|distinct|exists:rooms,id',
            'extra_beds' => 'nullable|integer|min:0',
            'extra_mattresses' => 'nullable|integer|min:0',
            'modified_by_name' => 'nullable|string|max:120',
            'modified_by_role' => 'nullable|string|in:admin,receptionist,superadmin',
        ]);

        $reservation = Reservation::query()->find($id);
        if (!$reservation) {
            return response()->json(['status' => 'error', 'message' => 'Réservation non trouvée'], 404);
        }

        if (
            $reservation->status !== 'arrive'
            && empty($validated['client_name'])
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'Le nom du client est requis.',
            ], 422);
        }

        if (
            $reservation->status !== 'arrive'
            && empty($validated['customer_phone'])
            && empty($validated['customer_email'])
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'Veuillez renseigner au moins un téléphone ou un email.',
            ], 422);
        }

        if (
            $reservation->status !== 'arrive'
            && (empty($validated['check_in']) || empty($validated['check_out']))
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'Les dates d’arrivée et de départ sont requises.',
            ], 422);
        }

        $reservation = $this->bookingService->updateReservation($id, $validated);

        if (!$reservation) {
            return response()->json(['status' => 'error', 'message' => 'Réservation non trouvée'], 404);
        }

        $this->availabilityService->invalidateCaches();

        return response()->json([
            'status' => 'success',
            'reservation' => $this->bookingService->formatReservation($reservation),
        ]);
    }

    public function getAllReservations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', Rule::when(
                $request->query('date') !== 'all',
                ['date'],
                ['in:all'],
            )],
            'status' => 'nullable|string|in:all,pending,unpaid,paid',
        ]);

        $date = $validated['date'] ?? null;
        $status = $validated['status'] ?? 'all';
        $cacheVersion = $this->availabilityService->getCacheVersion();
        $cacheKey = 'dashboard:reservations-for-date:' . $cacheVersion . ':' . ($date ?? 'all') . ':' . $status;
        $payload = Cache::remember($cacheKey, now()->addSeconds(30), function () use ($date, $status) {
            return $this->bookingService->reservationsForDate($date, $status)->values()->all();
        });

        return response()->json($payload);
    }

    public function getActiveReservations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'nullable|date',
        ]);
        $date = $validated['date'] ?? now()->toDateString();
        $cacheVersion = $this->availabilityService->getCacheVersion();
        $payload = Cache::remember("dashboard:active-reservations:$cacheVersion:$date", now()->addSeconds(30), function () use ($date) {
            return $this->bookingService->activeReservations($date)->values()->all();
        });

        return response()->json($payload);
    }

    public function searchClientHistory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'nullable|string|max:120',
        ]);

        $term = trim((string) ($validated['q'] ?? ''));
        $normalizedPhone = PhoneNumber::normalize($term);
        $normalizedTerm = Str::lower(Str::ascii($normalizedPhone ?? $term));
        $today = now()->startOfDay();

        $cacheSuffix = $normalizedTerm !== '' ? $normalizedTerm : 'recent';
        $cacheKey = 'dashboard:client-history:' . $this->availabilityService->getCacheVersion() . ':' . $cacheSuffix;
        $reservations = Cache::remember($cacheKey, now()->addMinutes(1), function () use ($normalizedTerm, $today, $term) {
            $query = Reservation::query()
                ->with(['rooms', 'user', 'guest', 'invoice.payments', 'latestAudit', 'latestCheckInAudit', 'latestModificationAudit']);

            if ($term !== '') {
                $query->where(function ($query) use ($normalizedTerm) {
                    $like = '%' . $normalizedTerm . '%';

                    $query->whereRaw('LOWER(client_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(client_phone) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(customer_phone) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(booking_reference) LIKE ?', [$like])
                        ->orWhereHas('guest', function ($guestQuery) use ($like) {
                            $guestQuery
                                ->whereRaw('LOWER(full_name) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(first_name) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(phone_number) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(id_number) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(id_document_number) LIKE ?', [$like]);
                        });
                });
            }

            return $query
                ->orderByDesc('check_in_date')
                ->orderByDesc('created_at')
                ->limit(100)
                ->get()
                ->map(function (Reservation $reservation) use ($today) {
                    $formatted = $this->bookingService->formatReservation($reservation);
                    $checkIn = Carbon::parse($reservation->check_in_date);
                    $checkOut = Carbon::parse($reservation->check_out_date);

                    $period = 'futur';
                    if ($reservation->status === 'annule') {
                        $period = 'annulé';
                    } elseif ($checkOut->lt($today)) {
                        $period = 'passé';
                    } elseif ($checkIn->lte($today) && $checkOut->gt($today)) {
                        $period = 'présent';
                    }

                    return [
                        ...$formatted,
                        'period' => $period,
                        'check_in_date' => $checkIn->toDateString(),
                        'check_out_date' => $checkOut->toDateString(),
                    ];
                })
                ->values()
                ->all();
        });

        return response()->json([
            'status' => 'success',
            'query' => $term,
            'data' => $reservations,
        ]);
    }

    public function getUsers(): JsonResponse
    {
        return response()->json(User::query()->orderBy('name')->get());
    }

    public function createUser(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190|unique:users',
            'password' => 'required|string|min:6|max:255',
            'role' => 'required|string|in:admin,receptionist,superadmin',
            'actor_role' => 'nullable|string|in:admin,superadmin',
        ]);

        $actorRole = $this->resolveActorRole($request);
        if (!in_array($actorRole, ['admin', 'superadmin'], true)) {
            return response()->json(['status' => 'error', 'message' => 'Accès refusé.'], 403);
        }

        if ($validated['role'] === 'superadmin' && $actorRole !== 'superadmin') {
            return response()->json(['status' => 'error', 'message' => 'Seul un superadmin peut créer un compte superadmin.'], 403);
        }

        // Le modèle User applique le cast "hashed" : le mot de passe n'est jamais stocké en clair.
        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => $validated['role'],
        ]);

        return response()->json(['status' => 'success', 'id' => $user->id]);
    }

    public function updateUser(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'required|exists:users,id',
            'name' => 'required|string|max:120',
            'email' => [
                'required',
                'email',
                'max:190',
                Rule::unique('users', 'email')->ignore($request->integer('id')),
            ],
            'role' => 'required|string|in:admin,receptionist,superadmin',
            'password' => 'nullable|string|min:6|max:255',
            'actor_role' => 'nullable|string|in:admin,superadmin',
        ]);

        $actorRole = $this->resolveActorRole($request);
        if (!in_array($actorRole, ['admin', 'superadmin'], true)) {
            return response()->json(['status' => 'error', 'message' => 'Accès refusé.'], 403);
        }

        $user = User::query()->findOrFail($validated['id']);
        if ($user->role === 'superadmin' && $actorRole !== 'superadmin') {
            return response()->json(['status' => 'error', 'message' => 'Seul un superadmin peut modifier un compte superadmin.'], 403);
        }
        if ($validated['role'] === 'superadmin' && $actorRole !== 'superadmin') {
            return response()->json(['status' => 'error', 'message' => 'Seul un superadmin peut attribuer le rôle superadmin.'], 403);
        }

        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
        ]);

        if (!empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        $user->save();

        return response()->json(['status' => 'success', 'message' => 'Utilisateur mis à jour avec succès']);
    }

    public function deleteUser(Request $request, int $id): JsonResponse
    {
        $actorRole = $this->resolveActorRole($request);
        if (!in_array($actorRole, ['admin', 'superadmin'], true)) {
            return response()->json(['status' => 'error', 'message' => 'Accès refusé.'], 403);
        }

        $user = User::query()->findOrFail($id);
        if ($user->role === 'superadmin' && $actorRole !== 'superadmin') {
            return response()->json(['status' => 'error', 'message' => 'Seul un superadmin peut supprimer un compte superadmin.'], 403);
        }

        $user->delete();

        return response()->json(['status' => 'success']);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:190',
            'password' => 'required|string|max:255',
        ]);

        $user = $this->authService->attempt($validated['email'], $validated['password']);

        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Identifiants incorrects'], 401);
        }

        return response()->json([
            'status' => 'success',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }

    private function resolveActorRole(Request $request): string
    {
        $authenticatedRole = Auth::user()?->role;
        if (is_string($authenticatedRole) && $authenticatedRole !== '') {
            return $authenticatedRole;
        }

        $requestedRole = $request->input('actor_role');
        if (is_string($requestedRole) && $requestedRole !== '') {
            return $requestedRole;
        }

        return 'admin';
    }

    public function getAiPredictionsAndPricing(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => 'nullable|integer|min:1|max:365',
            'start_date' => 'nullable|date',
        ]);
        $days = (int) ($validated['days'] ?? 30);
        $startDate = $validated['start_date'] ?? now()->toDateString();

        return response()->json($this->yieldService->predictions($days, $startDate));
    }

    public function auditDate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'nullable|date',
        ]);
        $date = $validated['date'] ?? now()->toDateString();

        $payload = Cache::remember("dashboard:audit-date:$date", now()->addSeconds(45), function () use ($date) {
            return $this->yieldService->auditDate($date);
        });

        return response()->json($payload);
    }

    public function aiRevenueSummary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => 'nullable|integer|min:1|max:365',
            'start_date' => 'nullable|date',
        ]);
        $days = (int) ($validated['days'] ?? 30);
        $startDate = $validated['start_date'] ?? now()->toDateString();

        $payload = Cache::remember("dashboard:ai-revenue-summary:$days:$startDate", now()->addMinutes(1), function () use ($days, $startDate) {
            return $this->yieldService->aiRevenueSummary($days, $startDate);
        });

        return response()->json($payload);
    }

    public function checkGlobalOccupationAndAlert(): void
    {
        $tomorrow = now()->addDay()->toDateString();
        $totalRooms = Room::query()->count();

        if ($totalRooms === 0) {
            return;
        }

        $bookedRoomsTomorrow = $this->availabilityService->occupiedRoomCount($tomorrow);
        $rate = ($bookedRoomsTomorrow / $totalRooms) * 100;

        if ($rate >= 95) {
            $hotelEmail = config('mail.from.address', 'manager@kamorohotel.com');
            Mail::raw("URGENT - Kamoro Hotel : Taux d'occupation critique de " . round($rate, 1) . "% prévu pour demain ({$tomorrow}). Fermez immédiatement les vannes Booking.com pour bloquer les réservations.", function ($message) use ($hotelEmail) {
                $message->to($hotelEmail)->subject('ALERTE CRITIQUE : Seuil d\'occupation hôtelière à 95% !');
            });
        }
    }
}
