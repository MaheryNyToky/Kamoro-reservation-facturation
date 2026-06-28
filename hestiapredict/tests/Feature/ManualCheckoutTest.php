<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\ReservationAudit;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_checkout_marks_reservation_and_frees_the_room(): void
    {
        $user = User::create([
            'name' => 'Reception CheckOut',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $room = Room::create([
            'room_number' => '901',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 120000,
            'is_fixed_price' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'Client Sortie Manuelle',
            'client_phone' => '0349000001',
            'customer_phone' => '0349000001',
            'customer_email' => 'manual.checkout@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'source' => 'direct',
            'check_in_date' => '2026-07-10',
            'check_out_date' => '2026-07-12',
            'status' => 'arrive',
            'payment_status' => 'paid',
            'extra_beds' => 0,
            'extra_mattresses' => 0,
        ]);

        $reservation->rooms()->attach($room->id, [
            'price_snapshot_ariary' => 120000,
            'segment_start_date' => '2026-07-10',
            'segment_end_date' => '2026-07-12',
            'segment_extra_beds' => 0,
            'segment_extra_mattresses' => 0,
        ]);

        $beforeAvailability = $this->getJson(
            '/api/available-rooms?check_in=2026-07-11&check_out=2026-07-12'
        );
        $beforeAvailability->assertOk();
        $this->assertFalse(
            collect($beforeAvailability->json())->pluck('id')->contains($room->id)
        );

        $response = $this->postJson("/api/reservations/{$reservation->id}/manual-checkout", [
            'checked_out_by_name' => $user->name,
            'checked_out_by_role' => $user->role,
        ]);

        $response->assertOk();
        $response->assertJsonPath('reservation.status', Reservation::MANUAL_CHECKOUT_STATUS);

        $updatedReservation = Reservation::query()->findOrFail($reservation->id);
        $this->assertSame(Reservation::MANUAL_CHECKOUT_STATUS, $updatedReservation->status);

        $audit = ReservationAudit::query()
            ->where('reservation_id', $reservation->id)
            ->where('action', 'manual_check_out')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($user->name, $audit->actor_name);
        $this->assertSame($user->role, $audit->actor_role);

        $afterAvailability = $this->getJson(
            '/api/available-rooms?check_in=2026-07-11&check_out=2026-07-12'
        );
        $afterAvailability->assertOk();
        $this->assertTrue(
            collect($afterAvailability->json())->pluck('id')->contains($room->id)
        );
    }
}
