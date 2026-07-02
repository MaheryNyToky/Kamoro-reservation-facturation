<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingConflictTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-19 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_booking_creation_rejects_any_room_already_reserved(): void
    {
        $user = User::create([
            'name' => 'Reception Test',
            'email' => 'reception-booking@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $room1 = Room::create([
            'room_number' => '401',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 50000,
            'is_fixed_price' => false,
        ]);

        $room2 = Room::create([
            'room_number' => '402',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 50000,
            'is_fixed_price' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'Booked Client',
            'client_phone' => '0340000010',
            'customer_phone' => '0340000010',
            'customer_email' => 'booked@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'source' => 'direct',
            'check_in_date' => '2026-06-20',
            'check_out_date' => '2026-06-22',
            'status' => 'en_attente',
            'payment_status' => 'unbilled',
            'extra_beds' => 0,
            'extra_mattresses' => 0,
        ]);

        $reservation->rooms()->attach($room1->id, [
            'price_snapshot_ariary' => 50000,
        ]);

        $response = $this->postJson('/api/bookings', [
            'client_name' => 'Conflict Client',
            'customer_phone' => '0340000011',
            'customer_email' => 'conflict@example.com',
            'check_in' => '2026-06-21',
            'check_out' => '2026-06-23',
            'room_ids' => [$room1->id, $room2->id],
            'source' => 'Appel',
            'receptionist_name' => $user->name,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('room_ids');
    }

    public function test_booking_source_forces_room_price_snapshot_to_booking_rate(): void
    {
        $user = User::create([
            'name' => 'Reception Booking Price',
            'email' => 'reception-booking-price@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $fixedRoom = Room::create([
            'room_number' => '501',
            'type' => 'Chambre Double',
            'model' => 'Supérieure',
            'base_price_ariary' => 95000,
            'is_fixed_price' => true,
        ]);

        $dynamicRoom = Room::create([
            'room_number' => '502',
            'type' => 'Chambre Double',
            'model' => 'Supérieure',
            'base_price_ariary' => 125000,
            'is_fixed_price' => false,
        ]);

        $response = $this->postJson('/api/bookings', [
            'client_name' => 'Booking Price Client',
            'customer_phone' => '0340000021',
            'customer_email' => 'booking-price@example.com',
            'check_in' => '2026-06-24',
            'check_out' => '2026-06-25',
            'room_ids' => [$fixedRoom->id, $dynamicRoom->id],
            'room_prices' => [
                ['id' => $fixedRoom->id, 'price' => 95000],
                ['id' => $dynamicRoom->id, 'price' => 180000],
            ],
            'source' => 'Booking',
            'receptionist_name' => $user->name,
        ]);

        $response->assertCreated();

        $reservation = Reservation::query()
            ->where('client_name', 'Booking Price Client')
            ->with('rooms')
            ->firstOrFail();

        $this->assertSame([162500, 162500], $reservation->rooms
            ->pluck('pivot.price_snapshot_ariary')
            ->map(fn ($price) => (int) $price)
            ->sort()
            ->values()
            ->all());
    }

    public function test_booking_source_rejects_rooms_that_are_not_superior_doubles(): void
    {
        $user = User::create([
            'name' => 'Reception Booking Restriction',
            'email' => 'reception-booking-restriction@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $room = Room::create([
            'room_number' => '601',
            'type' => 'Chambre Twin',
            'model' => 'Supérieure',
            'base_price_ariary' => 125000,
            'is_fixed_price' => false,
        ]);

        $response = $this->postJson('/api/bookings', [
            'client_name' => 'Booking Invalid Client',
            'customer_phone' => '0340000022',
            'customer_email' => 'booking-invalid@example.com',
            'check_in' => '2026-06-24',
            'check_out' => '2026-06-25',
            'room_ids' => [$room->id],
            'source' => 'Booking',
            'receptionist_name' => $user->name,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('room_ids');
    }

    public function test_only_admins_can_create_bookings_in_the_past(): void
    {
        Carbon::setTestNow('2026-07-02 10:00:00');

        try {
            $user = User::create([
                'name' => 'Past Booking User',
                'email' => 'past-booking@example.com',
                'password' => 'password',
                'role' => 'receptionist',
                'is_blacklisted' => false,
            ]);

            $room = Room::create([
                'room_number' => '701',
                'type' => 'Chambre Double',
                'model' => 'Standard',
                'base_price_ariary' => 50000,
                'is_fixed_price' => false,
            ]);

            $payload = [
                'client_name' => 'Past Booking Client',
                'customer_phone' => '0340000030',
                'customer_email' => 'past-booking@example.com',
                'check_in' => '2026-06-01',
                'check_out' => '2026-06-02',
                'room_ids' => [$room->id],
                'room_prices' => [
                    ['id' => $room->id, 'price' => 50000],
                ],
                'source' => 'Appel',
                'receptionist_name' => $user->name,
            ];

            $this->postJson('/api/bookings', [
                ...$payload,
                'actor_role' => 'receptionist',
            ])->assertForbidden();

            $this->postJson('/api/bookings', [
                ...$payload,
                'actor_role' => 'admin',
            ])->assertCreated();

            $this->assertDatabaseHas('reservations', [
                'client_name' => 'Past Booking Client',
                'check_in_date' => '2026-06-01',
                'check_out_date' => '2026-06-02',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }
}
