<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Organization;
use App\Models\Room;
use App\Models\ReservationAudit;
use App\Models\Payment;
use App\Models\Reservation;
use App\Support\PhoneNumber;
use App\Services\AvailabilityService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PMSController extends Controller
{
    private const TOURIST_TAX_PER_ROOM_NIGHT = 2000;
    private const EXTRA_BED_PRICE_ARIARY = 50000;
    private const EXTRA_MATTRESS_PRICE_ARIARY = 30000;
    private const BOOKING_ROOM_PRICE_EUR = 32.5;
    private const EXTRA_BED_PRICE_EUR = 10.0;
    private const EXTRA_MATTRESS_PRICE_EUR = 6.0;
    private const EUR_TO_ARIARY_RATE = 5000;
    private const RECEPTIONIST_ITEM_EDIT_WINDOW_SECONDS = 7;
    private static bool $hotelLogoLoaded = false;
    private static ?string $hotelLogoDataUri = null;

    public function __construct(
        private readonly AvailabilityService $availabilityService,
    ) {
    }

    public function checkIn(Request $request, int $id): JsonResponse
    {
        $reservation = Reservation::with(['rooms', 'guest', 'organization', 'invoice.items', 'invoice.payments'])->findOrFail($id);
        $isOrganizationReservation = ($reservation->booking_type ?? '') === 'organization';

        $roomCheckins = $request->input('room_checkins');
        if (is_string($roomCheckins)) {
            $decodedRoomCheckins = json_decode($roomCheckins, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $request->merge(['room_checkins' => $decodedRoomCheckins]);
            }
        }

        $payload = array_merge([
            'full_name' => $reservation->guest?->full_name ?? $reservation->client_name,
            'customer_phone' => $reservation->guest?->phone_number ?? $reservation->customer_phone ?? $reservation->client_phone ?? null,
            'phone_number' => $reservation->guest?->phone_number ?? $reservation->customer_phone ?? $reservation->client_phone ?? null,
            'date_of_birth' => optional($reservation->guest?->date_of_birth)->toDateString(),
            'sex' => $reservation->guest?->sex,
            'id_type' => $reservation->guest?->id_type,
            'id_number' => $reservation->guest?->id_number ?? $reservation->guest?->id_document_number,
            'id_document_number' => $reservation->guest?->id_document_number ?? $reservation->guest?->id_number,
            'passport_valid_from' => optional($reservation->guest?->passport_valid_from)->toDateString(),
            'passport_valid_until' => optional($reservation->guest?->passport_valid_until)->toDateString(),
            'loyalty_count' => $reservation->guest?->loyalty_count,
            'organization_name' => $reservation->organization?->name ?? $reservation->client_name,
            'organization_phone' => $reservation->organization?->phone,
            'organization_contact_name' => $reservation->organization?->contact_name,
            'organization_contact_phone' => $reservation->organization?->contact_phone,
            'organization_contact_email' => $reservation->organization?->contact_email,
            'organization_email' => $reservation->organization?->email,
            'organization_billing_address' => $reservation->organization?->billing_address,
            'organization_nif' => $reservation->organization?->nif ?? $reservation->organization?->tax_id,
            'organization_stat' => $reservation->organization?->stat,
            'organization_tax_id' => $reservation->organization?->tax_id,
        ], $request->all());

        $validated = validator($payload, [
            'full_name' => 'required|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'phone_number' => 'nullable|string|max:50',
            'date_of_birth' => 'required|date',
            'sex' => 'required|in:Homme,Femme,Autre',
            'id_type' => 'required|in:CIN,Passeport,Carte de séjour,Autre,Permis',
            'id_number' => 'required|string|max:100',
            'id_document_number' => 'nullable|string|max:100',
            'passport_valid_from' => [
                'nullable',
                'date',
                Rule::requiredIf(fn () => ! $isOrganizationReservation && in_array((string) ($payload['id_type'] ?? ''), ['Passeport', 'Carte de séjour', 'Autre'], true)),
                'before_or_equal:passport_valid_until',
            ],
            'passport_valid_until' => [
                'nullable',
                'date',
                Rule::requiredIf(fn () => ! $isOrganizationReservation && in_array((string) ($payload['id_type'] ?? ''), ['Passeport', 'Carte de séjour', 'Autre'], true)),
                'after_or_equal:passport_valid_from',
            ],
            'loyalty_count' => 'nullable|integer|min:0',
            'first_name' => 'nullable|string|max:120',
            'last_name' => 'nullable|string|max:120',
            'organization_name' => 'nullable|string|max:190',
            'organization_phone' => 'nullable|string|max:40',
            'organization_contact_name' => 'nullable|string|max:120',
            'organization_contact_phone' => 'nullable|string|max:40',
            'organization_contact_email' => 'nullable|email|max:190',
            'organization_email' => 'nullable|email|max:190',
            'organization_billing_address' => 'nullable|string|max:255',
            'organization_nif' => 'nullable|string|max:80',
            'organization_stat' => 'nullable|string|max:80',
            'organization_tax_id' => 'nullable|string|max:80',
            'checked_in_by_name' => 'nullable|string|max:120',
            'checked_in_by_role' => 'nullable|string|in:admin,receptionist,superadmin',
            'room_checkins' => 'nullable|array',
            'room_checkins.*.room_id' => 'required_with:room_checkins|integer|exists:rooms,id',
            'room_checkins.*.occupant_name' => 'nullable|string|max:255',
            'room_checkins.*.occupant_phone' => 'nullable|string|max:50',
            'room_checkins.*.occupant_email' => 'nullable|email|max:190',
            'room_checkins.*.occupant_date_of_birth' => 'nullable|date',
            'room_checkins.*.occupant_sex' => 'nullable|in:Homme,Femme,Autre',
            'room_checkins.*.occupant_id_type' => 'nullable|string|max:40',
            'room_checkins.*.occupant_id_number' => 'nullable|string|max:100',
            'room_checkins.*.occupant_passport_valid_from' => 'nullable|date',
            'room_checkins.*.occupant_passport_valid_until' => 'nullable|date',
        ])->validate();
        $result = DB::transaction(function () use ($reservation, $validated, $isOrganizationReservation) {
            $baseLoyaltyCount = (int) ($validated['loyalty_count'] ?? $reservation->guest?->loyalty_count ?? 0);
            $customerPhone = PhoneNumber::normalize($validated['customer_phone'] ?? $reservation->customer_phone ?? $reservation->client_phone ?? null);
            $phoneNumber = PhoneNumber::normalize($validated['phone_number'] ?? $customerPhone ?? null);
            $clientName = $validated['full_name'];

            if (($reservation->booking_type ?? '') === 'organization') {
                $organization = $reservation->organization ?: new Organization();
                $organizationPhone = PhoneNumber::normalize($validated['organization_phone'] ?? $organization->phone ?? null);
                $organizationContactPhone = PhoneNumber::normalize(
                    $validated['organization_contact_phone'] ?? $organization->contact_phone ?? null
                );
                $organization->fill([
                    'name' => $validated['organization_name'] ?? $reservation->client_name,
                    'phone' => $organizationPhone,
                    'contact_name' => $validated['organization_contact_name'] ?? $organization->contact_name,
                    'contact_phone' => $organizationContactPhone,
                    'contact_email' => $validated['organization_contact_email'] ?? $organization->contact_email,
                    'email' => $validated['organization_email'] ?? $organization->email,
                    'billing_address' => $validated['organization_billing_address'] ?? $organization->billing_address,
                    'nif' => $validated['organization_nif'] ?? $validated['organization_tax_id'] ?? $organization->nif ?? $organization->tax_id,
                    'stat' => $validated['organization_stat'] ?? $organization->stat,
                    'tax_id' => $validated['organization_tax_id'] ?? $validated['organization_nif'] ?? $organization->tax_id ?? $organization->nif,
                ]);
                $organization->save();
                $reservation->organization_id = $organization->id;
                $clientName = $organization->name;
            }

            $reservation->update([
                'client_name' => $clientName,
                'customer_phone' => $customerPhone,
                'client_phone' => $customerPhone ?? $reservation->client_phone,
                'status' => 'arrive',
            ]);

            $guest = Guest::query()->firstOrNew([
                'reservation_id' => $reservation->id,
            ]);
            $guest->fill([
                'first_name' => $validated['first_name'] ?? null,
                'last_name' => $validated['last_name'] ?? null,
                'phone_number' => $phoneNumber,
                'id_document_number' => $validated['id_document_number'] ?? $validated['id_number'],
                'loyalty_count' => $baseLoyaltyCount,
                'full_name' => $validated['full_name'],
                'date_of_birth' => $validated['date_of_birth'],
                'sex' => $validated['sex'],
                'passport_valid_from' => $validated['passport_valid_from'] ?? null,
                'passport_valid_until' => $validated['passport_valid_until'] ?? null,
                'id_type' => $validated['id_type'],
                'id_number' => $validated['id_number'],
            ]);
            $guest->reservation_id = $reservation->id;
            $guest->save();

            $roomCheckins = collect($validated['room_checkins'] ?? []);
            if ($roomCheckins->isNotEmpty()) {
                foreach ($roomCheckins as $roomCheckin) {
                    $roomIdType = (string) ($roomCheckin['occupant_id_type'] ?? $validated['id_type']);
                    $roomNeedsValidity = in_array($roomIdType, ['Passeport', 'Carte de séjour', 'Autre'], true);
                    if ($roomNeedsValidity && (
                        empty($roomCheckin['occupant_passport_valid_from'] ?? null)
                        || empty($roomCheckin['occupant_passport_valid_until'] ?? null)
                    )) {
                        throw ValidationException::withMessages([
                            'room_checkins' => 'Veuillez renseigner la validité du document pour chaque chambre concernée.',
                        ]);
                    }

                    $reservation->rooms()->updateExistingPivot((int) $roomCheckin['room_id'], [
                        'occupant_name' => $roomCheckin['occupant_name'] ?? $validated['full_name'],
                        'occupant_phone' => $isOrganizationReservation
                            ? ($roomCheckin['occupant_phone'] ?? null)
                            : ($roomCheckin['occupant_phone'] ?? $phoneNumber),
                        'occupant_email' => $isOrganizationReservation
                            ? ($roomCheckin['occupant_email'] ?? null)
                            : ($roomCheckin['occupant_email'] ?? $validated['customer_email'] ?? null),
                        'occupant_date_of_birth' => $roomCheckin['occupant_date_of_birth'] ?? $validated['date_of_birth'],
                        'occupant_sex' => $roomCheckin['occupant_sex'] ?? $validated['sex'],
                        'occupant_id_type' => $roomCheckin['occupant_id_type'] ?? $validated['id_type'],
                        'occupant_id_number' => $roomCheckin['occupant_id_number'] ?? $validated['id_number'],
                        'occupant_passport_valid_from' => $roomCheckin['occupant_passport_valid_from'] ?? null,
                        'occupant_passport_valid_until' => $roomCheckin['occupant_passport_valid_until'] ?? null,
                        'checked_in_at' => now(),
                        'checked_in_by_name' => $validated['checked_in_by_name'] ?? null,
                        'checked_in_by_role' => $validated['checked_in_by_role'] ?? null,
                    ]);
                }
            } else {
                foreach ($reservation->rooms as $room) {
                    $reservation->rooms()->updateExistingPivot($room->id, [
                        'occupant_name' => $validated['full_name'],
                        'occupant_phone' => $isOrganizationReservation ? null : $phoneNumber,
                        'occupant_email' => $isOrganizationReservation ? null : ($validated['customer_email'] ?? null),
                        'occupant_date_of_birth' => $validated['date_of_birth'],
                        'occupant_sex' => $validated['sex'],
                        'occupant_id_type' => $validated['id_type'],
                        'occupant_id_number' => $validated['id_number'],
                        'occupant_passport_valid_from' => $validated['passport_valid_from'] ?? null,
                        'occupant_passport_valid_until' => $validated['passport_valid_until'] ?? null,
                        'checked_in_at' => now(),
                        'checked_in_by_name' => $validated['checked_in_by_name'] ?? null,
                        'checked_in_by_role' => $validated['checked_in_by_role'] ?? null,
                    ]);
                }
            }

            ReservationAudit::create([
                'reservation_id' => $reservation->id,
                'action' => 'check_in',
                'actor_name' => $validated['checked_in_by_name'] ?? null,
                'actor_role' => $validated['checked_in_by_role'] ?? null,
                'details' => [
                    'guest_name' => $validated['full_name'],
                    'phone_number' => $phoneNumber,
                    'id_type' => $validated['id_type'],
                ],
            ]);

            return [
                'guest' => $guest,
                'reservation' => $reservation->refresh()->load('rooms', 'guest'),
            ];
        });

        $this->availabilityService->invalidateCaches();

        return response()->json([
            'message' => 'Check-in réussi',
            ...$result,
        ]);
    }

    public function getFolio(int $id): JsonResponse
    {
        $reservation = Reservation::with(['rooms', 'guest', 'invoice.items', 'invoice.payments', 'invoices.items', 'invoices.payments'])->findOrFail($id);

        $invoice = $this->resolveFolioInvoice($reservation, request()->integer('invoice_id') ?: null);

        return response()->json($this->folioPayload($invoice));
    }

    public function manualCheckout(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'checked_out_by_name' => 'nullable|string|max:120',
            'checked_out_by_role' => 'nullable|string|in:admin,receptionist,superadmin',
        ]);

        $result = DB::transaction(function () use ($validated, $id) {
            $reservation = Reservation::query()
                ->with(['invoice', 'audits'])
                ->lockForUpdate()
                ->findOrFail($id);

            if ($reservation->status !== 'arrive') {
                throw ValidationException::withMessages([
                    'reservation' => 'Le check-out manuel est disponible uniquement après le check-in.',
                ]);
            }

            $reservation->update([
                'status' => Reservation::MANUAL_CHECKOUT_STATUS,
            ]);

            ReservationAudit::create([
                'reservation_id' => $reservation->id,
                'action' => 'manual_check_out',
                'actor_name' => $validated['checked_out_by_name'] ?? null,
                'actor_role' => $validated['checked_out_by_role'] ?? null,
                'details' => [
                    'status' => Reservation::MANUAL_CHECKOUT_STATUS,
                ],
            ]);

            return [
                'reservation' => [
                    'id' => $reservation->id,
                    'status' => Reservation::MANUAL_CHECKOUT_STATUS,
                    'checked_out_by_name' => $validated['checked_out_by_name'] ?? null,
                    'checked_out_by_role' => $validated['checked_out_by_role'] ?? null,
                    'checked_out_at' => now()->toDateTimeString(),
                ],
            ];
        });

        $this->availabilityService->invalidateCaches();

        return response()->json([
            'message' => 'Check-out manuel enregistré',
            ...$result,
        ]);
    }

    public function addInvoiceItem(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'type' => 'required|in:room,extra,deposit',
            'amount_ariary' => 'required|integer|min:0',
            'quantity' => 'required|integer|min:1',
            'booking_room_id' => 'nullable|integer|exists:booking_room,id',
            'actor_name' => 'nullable|string|max:120',
            'actor_role' => 'nullable|string|in:admin,receptionist,superadmin',
        ]);

        $invoice = Invoice::findOrFail($id);
        if ($invoice->status === 'finalized') {
            return response()->json(['message' => 'Facture finalisée, ajout impossible.'], 400);
        }

        $bookingRoomId = $validated['booking_room_id'] ?? $invoice->booking_room_id ?? null;
        $description = $validated['description'];
        if ($validated['type'] === 'extra' && $bookingRoomId) {
            $reservation = $invoice->reservation()->with('rooms')->first();
            if ($reservation) {
                $room = $reservation->rooms
                    ->first(fn (Room $room) => (int) ($room->pivot->id ?? 0) === (int) $bookingRoomId);
                if ($room instanceof Room) {
                    $description = $this->segmentExtraDescription($description, $room, $reservation);
                }
            }
        }

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'booking_room_id' => $bookingRoomId,
            'description' => $description,
            'type' => $validated['type'],
            'amount_ariary' => $validated['amount_ariary'],
            'quantity' => $validated['quantity'],
            'created_by_name' => $validated['actor_name'] ?? null,
            'created_by_role' => $validated['actor_role'] ?? null,
        ]);

        $this->recalculateInvoice($invoice);
        $this->invalidateInvoiceDocumentArtifacts($invoice->refresh());

        return response()->json([
            'message' => 'Ligne ajoutée avec succès',
            'invoice' => $this->folioPayload($invoice->refresh()),
        ]);
    }

    public function updateInvoiceItem(Request $request, int $id, int $itemId): JsonResponse
    {
        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'type' => 'required|in:room,extra,deposit',
            'amount_ariary' => 'required|integer|min:0',
            'quantity' => 'required|integer|min:1',
            'booking_room_id' => 'nullable|integer|exists:booking_room,id',
            'actor_name' => 'nullable|string|max:120',
            'actor_role' => 'required|string|in:admin,receptionist,superadmin',
        ]);

        $invoice = Invoice::findOrFail($id);
        $item = $invoice->items()->findOrFail($itemId);
        $this->assertInvoiceItemModificationAllowed($invoice, $item, $validated['actor_role']);
        $before = $this->invoiceItemAuditPayload($item);

        $bookingRoomId = $validated['booking_room_id'] ?? $item->booking_room_id ?? $invoice->booking_room_id ?? null;
        $description = $validated['description'];
        if ($validated['type'] === 'extra' && $bookingRoomId) {
            $reservation = $invoice->reservation()->with('rooms')->first();
            if ($reservation) {
                $room = $reservation->rooms
                    ->first(fn (Room $room) => (int) ($room->pivot->id ?? 0) === (int) $bookingRoomId);
                if ($room instanceof Room) {
                    $description = $this->segmentExtraDescription(
                        $this->baseExtraDescription($description),
                        $room,
                        $reservation,
                    );
                }
            }
        }

        $item->update([
            'booking_room_id' => $bookingRoomId,
            'description' => $description,
            'type' => $validated['type'],
            'amount_ariary' => $validated['amount_ariary'],
            'quantity' => $validated['quantity'],
            'updated_by_name' => $validated['actor_name'] ?? null,
            'updated_by_role' => $validated['actor_role'],
            'manual_override_at' => now(),
        ]);

        $this->recordInvoiceItemAudit(
            $invoice->refresh(),
            'invoice_item_updated',
            $validated['actor_name'] ?? null,
            $validated['actor_role'],
            $before,
            $this->invoiceItemAuditPayload($item->refresh()),
        );

        $this->recalculateInvoice($invoice);
        $this->invalidateInvoiceDocumentArtifacts($invoice->refresh());

        return response()->json([
            'message' => 'Ligne modifiée avec succès',
            'invoice' => $this->folioPayload($invoice->refresh()),
        ]);
    }

    public function deleteInvoiceItem(Request $request, int $id, int $itemId): JsonResponse
    {
        $validated = $request->validate([
            'actor_name' => 'nullable|string|max:120',
            'actor_role' => 'required|string|in:admin,receptionist,superadmin',
        ]);

        $invoice = Invoice::findOrFail($id);
        $item = $invoice->items()->findOrFail($itemId);
        $this->assertInvoiceItemModificationAllowed($invoice, $item, $validated['actor_role']);
        $before = $this->invoiceItemAuditPayload($item);

        $item->update([
            'updated_by_name' => $validated['actor_name'] ?? null,
            'updated_by_role' => $validated['actor_role'],
            'manual_override_at' => now(),
        ]);
        $item->delete();

        $this->recordInvoiceItemAudit(
            $invoice->refresh(),
            'invoice_item_deleted',
            $validated['actor_name'] ?? null,
            $validated['actor_role'],
            $before,
            null,
        );

        $this->recalculateInvoice($invoice);
        $this->invalidateInvoiceDocumentArtifacts($invoice->refresh());

        return response()->json([
            'message' => 'Ligne supprimée avec succès',
            'invoice' => $this->folioPayload($invoice->refresh()),
        ]);
    }

    public function addPayment(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'amount_ariary' => 'required|integer|min:1',
            'payment_method' => 'required|string|in:Espèces,Carte Bancaire,Mobile Money,Chèque,Virement',
            'payment_operator' => 'nullable|string|in:mvola,orange money,airtel money',
            'reference' => 'nullable|string|max:120',
            'processed_by_name' => 'nullable|string|max:120',
            'processed_by_role' => 'nullable|string|in:admin,receptionist,superadmin',
        ]);

        $result = DB::transaction(function () use ($validated, $id) {
            $invoice = Invoice::with(['payments', 'reservation.audits', 'reservation.guest'])->lockForUpdate()->findOrFail($id);
            $previousStatus = $invoice->status;

            if ($invoice->status === 'finalized') {
                throw ValidationException::withMessages([
                    'invoice' => 'Facture finalisée, paiement impossible.',
                ]);
            }

            $remainingAmount = max(0, (int) $invoice->balance_amount_ariary);
            if ($remainingAmount <= 0) {
                throw ValidationException::withMessages([
                    'amount_ariary' => 'Cette facture est déjà soldée.',
                ]);
            }

            $amounts = $this->normalizePaymentAmounts(
                (int) $validated['amount_ariary'],
                $remainingAmount,
                $validated['payment_method'],
            );

            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'amount_ariary' => $amounts['applied_amount_ariary'],
                'amount_received_ariary' => $amounts['received_amount_ariary'],
                'change_given_ariary' => $amounts['change_given_ariary'],
                'payment_method' => $validated['payment_method'],
                'payment_operator' => $validated['payment_operator'] ?? null,
                'payment_context' => 'payment',
                'reference' => $validated['reference'] ?? null,
                'processed_by_name' => $validated['processed_by_name'] ?? null,
                'processed_by_role' => $validated['processed_by_role'] ?? null,
            ]);

            ReservationAudit::create([
                'reservation_id' => $invoice->reservation_id,
                'action' => 'payment',
                'actor_name' => $validated['processed_by_name'] ?? null,
                'actor_role' => $validated['processed_by_role'] ?? null,
                'details' => [
                    'amount_received_ariary' => $amounts['received_amount_ariary'],
                    'amount_ariary' => $amounts['applied_amount_ariary'],
                    'change_given_ariary' => $amounts['change_given_ariary'],
                    'payment_method' => $validated['payment_method'],
                    'payment_operator' => $validated['payment_operator'] ?? null,
                    'reference' => $validated['reference'] ?? null,
                ],
            ]);

            $invoice = $this->syncInvoiceAfterPayment($invoice);

            if ($previousStatus !== 'paid' && $invoice->status === 'paid') {
                $guest = $invoice->reservation?->guest;
                if ($guest) {
                    $guest->increment('loyalty_count');
                }
            }

            return [
                'payment' => $payment,
                'invoice' => $this->folioPayload($invoice),
            ];
        });

        $this->availabilityService->invalidateCaches();

        return response()->json([
            'message' => 'Paiement enregistré',
            ...$result,
        ]);
    }

    public function addDeposit(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'amount_ariary' => 'required|integer|min:1',
            'payment_method' => 'required|string|in:Espèces,Carte Bancaire,Mobile Money,Chèque,Virement',
            'payment_operator' => 'nullable|string|in:mvola,orange money,airtel money',
            'reference' => 'nullable|string|max:120',
            'processed_by_name' => 'nullable|string|max:120',
            'processed_by_role' => 'nullable|string|in:admin,receptionist,superadmin',
        ]);

        $result = DB::transaction(function () use ($validated, $id) {
            $reservation = Reservation::with(['invoice', 'guest'])->lockForUpdate()->findOrFail($id);
            $invoice = $reservation->invoice ?: $this->ensureOpenFolio($reservation);
            $previousStatus = $invoice->status;

            $remainingAmount = max(0, (int) $invoice->balance_amount_ariary);
            if ($remainingAmount <= 0) {
                throw ValidationException::withMessages([
                    'amount_ariary' => 'Cette facture est déjà soldée.',
                ]);
            }

            $amounts = $this->normalizePaymentAmounts(
                (int) $validated['amount_ariary'],
                $remainingAmount,
                $validated['payment_method'],
            );

            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'amount_ariary' => $amounts['applied_amount_ariary'],
                'amount_received_ariary' => $amounts['received_amount_ariary'],
                'change_given_ariary' => $amounts['change_given_ariary'],
                'payment_method' => $validated['payment_method'],
                'payment_operator' => $validated['payment_operator'] ?? null,
                'payment_context' => 'deposit',
                'reference' => $validated['reference'] ?? null,
                'processed_by_name' => $validated['processed_by_name'] ?? null,
                'processed_by_role' => $validated['processed_by_role'] ?? null,
            ]);

            ReservationAudit::create([
                'reservation_id' => $reservation->id,
                'action' => 'deposit',
                'actor_name' => $validated['processed_by_name'] ?? null,
                'actor_role' => $validated['processed_by_role'] ?? null,
                'details' => [
                    'amount_received_ariary' => $amounts['received_amount_ariary'],
                    'amount_ariary' => $amounts['applied_amount_ariary'],
                    'change_given_ariary' => $amounts['change_given_ariary'],
                    'payment_method' => $validated['payment_method'],
                    'payment_operator' => $validated['payment_operator'] ?? null,
                    'reference' => $validated['reference'] ?? null,
                ],
            ]);

            $invoice = $this->syncInvoiceAfterDeposit($invoice);

            if ($previousStatus !== 'paid' && $invoice->status === 'paid') {
                $guest = $invoice->reservation?->guest;
                if ($guest) {
                    $guest->increment('loyalty_count');
                }
            }

            return [
                'payment' => $payment,
                'invoice' => [
                    'id' => $invoice->id,
                    'status' => $invoice->status,
                    'deposit_amount_ariary' => (int) $invoice->deposit_amount_ariary,
                    'paid_amount_ariary' => (int) $invoice->paid_amount_ariary,
                    'balance_amount_ariary' => (int) $invoice->balance_amount_ariary,
                ],
            ];
        });

        $this->availabilityService->invalidateCaches();

        return response()->json([
            'message' => 'Acompte enregistré',
            ...$result,
        ]);
    }

    public function updatePayment(Request $request, int $id, int $paymentId): JsonResponse
    {
        $validated = $request->validate([
            'amount_ariary' => 'required|integer|min:1',
            'payment_method' => 'required|string|in:Espèces,Carte Bancaire,Mobile Money,Chèque,Virement',
            'payment_operator' => 'nullable|string|in:mvola,orange money,airtel money',
            'reference' => 'nullable|string|max:120',
            'processed_by_name' => 'nullable|string|max:120',
            'processed_by_role' => 'nullable|string|in:admin,receptionist,superadmin',
        ]);

        $result = DB::transaction(function () use ($validated, $id, $paymentId) {
            $invoice = Invoice::with(['payments', 'reservation.audits', 'reservation.guest'])
                ->lockForUpdate()
                ->findOrFail($id);

            if ($invoice->status === 'finalized') {
                throw ValidationException::withMessages([
                    'invoice' => 'Facture finalisée, modification impossible.',
                ]);
            }

            $payment = $invoice->payments->firstWhere('id', $paymentId)
                ?? Payment::query()
                    ->where('invoice_id', $invoice->id)
                    ->lockForUpdate()
                    ->findOrFail($paymentId);

            $actorRole = $validated['processed_by_role'] ?? 'receptionist';
            $this->assertPaymentModificationAllowed($invoice->reservation, $actorRole);

            $otherPaymentsTotal = (int) $invoice->payments
                ->where('id', '!=', $payment->id)
                ->sum('amount_ariary');
            $remainingAmountForPayment = max(0, (int) $invoice->total_amount_ariary - $otherPaymentsTotal);

            $amounts = $this->normalizePaymentAmounts(
                (int) $validated['amount_ariary'],
                $remainingAmountForPayment,
                $validated['payment_method'],
            );

            $before = [
                'amount_received_ariary' => (int) ($payment->amount_received_ariary ?? $payment->amount_ariary),
                'amount_ariary' => (int) $payment->amount_ariary,
                'change_given_ariary' => (int) ($payment->change_given_ariary ?? 0),
                'payment_method' => $payment->payment_method,
                'payment_operator' => $payment->payment_operator,
                'reference' => $payment->reference,
            ];

            $payment->update([
                'amount_ariary' => $amounts['applied_amount_ariary'],
                'amount_received_ariary' => $amounts['received_amount_ariary'],
                'change_given_ariary' => $amounts['change_given_ariary'],
                'payment_method' => $validated['payment_method'],
                'payment_operator' => $validated['payment_operator'] ?? null,
                'reference' => $validated['reference'] ?? null,
                'processed_by_name' => $validated['processed_by_name'] ?? $payment->processed_by_name,
                'processed_by_role' => $validated['processed_by_role'] ?? $payment->processed_by_role,
            ]);

            ReservationAudit::create([
                'reservation_id' => $invoice->reservation_id,
                'action' => 'payment_modified',
                'actor_name' => $validated['processed_by_name'] ?? null,
                'actor_role' => $validated['processed_by_role'] ?? null,
                'details' => [
                    'payment_id' => $payment->id,
                    'before' => $before,
                    'after' => [
                        'amount_received_ariary' => $amounts['received_amount_ariary'],
                        'amount_ariary' => $amounts['applied_amount_ariary'],
                        'change_given_ariary' => $amounts['change_given_ariary'],
                        'payment_method' => $validated['payment_method'],
                        'payment_operator' => $validated['payment_operator'] ?? null,
                        'reference' => $validated['reference'] ?? null,
                    ],
                ],
            ]);

            $invoice = $this->syncInvoiceAfterPayment($invoice->refresh());

            return [
                'payment' => $this->paymentPayload($payment->refresh()),
                'invoice' => $this->folioPayload($invoice),
            ];
        });

        $this->availabilityService->invalidateCaches();

        return response()->json([
            'message' => 'Paiement modifié',
            ...$result,
        ]);
    }

    public function generatePdf(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'pricing_mode' => 'nullable|in:fixed,ai',
            'discount_mode' => 'nullable|in:percent,amount',
            'discount_value' => 'nullable|numeric|min:0',
            'actor_role' => 'nullable|string|in:admin,receptionist,superadmin',
            'document_type' => 'nullable|in:facture,proforma',
            'billing_mode' => 'nullable|in:grouped,individual',
            'currency_mode' => 'nullable|in:ariary,euro',
        ]);
        $documentType = $validated['document_type'] ?? 'facture';
        $currencyMode = $validated['currency_mode'] ?? 'ariary';

        if (
            ($validated['discount_mode'] ?? null) !== null
            || ($validated['discount_value'] ?? null) !== null
        ) {
            if (!in_array($validated['actor_role'] ?? 'receptionist', ['admin', 'superadmin'], true)) {
                $validated['discount_mode'] = null;
                $validated['discount_value'] = null;
            }
        }

        $invoice = Invoice::with(['items', 'payments', 'reservation.guest', 'reservation.rooms'])->findOrFail($id);
        if ($currencyMode === 'euro' && $invoice->reservation?->source !== 'Booking') {
            return response()->json([
                'message' => 'La facture en euro est réservée aux réservations Booking.',
            ], 422);
        }

        DB::transaction(function () use ($invoice, $validated, $documentType) {
            $invoice->refresh();
            $reservation = $invoice->reservation()->lockForUpdate()->first();
            $invoice->refresh();
            if (!$invoice->invoice_number) {
                $invoice->invoice_number = $this->nextInvoiceNumber();
                $invoice->save();
            }

            if ($reservation && array_key_exists('billing_mode', $validated)) {
                $newBillingMode = $validated['billing_mode'] === 'individual' ? 'per_room' : 'grouped';
                $reservation->update([
                    'billing_mode' => $newBillingMode,
                ]);
                $invoice->update(['billing_mode' => $newBillingMode]);
                $reservation = $reservation->refresh()->loadMissing('rooms');
            }

            if ($invoice->status !== 'finalized' && $invoice->reservation) {
                $this->syncInvoiceForReservation($invoice, $invoice->reservation);
            }

            if ($reservation && ($reservation->billing_mode ?? 'grouped') === 'per_room') {
                $this->syncRoomInvoicesForReservation($reservation->refresh()->load('rooms'), $invoice->refresh());
            }

            $invoice->update([
                'document_type' => $documentType,
            ]);
            $this->applyInvoiceDiscount($invoice, $validated['discount_mode'] ?? null, $validated['discount_value'] ?? null);
        });

        $this->ensureInvoicePdf(
            $invoice->refresh()->load(['items', 'payments', 'reservation.guest', 'reservation.rooms']),
            $documentType,
            $currencyMode,
        );

        return response()->json([
            'message' => 'Facture générée avec succès',
            'invoice' => $this->folioPayload($invoice->refresh()),
            'pdf_url' => url("/api/invoices/{$invoice->id}/pdf"),
        ]);
    }

    public function downloadPdf(int $id): StreamedResponse
    {
        $invoice = Invoice::findOrFail($id);
        abort_unless($invoice->pdf_path && Storage::disk('local')->exists($invoice->pdf_path), 404);

        return Storage::disk('local')->download(
            $invoice->pdf_path,
            ($invoice->invoice_number ?? 'facture') . '.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    public function sendInvoiceEmail(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:190',
        ]);

        $invoice = Invoice::with('reservation.guest')->findOrFail($id);
        abort_unless($invoice->pdf_path && Storage::disk('local')->exists($invoice->pdf_path), 404);

        Mail::raw(
            "Bonjour,\n\nVeuillez trouver ci-joint votre facture {$invoice->invoice_number}.\n\nCordialement,\nKamoro Hotel",
            function ($message) use ($validated, $invoice) {
                $message
                    ->to($validated['email'])
                    ->subject("Facture {$invoice->invoice_number}")
                    ->attachData(
                        Storage::disk('local')->get($invoice->pdf_path),
                        "{$invoice->invoice_number}.pdf",
                        ['mime' => 'application/pdf'],
                    );
            },
        );

        return response()->json(['message' => 'Facture envoyée par email']);
    }

    private function ensureOpenFolio(Reservation $reservation): Invoice
    {
        $invoice = Invoice::firstOrCreate(
            ['reservation_id' => $reservation->id, 'invoice_kind' => 'master'],
            [
                'status' => 'open',
                'billing_mode' => $reservation->billing_mode ?? 'grouped',
                'organization_id' => $reservation->organization_id,
            ],
        );

        if ($invoice->items()->where('type', 'room')->doesntExist()) {
            $this->seedRoomItems($invoice, $reservation);
        }

        if ($invoice->status !== 'finalized') {
            $this->syncReservationExtras($invoice, $reservation);
        }

        $this->syncRoomItemDescriptions($invoice, $reservation);

        if (($reservation->billing_mode ?? 'grouped') === 'per_room') {
            $this->syncRoomInvoicesForReservation($reservation, $invoice);
        }

        $this->recalculateInvoice($invoice);

        return $invoice->refresh()->load(['items', 'payments', 'reservation.guest']);
    }

    private function ensureRoomInvoices(Reservation $reservation, Invoice $masterInvoice): void
    {
        $reservation->loadMissing('rooms');

        foreach ($reservation->rooms as $room) {
            $roomBookingId = $room->pivot->id ?? null;
            if (!$roomBookingId) {
                continue;
            }

            $childInvoice = Invoice::firstOrCreate(
                [
                    'reservation_id' => $reservation->id,
                    'invoice_kind' => 'room',
                    'booking_room_id' => $roomBookingId,
                ],
                [
                    'status' => 'open',
                    'billing_mode' => 'per_room',
                    'organization_id' => $reservation->organization_id,
                    'parent_invoice_id' => $masterInvoice->id,
                ],
            );

            $childInvoice->update([
                'billing_mode' => 'per_room',
                'organization_id' => $reservation->organization_id,
                'parent_invoice_id' => $masterInvoice->id,
            ]);

            if ($childInvoice->items()->where('type', 'room')->doesntExist()) {
                $nights = $this->bookingRoomNights($room, $reservation);
                $pricePerNight = (int) ($room->pivot->price_snapshot_ariary ?? $room->base_price_ariary);

                InvoiceItem::create([
                    'invoice_id' => $childInvoice->id,
                    'booking_room_id' => $roomBookingId,
                    'description' => $this->roomInvoiceDescription($room, $nights),
                    'type' => 'room',
                    'amount_ariary' => $pricePerNight,
                    'quantity' => $nights,
                ]);
            }

            $this->syncRoomSpecificExtras($childInvoice, $reservation, $room);
            $this->recalculateInvoice($childInvoice->refresh());
        }
    }

    private function syncInvoiceForReservation(Invoice $invoice, Reservation $reservation): void
    {
        if ($invoice->status === 'finalized') {
            return;
        }

        if (($invoice->invoice_kind ?? 'master') === 'master') {
            $this->seedRoomItems($invoice, $reservation);
            $this->syncReservationExtras($invoice, $reservation);
            $this->syncRoomItemDescriptions($invoice, $reservation);
            $this->recalculateInvoice($invoice->refresh());
            return;
        }

        if (($invoice->invoice_kind ?? 'master') === 'room') {
            $reservation->loadMissing('rooms');
            $room = $reservation->rooms
                ->first(fn (Room $room) => (int) ($room->pivot->id ?? 0) === (int) $invoice->booking_room_id);

            if ($room instanceof Room) {
                $this->syncGeneratedRoomItem($invoice, $reservation, $room);
                $this->syncRoomSpecificExtras($invoice, $reservation, $room);
                $this->recalculateInvoice($invoice->refresh());
                return;
            }
        }

        $this->syncRoomItemDescriptions($invoice, $reservation);
        if (($reservation->billing_mode ?? 'grouped') === 'per_room') {
            $this->recalculateInvoice($invoice->refresh());
        }
    }

    private function syncRoomInvoicesForReservation(Reservation $reservation, Invoice $masterInvoice): void
    {
        $reservation->loadMissing('rooms');

        foreach ($reservation->rooms as $room) {
            $roomBookingId = $room->pivot->id ?? null;
            if (!$roomBookingId) {
                continue;
            }

            $childInvoice = $this->resolveRoomInvoiceForBooking($reservation, $masterInvoice, $room);

            if (!$childInvoice) {
                $childInvoice = Invoice::create([
                    'reservation_id' => $reservation->id,
                    'invoice_kind' => 'room',
                    'booking_room_id' => $roomBookingId,
                    'status' => 'open',
                    'billing_mode' => 'per_room',
                    'organization_id' => $reservation->organization_id,
                    'parent_invoice_id' => $masterInvoice->id,
                ]);
            }

            $childInvoice->update([
                'billing_mode' => 'per_room',
                'organization_id' => $reservation->organization_id,
                'parent_invoice_id' => $masterInvoice->id,
                'booking_room_id' => $roomBookingId,
            ]);
            $reservation->rooms()->updateExistingPivot($room->id, [
                'invoice_id' => $childInvoice->id,
            ]);

            $this->syncGeneratedRoomItem($childInvoice, $reservation, $room);
            $this->syncRoomSpecificExtras($childInvoice, $reservation, $room);
            $this->recalculateInvoice($childInvoice->refresh());
        }
    }

    private function seedRoomItems(Invoice $invoice, Reservation $reservation): void
    {
        foreach ($reservation->rooms as $room) {
            $this->syncGeneratedRoomItem($invoice, $reservation, $room);
        }
    }

    private function syncReservationExtras(Invoice $invoice, Reservation $reservation): void
    {
        $reservation->loadMissing('rooms');

        $hasSegmentExtras = $reservation->rooms->contains(function (Room $room) {
            return (int) ($room->pivot->segment_extra_beds ?? 0) > 0
                || (int) ($room->pivot->segment_extra_mattresses ?? 0) > 0;
        });

        if ($hasSegmentExtras) {
            foreach ($reservation->rooms as $room) {
                $this->syncRoomSpecificExtras($invoice, $reservation, $room);
            }
            return;
        }

        $nights = $this->reservationNights($reservation);
        $this->syncGeneratedExtraItem(
            $invoice,
            null,
            'Lit supplémentaire',
            self::EXTRA_BED_PRICE_ARIARY,
            (int) $reservation->extra_beds * $nights,
        );
        $this->syncGeneratedExtraItem(
            $invoice,
            null,
            'Matelas supplémentaire',
            self::EXTRA_MATTRESS_PRICE_ARIARY,
            (int) $reservation->extra_mattresses * $nights,
        );
    }

    private function syncRoomItemDescriptions(Invoice $invoice, Reservation $reservation): void
    {
        $reservation->loadMissing('rooms');
        $roomsByBookingId = $reservation->rooms->keyBy(fn (Room $room) => $room->pivot->id ?? $room->id);

        foreach ($invoice->items()->where('type', 'room')->whereNull('manual_override_at')->get() as $item) {
            $bookingRoomId = $item->booking_room_id;
            $room = $bookingRoomId ? $roomsByBookingId->get($bookingRoomId) : null;
            if (!$room instanceof Room) {
                continue;
            }

            $item->update([
                'booking_room_id' => $room->pivot->id ?? $item->booking_room_id,
                'description' => $this->roomInvoiceDescription($room, $this->bookingRoomNights($room, $reservation)),
            ]);
        }
    }

    private function roomInvoiceDescription(Room $room, int $nights): string
    {
        return sprintf(
            'Chambre %s (%s) - %d %s',
            $room->room_number,
            $this->roomClassificationLabel($room),
            $nights,
            $nights > 1 ? 'nuits' : 'nuit',
        );
    }

    private function segmentExtraDescription(string $label, Room $room, Reservation $reservation): string
    {
        return $label . ' - ' . $this->roomInvoiceDescription($room, $this->bookingRoomNights($room, $reservation));
    }

    private function roomClassificationLabel(Room $room): string
    {
        $type = trim((string) $room->type);
        $model = trim((string) $room->model);

        if ($type !== '') {
            $type = preg_replace('/^chambre\s+/i', '', $type) ?? $type;
        }

        $parts = array_values(array_filter([$type, $model], fn (string $value) => $value !== ''));

        return $parts ? mb_strtolower(implode(' ', $parts)) : 'standard';
    }

    private function syncRoomSpecificExtras(Invoice $invoice, Reservation $reservation, Room $room): void
    {
        $nights = $this->bookingRoomNights($room, $reservation);
        $beds = (int) ($room->pivot->segment_extra_beds ?? 0);
        $mattresses = (int) ($room->pivot->segment_extra_mattresses ?? 0);
        $bookingRoomId = $room->pivot->id ?? null;

        $this->syncGeneratedExtraItem(
            $invoice,
            $bookingRoomId,
            $this->segmentExtraDescription('Lit supplémentaire', $room, $reservation),
            self::EXTRA_BED_PRICE_ARIARY,
            $beds * $nights,
        );
        $this->syncGeneratedExtraItem(
            $invoice,
            $bookingRoomId,
            $this->segmentExtraDescription('Matelas supplémentaire', $room, $reservation),
            self::EXTRA_MATTRESS_PRICE_ARIARY,
            $mattresses * $nights,
        );
    }

    private function syncGeneratedRoomItem(Invoice $invoice, Reservation $reservation, Room $room): void
    {
        $bookingRoomId = $room->pivot->id ?? null;
        if (! $bookingRoomId) {
            return;
        }

        $item = $this->generatedInvoiceItemQuery($invoice, 'room', $bookingRoomId)
            ->first();
        $item ??= $this->generatedInvoiceItemQuery($invoice, 'room', null)
            ->first();
        $item ??= InvoiceItem::withTrashed()
            ->where('invoice_id', $invoice->id)
            ->where('type', 'room')
            ->whereNull('created_by_role')
            ->whereNull('manual_override_at')
            ->first();
        if ($item?->manual_override_at) {
            return;
        }
        $item?->restore();

        $nights = $this->bookingRoomNights($room, $reservation);
        $data = [
            'invoice_id' => $invoice->id,
            'booking_room_id' => $bookingRoomId,
            'description' => $this->roomInvoiceDescription($room, $nights),
            'type' => 'room',
            'amount_ariary' => (int) ($room->pivot->price_snapshot_ariary ?? $room->base_price_ariary),
            'quantity' => $nights,
        ];

        $item ? $item->update($data) : InvoiceItem::create($data);
    }

    private function syncGeneratedExtraItem(
        Invoice $invoice,
        ?int $bookingRoomId,
        string $description,
        int $amountAriary,
        int $quantity,
    ): void {
        $item = $this->generatedInvoiceItemQuery($invoice, 'extra', $bookingRoomId, $description)
            ->first();
        if (! $item && $bookingRoomId) {
            $item = InvoiceItem::withTrashed()
                ->where('invoice_id', $invoice->id)
                ->where('type', 'extra')
                ->where('description', $description)
                ->whereNull('created_by_role')
                ->whereNull('manual_override_at')
                ->first();
        }
        if ($item?->manual_override_at) {
            return;
        }

        if ($quantity <= 0) {
            $item?->forceDelete();
            return;
        }
        $item?->restore();

        $data = [
            'invoice_id' => $invoice->id,
            'booking_room_id' => $bookingRoomId,
            'description' => $description,
            'type' => 'extra',
            'amount_ariary' => $amountAriary,
            'quantity' => $quantity,
        ];

        $item ? $item->update($data) : InvoiceItem::create($data);
    }

    private function generatedInvoiceItemQuery(
        Invoice $invoice,
        string $type,
        ?int $bookingRoomId,
        ?string $description = null,
    ) {
        return InvoiceItem::withTrashed()
            ->where('invoice_id', $invoice->id)
            ->where('type', $type)
            ->whereNull('created_by_role')
            ->when(
                $bookingRoomId,
                fn ($query) => $query->where('booking_room_id', $bookingRoomId),
                fn ($query) => $query->whereNull('booking_room_id'),
            )
            ->when($description, fn ($query) => $query->where('description', $description));
    }

    private function resolveRoomInvoiceForBooking(Reservation $reservation, Invoice $masterInvoice, Room $room): ?Invoice
    {
        $roomBookingId = $room->pivot->id ?? null;
        if (! $roomBookingId) {
            return null;
        }

        $query = Invoice::query()
            ->where('reservation_id', $reservation->id)
            ->where('invoice_kind', 'room');

        $childInvoice = (clone $query)
            ->where('booking_room_id', $roomBookingId)
            ->first();

        if (! $childInvoice && $room->pivot->invoice_id) {
            $childInvoice = (clone $query)
                ->whereKey($room->pivot->invoice_id)
                ->first();
        }

        if (! $childInvoice) {
            $childInvoice = (clone $query)
                ->whereHas('items', function ($itemQuery) use ($room) {
                    $itemQuery
                        ->where('type', 'room')
                        ->where('description', 'like', 'Chambre ' . $room->room_number . ' %');
                })
                ->orderBy('id')
                ->first();
        }

        if ($childInvoice) {
            $childInvoice->update([
                'booking_room_id' => $roomBookingId,
                'billing_mode' => 'per_room',
                'organization_id' => $reservation->organization_id,
                'parent_invoice_id' => $masterInvoice->id,
            ]);
        }

        return $childInvoice;
    }

    private function reservationNights(Reservation $reservation): int
    {
        $checkIn = Carbon::parse($reservation->check_in_date);
        $checkOut = Carbon::parse($reservation->check_out_date);

        return max(1, $checkIn->diffInDays($checkOut));
    }

    private function bookingRoomNights(Room $room, Reservation $reservation): int
    {
        $start = $room->pivot->segment_start_date ?? $reservation->check_in_date;
        $end = $room->pivot->segment_end_date ?? $reservation->check_out_date;

        return max(1, Carbon::parse($start)->diffInDays(Carbon::parse($end)));
    }

    private function bookingRoomDateRange(Room $room, Reservation $reservation): string
    {
        $start = Carbon::parse($room->pivot->segment_start_date ?? $reservation->check_in_date)->format('d/m/Y');
        $end = Carbon::parse($room->pivot->segment_end_date ?? $reservation->check_out_date)->format('d/m/Y');

        return "{$start} → {$end}";
    }

    private function hasSegmentedRoomDates(Reservation $reservation): bool
    {
        $reservation->loadMissing('rooms');

        foreach ($reservation->rooms as $room) {
            $segmentStart = optional($room->pivot->segment_start_date)->toDateString();
            $segmentEnd = optional($room->pivot->segment_end_date)->toDateString();

            if (
                ($segmentStart && $segmentStart !== optional($reservation->check_in_date)->toDateString())
                || ($segmentEnd && $segmentEnd !== optional($reservation->check_out_date)->toDateString())
            ) {
                return true;
            }
        }

        return false;
    }

    private function recalculateInvoice(Invoice $invoice): void
    {
        $invoice->loadMissing(['items', 'payments', 'reservation.invoices.items', 'parentInvoice']);

        $subtotal = $this->invoiceSubtotal($invoice);
        $discountAmount = (int) $invoice->discount_amount_ariary;
        $total = max(0, $subtotal - $discountAmount);

        $invoice->update([
            'tax_amount_ariary' => 0,
            'total_amount_ariary' => $total,
        ]);

        $invoice = $invoice->refresh()->load(['payments', 'reservation.invoices.items', 'parentInvoice']);
        $this->updatePaymentStatus($invoice);

        if ($invoice->parentInvoice) {
            $this->recalculateInvoice($invoice->parentInvoice->refresh());
        }
    }

    private function assertInvoiceItemModificationAllowed(Invoice $invoice, InvoiceItem $item, string $actorRole): void
    {
        if ($invoice->status === 'finalized') {
            throw ValidationException::withMessages([
                'invoice' => 'Facture finalisée, modification impossible.',
            ]);
        }

        if (in_array($actorRole, ['admin', 'superadmin'], true)) {
            return;
        }

        if (
            $actorRole === 'receptionist'
            && $item->created_by_role === 'receptionist'
            && $item->created_at
            && $item->created_at->gte(now()->subSeconds(self::RECEPTIONIST_ITEM_EDIT_WINDOW_SECONDS))
        ) {
            return;
        }

        throw new AuthorizationException(
            'Modification réservée à l’administrateur après le délai de 7 secondes.'
        );
    }

    private function recordInvoiceItemAudit(
        Invoice $invoice,
        string $action,
        ?string $actorName,
        string $actorRole,
        ?array $before,
        ?array $after,
    ): void {
        if (! $invoice->reservation_id) {
            return;
        }

        ReservationAudit::create([
            'reservation_id' => $invoice->reservation_id,
            'action' => $action,
            'actor_name' => $actorName,
            'actor_role' => $actorRole,
            'details' => [
                'invoice_id' => $invoice->id,
                'invoice_kind' => $invoice->invoice_kind ?? 'master',
                'booking_room_id' => $invoice->booking_room_id,
                'before' => $before,
                'after' => $after,
            ],
        ]);
    }

    private function invoiceItemAuditPayload(InvoiceItem $item): array
    {
        return [
            'id' => $item->id,
            'invoice_id' => $item->invoice_id,
            'booking_room_id' => $item->booking_room_id,
            'description' => $item->description,
            'type' => $item->type,
            'amount_ariary' => (int) $item->amount_ariary,
            'quantity' => (int) $item->quantity,
            'line_total_ariary' => (int) $item->amount_ariary * (int) $item->quantity,
            'created_by_name' => $item->created_by_name,
            'created_by_role' => $item->created_by_role,
            'updated_by_name' => $item->updated_by_name,
            'updated_by_role' => $item->updated_by_role,
            'manual_override_at' => optional($item->manual_override_at)->toDateTimeString(),
        ];
    }

    private function baseExtraDescription(string $description): string
    {
        return trim(preg_replace('/\s+-\s+Chambre\s+.+$/iu', '', $description) ?? $description);
    }

    private function updatePaymentStatus(Invoice $invoice): void
    {
        if ($invoice->status === 'finalized') {
            return;
        }

        $paid = (int) $invoice->payments()->sum('amount_ariary');
        $status = match (true) {
            $paid <= 0 => 'open',
            $paid < (int) $invoice->total_amount_ariary => 'partial',
            default => 'paid',
        };

        $invoice->update(['status' => $status]);
    }

    private function nextInvoiceNumber(): string
    {
        $year = now()->year;
        $count = Invoice::query()
            ->whereNotNull('invoice_number')
            ->where('invoice_number', 'like', "FACT-{$year}-%")
            ->lockForUpdate()
            ->count();

        return sprintf('FACT-%d-%04d', $year, $count + 1);
    }

    private function folioPayload(Invoice $invoice): array
    {
        $invoice->load(['items', 'payments', 'reservation.guest', 'reservation.rooms', 'reservation.organization', 'reservation.invoices.items', 'reservation.invoices.payments']);
        $reservation = $invoice->reservation;
        $reservationInvoices = $reservation?->invoices
            ? $reservation->invoices->sortBy(fn (Invoice $candidate) => sprintf(
                '%d-%06d-%06d',
                ($candidate->invoice_kind ?? 'master') === 'master' ? 0 : 1,
                $candidate->booking_room_id ?? 0,
                $candidate->id,
            ))
            : collect();
        $roomBookings = $reservation?->rooms?->map(fn (Room $room) => [
            'id' => $room->pivot->id ?? null,
            'room_id' => $room->id,
            'room_number' => $room->room_number,
            'type' => $room->type,
            'model' => $room->model,
            'price_snapshot_ariary' => (int) ($room->pivot->price_snapshot_ariary ?? $room->base_price_ariary),
            'segment_start_date' => optional($room->pivot->segment_start_date)->toDateString(),
            'segment_end_date' => optional($room->pivot->segment_end_date)->toDateString(),
            'segment_extra_beds' => (int) ($room->pivot->segment_extra_beds ?? 0),
            'segment_extra_mattresses' => (int) ($room->pivot->segment_extra_mattresses ?? 0),
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
        ])->values() ?? collect();

        return [
            'id' => $invoice->id,
            'reservation_id' => $invoice->reservation_id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status,
            'document_type' => $invoice->document_type ?? 'facture',
            'invoice_kind' => $invoice->invoice_kind ?? 'master',
            'billing_mode' => $invoice->billing_mode ?? $reservation?->billing_mode ?? 'grouped',
            'selected_invoice_id' => $invoice->id,
            'is_booking' => $invoice->reservation?->source === 'Booking',
            'booking_type' => $reservation?->booking_type ?? ($reservation?->organization_id ? 'organization' : 'individual'),
            'organization' => $reservation?->organization ? [
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
            'total_amount_ariary' => (int) $invoice->total_amount_ariary,
            'discount_mode' => $invoice->discount_mode,
            'discount_value' => $invoice->discount_value,
            'discount_amount_ariary' => (int) $invoice->discount_amount_ariary,
            'deposit_amount_ariary' => (int) $invoice->deposit_amount_ariary,
            'paid_amount_ariary' => $invoice->paid_amount_ariary,
            'balance_amount_ariary' => $invoice->balance_amount_ariary,
            'change_given_ariary' => (int) $invoice->payments->sum(fn (Payment $payment) => (int) ($payment->change_given_ariary ?? 0)),
            'payment_modification_count' => $invoice->reservation?->audits()
                ->where('action', 'payment_modified')
                ->count() ?? 0,
            'pdf_url' => $invoice->pdf_path ? url("/api/invoices/{$invoice->id}/pdf") : null,
            'finalized_at' => optional($invoice->finalized_at)->toDateTimeString(),
            'guest' => $invoice->reservation?->guest,
            'room_bookings' => $roomBookings,
            'invoices' => $reservationInvoices->map(fn (Invoice $candidate) => [
                'id' => $candidate->id,
                'reservation_id' => $candidate->reservation_id,
                'invoice_number' => $candidate->invoice_number,
                'status' => $candidate->status,
                'document_type' => $candidate->document_type ?? 'facture',
                'invoice_kind' => $candidate->invoice_kind ?? 'master',
                'billing_mode' => $candidate->billing_mode ?? 'grouped',
                'parent_invoice_id' => $candidate->parent_invoice_id,
                'booking_room_id' => $candidate->booking_room_id,
                'total_amount_ariary' => (int) $candidate->total_amount_ariary,
                'paid_amount_ariary' => (int) $candidate->paid_amount_ariary,
                'balance_amount_ariary' => (int) $candidate->balance_amount_ariary,
                'deposit_amount_ariary' => (int) $candidate->deposit_amount_ariary,
                'pdf_url' => $candidate->pdf_path ? url("/api/invoices/{$candidate->id}/pdf") : null,
            ])->values(),
            'items' => $this->invoiceDisplayItems($invoice)
                ->map(fn (InvoiceItem $item) => [
                'id' => $item->id,
                'description' => $item->description,
                'type' => $item->type,
                'amount_ariary' => (int) $item->amount_ariary,
                'quantity' => (int) $item->quantity,
                'line_total_ariary' => (int) $item->amount_ariary * (int) $item->quantity,
                'booking_room_id' => $item->booking_room_id,
                'created_by_name' => $item->created_by_name,
                'created_by_role' => $item->created_by_role,
                'updated_by_name' => $item->updated_by_name,
                'updated_by_role' => $item->updated_by_role,
                'manual_override_at' => optional($item->manual_override_at)->toDateTimeString(),
                'created_at' => optional($item->created_at)->toDateTimeString(),
            ])->values(),
            'payments' => $invoice->payments->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'amount_ariary' => (int) $payment->amount_ariary,
                'payment_method' => $payment->payment_method,
                'payment_operator' => $payment->payment_operator,
                'payment_context' => $payment->payment_context ?? 'payment',
                'reference' => $payment->reference,
                'amount_received_ariary' => (int) ($payment->amount_received_ariary ?? $payment->amount_ariary),
                'change_given_ariary' => (int) ($payment->change_given_ariary ?? 0),
                'processed_by_name' => $payment->processed_by_name,
                'processed_by_role' => $payment->processed_by_role,
                'created_at' => optional($payment->created_at)->toDateTimeString(),
            ])->values(),
        ];
    }

    private function resolveFolioInvoice(Reservation $reservation, ?int $invoiceId = null): Invoice
    {
        $reservation->loadMissing(['rooms', 'invoices.items', 'invoices.payments']);

        if ($invoiceId) {
            $invoice = $reservation->invoices->firstWhere('id', $invoiceId);
            if ($invoice) {
                $invoice->load(['items', 'payments', 'reservation.guest', 'reservation.rooms', 'reservation.organization', 'reservation.invoices.items', 'reservation.invoices.payments']);
                $this->syncInvoiceForReservation($invoice, $reservation);
                return $invoice;
            }
        }

        $masterInvoice = $reservation->invoice ?: $this->ensureOpenFolio($reservation);
        if (($reservation->billing_mode ?? 'grouped') === 'per_room') {
            $this->syncRoomInvoicesForReservation($reservation->refresh()->load('rooms'), $masterInvoice->refresh());
            $firstChild = Invoice::query()
                ->where('reservation_id', $reservation->id)
                ->where('invoice_kind', 'room')
                ->orderBy('booking_room_id')
                ->orderBy('id')
                ->first();
            if ($firstChild) {
                $firstChild->load(['items', 'payments', 'reservation.guest', 'reservation.rooms', 'reservation.organization', 'reservation.invoices.items', 'reservation.invoices.payments']);
                $this->syncInvoiceForReservation($firstChild, $reservation);
                return $firstChild;
            }
        }

        $masterInvoice->load(['items', 'payments', 'reservation.guest', 'reservation.rooms', 'reservation.organization', 'reservation.invoices.items', 'reservation.invoices.payments']);
        $this->syncInvoiceForReservation($masterInvoice, $reservation);

        return $masterInvoice;
    }

    private function invoiceHtml(Invoice $invoice, string $documentType = 'facture', string $currencyMode = 'ariary'): string
    {
        $reservation = $invoice->reservation;
        $clientName = $reservation->organization?->name
            ?? $reservation->guest?->full_name
            ?? $reservation->client_name;
        $guestName = e($clientName);
        $seatPhone = $reservation->organization?->phone ?? null;
        $contactParts = array_filter([
            $reservation->booking_type === 'organization' && filled($seatPhone)
                ? 'Siège : ' . $seatPhone
                : null,
            $reservation->customer_phone ?: $reservation->client_phone ?: null,
            $reservation->customer_email ?: null,
        ], fn ($value) => filled($value) && $value !== 'N/A');
        $contactLine = $contactParts ? e(implode(' | ', $contactParts)) : '';
        $invoiceNumber = e($invoice->invoice_number);
        $checkIn = $reservation->check_in_date->format('d/m/Y');
        $checkOut = $reservation->check_out_date->format('d/m/Y');
        $paidAmount = (int) $invoice->paid_amount_ariary;
        $balanceAmount = (int) $invoice->balance_amount_ariary;
        $depositAmount = (int) $invoice->deposit_amount_ariary;
        $changeAmount = (int) $invoice->payments->sum(fn (Payment $payment) => (int) ($payment->change_given_ariary ?? 0));
        $visibleItems = $this->invoiceDisplayItems($invoice);
        $subtotal = $this->invoiceSubtotal($invoice);
        $discountAmount = (int) $invoice->discount_amount_ariary;
        $showDiscount = $discountAmount > 0;
        $showEuro = $currencyMode === 'euro' && $invoice->reservation?->source === 'Booking';
        $currencyLabel = $showEuro ? 'EUR' : 'Ar';
        $unitHeader = $showEuro ? 'PU (EUR)' : 'PU (Ar)';
        $totalHeader = $showEuro ? 'Total (EUR)' : 'Total (Ar)';
        $amountHeader = $showEuro ? 'Montant (EUR)' : 'Montant (Ar)';
        $displaySubtotal = $showEuro ? $this->invoiceAmountInEuro($visibleItems) : $subtotal;
        $displayDiscount = $showEuro ? $this->ariaryToEuro($discountAmount) : $discountAmount;
        $displayDeposit = $showEuro ? $this->ariaryToEuro($depositAmount) : $depositAmount;
        $displayTotal = $showEuro ? max(0, $displaySubtotal - $displayDiscount) : $invoice->total_amount_ariary;
        $displayPaid = $showEuro ? $this->ariaryToEuro($paidAmount) : $paidAmount;
        $displayBalance = $showEuro ? max(0, $displayTotal - $displayPaid) : $balanceAmount;
        $amountInWords = $showEuro
            ? $this->formatMoney($displayTotal, $currencyLabel)
            : e($this->amountInWords($invoice->total_amount_ariary)) . " (" . number_format($invoice->total_amount_ariary, 0, ',', ' ') . ") Ariary";
        $isProforma = $documentType === 'proforma';
        $documentLabel = $isProforma ? 'Facture proforma' : 'Facture de séjour';
        $amountLabel = $isProforma ? 'facture proforma' : 'facture';
        $logoDataUri = $this->hotelLogoDataUri();
        $accentColor = '#d10f0f';
        $accentSoft = $isProforma ? '#fff7f7' : '#fff1f1';
        $accentText = '#111111';
        $badgeBg = '#f6e2e2';
        $badgeText = '#9f1d1d';
        $paymentStatus = $balanceAmount > 0 ? 'Non soldée' : 'Payée';
        $paymentNotice = $balanceAmount > 0
            ? 'Facture pas encore payée intégralement'
            : 'Facture réglée intégralement';
        $printedAt = now()->format('d/m/Y');
        $rows = '';
        $paymentRows = '';
        $roomBookById = $reservation->rooms->mapWithKeys(
            fn (Room $room) => [($room->pivot->id ?? $room->id) => $room]
        );
        $showSegmentDates = $this->hasSegmentedRoomDates($reservation);

        foreach ($visibleItems as $item) {
            $unitAmount = $showEuro ? $this->invoiceItemUnitAmountInEuro($item) : (float) $item->amount_ariary;
            $lineTotal = $item->quantity * $unitAmount;
            $description = $item->description;
            $segmentDateRange = '';
            if ($item->type === 'room' && $item->booking_room_id) {
                $room = $roomBookById->get($item->booking_room_id);
                if ($room instanceof Room) {
                    $nights = $this->bookingRoomNights($room, $reservation);
                    $description = $this->roomInvoiceDescription($room, $nights);
                    if ($showSegmentDates) {
                        $segmentDateRange = $this->bookingRoomDateRange($room, $reservation);
                    }
                }
            }
            $rows .= '<tr>'
                . '<td>' . e($description) . '</td>'
                . ($showSegmentDates ? '<td>' . e($segmentDateRange) . '</td>' : '')
                . '<td>' . $item->quantity . '</td>'
                . '<td>' . $this->formatMoney($unitAmount, $currencyLabel, false) . '</td>'
                . '<td>' . $this->formatMoney($lineTotal, $currencyLabel, false) . '</td>'
                . '</tr>';
        }

        foreach ($invoice->payments as $payment) {
            $reference = $payment->reference ? e($payment->reference) : '-';
            $processedBy = $payment->processed_by_name ? e($payment->processed_by_name) : '-';
            $processedRole = $payment->processed_by_role ? e($payment->processed_by_role) : '-';
            $operator = $payment->payment_operator ? ' / ' . e($payment->payment_operator) : '';
            $paymentContext = ($payment->payment_context ?? 'payment') === 'deposit' ? 'Acompte' : 'Paiement';
            $receivedAmount = (int) ($payment->amount_received_ariary ?? $payment->amount_ariary);
            $appliedAmount = (int) $payment->amount_ariary;
            $changeGiven = (int) ($payment->change_given_ariary ?? 0);
            $paymentRows .= '<tr>'
                . '<td>' . optional($payment->created_at)->format('d/m/Y H:i') . '</td>'
                . '<td>' . e($payment->payment_method) . $operator . '</td>'
                . '<td>' . $paymentContext . '</td>'
                . '<td>' . $this->formatMoney($showEuro ? $this->ariaryToEuro($receivedAmount) : (float) $receivedAmount, $currencyLabel, false) . '</td>'
                . '<td>' . $this->formatMoney($showEuro ? $this->ariaryToEuro($changeGiven) : (float) $changeGiven, $currencyLabel, false) . '</td>'
                . '<td>' . $this->formatMoney($showEuro ? $this->ariaryToEuro($appliedAmount) : (float) $appliedAmount, $currencyLabel, false) . '</td>'
                . '<td>' . $processedBy . ' (' . $processedRole . ')</td>'
                . '<td>' . $reference . '</td>'
                . '</tr>';
        }

        if ($paymentRows === '') {
            $paymentRows = '<tr><td colspan="8">Aucun paiement enregistré</td></tr>';
        }

        return "
            <html>
            <head>
                <meta charset='utf-8'>
                <style>
                    @page { size: A4 portrait; margin: 12mm 14mm; }
                    body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 11px; margin: 0; line-height: 1.25; }
                    .document-ribbon { margin-bottom: 8px; padding: 6px 10px; background: {$accentSoft}; border: 1px solid {$accentColor}; color: {$accentText}; border-radius: 8px; text-align: center; font-size: 10px; font-weight: bold; letter-spacing: 0.7px; }
                    .topbar { display: table; width: 100%; border-bottom: 2px solid {$accentColor}; padding-bottom: 10px; margin-bottom: 12px; }
                    .brand { display: table-cell; width: 62%; vertical-align: top; }
                    .brand-logo { width: 130px; height: auto; display: block; margin-bottom: 2px; }
                    .brand-fallback { margin: 0; color: {$accentText}; font-size: 19px; letter-spacing: 0.4px; font-weight: bold; }
                    .brand .subtitle { color: #64748b; font-size: 10px; margin-top: 3px; }
                    .meta { display: table-cell; width: 38%; vertical-align: top; text-align: right; }
                    .pill { display: inline-block; padding: 4px 8px; border-radius: 999px; background: {$badgeBg}; color: {$badgeText}; font-weight: bold; font-size: 10px; }
                    .pill.unpaid { background: #fef3c7; color: #92400e; }
                    .info-grid { width: 100%; margin-bottom: 12px; }
                    .info-grid td { vertical-align: top; padding: 0; border: 0; }
                    .box { border: 1px solid #dbe4ea; border-radius: 8px; padding: 9px 11px; background: #fff; }
                    .box-title { color: #64748b; font-size: 9px; text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 4px; }
                    table.lines { width: 100%; border-collapse: collapse; margin-top: 3px; }
                    table.lines th, table.lines td { border-bottom: 1px solid #dbe4ea; padding: 5px 6px; text-align: left; line-height: 1.2; }
                    table.lines th { background-color: #f8fafc; color: #0f172a; font-size: 10px; text-transform: uppercase; letter-spacing: 0.35px; }
                    table.lines td.num, table.lines th.num { text-align: right; }
                    table.lines tr { page-break-inside: avoid; }
                    .section-title { margin: 10px 0 6px; color: {$accentText}; font-size: 12px; font-weight: bold; }
                    .summary-wrap { width: 100%; margin-top: 10px; }
                    .summary { width: 48%; margin-left: auto; border: 1px solid #dbe4ea; border-radius: 8px; padding: 9px 11px; }
                    .summary-row { display: table; width: 100%; margin-bottom: 4px; }
                    .summary-row .label { display: table-cell; color: #475569; }
                    .summary-row .value { display: table-cell; text-align: right; }
                    .summary-row.total { margin-top: 6px; padding-top: 6px; border-top: 1px solid #cbd5e1; font-weight: bold; font-size: 12px; color: #0f172a; }
                    .summary-row.discount .value { color: #b91c1c; }
                    .summary-row.deposit .value { color: #0f766e; }
                    .notice { margin: 8px 0 12px; padding: 8px 10px; border: 1px solid #cbd5e1; background: #f8fafc; color: #334155; border-radius: 8px; font-size: 10.5px; }
                    .signature-wrap { width: 100%; margin-top: 14px; page-break-inside: avoid; }
                    .signature-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
                    .signature-cell { width: 50%; vertical-align: top; padding: 0 4px; }
                    .signature-box { min-height: 84px; border: 1px solid #dbe4ea; border-radius: 10px; padding: 10px 12px; background: #fff; }
                    .signature-title { margin-bottom: 6px; color: {$accentText}; font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; }
                    .signature-line { margin-top: 34px; border-top: 1px solid #94a3b8; padding-top: 5px; color: #475569; font-size: 10px; }
                    .signature-label { display: block; margin-bottom: 3px; color: #64748b; font-size: 9px; text-transform: uppercase; letter-spacing: 0.4px; }
                    .invoice-footer { margin-top: 14px; page-break-inside: avoid; }
                    .invoice-location { margin-top: 8px; font-weight: bold; text-align: right; color: {$accentText}; font-size: 10.5px; }
                    .legal-block { margin-top: 8px; padding: 8px 10px; border: 1px solid {$accentColor}; border-radius: 8px; background: {$accentSoft}; color: {$accentText}; font-size: 9.8px; line-height: 1.35; }
                    .footer-note { margin-top: 10px; font-weight: bold; text-transform: uppercase; font-size: 10.5px; line-height: 1.25; }
                </style>
            </head>
            <body>
                " . ($isProforma ? "<div class='document-ribbon'>DOCUMENT PROFORMA</div>" : "") . "
                <div class='topbar'>
                    <div class='brand'>
                        " . ($logoDataUri
                            ? "<img class='brand-logo' src='{$logoDataUri}' alt='Kamoro Hotel'>"
                            : "<div class='brand-fallback'>KAMORO HOTEL</div>") . "
                        <div class='subtitle'>{$documentLabel}</div>
                    </div>
                    <div class='meta'>
                        <div style='margin-bottom: 8px; font-weight: bold; color: {$accentText};'>" . ($isProforma ? 'Proforma n° ' : 'Facture n° ') . "{$invoiceNumber}</div>
                        <span class='pill " . ($balanceAmount > 0 ? 'unpaid' : '') . "'>{$paymentStatus}</span>
                    </div>
                </div>
                <div class='notice'>{$paymentNotice}</div>
                <table class='info-grid'>
                    <tr>
                        <td style='width: 56%; padding-right: 10px;'>
                            <div class='box'>
                                <div class='box-title'>Client</div>
                                <strong>{$guestName}</strong><br>
                                " . ($reservation->booking_type === 'organization' ? 'Client : organisme<br>' : '') . "
                                " . ($contactLine ? "Contact : {$contactLine}<br>" : '') . "
                                Séjour du {$checkIn} au {$checkOut}
                            </div>
                        </td>
                        <td>
                            <div class='box'>
                                <div class='box-title'>Récapitulatif</div>
                                Total prestations: " . $this->formatMoney($displaySubtotal, $currencyLabel) . "<br>
                                " . ($showDiscount ? 'Remise: ' . $this->formatMoney($displayDiscount, $currencyLabel) : '') . "
                            </div>
                        </td>
                    </tr>
                </table>
                <table class='lines'>
                    <thead>
                        <tr><th>Chambre</th>" . ($showSegmentDates ? "<th>Dates</th>" : "") . "<th class='num'>Qté</th><th class='num'>{$unitHeader}</th><th class='num'>{$totalHeader}</th></tr>
                    </thead>
                    <tbody>{$rows}</tbody>
                </table>
                <div class='section-title'>Paiements</div>
                <table class='lines'>
                    <thead>
                        <tr><th>Date</th><th>Méthode</th><th>Type</th><th class='num'>Reçu</th><th class='num'>Rendu</th><th class='num'>Net</th><th>Par</th><th>Réf.</th></tr>
                    </thead>
                    <tbody>{$paymentRows}</tbody>
                </table>
                <div class='summary-wrap'>
                    <div class='summary'>
                        <div class='summary-row'>
                            <div class='label'>Sous-total</div>
                            <div class='value'>" . $this->formatMoney($displaySubtotal, $currencyLabel) . "</div>
                        </div>
                        " . ($depositAmount > 0 ? "
                        <div class='summary-row deposit'>
                            <div class='label'>Acompte versé</div>
                            <div class='value'>- " . $this->formatMoney($displayDeposit, $currencyLabel) . "</div>
                        </div>
                        " : '') . "
                        " . ($showDiscount ? "
                        <div class='summary-row discount'>
                            <div class='label'>Remise</div>
                            <div class='value'>- " . $this->formatMoney($displayDiscount, $currencyLabel) . "</div>
                        </div>
                        " : '') . "
                        <div class='summary-row total'>
                            <div class='label'>Total facture</div>
                            <div class='value'>" . $this->formatMoney($displayTotal, $currencyLabel) . "</div>
                        </div>
                        <div class='summary-row'>
                            <div class='label'>Total payé</div>
                            <div class='value'>" . $this->formatMoney($displayPaid, $currencyLabel) . "</div>
                        </div>
                        <div class='summary-row total'>
                            <div class='label'>Reste à payer</div>
                            <div class='value'>" . $this->formatMoney($displayBalance, $currencyLabel) . "</div>
                        </div>
                        " . ($changeAmount > 0 ? "
                        <div class='summary-row'>
                            <div class='label'>Monnaie rendue</div>
                            <div class='value'>" . $this->formatMoney($showEuro ? $this->ariaryToEuro($changeAmount) : $changeAmount, $currencyLabel) . "</div>
                        </div>
                        " : '') . "
                    </div>
                </div>
                <div class='signature-wrap'>
                    <table class='signature-table'>
                        <tr>
                            <td class='signature-cell'>
                                <div class='signature-box'>
                                    <div class='signature-title'>Client</div>
                                    <span class='signature-label'>Signature</span>
                                    <div class='signature-line'>&nbsp;</div>
                                </div>
                            </td>
                            <td class='signature-cell'>
                                <div class='signature-box'>
                                    <div class='signature-title'>Responsable</div>
                                    <span class='signature-label'>Signature</span>
                                    <div class='signature-line'>&nbsp;</div>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>
                <div class='footer-note'>
                    Arrêtée la présente {$amountLabel} à la somme de : {$amountInWords}
                </div>
                <div class='invoice-footer'>
                    <div class='invoice-location'>Fait à Ambondromamy le {$printedAt}</div>
                    <div class='legal-block'>
                        NIF: 2000683017 STAT: 46101 11 2011 Siège social: LOT II H 12 ter Bis EA Ankerana
                    </div>
                </div>
            </body>
            </html>
        ";
    }

    private function invoiceAmountInEuro(iterable $items): float
    {
        $total = 0.0;
        foreach ($items as $item) {
            $total += $this->invoiceItemUnitAmountInEuro($item) * (int) $item->quantity;
        }

        return $total;
    }

    private function invoiceItemUnitAmountInEuro(InvoiceItem $item): float
    {
        if ($item->type === 'room') {
            return self::BOOKING_ROOM_PRICE_EUR;
        }

        return match ($item->description) {
            'Lit supplémentaire' => self::EXTRA_BED_PRICE_EUR,
            'Matelas supplémentaire' => self::EXTRA_MATTRESS_PRICE_EUR,
            default => $this->ariaryToEuro((int) $item->amount_ariary),
        };
    }

    private function ariaryToEuro(int $amount): float
    {
        return $amount / self::EUR_TO_ARIARY_RATE;
    }

    private function formatMoney(float|int $amount, string $currency, bool $withCurrency = true): string
    {
        $decimals = $currency === 'EUR' ? 2 : 0;
        $formatted = number_format((float) $amount, $decimals, ',', ' ');

        return $withCurrency ? "{$formatted} {$currency}" : $formatted;
    }

    private function amountInWords(int $amount): string
    {
        $formatter = new \NumberFormatter('fr_FR', \NumberFormatter::SPELLOUT);
        $words = $formatter->format($amount) ?: (string) $amount;
        return mb_strtoupper(trim($words), 'UTF-8');
    }

    private function hotelLogoDataUri(): ?string
    {
        if (self::$hotelLogoLoaded) {
            return self::$hotelLogoDataUri;
        }

        self::$hotelLogoLoaded = true;
        $root = dirname(base_path());
        $captureMatches = glob($root . DIRECTORY_SEPARATOR . 'Capture*.png') ?: [];
        $candidates = [
            $captureMatches[0] ?? null,
            $root . DIRECTORY_SEPARATOR . 'hestia_app' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'login_logo.png',
            base_path('public/logo.png'),
            storage_path('app/public/logo.png'),
        ];

        $logoPath = collect($candidates)->first(fn ($path) => is_string($path) && is_file($path));
        if (!$logoPath) {
            return null;
        }

        $mime = mime_content_type($logoPath) ?: 'image/png';
        self::$hotelLogoDataUri = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoPath));

        return self::$hotelLogoDataUri;
    }

    private function ensureInvoicePdf(Invoice $invoice, string $documentType = 'facture', string $currencyMode = 'ariary'): void
    {
        $invoice->refresh();
        if (!$invoice->invoice_number) {
            $invoice->invoice_number = $this->nextInvoiceNumber();
            $invoice->save();
        }

        $invoice->load(['items', 'payments', 'reservation.guest', 'reservation.rooms']);
        $pdf = Pdf::loadHTML($this->invoiceHtml($invoice, $documentType, $currencyMode));
        $path = "invoices/{$invoice->invoice_number}.pdf";
        Storage::disk('local')->put($path, $pdf->output());

        $invoice->update([
            'document_type' => $documentType,
            'pdf_path' => $path,
        ]);
    }

    private function invalidateInvoiceDocumentArtifacts(Invoice $invoice): void
    {
        $invoice->loadMissing('parentInvoice');
        $invoices = collect([$invoice]);

        if ($invoice->parentInvoice) {
            $invoices->push($invoice->parentInvoice);
        }

        foreach ($invoices as $targetInvoice) {
            if ($targetInvoice->pdf_path && Storage::disk('local')->exists($targetInvoice->pdf_path)) {
                Storage::disk('local')->delete($targetInvoice->pdf_path);
            }

            $targetInvoice->update(['pdf_path' => null]);
        }
    }

    private function syncInvoiceAfterPayment(Invoice $invoice, bool $regeneratePdf = false): Invoice
    {
        $invoice->refresh();
        $depositAmount = (int) $invoice->payments()->where('payment_context', 'deposit')->sum('amount_ariary');

        $invoice->update([
            'deposit_amount_ariary' => $depositAmount,
        ]);

        $this->updatePaymentStatus($invoice->refresh());
        $invoice = $invoice->refresh()->load('reservation.guest', 'payments');
        if ($regeneratePdf) {
            $this->ensureInvoicePdf($invoice, $invoice->document_type ?? 'facture');
        }

        return $invoice->refresh()->load('reservation.guest', 'payments');
    }

    private function syncInvoiceAfterDeposit(Invoice $invoice): Invoice
    {
        $invoice->refresh();
        if ($invoice->status === 'finalized') {
            return $invoice->refresh()->load('reservation.guest');
        }

        $depositAmount = (int) $invoice->payments()->where('payment_context', 'deposit')->sum('amount_ariary');
        $paidAmount = (int) $invoice->payments()->sum('amount_ariary');
        $status = match (true) {
            $paidAmount <= 0 => 'open',
            $paidAmount < (int) $invoice->total_amount_ariary => 'partial',
            default => 'paid',
        };

        $invoice->update([
            'deposit_amount_ariary' => $depositAmount,
            'status' => $status,
        ]);

        return $invoice->refresh()->load('reservation.guest');
    }

    private function normalizePaymentAmounts(int $receivedAmount, int $availableAmount, string $paymentMethod): array
    {
        $receivedAmount = max(1, $receivedAmount);
        $availableAmount = max(0, $availableAmount);

        if ($receivedAmount > $availableAmount && $paymentMethod !== 'Espèces') {
            throw ValidationException::withMessages([
                'amount_ariary' => 'Le paiement dépasse le reste à payer.',
            ]);
        }

        $appliedAmount = min($receivedAmount, $availableAmount);
        $changeGiven = max(0, $receivedAmount - $appliedAmount);

        return [
            'received_amount_ariary' => $receivedAmount,
            'applied_amount_ariary' => $appliedAmount,
            'change_given_ariary' => $changeGiven,
        ];
    }

    private function assertPaymentModificationAllowed(?Reservation $reservation, string $actorRole): void
    {
        if (!$reservation) {
            throw ValidationException::withMessages([
                'payment' => 'Réservation introuvable pour ce paiement.',
            ]);
        }

        $modificationCount = (int) $reservation->audits()
            ->where('action', 'payment_modified')
            ->count();

        if ($actorRole === 'receptionist' && $modificationCount >= 1) {
            throw ValidationException::withMessages([
                'payment' => 'Le réceptionniste ne peut modifier qu’un seul paiement par réservation.',
            ]);
        }
    }

    private function paymentPayload(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'invoice_id' => $payment->invoice_id,
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
        ];
    }

    private function applyInvoiceDiscount(Invoice $invoice, ?string $mode, mixed $value): void
    {
        $mode = in_array($mode, ['percent', 'amount'], true) ? $mode : null;
        $numericValue = is_numeric($value) ? (float) $value : null;
        $subtotal = $this->invoiceSubtotal($invoice->loadMissing(['items', 'reservation.invoices.items']));

        $discountAmount = 0;
        if ($mode && $numericValue !== null && $numericValue > 0) {
            $discountAmount = $mode === 'percent'
                ? (int) round($subtotal * ($numericValue / 100))
                : (int) round($numericValue);
        }

        $discountAmount = min(max(0, $discountAmount), max(0, $subtotal));

        if (
            Schema::hasColumn('invoices', 'discount_mode')
            && Schema::hasColumn('invoices', 'discount_value')
            && Schema::hasColumn('invoices', 'discount_amount_ariary')
        ) {
            $invoice->update([
                'discount_mode' => $discountAmount > 0 ? $mode : null,
                'discount_value' => $discountAmount > 0 ? $numericValue : null,
                'discount_amount_ariary' => $discountAmount,
            ]);
        }

        $this->recalculateInvoice($invoice);
    }

    private function invoiceSubtotal(Invoice $invoice): int
    {
        $billingMode = $invoice->billing_mode ?? $invoice->reservation?->billing_mode ?? 'grouped';

        if (($invoice->invoice_kind ?? 'master') === 'master' && $billingMode === 'per_room') {
            $subtotal = $this->invoiceLineSubtotal($invoice, true, true);
            $subtotal += (int) ($invoice->reservation?->invoices
                ?->filter(fn (Invoice $candidate) => ($candidate->invoice_kind ?? 'master') === 'room')
                ?->sum(fn (Invoice $candidate) => $this->invoiceLineSubtotal($candidate)) ?? 0);

            return $subtotal;
        }

        return $this->invoiceLineSubtotal($invoice);
    }

    private function invoiceDisplayItems(Invoice $invoice): Collection
    {
        $billingMode = $invoice->billing_mode ?? $invoice->reservation?->billing_mode ?? 'grouped';
        $items = $invoice->items()
            ->where('type', '!=', 'tax')
            ->get();

        if (($invoice->invoice_kind ?? 'master') !== 'master' || $billingMode !== 'per_room') {
            return $items;
        }

        $ownItems = $items
            ->reject(fn (InvoiceItem $item) => $item->type === 'room' || $this->isGeneratedRoomSpecificExtra($item))
            ->values();
        $childItems = $invoice->reservation?->invoices
            ?->filter(
                fn (Invoice $candidate) => ($candidate->invoice_kind ?? 'master') === 'room'
            )
            ?->flatMap(
                fn (Invoice $candidate) => $candidate->items()
                    ->where('type', '!=', 'tax')
                    ->get()
                    ->values()
            ) ?? collect();

        return $ownItems->concat($childItems)->values();
    }

    private function invoiceLineSubtotal(
        Invoice $invoice,
        bool $excludeRoomLines = false,
        bool $excludeGeneratedRoomSpecificExtras = false,
    ): int
    {
        $query = $invoice->items()->where('type', '!=', 'tax');
        if ($excludeRoomLines) {
            $query->where('type', '!=', 'room');
        }

        return (int) $query->get()
            ->reject(fn (InvoiceItem $item) => $excludeGeneratedRoomSpecificExtras && $this->isGeneratedRoomSpecificExtra($item))
            ->sum(fn (InvoiceItem $item) => (int) $item->amount_ariary * (int) $item->quantity);
    }

    private function isGeneratedRoomSpecificExtra(InvoiceItem $item): bool
    {
        return $item->type === 'extra'
            && $item->booking_room_id !== null
            && $item->created_by_role === null
            && $item->manual_override_at === null;
    }
}
