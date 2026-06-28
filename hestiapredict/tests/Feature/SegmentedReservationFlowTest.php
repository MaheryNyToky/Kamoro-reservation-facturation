<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SegmentedReservationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_segmented_reservation_can_be_created_checked_in_billed_and_updated_for_remaining_nights(): void
    {
        $user = $this->createReceptionUser();
        $room1 = $this->createRoom('801', 110000);
        $room2 = $this->createRoom('802', 110000);
        $room3 = $this->createRoom('803', 110000);

        $createResponse = $this->postJson('/api/bookings', [
            'client_name' => 'Client Segmente',
            'customer_phone' => '0348000001',
            'customer_email' => null,
            'check_in' => '2026-08-01',
            'check_out' => '2026-08-04',
            'room_ids' => [$room1->id, $room2->id],
            'room_segments' => [
                [
                    'room_id' => $room1->id,
                    'segment_start_date' => '2026-08-01',
                    'segment_end_date' => '2026-08-02',
                    'segment_extra_beds' => 1,
                    'segment_extra_mattresses' => 0,
                ],
                [
                    'room_id' => $room2->id,
                    'segment_start_date' => '2026-08-02',
                    'segment_end_date' => '2026-08-04',
                    'segment_extra_beds' => 0,
                    'segment_extra_mattresses' => 1,
                ],
            ],
            'room_prices' => [
                ['id' => $room1->id, 'price' => 110000],
                ['id' => $room2->id, 'price' => 110000],
                ['id' => $room3->id, 'price' => 110000],
            ],
            'extra_beds' => 1,
            'extra_mattresses' => 1,
            'source' => 'Appel',
            'receptionist_name' => $user->name,
        ]);

        $createResponse->assertCreated();

        $reservation = Reservation::query()->with('rooms')->where('client_name', 'Client Segmente')->firstOrFail();
        $this->assertCount(2, $reservation->rooms);
        $this->assertSame('2026-08-01', $reservation->rooms[0]->pivot->segment_start_date->toDateString());
        $this->assertSame('2026-08-04', $reservation->rooms[1]->pivot->segment_end_date->toDateString());

        $this->postJson("/api/reservations/{$reservation->id}/checkin", [
            'full_name' => 'Client Segmente',
            'first_name' => 'Client',
            'last_name' => 'Segmente',
            'customer_phone' => '0348000001',
            'phone_number' => '0348000001',
            'date_of_birth' => '1990-01-01',
            'sex' => 'Homme',
            'id_type' => 'CIN',
            'id_number' => 'CIN-SEG-001',
            'id_document_number' => 'CIN-SEG-001',
            'checked_in_by_name' => $user->name,
            'checked_in_by_role' => $user->role,
        ])->assertOk();

        $this->postJson("/api/reservations/{$reservation->id}/deposit", [
            'amount_ariary' => 50000,
            'payment_method' => 'Espèces',
            'processed_by_name' => $user->name,
            'processed_by_role' => $user->role,
            'reference' => 'DEP-SEG-001',
        ])->assertOk();

        $folio = $this->getJson("/api/reservations/{$reservation->id}/folio");
        $folio->assertOk();

        $invoiceId = $folio->json('id');
        $this->assertNotEmpty($invoiceId);

        $this->postJson("/api/invoices/{$invoiceId}/generate-pdf", [
            'document_type' => 'proforma',
            'billing_mode' => 'grouped',
            'currency_mode' => 'ariary',
            'actor_role' => $user->role,
        ])->assertOk();

        $updateResponse = $this->putJson("/api/reservations/{$reservation->id}", [
            'client_name' => 'Client Segmente',
            'customer_phone' => '0348000001',
            'customer_email' => null,
            'check_in' => '2026-08-01',
            'check_out' => '2026-08-04',
            'room_ids' => [$room1->id, $room3->id],
            'room_segments' => [
                [
                    'room_id' => $room1->id,
                    'segment_start_date' => '2026-08-01',
                    'segment_end_date' => '2026-08-02',
                    'segment_extra_beds' => 1,
                    'segment_extra_mattresses' => 0,
                ],
                [
                    'room_id' => $room3->id,
                    'segment_start_date' => '2026-08-02',
                    'segment_end_date' => '2026-08-04',
                    'segment_extra_beds' => 0,
                    'segment_extra_mattresses' => 1,
                ],
            ],
            'extra_beds' => 1,
            'extra_mattresses' => 1,
            'modified_by_name' => $user->name,
            'modified_by_role' => $user->role,
        ]);

        $updateResponse->assertOk();

        $updated = Reservation::query()->with('rooms')->findOrFail($reservation->id);
        $this->assertSame([801, 803], $updated->rooms->pluck('room_number')->map(fn ($value) => (int) $value)->sort()->values()->all());
        $updatedRoom3 = $updated->rooms->firstWhere('id', $room3->id);
        $this->assertNotNull($updatedRoom3);
        $this->assertSame('2026-08-02', $updatedRoom3->pivot->segment_start_date->toDateString());
        $this->assertSame('2026-08-04', $updatedRoom3->pivot->segment_end_date->toDateString());

        $folioAfterUpdate = $this->getJson("/api/reservations/{$reservation->id}/folio");
        $folioAfterUpdate->assertOk();
        $roomBookings = collect($folioAfterUpdate->json('room_bookings'));
        $this->assertTrue($roomBookings->contains(fn (array $room) => (int) $room['room_id'] === $room3->id));
        $this->assertFalse($roomBookings->contains(fn (array $room) => (int) $room['room_id'] === $room2->id));

        $invoice = Invoice::query()->findOrFail($invoiceId);
        $this->assertSame('proforma', $invoice->document_type);
        $this->assertNotNull($invoice->pdf_path);
    }

    private function createReceptionUser(): User
    {
        return User::create([
            'name' => 'Reception Segmente',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);
    }

    private function createRoom(string $roomNumber, int $price): Room
    {
        return Room::create([
            'room_number' => $roomNumber,
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => $price,
            'is_fixed_price' => false,
        ]);
    }
}
