<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingTypeFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-30 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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
            'processed_by_name' => 'Admin Test',
            'processed_by_role' => 'admin',
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
            'processed_by_name' => 'Admin Test',
            'processed_by_role' => 'admin',
        ]);

        $depositResponse->assertOk();
        $this->getJson("/api/reservations/{$reservation->id}/folio")->assertOk();
    }

    public function test_organization_booking_can_split_into_separate_reservations(): void
    {
        $this->createBookingUser();
        $room1 = $this->createRoom('703');
        $room2 = $this->createRoom('704');

        $response = $this->postJson('/api/bookings', [
            'client_name' => 'Organisme Séparé',
            'customer_phone' => '0347000003',
            'customer_email' => null,
            'organization_name' => 'Organisme Séparé',
            'organization_phone' => '020700001',
            'organization_contact_name' => 'Contact Séparé',
            'organization_contact_phone' => '0347000003',
            'organization_contact_email' => null,
            'organization_email' => null,
            'organization_billing_address' => 'Adresse Organisme',
            'organization_nif' => 'NIF-ORG-002',
            'organization_stat' => 'STAT-ORG-002',
            'organization_arrival_mode' => 'separate',
            'check_in' => '2026-07-05',
            'check_out' => '2026-07-07',
            'room_ids' => [$room1->id, $room2->id],
            'room_segments' => [
                [
                    'room_id' => $room1->id,
                    'segment_start_date' => '2026-07-05',
                    'segment_end_date' => '2026-07-07',
                    'segment_extra_beds' => 0,
                    'segment_extra_mattresses' => 0,
                ],
                [
                    'room_id' => $room2->id,
                    'segment_start_date' => '2026-07-05',
                    'segment_end_date' => '2026-07-07',
                    'segment_extra_beds' => 0,
                    'segment_extra_mattresses' => 0,
                ],
            ],
            'room_prices' => [
                ['id' => $room1->id, 'price' => 110000],
                ['id' => $room2->id, 'price' => 110000],
            ],
            'extra_beds' => 0,
            'extra_mattresses' => 0,
            'source' => 'Appel',
            'receptionist_name' => 'Reception Test',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('reservation_count', 2);

        $reservations = Reservation::query()
            ->where('client_name', 'Organisme Séparé')
            ->with('rooms', 'organization')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $reservations);
        $this->assertSame(
            ['703', '704'],
            $reservations->flatMap(fn (Reservation $reservation) => $reservation->rooms->pluck('room_number'))->values()->all(),
        );
        $this->assertSame(
            1,
            $reservations->pluck('organization_id')->unique()->count(),
        );
        $this->assertTrue($reservations->every(fn (Reservation $reservation) => $reservation->booking_type === 'organization'));
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
