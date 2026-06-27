<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Room;
use App\Models\ReservationAudit;
use App\Models\Payment;
use App\Models\Reservation;
use App\Support\PhoneNumber;
use App\Services\AvailabilityService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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
    private static bool $hotelLogoLoaded = false;
    private static ?string $hotelLogoDataUri = null;

    public function __construct(
        private readonly AvailabilityService $availabilityService,
    ) {
    }

    public function checkIn(Request $request, int $id): JsonResponse
    {
        $reservation = Reservation::with(['rooms', 'guest', 'invoice.items', 'invoice.payments'])->findOrFail($id);

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
        ], $request->all());

        $validated = validator($payload, [
            'full_name' => 'required|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'phone_number' => 'nullable|string|max:50',
            'date_of_birth' => 'required|date',
            'sex' => 'required|in:Homme,Femme,Autre',
            'id_type' => 'required|in:CIN,Passeport,Permis',
            'id_number' => 'required|string|max:100',
            'id_document_number' => 'nullable|string|max:100',
            'passport_valid_from' => 'nullable|date|required_if:id_type,Passeport|before_or_equal:passport_valid_until',
            'passport_valid_until' => 'nullable|date|required_if:id_type,Passeport|after_or_equal:passport_valid_from',
            'loyalty_count' => 'nullable|integer|min:0',
            'first_name' => 'nullable|string|max:120',
            'last_name' => 'nullable|string|max:120',
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
        ])->validate();
        $result = DB::transaction(function () use ($reservation, $validated) {
            $baseLoyaltyCount = (int) ($validated['loyalty_count'] ?? $reservation->guest?->loyalty_count ?? 0);
            $customerPhone = PhoneNumber::normalize($validated['customer_phone'] ?? $reservation->customer_phone ?? $reservation->client_phone ?? null);
            $phoneNumber = PhoneNumber::normalize($validated['phone_number'] ?? $customerPhone ?? null);

            $reservation->update([
                'client_name' => $validated['full_name'],
                'customer_phone' => $customerPhone,
                'client_phone' => $customerPhone ?? $reservation->client_phone,
                'status' => 'arrive',
            ]);

            $guest = Guest::updateOrCreate(
                ['reservation_id' => $reservation->id],
                [
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
                ],
            );

            $roomCheckins = collect($validated['room_checkins'] ?? []);
            if ($roomCheckins->isNotEmpty()) {
                foreach ($roomCheckins as $roomCheckin) {
                    $reservation->rooms()->updateExistingPivot((int) $roomCheckin['room_id'], [
                        'occupant_name' => $roomCheckin['occupant_name'] ?? $validated['full_name'],
                        'occupant_phone' => $roomCheckin['occupant_phone'] ?? $phoneNumber,
                        'occupant_email' => $roomCheckin['occupant_email'] ?? $validated['customer_email'] ?? null,
                        'occupant_date_of_birth' => $roomCheckin['occupant_date_of_birth'] ?? $validated['date_of_birth'],
                        'occupant_sex' => $roomCheckin['occupant_sex'] ?? $validated['sex'],
                        'occupant_id_type' => $roomCheckin['occupant_id_type'] ?? $validated['id_type'],
                        'occupant_id_number' => $roomCheckin['occupant_id_number'] ?? $validated['id_number'],
                        'checked_in_at' => now(),
                        'checked_in_by_name' => $validated['checked_in_by_name'] ?? null,
                        'checked_in_by_role' => $validated['checked_in_by_role'] ?? null,
                    ]);
                }
            } else {
                foreach ($reservation->rooms as $room) {
                    $reservation->rooms()->updateExistingPivot($room->id, [
                        'occupant_name' => $validated['full_name'],
                        'occupant_phone' => $phoneNumber,
                        'occupant_email' => $validated['customer_email'] ?? null,
                        'occupant_date_of_birth' => $validated['date_of_birth'],
                        'occupant_sex' => $validated['sex'],
                        'occupant_id_type' => $validated['id_type'],
                        'occupant_id_number' => $validated['id_number'],
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

    public function addInvoiceItem(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'type' => 'required|in:room,extra,deposit',
            'amount_ariary' => 'required|integer|min:0',
            'quantity' => 'required|integer|min:1',
            'booking_room_id' => 'nullable|integer|exists:booking_room,id',
        ]);

        $invoice = Invoice::findOrFail($id);
        if ($invoice->status === 'finalized') {
            return response()->json(['message' => 'Facture finalisée, ajout impossible.'], 400);
        }

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'booking_room_id' => $validated['booking_room_id'] ?? null,
                'description' => $validated['description'],
                'type' => $validated['type'],
                'amount_ariary' => $validated['amount_ariary'],
            'quantity' => $validated['quantity'],
        ]);

        $this->recalculateInvoice($invoice);

        return response()->json([
            'message' => 'Ligne ajoutée avec succès',
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

        DB::transaction(function () use ($invoice, $validated, $documentType, $currencyMode) {
            $invoice->refresh();
            if (!$invoice->invoice_number) {
                $invoice->invoice_number = $this->nextInvoiceNumber();
                $invoice->save();
            }

            if ($invoice->status !== 'finalized' && $invoice->reservation) {
                $this->syncReservationExtras($invoice, $invoice->reservation);
                $this->syncRoomItemDescriptions($invoice, $invoice->reservation);
                $this->recalculateInvoice($invoice->refresh());
            }

            $invoice->update([
                'document_type' => $documentType,
            ]);
            $this->applyInvoiceDiscount($invoice, $validated['discount_mode'] ?? null, $validated['discount_value'] ?? null);
            $this->ensureInvoicePdf($invoice->refresh()->load(['items', 'payments', 'reservation.guest', 'reservation.rooms']), $documentType, $currencyMode);
        });

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
            $this->ensureRoomInvoices($reservation, $invoice);
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
                $nights = $this->reservationNights($reservation);
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

            $this->recalculateInvoice($childInvoice->refresh());
        }
    }

    private function seedRoomItems(Invoice $invoice, Reservation $reservation): void
    {
        $nights = $this->reservationNights($reservation);

        foreach ($reservation->rooms as $room) {
            $pricePerNight = (int) ($room->pivot->price_snapshot_ariary ?? $room->base_price_ariary);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'booking_room_id' => $room->pivot->id ?? null,
                'description' => $this->roomInvoiceDescription($room, $nights),
                'type' => 'room',
                'amount_ariary' => $pricePerNight,
                'quantity' => $nights,
            ]);
        }
    }

    private function syncReservationExtras(Invoice $invoice, Reservation $reservation): void
    {
        $nights = $this->reservationNights($reservation);

        $invoice->items()
            ->whereIn('description', ['Lit supplémentaire', 'Matelas supplémentaire'])
            ->delete();

        if ((int) $reservation->extra_beds > 0) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => 'Lit supplémentaire',
                'type' => 'extra',
                'amount_ariary' => self::EXTRA_BED_PRICE_ARIARY,
                'quantity' => (int) $reservation->extra_beds * $nights,
            ]);
        }

        if ((int) $reservation->extra_mattresses > 0) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => 'Matelas supplémentaire',
                'type' => 'extra',
                'amount_ariary' => self::EXTRA_MATTRESS_PRICE_ARIARY,
                'quantity' => (int) $reservation->extra_mattresses * $nights,
            ]);
        }
    }

    private function syncRoomItemDescriptions(Invoice $invoice, Reservation $reservation): void
    {
        $reservation->loadMissing('rooms');
        $roomsByBookingId = $reservation->rooms->keyBy(fn (Room $room) => $room->pivot->id ?? $room->id);

        foreach ($invoice->items()->where('type', 'room')->get() as $item) {
            $bookingRoomId = $item->booking_room_id;
            $room = $bookingRoomId ? $roomsByBookingId->get($bookingRoomId) : null;
            if (!$room instanceof Room) {
                continue;
            }

            $item->update([
                'booking_room_id' => $room->pivot->id ?? $item->booking_room_id,
                'description' => $this->roomInvoiceDescription($room, (int) $item->quantity),
            ]);
        }
    }

    private function roomInvoiceDescription(Room $room, int $nights): string
    {
        $occupantName = trim((string) ($room->pivot->occupant_name ?? ''));
        $base = "Chambre {$room->room_number} ({$room->type})";

        if ($occupantName !== '') {
            $base .= " - {$occupantName}";
        }

        return "{$base} - {$nights} nuit(s)";
    }

    private function reservationNights(Reservation $reservation): int
    {
        $checkIn = Carbon::parse($reservation->check_in_date);
        $checkOut = Carbon::parse($reservation->check_out_date);

        return max(1, $checkIn->diffInDays($checkOut));
    }

    private function recalculateInvoice(Invoice $invoice): void
    {
        $items = $invoice->items()->get();
        $subtotal = (int) $items->sum(fn (InvoiceItem $item) => $item->amount_ariary * $item->quantity);
        $discountAmount = (int) $invoice->discount_amount_ariary;
        $total = max(0, $subtotal - $discountAmount);

        $invoice->update([
            'tax_amount_ariary' => 0,
            'total_amount_ariary' => $total,
        ]);

        $this->updatePaymentStatus($invoice->refresh());
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
            'occupant_name' => $room->pivot->occupant_name,
            'occupant_phone' => $room->pivot->occupant_phone,
            'occupant_email' => $room->pivot->occupant_email,
            'occupant_date_of_birth' => optional($room->pivot->occupant_date_of_birth)->toDateString(),
            'occupant_sex' => $room->pivot->occupant_sex,
            'occupant_id_type' => $room->pivot->occupant_id_type,
            'occupant_id_number' => $room->pivot->occupant_id_number,
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
            'items' => $invoice->items
                ->where('type', '!=', 'tax')
                ->map(fn (InvoiceItem $item) => [
                'id' => $item->id,
                'description' => $item->description,
                'type' => $item->type,
                'amount_ariary' => (int) $item->amount_ariary,
                'quantity' => (int) $item->quantity,
                'line_total_ariary' => (int) $item->amount_ariary * (int) $item->quantity,
                'booking_room_id' => $item->booking_room_id,
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
                $this->syncRoomItemDescriptions($invoice, $reservation);
                return $invoice;
            }
        }

        $masterInvoice = $reservation->invoice ?: $this->ensureOpenFolio($reservation);
        if (($reservation->billing_mode ?? 'grouped') === 'per_room') {
            $firstChild = Invoice::query()
                ->where('reservation_id', $reservation->id)
                ->where('invoice_kind', 'room')
                ->orderBy('booking_room_id')
                ->orderBy('id')
                ->first();
            if ($firstChild) {
                $firstChild->load(['items', 'payments', 'reservation.guest', 'reservation.rooms', 'reservation.organization', 'reservation.invoices.items', 'reservation.invoices.payments']);
                $this->syncRoomItemDescriptions($firstChild, $reservation);
                return $firstChild;
            }
        }

        $masterInvoice->load(['items', 'payments', 'reservation.guest', 'reservation.rooms', 'reservation.organization', 'reservation.invoices.items', 'reservation.invoices.payments']);
        $this->syncRoomItemDescriptions($masterInvoice, $reservation);

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
        $visibleItems = $invoice->items->where('type', '!=', 'tax');
        $subtotal = (int) $visibleItems->sum(fn (InvoiceItem $item) => $item->amount_ariary * $item->quantity);
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
        $rows = '';
        $paymentRows = '';
        $roomBookById = $reservation->rooms->mapWithKeys(
            fn (Room $room) => [($room->pivot->id ?? $room->id) => $room]
        );

        foreach ($visibleItems as $item) {
            $unitAmount = $showEuro ? $this->invoiceItemUnitAmountInEuro($item) : (float) $item->amount_ariary;
            $lineTotal = $item->quantity * $unitAmount;
            $description = $item->description;
            if ($item->type === 'room' && $item->booking_room_id) {
                $room = $roomBookById->get($item->booking_room_id);
                if ($room instanceof Room) {
                    $description = $this->roomInvoiceDescription($room, (int) $item->quantity);
                }
            }
            $rows .= '<tr>'
                . '<td>' . e($description) . '</td>'
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
                    .footer-note { margin-top: 10px; font-weight: bold; text-transform: uppercase; font-size: 10.5px; line-height: 1.25; }
                </style>
            </head>
            <body>
                " . ($isProforma ? "<div class='document-ribbon'>DOCUMENT PROFORMA - NON VALABLE POUR COMPTABILISATION FINALE</div>" : "") . "
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
                        <tr><th>Description</th><th class='num'>Qté</th><th class='num'>{$unitHeader}</th><th class='num'>{$totalHeader}</th></tr>
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
                <div class='footer-note'>
                    Arrêtée la présente {$amountLabel} à la somme de : {$amountInWords}
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
        $matches = glob($root . DIRECTORY_SEPARATOR . 'Capture*.png');
        $logoPath = $matches[0] ?? null;
        if (!$logoPath || !is_file($logoPath)) {
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
        $subtotal = (int) $invoice->items()->get()->sum(fn (InvoiceItem $item) => $item->amount_ariary * $item->quantity);

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
}
