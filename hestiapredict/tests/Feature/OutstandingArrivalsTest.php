<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Guest;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutstandingArrivalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-27 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_outstanding_arrivals_summary_only_contains_checked_in_unpaid_or_partial_stays(): void
    {
        [$individual, $organization] = $this->seedOutstandingArrivals();

        $response = $this->getJson('/api/dashboard/outstanding-arrivals?type=all');

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('summary.count', 2);
        $response->assertJsonPath('summary.total_amount_ariary', 400000);
        $response->assertJsonPath('summary.paid_amount_ariary', 100000);
        $response->assertJsonPath('summary.balance_amount_ariary', 300000);
        $response->assertJsonPath('rankings.organizations.0.name', 'MADA TEST');
        $response->assertJsonPath('rankings.organizations.0.balance_amount_ariary', 200000);
        $response->assertJsonPath('rankings.individuals.0.name', 'Client Impayé');
        $response->assertJsonPath('rankings.individuals.0.balance_amount_ariary', 100000);
        $response->assertJsonPath('rankings.individuals.0.guest.id_type', 'CIN');
        $response->assertJsonPath('data.0.guest.id_number', '101012345678');
        $response->assertJsonCount(2, 'data');
        $response->assertJsonFragment([
            'reference' => $individual->booking_reference,
            'payment_status' => 'unpaid',
            'balance_amount_ariary' => 100000,
        ]);
        $response->assertJsonFragment([
            'reference' => $organization->booking_reference,
            'payment_status' => 'partial',
            'balance_amount_ariary' => 200000,
        ]);
    }

    public function test_outstanding_arrivals_can_filter_by_booking_type_and_search(): void
    {
        [$individual, $organization] = $this->seedOutstandingArrivals();

        $this->getJson('/api/dashboard/outstanding-arrivals?type=individual')
            ->assertOk()
            ->assertJsonPath('summary.count', 1)
            ->assertJsonPath('summary.balance_amount_ariary', 100000)
            ->assertJsonPath('data.0.reference', $individual->booking_reference)
            ->assertJsonPath('data.0.booking_type', 'individual');

        $this->getJson('/api/dashboard/outstanding-arrivals?type=organization&q=MADA')
            ->assertOk()
            ->assertJsonPath('summary.count', 1)
            ->assertJsonPath('summary.balance_amount_ariary', 200000)
            ->assertJsonPath('data.0.reference', $organization->booking_reference)
            ->assertJsonPath('data.0.organization_name', 'MADA TEST');

        $this->getJson('/api/dashboard/outstanding-arrivals?q=Client%20Soldé')
            ->assertOk()
            ->assertJsonPath('summary.count', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_individual_ranking_combines_stays_with_different_guest_records(): void
    {
        [$individual] = $this->seedOutstandingArrivals();
        $individual->update(['client_name' => 'LIKO KININIKA']);
        $individual->guest()->update(['full_name' => 'LIKO KININIKA', 'phone_number' => '0341131021']);
        $individual->invoice()->update(['total_amount_ariary' => 338500]);

        $secondRoom = Room::create([
            'room_number' => 'DUE-106',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 125000,
            'is_fixed_price' => false,
        ]);
        $secondStay = $this->createReservationWithInvoice(
            $individual->user,
            $secondRoom,
            'LIKO KININIKA',
            'RES-DUE-LIKO-2',
            'arrive',
            'individual',
            null,
            125000,
            0,
            '2026-07-26',
        );
        Guest::create([
            'reservation_id' => $secondStay->id,
            'full_name' => 'LIKO KININIKA',
            'phone_number' => '0341131021',
            'date_of_birth' => '1990-01-15',
            'id_type' => 'CIN',
            'id_number' => '101012345679',
        ]);

        $this->getJson('/api/dashboard/outstanding-arrivals?type=individual')
            ->assertOk()
            ->assertJsonPath('rankings.individuals.0.name', 'LIKO KININIKA')
            ->assertJsonPath('rankings.individuals.0.stay_count', 2)
            ->assertJsonPath('rankings.individuals.0.balance_amount_ariary', 463500);
    }

    /**
     * @return array{Reservation, Reservation}
     */
    private function seedOutstandingArrivals(): array
    {
        $user = User::create([
            'name' => 'Admin Encaissements',
            'email' => 'admin-encaissements@example.com',
            'password' => 'password',
            'role' => 'admin',
            'is_blacklisted' => false,
        ]);
        $organization = Organization::create([
            'name' => 'MADA TEST',
            'phone' => '020000001',
        ]);
        $rooms = collect([
            ['room_number' => 'DUE-101', 'price' => 100000],
            ['room_number' => 'DUE-102', 'price' => 300000],
            ['room_number' => 'DUE-103', 'price' => 200000],
            ['room_number' => 'DUE-104', 'price' => 150000],
            ['room_number' => 'DUE-105', 'price' => 175000],
        ])->map(fn (array $room) => Room::create([
            'room_number' => $room['room_number'],
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => $room['price'],
            'is_fixed_price' => false,
        ]));

        $individual = $this->createReservationWithInvoice(
            $user,
            $rooms[0],
            'Client Impayé',
            'RES-DUE-IND',
            'arrive',
            'individual',
            null,
            100000,
            0,
            '2026-07-26',
        );
        Guest::create([
            'reservation_id' => $individual->id,
            'full_name' => 'Client Impayé',
            'phone_number' => '0340000000',
            'id_type' => 'CIN',
            'id_number' => '101012345678',
            'date_of_birth' => '1990-01-15',
            'sex' => 'F',
        ]);
        $organizationReservation = $this->createReservationWithInvoice(
            $user,
            $rooms[1],
            'Contact MADA',
            'RES-DUE-ORG',
            'check_out_manuel',
            'organization',
            $organization,
            300000,
            100000,
            '2026-07-29',
        );
        $this->createReservationWithInvoice(
            $user,
            $rooms[2],
            'Client Soldé',
            'RES-DUE-PAID',
            'arrive',
            'individual',
            null,
            200000,
            200000,
            '2026-07-27',
        );
        $this->createReservationWithInvoice(
            $user,
            $rooms[3],
            'Client Pas Arrivé',
            'RES-DUE-PENDING',
            'en_attente',
            'individual',
            null,
            150000,
            0,
            '2026-07-29',
        );
        $this->createReservationWithInvoice(
            $user,
            $rooms[4],
            'Client Séjour En Cours',
            'RES-DUE-ONGOING',
            'arrive',
            'individual',
            null,
            175000,
            0,
            '2026-07-29',
        );

        return [$individual, $organizationReservation];
    }

    private function createReservationWithInvoice(
        User $user,
        Room $room,
        string $clientName,
        string $reference,
        string $status,
        string $bookingType,
        ?Organization $organization,
        int $total,
        int $paid,
        string $checkOut,
    ): Reservation {
        $reservation = Reservation::create([
            'user_id' => $user->id,
            'organization_id' => $organization?->id,
            'client_name' => $clientName,
            'client_phone' => '0340000400',
            'customer_phone' => '0340000400',
            'customer_email' => strtolower(str_replace(' ', '-', $reference)) . '@example.com',
            'booking_reference' => $reference,
            'booking_type' => $bookingType,
            'source' => 'Appel',
            'check_in_date' => '2026-07-26',
            'check_out_date' => $checkOut,
            'status' => $status,
            'payment_status' => $paid <= 0
                ? 'unpaid'
                : ($paid < $total ? 'partial' : 'paid'),
            'extra_beds' => 0,
            'extra_mattresses' => 0,
        ]);
        $reservation->rooms()->attach($room->id, [
            'price_snapshot_ariary' => $room->base_price_ariary,
            'segment_start_date' => '2026-07-26',
            'segment_end_date' => $checkOut,
        ]);
        $invoice = Invoice::create([
            'reservation_id' => $reservation->id,
            'organization_id' => $organization?->id,
            'invoice_number' => 'FACT-' . $reference,
            'total_amount_ariary' => $total,
            'tax_amount_ariary' => 0,
            'discount_mode' => null,
            'discount_value' => null,
            'discount_amount_ariary' => 0,
            'deposit_amount_ariary' => 0,
            'pdf_path' => null,
            'finalized_at' => null,
            'status' => $paid <= 0
                ? 'open'
                : ($paid < $total ? 'partial' : 'paid'),
            'document_type' => 'facture',
            'billing_mode' => 'grouped',
            'invoice_kind' => 'master',
        ]);

        if ($paid > 0) {
            Payment::create([
                'invoice_id' => $invoice->id,
                'amount_ariary' => $paid,
                'amount_received_ariary' => $paid,
                'change_given_ariary' => 0,
                'payment_method' => 'Espèces',
                'payment_context' => 'payment',
                'processed_by_name' => $user->name,
                'processed_by_role' => $user->role,
            ]);
        }

        return $reservation;
    }
}
