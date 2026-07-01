<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationCancellationAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_cancel_an_arrived_reservation(): void
    {
        $receptionist = User::create([
            'name' => 'Reception Test',
            'email' => 'reception-cancel@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $admin = User::create([
            'name' => 'Admin Test',
            'email' => 'admin-cancel@example.com',
            'password' => 'password',
            'role' => 'admin',
            'is_blacklisted' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $receptionist->id,
            'client_name' => 'Cancel Client',
            'client_phone' => '0340000009',
            'customer_phone' => '0340000009',
            'customer_email' => 'cancel@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'source' => 'direct',
            'check_in_date' => '2026-06-16',
            'check_out_date' => '2026-06-17',
            'status' => 'arrive',
            'payment_status' => 'paid',
            'extra_beds' => 0,
            'extra_mattresses' => 0,
        ]);

        $blocked = $this->postJson('/api/bookings/update-status', [
            'id' => $reservation->id,
            'status' => 'annule',
            'cancelled_by_name' => $receptionist->name,
            'cancelled_by_role' => $receptionist->role,
        ]);

        $blocked->assertStatus(403);
        $this->assertSame('arrive', Reservation::query()->findOrFail($reservation->id)->status);

        $allowed = $this->postJson('/api/bookings/update-status', [
            'id' => $reservation->id,
            'status' => 'annule',
            'cancelled_by_name' => $admin->name,
            'cancelled_by_role' => $admin->role,
        ]);

        $allowed->assertOk();
        $this->assertSame('annule', Reservation::query()->findOrFail($reservation->id)->status);
    }

    public function test_only_admin_can_cancel_a_reservation_with_deposit(): void
    {
        $receptionist = User::create([
            'name' => 'Reception Deposit',
            'email' => 'reception-deposit@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $admin = User::create([
            'name' => 'Admin Deposit',
            'email' => 'admin-deposit@example.com',
            'password' => 'password',
            'role' => 'admin',
            'is_blacklisted' => false,
        ]);

        $room = Room::create([
            'room_number' => '202',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 50000,
            'is_fixed_price' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $receptionist->id,
            'client_name' => 'Deposit Client',
            'client_phone' => '0340000010',
            'customer_phone' => '0340000010',
            'customer_email' => 'deposit@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'source' => 'Appel',
            'check_in_date' => '2026-06-20',
            'check_out_date' => '2026-06-21',
            'status' => 'en_attente',
            'payment_status' => 'unbilled',
            'extra_beds' => 0,
            'extra_mattresses' => 0,
        ]);

        $reservation->rooms()->attach($room->id, [
            'price_snapshot_ariary' => 50000,
        ]);

        $this->postJson("/api/reservations/{$reservation->id}/deposit", [
            'amount_ariary' => 20000,
            'payment_method' => 'Espèces',
            'processed_by_name' => $receptionist->name,
            'processed_by_role' => $receptionist->role,
            'reference' => 'ACPT-002',
        ])->assertOk();

        $blocked = $this->postJson('/api/bookings/update-status', [
            'id' => $reservation->id,
            'status' => 'annule',
            'cancelled_by_name' => $receptionist->name,
            'cancelled_by_role' => $receptionist->role,
        ]);

        $blocked->assertStatus(403);
        $this->assertSame('en_attente', Reservation::query()->findOrFail($reservation->id)->status);

        $allowed = $this->postJson('/api/bookings/update-status', [
            'id' => $reservation->id,
            'status' => 'annule',
            'cancelled_by_name' => $admin->name,
            'cancelled_by_role' => $admin->role,
        ]);

        $allowed->assertOk();
        $this->assertSame('annule', Reservation::query()->findOrFail($reservation->id)->status);
    }
}
