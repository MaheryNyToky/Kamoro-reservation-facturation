<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_checked_in_reservation_can_change_rooms_and_extras_but_keeps_identity_fields(): void
    {
        $user = User::create([
            'name' => 'Reception Test',
            'email' => 'reception-update@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $room1 = Room::create([
            'room_number' => '501',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 50000,
            'is_fixed_price' => false,
        ]);

        $room2 = Room::create([
            'room_number' => '502',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 55000,
            'is_fixed_price' => false,
        ]);

        $room3 = Room::create([
            'room_number' => '503',
            'type' => 'Chambre Double',
            'model' => 'Deluxe',
            'base_price_ariary' => 65000,
            'is_fixed_price' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'Paul Maintenu',
            'client_phone' => '0340000012',
            'customer_phone' => '0340000012',
            'customer_email' => 'paul@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'source' => 'direct',
            'check_in_date' => '2026-07-20',
            'check_out_date' => '2026-07-22',
            'status' => 'en_attente',
            'payment_status' => 'unbilled',
            'extra_beds' => 0,
            'extra_mattresses' => 0,
        ]);

        $reservation->rooms()->attach($room1->id, [
            'price_snapshot_ariary' => 50000,
        ]);

        $checkIn = $this->postJson("/api/reservations/{$reservation->id}/checkin", [
            'full_name' => 'Paul Maintenu',
            'first_name' => 'Paul',
            'last_name' => 'Maintenu',
            'customer_phone' => '0340000012',
            'phone_number' => '0340000012',
            'date_of_birth' => '1991-01-01',
            'sex' => 'Homme',
            'id_type' => 'CIN',
            'id_number' => 'CIN-123456',
            'id_document_number' => 'CIN-123456',
            'loyalty_count' => 2,
        ]);

        $checkIn->assertOk();

        Guest::query()->where('reservation_id', $reservation->id)->firstOrFail();

        $update = $this->putJson("/api/reservations/{$reservation->id}", [
            'client_name' => 'Paul Maintenu',
            'customer_phone' => '0340000012',
            'customer_email' => 'paul@example.com',
            'check_in' => '2026-07-20',
            'check_out' => '2026-07-22',
            'room_ids' => [$room2->id, $room3->id],
            'extra_beds' => 1,
            'extra_mattresses' => 2,
            'modified_by_name' => $user->name,
            'modified_by_role' => $user->role,
        ]);

        $update->assertOk();

        $updated = Reservation::query()->with('rooms')->findOrFail($reservation->id);
        $this->assertSame('Paul Maintenu', $updated->client_name);
        $this->assertSame('0340000012', $updated->customer_phone);
        $this->assertSame('paul@example.com', $updated->customer_email);
        $this->assertSame('arrive', $updated->status);
        $this->assertCount(2, $updated->rooms);
        $this->assertSame(
            [$room2->id, $room3->id],
            $updated->rooms->pluck('id')->sort()->values()->all(),
        );
        $this->assertSame(1, (int) $updated->extra_beds);
        $this->assertSame(2, (int) $updated->extra_mattresses);
    }
}
