<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationAudit;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-20 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

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

    public function test_checked_in_room_change_keeps_consumed_night_billable_for_receptionist(): void
    {
        $this->travelTo(Carbon::parse('2026-07-21 10:00:00'));

        $user = User::create([
            'name' => 'Reception Room Move',
            'email' => 'reception-room-move@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $room1 = Room::create([
            'room_number' => '601',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 50000,
            'is_fixed_price' => false,
        ]);

        $room2 = Room::create([
            'room_number' => '602',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 70000,
            'is_fixed_price' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'Client Changement',
            'client_phone' => '0340000099',
            'customer_phone' => '0340000099',
            'customer_email' => 'changement@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'source' => 'direct',
            'check_in_date' => '2026-07-20',
            'check_out_date' => '2026-07-23',
            'status' => 'arrive',
            'payment_status' => 'unbilled',
            'extra_beds' => 0,
            'extra_mattresses' => 0,
        ]);

        $reservation->rooms()->attach($room1->id, [
            'price_snapshot_ariary' => 50000,
            'segment_start_date' => '2026-07-20',
            'segment_end_date' => '2026-07-23',
        ]);

        $update = $this->putJson("/api/reservations/{$reservation->id}", [
            'client_name' => 'Client Changement',
            'customer_phone' => '0340000099',
            'customer_email' => 'changement@example.com',
            'check_in' => '2026-07-20',
            'check_out' => '2026-07-23',
            'room_ids' => [$room2->id],
            'room_segments' => [
                [
                    'room_id' => $room2->id,
                    'segment_start_date' => '2026-07-20',
                    'segment_end_date' => '2026-07-23',
                    'segment_extra_beds' => 0,
                    'segment_extra_mattresses' => 0,
                ],
            ],
            'extra_beds' => 0,
            'extra_mattresses' => 0,
            'modified_by_name' => $user->name,
            'modified_by_role' => $user->role,
        ]);

        $update->assertOk();

        $segments = Reservation::query()
            ->with('rooms')
            ->findOrFail($reservation->id)
            ->rooms
            ->mapWithKeys(fn (Room $room) => [
                $room->room_number => [
                    'start' => $room->pivot->segment_start_date->toDateString(),
                    'end' => $room->pivot->segment_end_date->toDateString(),
                    'price' => (int) $room->pivot->price_snapshot_ariary,
                ],
            ]);

        $this->assertSame([
            'start' => '2026-07-20',
            'end' => '2026-07-21',
            'price' => 50000,
        ], $segments['601']);
        $this->assertSame([
            'start' => '2026-07-21',
            'end' => '2026-07-23',
            'price' => 70000,
        ], $segments['602']);
    }

    public function test_admin_can_retroactively_extend_a_finished_stay_without_replacing_its_room_link(): void
    {
        $this->travelTo(Carbon::parse('2026-07-26 10:00:00'));

        [$user, $room, $reservation] = $this->createFinishedStay(
            'Admin Correction',
            'admin-correction@example.com',
            'RETRO-701',
        );
        $initialBookingRoomId = (int) $reservation->rooms()->firstOrFail()->pivot->id;

        $initialFolio = $this->getJson(
            "/api/reservations/{$reservation->id}/folio",
        )->assertOk();
        $initialFolio->assertJsonPath('total_amount_ariary', 50000);

        $update = $this->putJson("/api/reservations/{$reservation->id}", [
            'client_name' => $reservation->client_name,
            'customer_phone' => $reservation->customer_phone,
            'customer_email' => $reservation->customer_email,
            'check_in' => '2026-07-23',
            'check_out' => '2026-07-26',
            'room_ids' => [$room->id],
            'room_segments' => [
                [
                    'room_id' => $room->id,
                    'segment_start_date' => '2026-07-23',
                    'segment_end_date' => '2026-07-24',
                    'segment_extra_beds' => 0,
                    'segment_extra_mattresses' => 0,
                ],
            ],
            'extra_beds' => 0,
            'extra_mattresses' => 0,
            'modified_by_name' => $user->name,
            'modified_by_role' => 'admin',
        ]);

        $update->assertOk();

        $reservation->refresh()->load('rooms');
        $this->assertSame(
            '2026-07-26',
            $reservation->check_out_date->toDateString(),
        );
        $this->assertCount(1, $reservation->rooms);
        $this->assertSame($room->id, $reservation->rooms->first()->id);
        $this->assertSame(
            $initialBookingRoomId,
            (int) $reservation->rooms->first()->pivot->id,
        );
        $this->assertSame(
            '2026-07-26',
            $reservation->rooms->first()->pivot->segment_end_date->toDateString(),
        );

        $audit = ReservationAudit::query()
            ->where('reservation_id', $reservation->id)
            ->where('action', 'modified')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($user->name, $audit->actor_name);
        $this->assertSame('admin', $audit->actor_role);
        $this->assertSame(
            '2026-07-24',
            $audit->details['check_out']['before'],
        );
        $this->assertSame(
            '2026-07-26',
            $audit->details['check_out']['after'],
        );
        $this->assertTrue($audit->details['retroactive_extension']['after']);

        $updatedFolio = $this->getJson(
            "/api/reservations/{$reservation->id}/folio",
        )->assertOk();
        $updatedFolio->assertJsonPath('total_amount_ariary', 150000);
        $updatedFolio->assertJsonPath('items.0.quantity', 3);
    }

    public function test_receptionist_cannot_retroactively_extend_a_finished_stay(): void
    {
        $this->travelTo(Carbon::parse('2026-07-26 10:00:00'));

        [$user, $room, $reservation] = $this->createFinishedStay(
            'Reception Correction',
            'reception-correction@example.com',
            'RETRO-702',
        );

        $update = $this->putJson("/api/reservations/{$reservation->id}", [
            'client_name' => $reservation->client_name,
            'customer_phone' => $reservation->customer_phone,
            'customer_email' => $reservation->customer_email,
            'check_in' => '2026-07-23',
            'check_out' => '2026-07-26',
            'room_ids' => [$room->id],
            'extra_beds' => 0,
            'extra_mattresses' => 0,
            'modified_by_name' => $user->name,
            'modified_by_role' => 'receptionist',
        ]);

        $update->assertStatus(422);
        $update->assertJsonPath(
            'message',
            'Seules les réservations non terminées peuvent être modifiées.',
        );

        $reservation->refresh()->load('rooms');
        $this->assertSame(
            '2026-07-24',
            $reservation->check_out_date->toDateString(),
        );
        $this->assertSame(
            '2026-07-24',
            $reservation->rooms->first()->pivot->segment_end_date->toDateString(),
        );
    }

    public function test_retroactive_extension_is_rejected_when_the_room_was_already_occupied(): void
    {
        $this->travelTo(Carbon::parse('2026-07-26 10:00:00'));

        [$user, $room, $reservation] = $this->createFinishedStay(
            'Admin Conflit',
            'admin-conflit@example.com',
            'RETRO-703',
        );
        $conflictingReservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'Autre Client',
            'client_phone' => '0340000072',
            'customer_phone' => '0340000072',
            'customer_email' => 'autre-client@example.com',
            'booking_reference' => 'BR-CONFLICT-' . uniqid(),
            'source' => 'Appel',
            'check_in_date' => '2026-07-24',
            'check_out_date' => '2026-07-26',
            'status' => 'arrive',
            'payment_status' => 'unbilled',
            'extra_beds' => 0,
            'extra_mattresses' => 0,
        ]);
        $conflictingReservation->rooms()->attach($room->id, [
            'price_snapshot_ariary' => 50000,
            'segment_start_date' => '2026-07-24',
            'segment_end_date' => '2026-07-26',
        ]);

        $update = $this->putJson("/api/reservations/{$reservation->id}", [
            'client_name' => $reservation->client_name,
            'customer_phone' => $reservation->customer_phone,
            'customer_email' => $reservation->customer_email,
            'check_in' => '2026-07-23',
            'check_out' => '2026-07-26',
            'room_ids' => [$room->id],
            'extra_beds' => 0,
            'extra_mattresses' => 0,
            'modified_by_name' => $user->name,
            'modified_by_role' => 'admin',
        ]);

        $update->assertStatus(422);
        $this->assertStringContainsString(
            $room->room_number,
            $update->json('message'),
        );

        $reservation->refresh()->load('rooms');
        $this->assertSame(
            '2026-07-24',
            $reservation->check_out_date->toDateString(),
        );
        $this->assertSame(
            '2026-07-24',
            $reservation->rooms->first()->pivot->segment_end_date->toDateString(),
        );
    }

    private function createFinishedStay(
        string $userName,
        string $email,
        string $roomNumber,
    ): array {
        $user = User::create([
            'name' => $userName,
            'email' => $email,
            'password' => 'password',
            'role' => 'admin',
            'is_blacklisted' => false,
        ]);
        $room = Room::create([
            'room_number' => $roomNumber,
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 50000,
            'is_fixed_price' => false,
        ]);
        $reservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'Client ' . $userName,
            'client_phone' => '0340000071',
            'customer_phone' => '0340000071',
            'customer_email' => 'client-' . $email,
            'booking_reference' => 'BR-' . uniqid(),
            'source' => 'Appel',
            'check_in_date' => '2026-07-23',
            'check_out_date' => '2026-07-24',
            'status' => 'arrive',
            'payment_status' => 'unbilled',
            'extra_beds' => 0,
            'extra_mattresses' => 0,
        ]);
        $reservation->rooms()->attach($room->id, [
            'price_snapshot_ariary' => 50000,
            'segment_start_date' => '2026-07-23',
            'segment_end_date' => '2026-07-24',
        ]);

        return [$user, $room, $reservation->refresh()->load('rooms')];
    }
}
