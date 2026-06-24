<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationListingOptimizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservations_all_filters_pending_status_on_server(): void
    {
        $user = User::create([
            'name' => 'Reception Test',
            'email' => 'reservation-listing@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $room1 = $this->createRoom('901');
        $room2 = $this->createRoom('902');

        $pending = $this->createReservation($user->id, 'Pending Client', 'en_attente');
        $pending->rooms()->attach($room1->id, ['price_snapshot_ariary' => 50000]);

        $arrived = $this->createReservation($user->id, 'Arrived Client', 'arrive');
        $arrived->rooms()->attach($room2->id, ['price_snapshot_ariary' => 50000]);

        $response = $this->getJson('/api/reservations/all?date=2026-06-20&status=pending');

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['client_name' => 'Pending Client']);
        $response->assertJsonMissing(['client_name' => 'Arrived Client']);
    }

    public function test_reservations_all_status_returns_every_reservation_for_selected_date(): void
    {
        $user = User::create([
            'name' => 'Reception Test',
            'email' => 'reservation-listing-all@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $this->createReservation($user->id, 'Pending Client', 'en_attente');
        $this->createReservation($user->id, 'Arrived Client', 'arrive');
        $this->createReservation($user->id, 'Paid Client', 'arrive', 'paid');
        $this->createReservation($user->id, 'Cancelled Client', 'annule');

        $response = $this->getJson('/api/reservations/all?date=2026-06-20&status=all');

        $response->assertOk();
        $response->assertJsonCount(4);
        $response->assertJsonFragment(['client_name' => 'Pending Client']);
        $response->assertJsonFragment(['client_name' => 'Arrived Client']);
        $response->assertJsonFragment(['client_name' => 'Paid Client']);
        $response->assertJsonFragment(['client_name' => 'Cancelled Client']);
    }

    public function test_reservations_all_filters_paid_status_on_server(): void
    {
        $user = User::create([
            'name' => 'Reception Test',
            'email' => 'reservation-listing-paid@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $this->createReservation($user->id, 'Unpaid Client', 'arrive', 'unpaid');
        $paidReservation = $this->createReservation($user->id, 'Paid Client', 'en_attente', 'unbilled');
        $this->createPaidInvoice($paidReservation, 50000);

        $response = $this->getJson('/api/reservations/all?date=2026-06-20&status=paid');

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['client_name' => 'Paid Client']);
        $response->assertJsonMissing(['client_name' => 'Unpaid Client']);
    }

    public function test_reservations_all_filters_paid_status_even_before_checkin(): void
    {
        $user = User::create([
            'name' => 'Reception Test',
            'email' => 'reservation-listing-paid-precheckin@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $paidBeforeCheckin = $this->createReservation($user->id, 'Paid Before Checkin', 'en_attente', 'unbilled');
        $paidBeforeCheckin->rooms()->attach($this->createRoom('903')->id, ['price_snapshot_ariary' => 50000]);
        $this->createPaidInvoice($paidBeforeCheckin, 50000);

        $response = $this->getJson('/api/reservations/all?date=2026-06-20&status=paid');

        $response->assertOk();
        $response->assertJsonFragment(['client_name' => 'Paid Before Checkin']);
    }

    public function test_reservations_all_includes_checkout_date_for_one_night_stays(): void
    {
        $user = User::create([
            'name' => 'Reception Test',
            'email' => 'reservation-listing-boundary@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'Boundary Client',
            'client_phone' => '0340000123',
            'customer_phone' => '0340000123',
            'customer_email' => 'boundary@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'source' => 'Appel',
            'check_in_date' => '2026-06-22',
            'check_out_date' => '2026-06-23',
            'status' => 'arrive',
            'payment_status' => 'unbilled',
            'extra_beds' => 0,
            'extra_mattresses' => 0,
        ]);
        $reservation->rooms()->attach($this->createRoom('904')->id, ['price_snapshot_ariary' => 50000]);
        $this->createPaidInvoice($reservation, 50000);

        $responseCheckoutDay = $this->getJson('/api/reservations/all?date=2026-06-23&status=paid');
        $responseCheckoutDay->assertOk();
        $responseCheckoutDay->assertJsonFragment(['client_name' => 'Boundary Client']);

        $responseCheckoutDayAll = $this->getJson('/api/reservations/all?date=2026-06-23&status=all');
        $responseCheckoutDayAll->assertOk();
        $responseCheckoutDayAll->assertJsonFragment(['client_name' => 'Boundary Client']);
    }

    public function test_reservations_all_counts_extras_for_each_night_in_total_price(): void
    {
        $user = User::create([
            'name' => 'Reception Test',
            'email' => 'reservation-listing-nightly-extras@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'Nightly Extras Client',
            'client_phone' => '0340000456',
            'customer_phone' => '0340000456',
            'customer_email' => 'nightly-extras-client@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'source' => 'Appel',
            'check_in_date' => '2026-06-22',
            'check_out_date' => '2026-06-25',
            'status' => 'arrive',
            'payment_status' => 'unbilled',
            'extra_beds' => 1,
            'extra_mattresses' => 2,
        ]);
        $reservation->rooms()->attach($this->createRoom('905')->id, ['price_snapshot_ariary' => 50000]);

        $response = $this->getJson('/api/reservations/all?date=2026-06-23&status=all');

        $response->assertOk();
        $reservationData = collect($response->json())->firstWhere('client_name', 'Nightly Extras Client');

        $this->assertNotNull($reservationData);
        $this->assertSame(480000, (int) $reservationData['total_price']);
        $this->assertSame(480000, (int) $reservationData['fixed_total_price']);
    }

    public function test_reservations_all_includes_the_checkin_actor_after_checkin(): void
    {
        $user = User::create([
            'name' => 'Reception Test',
            'email' => 'reservation-listing-checkin-actor@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $reservation = $this->createReservation($user->id, 'Checkin Actor Client', 'en_attente');

        $checkInResponse = $this->postJson("/api/reservations/{$reservation->id}/checkin", [
            'full_name' => 'Checkin Actor Client',
            'first_name' => 'Checkin',
            'last_name' => 'Actor Client',
            'customer_phone' => '0340000777',
            'phone_number' => '0340000777',
            'date_of_birth' => '1992-04-04',
            'sex' => 'Femme',
            'id_type' => 'CIN',
            'id_number' => 'CIN-777777',
            'id_document_number' => 'CIN-777777',
            'checked_in_by_name' => 'Reception Test',
            'checked_in_by_role' => 'receptionist',
        ]);

        $checkInResponse->assertOk();

        $response = $this->getJson('/api/reservations/all?date=2026-06-20&status=all');
        $response->assertOk();

        $reservationData = collect($response->json())->firstWhere('client_name', 'Checkin Actor Client');
        $this->assertNotNull($reservationData);
        $this->assertSame('Reception Test', $reservationData['check_in_by']);
        $this->assertSame('receptionist', $reservationData['check_in_role']);
    }

    public function test_reservation_status_summary_returns_counts_without_full_listing(): void
    {
        $user = User::create([
            'name' => 'Reception Test',
            'email' => 'reservation-summary@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $this->createReservation($user->id, 'Pending Client', 'en_attente');
        $this->createReservation($user->id, 'Arrived Client', 'arrive');
        $this->createReservation($user->id, 'Cancelled Client', 'annule');

        $response = $this->getJson('/api/dashboard/reservation-status-summary?date=2026-06-20');

        $response->assertOk();
        $response->assertJson([
            'date' => '2026-06-20',
            'pending' => 1,
            'arrived' => 1,
        ]);
    }

    private function createRoom(string $number): Room
    {
        return Room::create([
            'room_number' => $number,
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 50000,
            'is_fixed_price' => false,
        ]);
    }

    private function createReservation(
        int $userId,
        string $name,
        string $status,
        string $paymentStatus = 'unbilled',
    ): Reservation
    {
        return Reservation::create([
            'user_id' => $userId,
            'client_name' => $name,
            'client_phone' => '0340000999',
            'customer_phone' => '0340000999',
            'customer_email' => strtolower(str_replace(' ', '.', $name)) . '@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'source' => 'Appel',
            'check_in_date' => '2026-06-20',
            'check_out_date' => '2026-06-21',
            'status' => $status,
            'payment_status' => $paymentStatus,
            'extra_beds' => 0,
            'extra_mattresses' => 0,
        ]);
    }

    private function createPaidInvoice(Reservation $reservation, int $amount): void
    {
        $invoice = Invoice::create([
            'reservation_id' => $reservation->id,
            'invoice_number' => 'FACT-' . uniqid(),
            'total_amount_ariary' => $amount,
            'tax_amount_ariary' => 0,
            'discount_mode' => null,
            'discount_value' => null,
            'discount_amount_ariary' => 0,
            'deposit_amount_ariary' => 0,
            'pdf_path' => null,
            'finalized_at' => null,
            'status' => 'paid',
            'document_type' => 'facture',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Séjour chambre',
            'type' => 'room',
            'amount_ariary' => $amount,
            'quantity' => 1,
        ]);

        Payment::create([
            'invoice_id' => $invoice->id,
            'amount_ariary' => $amount,
            'payment_method' => 'Espèces',
            'payment_context' => 'payment',
            'processed_by_name' => 'Reception Test',
            'processed_by_role' => 'receptionist',
        ]);
    }
}
