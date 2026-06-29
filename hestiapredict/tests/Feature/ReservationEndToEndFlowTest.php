<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Reservation;
use App\Models\ReservationAudit;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationEndToEndFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_individual_and_organization_flows_work_end_to_end(): void
    {
        $user = $this->createReceptionUser();
        $individualRoom = $this->createRoom('901');
        $organizationRoom1 = $this->createRoom('902');
        $organizationRoom2 = $this->createRoom('903');

        $individualReservation = $this->createReservation([
            'client_name' => 'Client Individu',
            'customer_phone' => '0349000001',
            'customer_email' => 'individu@example.com',
            'check_in' => '2026-07-10',
            'check_out' => '2026-07-11',
            'room_ids' => [$individualRoom->id],
            'room_prices' => [
                ['id' => $individualRoom->id, 'price' => 110000],
            ],
            'source' => 'Appel',
            'receptionist_name' => $user->name,
        ]);

        $this->assertSame(201, $individualReservation['code']);

        $individual = Reservation::query()
            ->where('client_name', 'Client Individu')
            ->with('rooms')
            ->firstOrFail();

        $this->assertSame('individual', $individual->booking_type);

        $this->postJson("/api/reservations/{$individual->id}/checkin", [
            'full_name' => 'Client Individu',
            'first_name' => 'Client',
            'last_name' => 'Individu',
            'customer_phone' => '0349000001',
            'phone_number' => '0349000001',
            'date_of_birth' => '1990-01-01',
            'sex' => 'Homme',
            'id_type' => 'CIN',
            'id_number' => 'CIN-IND-001',
            'id_document_number' => 'CIN-IND-001',
            'checked_in_by_name' => $user->name,
            'checked_in_by_role' => $user->role,
        ])->assertOk();

        $this->postJson("/api/reservations/{$individual->id}/deposit", [
            'amount_ariary' => 50000,
            'payment_method' => 'Espèces',
            'processed_by_name' => $user->name,
            'processed_by_role' => $user->role,
            'reference' => 'DEP-IND-001',
        ])->assertOk();

        $individualFolio = $this->getJson("/api/reservations/{$individual->id}/folio");
        $individualFolio->assertOk();

        $individualInvoiceId = $individualFolio->json('id');
        $this->assertNotEmpty($individualInvoiceId);

        $this->postJson("/api/invoices/{$individualInvoiceId}/generate-pdf", [
            'document_type' => 'facture',
            'billing_mode' => 'grouped',
            'currency_mode' => 'ariary',
            'actor_role' => $user->role,
        ])->assertOk();

        $organizationReservation = $this->createReservation([
            'client_name' => 'Organisme End To End',
            'customer_phone' => '0349000002',
            'customer_email' => 'contact@organisation.example',
            'organization_name' => 'Organisme End To End',
            'organization_phone' => '020900000',
            'organization_contact_name' => 'Contact Organisme',
            'organization_contact_phone' => '0349000002',
            'organization_contact_email' => 'contact@organisation.example',
            'organization_email' => 'siege@organisation.example',
            'organization_billing_address' => 'Adresse Organisme',
            'organization_nif' => 'NIF-ORG-END-001',
            'organization_stat' => 'STAT-ORG-END-001',
            'check_in' => '2026-07-12',
            'check_out' => '2026-07-14',
            'room_ids' => [$organizationRoom1->id, $organizationRoom2->id],
            'room_prices' => [
                ['id' => $organizationRoom1->id, 'price' => 110000],
                ['id' => $organizationRoom2->id, 'price' => 110000],
            ],
            'source' => 'Appel',
            'receptionist_name' => $user->name,
        ]);

        $this->assertSame(201, $organizationReservation['code']);

        $organization = Reservation::query()
            ->where('client_name', 'Organisme End To End')
            ->with('rooms')
            ->firstOrFail();

        $this->assertSame('organization', $organization->booking_type);

        $this->postJson("/api/reservations/{$organization->id}/checkin", [
            'full_name' => 'Organisme End To End',
            'customer_phone' => '0349000002',
            'phone_number' => '0349000002',
            'date_of_birth' => '1985-05-05',
            'sex' => 'Femme',
            'id_type' => 'CIN',
            'id_number' => 'CIN-ORG-001',
            'id_document_number' => 'CIN-ORG-001',
            'room_checkins' => [
                [
                    'room_id' => $organizationRoom1->id,
                    'occupant_name' => 'Occupant Un',
                    'occupant_phone' => '0349000003',
                    'occupant_email' => 'occupant1@example.com',
                    'occupant_date_of_birth' => '1992-02-02',
                    'occupant_sex' => 'Homme',
                    'occupant_id_type' => 'CIN',
                    'occupant_id_number' => 'CIN-OCC-001',
                ],
                [
                    'room_id' => $organizationRoom2->id,
                    'occupant_name' => 'Occupant Deux',
                    'occupant_phone' => '0349000004',
                    'occupant_email' => 'occupant2@example.com',
                    'occupant_date_of_birth' => '1993-03-03',
                    'occupant_sex' => 'Femme',
                    'occupant_id_type' => 'CIN',
                    'occupant_id_number' => 'CIN-OCC-002',
                ],
            ],
            'checked_in_by_name' => $user->name,
            'checked_in_by_role' => $user->role,
        ])->assertOk();

        $this->postJson("/api/reservations/{$organization->id}/deposit", [
            'amount_ariary' => 60000,
            'payment_method' => 'Espèces',
            'processed_by_name' => $user->name,
            'processed_by_role' => $user->role,
            'reference' => 'DEP-ORG-001',
        ])->assertOk();

        $organizationFolio = $this->getJson("/api/reservations/{$organization->id}/folio");
        $organizationFolio->assertOk();

        $organizationInvoiceId = $organizationFolio->json('id');
        $this->assertNotEmpty($organizationInvoiceId);

        $this->postJson("/api/invoices/{$organizationInvoiceId}/generate-pdf", [
            'document_type' => 'facture',
            'billing_mode' => 'grouped',
            'currency_mode' => 'ariary',
            'actor_role' => $user->role,
        ])->assertOk();
    }

    public function test_organization_invoice_can_switch_to_individual_mode(): void
    {
        $user = $this->createReceptionUser();
        $room1 = $this->createRoom('904');
        $room2 = $this->createRoom('905');

        $reservationPayload = $this->createReservation([
            'client_name' => 'Organisme Individuel',
            'customer_phone' => '0349000005',
            'customer_email' => 'contact@organisme-individuel.example',
            'organization_name' => 'Organisme Individuel',
            'organization_phone' => '020900001',
            'organization_contact_name' => 'Contact Organisme',
            'organization_contact_phone' => '0349000005',
            'organization_contact_email' => 'contact@organisme-individuel.example',
            'organization_email' => 'siege@organisme-individuel.example',
            'organization_billing_address' => 'Adresse Organisme',
            'organization_nif' => 'NIF-ORG-IND-001',
            'organization_stat' => 'STAT-ORG-IND-001',
            'check_in' => '2026-07-15',
            'check_out' => '2026-07-17',
            'room_ids' => [$room1->id, $room2->id],
            'room_prices' => [
                ['id' => $room1->id, 'price' => 110000],
                ['id' => $room2->id, 'price' => 110000],
            ],
            'source' => 'Appel',
            'receptionist_name' => $user->name,
        ]);

        $this->assertSame(201, $reservationPayload['code']);

        $reservation = Reservation::query()
            ->where('client_name', 'Organisme Individuel')
            ->firstOrFail();

        $this->postJson("/api/reservations/{$reservation->id}/checkin", [
            'full_name' => 'Organisme Individuel',
            'customer_phone' => '0349000005',
            'phone_number' => '0349000005',
            'date_of_birth' => '1986-06-06',
            'sex' => 'Femme',
            'id_type' => 'CIN',
            'id_number' => 'CIN-ORG-IND-001',
            'id_document_number' => 'CIN-ORG-IND-001',
            'room_checkins' => [
                [
                    'room_id' => $room1->id,
                    'occupant_name' => 'Occupant Un',
                    'occupant_phone' => '0349000006',
                    'occupant_email' => 'occupant1@example.com',
                    'occupant_date_of_birth' => '1990-01-01',
                    'occupant_sex' => 'Homme',
                    'occupant_id_type' => 'CIN',
                    'occupant_id_number' => 'CIN-OI-001',
                ],
                [
                    'room_id' => $room2->id,
                    'occupant_name' => 'Occupant Deux',
                    'occupant_phone' => '0349000007',
                    'occupant_email' => 'occupant2@example.com',
                    'occupant_date_of_birth' => '1991-02-02',
                    'occupant_sex' => 'Femme',
                    'occupant_id_type' => 'CIN',
                    'occupant_id_number' => 'CIN-OI-002',
                ],
            ],
            'checked_in_by_name' => $user->name,
            'checked_in_by_role' => $user->role,
        ])->assertOk();

        $folio = $this->getJson("/api/reservations/{$reservation->id}/folio");
        $folio->assertOk();

        $invoiceId = $folio->json('id');
        $this->postJson("/api/invoices/{$invoiceId}/generate-pdf", [
            'document_type' => 'facture',
            'billing_mode' => 'individual',
            'currency_mode' => 'ariary',
            'actor_role' => $user->role,
        ])->assertOk();

        $reservation->refresh();
        $this->assertSame('per_room', $reservation->billing_mode);
        $this->assertGreaterThanOrEqual(3, $reservation->invoices()->count());
    }

    public function test_extra_added_to_a_child_invoice_updates_the_master_total(): void
    {
        $user = $this->createReceptionUser();
        $room1 = $this->createRoom('906');
        $room2 = $this->createRoom('907');

        $reservationPayload = $this->createReservation([
            'client_name' => 'Organisme Extras',
            'customer_phone' => '0349000008',
            'customer_email' => 'contact@organisme-extras.example',
            'organization_name' => 'Organisme Extras',
            'organization_phone' => '020900002',
            'organization_contact_name' => 'Contact Organisme',
            'organization_contact_phone' => '0349000008',
            'organization_contact_email' => 'contact@organisme-extras.example',
            'organization_email' => 'siege@organisme-extras.example',
            'organization_billing_address' => 'Adresse Organisme',
            'organization_nif' => 'NIF-ORG-EXTRA-001',
            'organization_stat' => 'STAT-ORG-EXTRA-001',
            'check_in' => '2026-07-18',
            'check_out' => '2026-07-20',
            'room_ids' => [$room1->id, $room2->id],
            'room_prices' => [
                ['id' => $room1->id, 'price' => 110000],
                ['id' => $room2->id, 'price' => 110000],
            ],
            'source' => 'Appel',
            'receptionist_name' => $user->name,
            'billing_mode' => 'per_room',
        ]);

        $this->assertSame(201, $reservationPayload['code']);

        $reservation = Reservation::query()
            ->where('client_name', 'Organisme Extras')
            ->firstOrFail();

        $this->postJson("/api/reservations/{$reservation->id}/checkin", [
            'full_name' => 'Organisme Extras',
            'customer_phone' => '0349000008',
            'phone_number' => '0349000008',
            'date_of_birth' => '1987-07-07',
            'sex' => 'Homme',
            'id_type' => 'CIN',
            'id_number' => 'CIN-EXTRA-001',
            'id_document_number' => 'CIN-EXTRA-001',
            'room_checkins' => [
                [
                    'room_id' => $room1->id,
                    'occupant_name' => 'Occupant Un',
                    'occupant_phone' => '0349000009',
                    'occupant_email' => 'occupant1@example.com',
                    'occupant_date_of_birth' => '1994-04-04',
                    'occupant_sex' => 'Homme',
                    'occupant_id_type' => 'CIN',
                    'occupant_id_number' => 'CIN-EXTRA-OCC-001',
                ],
                [
                    'room_id' => $room2->id,
                    'occupant_name' => 'Occupant Deux',
                    'occupant_phone' => '0349000010',
                    'occupant_email' => 'occupant2@example.com',
                    'occupant_date_of_birth' => '1995-05-05',
                    'occupant_sex' => 'Femme',
                    'occupant_id_type' => 'CIN',
                    'occupant_id_number' => 'CIN-EXTRA-OCC-002',
                ],
            ],
            'checked_in_by_name' => $user->name,
            'checked_in_by_role' => $user->role,
        ])->assertOk();

        $this->getJson("/api/reservations/{$reservation->id}/folio")->assertOk();

        $reservation->refresh();
        $reservation->load('rooms', 'invoices.items', 'invoices.payments');
        $masterInvoice = $reservation->invoices()
            ->where('invoice_kind', 'master')
            ->firstOrFail();
        $childInvoice = $reservation->invoices()
            ->where('invoice_kind', 'room')
            ->where('booking_room_id', $reservation->rooms()->orderBy('room_number')->first()->pivot->id)
            ->firstOrFail();

        $initialMasterTotal = (int) $masterInvoice->total_amount_ariary;
        $initialChildTotal = (int) $childInvoice->total_amount_ariary;

        $this->assertSame(440000, $initialMasterTotal);
        $this->assertSame(220000, $initialChildTotal);

        $response = $this->postJson("/api/invoices/{$childInvoice->id}/items", [
            'description' => 'Dîner',
            'type' => 'extra',
            'amount_ariary' => 25000,
            'quantity' => 2,
            'booking_room_id' => $childInvoice->booking_room_id,
        ])->assertOk();

        $response->assertJsonPath('invoice.total_amount_ariary', 270000);

        $masterInvoice->refresh();
        $childInvoice->refresh();

        $this->assertSame(270000, (int) $childInvoice->total_amount_ariary);
        $this->assertSame(490000, (int) $masterInvoice->total_amount_ariary);
        $this->assertSame(0, $masterInvoice->paid_amount_ariary);
        $this->assertSame(490000, $masterInvoice->balance_amount_ariary);
    }

    public function test_per_room_master_total_does_not_count_segment_extras_twice(): void
    {
        $user = $this->createReceptionUser();
        $room1 = $this->createRoom('910');
        $room2 = $this->createRoom('911', 125000);

        $reservationPayload = $this->createReservation([
            'client_name' => 'Organisme Sans Doublon',
            'customer_phone' => '0349000013',
            'customer_email' => 'contact@sans-doublon.example',
            'organization_name' => 'Organisme Sans Doublon',
            'organization_phone' => '020900003',
            'organization_contact_name' => 'Contact Organisme',
            'organization_contact_phone' => '0349000013',
            'organization_contact_email' => 'contact@sans-doublon.example',
            'organization_email' => 'siege@sans-doublon.example',
            'organization_billing_address' => 'Adresse Organisme',
            'organization_nif' => 'NIF-ORG-DOUBLE-001',
            'organization_stat' => 'STAT-ORG-DOUBLE-001',
            'check_in' => '2026-07-26',
            'check_out' => '2026-07-27',
            'room_ids' => [$room1->id, $room2->id],
            'room_segments' => [
                [
                    'room_id' => $room1->id,
                    'segment_start_date' => '2026-07-26',
                    'segment_end_date' => '2026-07-27',
                    'segment_extra_beds' => 1,
                    'segment_extra_mattresses' => 0,
                ],
                [
                    'room_id' => $room2->id,
                    'segment_start_date' => '2026-07-26',
                    'segment_end_date' => '2026-07-27',
                    'segment_extra_beds' => 0,
                    'segment_extra_mattresses' => 0,
                ],
            ],
            'room_prices' => [
                ['id' => $room1->id, 'price' => 110000],
                ['id' => $room2->id, 'price' => 125000],
            ],
            'extra_beds' => 1,
            'extra_mattresses' => 0,
            'source' => 'Appel',
            'receptionist_name' => $user->name,
            'billing_mode' => 'per_room',
        ]);

        $this->assertSame(201, $reservationPayload['code']);

        $reservation = Reservation::query()
            ->where('client_name', 'Organisme Sans Doublon')
            ->firstOrFail();

        $this->postJson("/api/reservations/{$reservation->id}/checkin", [
            'full_name' => 'Organisme Sans Doublon',
            'customer_phone' => '0349000013',
            'phone_number' => '0349000013',
            'date_of_birth' => '1987-07-07',
            'sex' => 'Homme',
            'id_type' => 'CIN',
            'id_number' => 'CIN-DOUBLE-001',
            'id_document_number' => 'CIN-DOUBLE-001',
            'checked_in_by_name' => $user->name,
            'checked_in_by_role' => $user->role,
        ])->assertOk();

        $folio = $this->getJson("/api/reservations/{$reservation->id}/folio")->assertOk();
        $masterInvoiceId = collect($folio->json('invoices'))
            ->firstWhere('invoice_kind', 'master')['id'];

        $response = $this->postJson("/api/invoices/{$masterInvoiceId}/generate-pdf", [
            'document_type' => 'facture',
            'billing_mode' => 'individual',
            'currency_mode' => 'ariary',
            'actor_role' => 'admin',
        ])->assertOk();

        $response->assertJsonPath('invoice.total_amount_ariary', 285000);
        $descriptions = collect($response->json('invoice.items'))
            ->pluck('description')
            ->all();
        $this->assertContains('Lit supplémentaire - Chambre 910 (double standard) - 1 nuit', $descriptions);
        $this->assertSame(
            1,
            collect($response->json('invoice.items'))
                ->where('description', 'Lit supplémentaire - Chambre 910 (double standard) - 1 nuit')
                ->count(),
        );

        $masterInvoice = Invoice::query()->findOrFail($masterInvoiceId);
        $this->assertSame(285000, (int) $masterInvoice->total_amount_ariary);
    }

    public function test_post_checkin_room_option_update_refreshes_child_invoice_extras(): void
    {
        $user = $this->createReceptionUser();
        $room1 = $this->createRoom('912');
        $room2 = $this->createRoom('913', 125000);

        $this->createReservation([
            'client_name' => 'Organisme Option Tardive',
            'customer_phone' => '0349000014',
            'customer_email' => 'contact@option-tardive.example',
            'organization_name' => 'Organisme Option Tardive',
            'organization_phone' => '020900004',
            'organization_contact_name' => 'Contact Organisme',
            'organization_contact_phone' => '0349000014',
            'organization_contact_email' => 'contact@option-tardive.example',
            'organization_email' => 'siege@option-tardive.example',
            'organization_billing_address' => 'Adresse Organisme',
            'organization_nif' => 'NIF-ORG-OPTION-001',
            'organization_stat' => 'STAT-ORG-OPTION-001',
            'check_in' => '2026-07-28',
            'check_out' => '2026-07-29',
            'room_ids' => [$room1->id, $room2->id],
            'room_segments' => [
                [
                    'room_id' => $room1->id,
                    'segment_start_date' => '2026-07-28',
                    'segment_end_date' => '2026-07-29',
                    'segment_extra_beds' => 0,
                    'segment_extra_mattresses' => 0,
                ],
                [
                    'room_id' => $room2->id,
                    'segment_start_date' => '2026-07-28',
                    'segment_end_date' => '2026-07-29',
                    'segment_extra_beds' => 0,
                    'segment_extra_mattresses' => 0,
                ],
            ],
            'room_prices' => [
                ['id' => $room1->id, 'price' => 110000],
                ['id' => $room2->id, 'price' => 125000],
            ],
            'source' => 'Appel',
            'receptionist_name' => $user->name,
            'billing_mode' => 'per_room',
        ]);

        $reservation = Reservation::query()
            ->where('client_name', 'Organisme Option Tardive')
            ->firstOrFail();

        $this->postJson("/api/reservations/{$reservation->id}/checkin", [
            'full_name' => 'Organisme Option Tardive',
            'customer_phone' => '0349000014',
            'phone_number' => '0349000014',
            'date_of_birth' => '1987-07-07',
            'sex' => 'Homme',
            'id_type' => 'CIN',
            'id_number' => 'CIN-OPTION-001',
            'id_document_number' => 'CIN-OPTION-001',
            'checked_in_by_name' => $user->name,
            'checked_in_by_role' => $user->role,
        ])->assertOk();

        $initialFolio = $this->getJson("/api/reservations/{$reservation->id}/folio")->assertOk();
        $masterInvoiceId = collect($initialFolio->json('invoices'))
            ->firstWhere('invoice_kind', 'master')['id'];

        $this->postJson("/api/invoices/{$masterInvoiceId}/generate-pdf", [
            'document_type' => 'facture',
            'billing_mode' => 'individual',
            'currency_mode' => 'ariary',
            'actor_role' => 'admin',
        ])->assertOk();

        $this->putJson("/api/reservations/{$reservation->id}", [
            'room_ids' => [$room1->id, $room2->id],
            'room_segments' => [
                [
                    'room_id' => $room1->id,
                    'segment_start_date' => '2026-07-28',
                    'segment_end_date' => '2026-07-29',
                    'segment_extra_beds' => 1,
                    'segment_extra_mattresses' => 0,
                ],
                [
                    'room_id' => $room2->id,
                    'segment_start_date' => '2026-07-28',
                    'segment_end_date' => '2026-07-29',
                    'segment_extra_beds' => 0,
                    'segment_extra_mattresses' => 0,
                ],
            ],
            'extra_beds' => 1,
            'extra_mattresses' => 0,
            'modified_by_name' => $user->name,
            'modified_by_role' => $user->role,
        ])->assertOk();

        $childFolio = $this->getJson("/api/reservations/{$reservation->id}/folio")->assertOk();
        $childFolio->assertJsonPath('total_amount_ariary', 160000);
        $descriptions = collect($childFolio->json('items'))->pluck('description')->all();
        $this->assertContains('Lit supplémentaire - Chambre 912 (double standard) - 1 nuit', $descriptions);

        $masterFolio = $this->getJson("/api/reservations/{$reservation->id}/folio?invoice_id={$masterInvoiceId}")->assertOk();
        $masterFolio->assertJsonPath('total_amount_ariary', 285000);
    }

    public function test_individual_invoice_uses_room_labels_without_guest_names(): void
    {
        $user = $this->createReceptionUser();
        $room = $this->createRoom('908');

        $reservationPayload = $this->createReservation([
            'client_name' => 'Client Libellé',
            'customer_phone' => '0349000011',
            'customer_email' => 'client-libre@example.com',
            'check_in' => '2026-07-21',
            'check_out' => '2026-07-23',
            'room_ids' => [$room->id],
            'room_prices' => [
                ['id' => $room->id, 'price' => 110000],
            ],
            'source' => 'Appel',
            'receptionist_name' => $user->name,
        ]);

        $this->assertSame(201, $reservationPayload['code']);

        $reservation = Reservation::query()
            ->where('client_name', 'Client Libellé')
            ->firstOrFail();

        $this->postJson("/api/reservations/{$reservation->id}/checkin", [
            'full_name' => 'Client Libellé',
            'first_name' => 'Client',
            'last_name' => 'Libellé',
            'customer_phone' => '0349000011',
            'phone_number' => '0349000011',
            'date_of_birth' => '1990-01-01',
            'sex' => 'Homme',
            'id_type' => 'CIN',
            'id_number' => 'CIN-LIB-001',
            'id_document_number' => 'CIN-LIB-001',
            'checked_in_by_name' => $user->name,
            'checked_in_by_role' => $user->role,
        ])->assertOk();

        $folio = $this->getJson("/api/reservations/{$reservation->id}/folio")->assertOk();
        $invoiceId = $folio->json('id');
        $bookingRoomId = $folio->json('room_bookings.0.id');

        $folio->assertJsonPath('items.0.description', 'Chambre 908 (double standard) - 2 nuits');

        $extraResponse = $this->postJson("/api/invoices/{$invoiceId}/items", [
            'description' => 'Lit supplémentaire',
            'type' => 'extra',
            'amount_ariary' => 50000,
            'quantity' => 1,
            'booking_room_id' => $bookingRoomId,
        ])->assertOk();

        $descriptions = collect($extraResponse->json('invoice.items'))
            ->pluck('description')
            ->all();
        $this->assertContains('Chambre 908 (double standard) - 2 nuits', $descriptions);
        $this->assertContains('Lit supplémentaire - Chambre 908 (double standard) - 2 nuits', $descriptions);
        $extraResponse->assertJsonPath('invoice.total_amount_ariary', 270000);
    }

    public function test_invoice_item_edits_are_limited_and_audited(): void
    {
        $receptionist = $this->createReceptionUser();
        $room = $this->createRoom('909');

        $this->createReservation([
            'client_name' => 'Client Audit Ligne',
            'customer_phone' => '0349000012',
            'customer_email' => 'client-audit@example.com',
            'check_in' => '2026-07-24',
            'check_out' => '2026-07-25',
            'room_ids' => [$room->id],
            'room_prices' => [
                ['id' => $room->id, 'price' => 110000],
            ],
            'source' => 'Appel',
            'receptionist_name' => $receptionist->name,
        ]);

        $reservation = Reservation::query()
            ->where('client_name', 'Client Audit Ligne')
            ->firstOrFail();

        $this->postJson("/api/reservations/{$reservation->id}/checkin", [
            'full_name' => 'Client Audit Ligne',
            'first_name' => 'Client',
            'last_name' => 'Audit',
            'customer_phone' => '0349000012',
            'phone_number' => '0349000012',
            'date_of_birth' => '1990-01-01',
            'sex' => 'Homme',
            'id_type' => 'CIN',
            'id_number' => 'CIN-AUDIT-001',
            'id_document_number' => 'CIN-AUDIT-001',
            'checked_in_by_name' => $receptionist->name,
            'checked_in_by_role' => $receptionist->role,
        ])->assertOk();

        $folio = $this->getJson("/api/reservations/{$reservation->id}/folio")->assertOk();
        $invoiceId = $folio->json('id');
        $bookingRoomId = $folio->json('room_bookings.0.id');

        $addResponse = $this->postJson("/api/invoices/{$invoiceId}/items", [
            'description' => 'Dîner',
            'type' => 'extra',
            'amount_ariary' => 25000,
            'quantity' => 1,
            'booking_room_id' => $bookingRoomId,
            'actor_name' => $receptionist->name,
            'actor_role' => 'receptionist',
        ])->assertOk();

        $itemId = collect($addResponse->json('invoice.items'))
            ->firstWhere('description', 'Dîner - Chambre 909 (double standard) - 1 nuit')['id'];

        $this->putJson("/api/invoices/{$invoiceId}/items/{$itemId}", [
            'description' => 'Dîner',
            'type' => 'extra',
            'amount_ariary' => 30000,
            'quantity' => 1,
            'booking_room_id' => $bookingRoomId,
            'actor_name' => $receptionist->name,
            'actor_role' => 'receptionist',
        ])->assertOk()
            ->assertJsonPath('invoice.total_amount_ariary', 140000);

        InvoiceItem::query()
            ->whereKey($itemId)
            ->update(['created_at' => now()->subMinute()->toDateTimeString()]);
        $this->assertTrue(InvoiceItem::query()->findOrFail($itemId)->created_at->lt(now()->subSeconds(7)));

        $this->putJson("/api/invoices/{$invoiceId}/items/{$itemId}", [
            'description' => 'Dîner',
            'type' => 'extra',
            'amount_ariary' => 35000,
            'quantity' => 1,
            'booking_room_id' => $bookingRoomId,
            'actor_name' => $receptionist->name,
            'actor_role' => 'receptionist',
        ])->assertForbidden();

        $this->putJson("/api/invoices/{$invoiceId}/items/{$itemId}", [
            'description' => 'Dîner',
            'type' => 'extra',
            'amount_ariary' => 35000,
            'quantity' => 1,
            'booking_room_id' => $bookingRoomId,
            'actor_name' => 'Admin Facturation',
            'actor_role' => 'admin',
        ])->assertOk()
            ->assertJsonPath('invoice.total_amount_ariary', 145000);

        $this->deleteJson("/api/invoices/{$invoiceId}/items/{$itemId}", [
            'actor_name' => 'Admin Facturation',
            'actor_role' => 'admin',
        ])->assertOk()
            ->assertJsonPath('invoice.total_amount_ariary', 110000);

        $this->assertSoftDeleted('invoice_items', ['id' => $itemId]);
        $this->assertSame(3, ReservationAudit::query()
            ->where('reservation_id', $reservation->id)
            ->whereIn('action', ['invoice_item_updated', 'invoice_item_deleted'])
            ->count());
        $this->assertDatabaseHas('reservation_audits', [
            'reservation_id' => $reservation->id,
            'action' => 'invoice_item_deleted',
            'actor_name' => 'Admin Facturation',
            'actor_role' => 'admin',
        ]);
    }

    private function createReceptionUser(): User
    {
        return User::create([
            'name' => 'Reception EndToEnd',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);
    }

    private function createRoom(string $roomNumber, int $price = 110000): Room
    {
        return Room::create([
            'room_number' => $roomNumber,
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => $price,
            'is_fixed_price' => false,
        ]);
    }

    private function createReservation(array $payload): array
    {
        $response = $this->postJson('/api/bookings', array_merge([
            'extra_beds' => 0,
            'extra_mattresses' => 0,
        ], $payload))->assertCreated()->json();

        return [
            'code' => 201,
            'body' => $response,
        ];
    }
}
