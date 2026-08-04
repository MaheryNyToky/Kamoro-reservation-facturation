<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OrganizationDossierRoomIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-25 10:00:00');
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dossier_excludes_roomless_reservations_without_deleting_other_rooms(): void
    {
        $user = User::create([
            'name' => 'Reception Dossier Test',
            'email' => 'reception-dossier-test@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);
        $organization = Organization::create([
            'name' => 'Organisme Dossier Test',
        ]);
        $room = Room::create([
            'room_number' => 'DOSSIER-ROOM-01',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 110000,
            'is_fixed_price' => false,
        ]);

        $reservationWithRoom = $this->createOrganizationReservation(
            $user,
            $organization,
            'RES-WITH-ROOM',
        );
        $reservationWithRoom->rooms()->attach($room->id, [
            'price_snapshot_ariary' => 110000,
            'segment_start_date' => '2026-07-10',
            'segment_end_date' => '2026-07-12',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reservationWithoutRoom = $this->createOrganizationReservation(
            $user,
            $organization,
            'RES-WITHOUT-ROOM',
        );

        $summary = $this->getJson('/api/organization-dossiers?q=Organisme%20Dossier%20Test');
        $summary->assertOk();
        $summary->assertJsonCount(1, 'organizations');
        $summary->assertJsonPath('organizations.0.reservation_count', 1);

        $dossier = $this->getJson("/api/organization-dossiers/{$organization->id}?scope=all");
        $dossier->assertOk();
        $dossier->assertJsonCount(1, 'reservations');
        $dossier->assertJsonPath('reservations.0.id', $reservationWithRoom->id);

        $invoicePreview = $this->postJson(
            "/api/organization-dossiers/{$organization->id}/invoice-pdf",
            [
                'reservation_ids' => [$reservationWithoutRoom->id],
                'document_type' => 'facture',
            ],
        );
        $invoicePreview->assertStatus(422);
        $invoicePreview->assertJsonPath(
            'message',
            'Aucun séjour valide sélectionné pour cette facture.',
        );

        $this->assertDatabaseHas('rooms', [
            'id' => $room->id,
            'room_number' => 'DOSSIER-ROOM-01',
        ]);
        $this->assertDatabaseHas('booking_room', [
            'reservation_id' => $reservationWithRoom->id,
            'room_id' => $room->id,
        ]);
        $this->assertDatabaseHas('reservations', [
            'id' => $reservationWithoutRoom->id,
            'booking_reference' => 'RES-WITHOUT-ROOM',
        ]);
    }

    public function test_dossier_can_group_past_and_future_stays_in_a_proforma(): void
    {
        $user = User::create([
            'name' => 'Reception Dossier Future Test',
            'email' => 'reception-dossier-future-test@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);
        $organization = Organization::create(['name' => 'Organisme Multi Dates']);
        $room = Room::create([
            'room_number' => 'DOSSIER-FUTURE-01',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 120000,
            'is_fixed_price' => false,
        ]);

        $past = $this->createOrganizationReservation($user, $organization, 'RES-PAST');
        $future = $this->createOrganizationReservation($user, $organization, 'RES-FUTURE');
        $future->update([
            'check_in_date' => '2026-08-10',
            'check_out_date' => '2026-08-12',
            'status' => 'en_attente',
        ]);

        foreach ([$past, $future] as $reservation) {
            $reservation->rooms()->attach($room->id, [
                'price_snapshot_ariary' => 120000,
                'segment_start_date' => $reservation->check_in_date,
                'segment_end_date' => $reservation->check_out_date,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $past->refresh()->load('rooms');
        $pastInvoice = Invoice::create([
            'reservation_id' => $past->id,
            'organization_id' => $organization->id,
            'invoice_number' => 'INV-ORG-MULTI-DATES',
            'total_amount_ariary' => 260000,
            'tax_amount_ariary' => 0,
            'discount_mode' => 'amount',
            'discount_value' => 10000,
            'discount_amount_ariary' => 10000,
            'deposit_amount_ariary' => 0,
            'status' => 'open',
            'document_type' => 'facture',
            'billing_mode' => 'grouped',
            'invoice_kind' => 'master',
        ]);
        InvoiceItem::create([
            'invoice_id' => $pastInvoice->id,
            'booking_room_id' => $past->rooms->first()->pivot->id,
            'description' => 'Séjour chambre',
            'type' => 'room',
            'amount_ariary' => 120000,
            'quantity' => 2,
        ]);
        InvoiceItem::create([
            'invoice_id' => $pastInvoice->id,
            'description' => 'Petit-déjeuner',
            'type' => 'extra',
            'amount_ariary' => 30000,
            'quantity' => 1,
        ]);

        $dossier = $this->getJson("/api/organization-dossiers/{$organization->id}?scope=all");
        $dossier->assertOk()->assertJsonCount(2, 'reservations');

        $proforma = $this->postJson(
            "/api/organization-dossiers/{$organization->id}/invoice-pdf",
            [
                'reservation_ids' => [$past->id, $future->id],
                'document_type' => 'proforma',
            ],
        );
        $proforma->assertOk();
        $this->assertSame('application/pdf', $proforma->headers->get('content-type'));
        $this->assertDatabaseHas('invoices', [
            'organization_id' => $organization->id,
            'total_amount_ariary' => 500000,
            'document_type' => 'proforma',
        ]);

        $facture = $this->postJson(
            "/api/organization-dossiers/{$organization->id}/invoice-pdf",
            [
                'reservation_ids' => [$future->id],
                'document_type' => 'facture',
            ],
        );
        $facture->assertStatus(422)
            ->assertJsonPath('message', 'Un séjour futur ne peut être généré qu’en facture proforma.');
    }

    private function createOrganizationReservation(
        User $user,
        Organization $organization,
        string $reference,
    ): Reservation {
        return Reservation::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'client_name' => $organization->name,
            'client_phone' => '0340000099',
            'customer_phone' => '0340000099',
            'customer_email' => 'dossier-test@example.com',
            'check_in_date' => '2026-07-10',
            'check_out_date' => '2026-07-12',
            'status' => 'arrive',
            'source' => 'Appel',
            'booking_reference' => $reference,
            'booking_type' => 'organization',
            'billing_mode' => 'grouped',
            'payment_status' => 'unbilled',
            'extra_beds' => 0,
            'extra_mattresses' => 0,
        ]);
    }
}
