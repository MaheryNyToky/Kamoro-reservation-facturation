<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingTypeFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_individual_booking_flow_works_without_billing_mode(): void
    {
        $this->createBookingUser();
        $room = $this->createRoom('701');

        $response = $this->postJson('/api/bookings', [
            'client_name' => 'Client Test',
            'customer_phone' => '0347000001',
            'customer_email' => null,
            'check_in' => '2026-07-01',
            'check_out' => '2026-07-02',
            'room_ids' => [$room->id],
            'room_prices' => [
                ['id' => $room->id, 'price' => 110000],
            ],
            'extra_beds' => 0,
            'extra_mattresses' => 0,
            'source' => 'Appel',
            'receptionist_name' => 'Reception Test',
        ]);

        $response->assertCreated();

        $reservation = Reservation::query()
            ->where('client_name', 'Client Test')
            ->firstOrFail();

        $this->assertSame('individual', $reservation->booking_type);

        $depositResponse = $this->postJson("/api/reservations/{$reservation->id}/deposit", [
            'amount_ariary' => 10000,
            'payment_method' => 'Espèces',
            'processed_by_name' => 'Reception Test',
            'processed_by_role' => 'receptionist',
        ]);

        $depositResponse->assertOk();
        $this->getJson("/api/reservations/{$reservation->id}/folio")->assertOk();
    }

    public function test_organization_booking_flow_works_without_billing_mode(): void
    {
        $this->createBookingUser();
        $room = $this->createRoom('702');

        $response = $this->postJson('/api/bookings', [
            'client_name' => 'Organisme Test',
            'customer_phone' => '0347000002',
            'customer_email' => null,
            'organization_name' => 'Organisme Test',
            'organization_phone' => '020700000',
            'organization_contact_name' => 'Contact Organisme',
            'organization_contact_phone' => '0347000002',
            'organization_contact_email' => null,
            'organization_email' => null,
            'organization_billing_address' => 'Adresse Organisme',
            'organization_nif' => 'NIF-ORG-001',
            'organization_stat' => 'STAT-ORG-001',
            'check_in' => '2026-07-03',
            'check_out' => '2026-07-04',
            'room_ids' => [$room->id],
            'room_prices' => [
                ['id' => $room->id, 'price' => 110000],
            ],
            'extra_beds' => 0,
            'extra_mattresses' => 0,
            'source' => 'Appel',
            'receptionist_name' => 'Reception Test',
        ]);

        $response->assertCreated();

        $reservation = Reservation::query()
            ->where('client_name', 'Organisme Test')
            ->firstOrFail();

        $this->assertSame('organization', $reservation->booking_type);
        $this->assertNotNull($reservation->organization_id);

        $depositResponse = $this->postJson("/api/reservations/{$reservation->id}/deposit", [
            'amount_ariary' => 10000,
            'payment_method' => 'Espèces',
            'processed_by_name' => 'Reception Test',
            'processed_by_role' => 'receptionist',
        ]);

        $depositResponse->assertOk();
        $this->getJson("/api/reservations/{$reservation->id}/folio")->assertOk();
    }

    private function createBookingUser(): User
    {
        return User::create([
            'name' => 'Reception Test',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);
    }

    private function createRoom(string $roomNumber): Room
    {
        return Room::create([
            'room_number' => $roomNumber,
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 110000,
            'is_fixed_price' => false,
        ]);
    }
}
