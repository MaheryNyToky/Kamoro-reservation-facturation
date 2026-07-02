<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Reservation;
use App\Models\ReservationAudit;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
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

    public function test_post_checkin_room_option_update_keeps_all_grouped_room_lines(): void
    {
        $user = $this->createReceptionUser();
        $room1 = $this->createRoom('01');
        $room2 = $this->createRoom('02', 125000);

        $this->createReservation([
            'client_name' => 'Client Deux Chambres',
            'customer_phone' => '0349000015',
            'customer_email' => 'client-deux-chambres@example.com',
            'check_in' => '2026-07-30',
            'check_out' => '2026-07-31',
            'room_ids' => [$room1->id, $room2->id],
            'room_segments' => [
                [
                    'room_id' => $room1->id,
                    'segment_start_date' => '2026-07-30',
                    'segment_end_date' => '2026-07-31',
                    'segment_extra_beds' => 0,
                    'segment_extra_mattresses' => 0,
                ],
                [
                    'room_id' => $room2->id,
                    'segment_start_date' => '2026-07-30',
                    'segment_end_date' => '2026-07-31',
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
        ]);

        $reservation = Reservation::query()
            ->where('client_name', 'Client Deux Chambres')
            ->firstOrFail();

        $this->postJson("/api/reservations/{$reservation->id}/checkin", [
            'full_name' => 'Client Deux Chambres',
            'customer_phone' => '0349000015',
            'phone_number' => '0349000015',
            'date_of_birth' => '1989-07-07',
            'sex' => 'Homme',
            'id_type' => 'CIN',
            'id_number' => 'CIN-ROOM-001',
            'id_document_number' => 'CIN-ROOM-001',
            'checked_in_by_name' => $user->name,
            'checked_in_by_role' => $user->role,
        ])->assertOk();

        $this->putJson("/api/reservations/{$reservation->id}", [
            'room_ids' => [$room1->id, $room2->id],
            'room_segments' => [
                [
                    'room_id' => $room1->id,
                    'segment_start_date' => '2026-07-30',
                    'segment_end_date' => '2026-07-31',
                    'segment_extra_beds' => 0,
                    'segment_extra_mattresses' => 1,
                ],
                [
                    'room_id' => $room2->id,
                    'segment_start_date' => '2026-07-30',
                    'segment_end_date' => '2026-07-31',
                    'segment_extra_beds' => 0,
                    'segment_extra_mattresses' => 0,
                ],
            ],
            'extra_beds' => 0,
            'extra_mattresses' => 1,
            'modified_by_name' => $user->name,
            'modified_by_role' => $user->role,
        ])->assertOk();

        $folio = $this->getJson("/api/reservations/{$reservation->id}/folio")->assertOk();
        $folio->assertJsonPath('total_amount_ariary', 270000);
        $descriptions = collect($folio->json('items'))->pluck('description')->all();

        $this->assertContains('Chambre 01 (double standard) - 1 nuit', $descriptions);
        $this->assertContains('Chambre 02 (double standard) - 1 nuit', $descriptions);
        $this->assertContains('Matelas supplémentaire - Chambre 01 (double standard) - 1 nuit', $descriptions);
        $this->assertSame(3, count($descriptions));

        $invoiceId = $folio->json('id');
        $pdfResponse = $this->postJson("/api/invoices/{$invoiceId}/generate-pdf", [
            'document_type' => 'facture',
            'billing_mode' => 'grouped',
            'currency_mode' => 'ariary',
            'actor_role' => 'admin',
        ])->assertOk();
        $pdfResponse->assertJsonPath('invoice.total_amount_ariary', 270000);
        $pdfDescriptions = collect($pdfResponse->json('invoice.items'))->pluck('description')->all();

        $this->assertContains('Chambre 01 (double standard) - 1 nuit', $pdfDescriptions);
        $this->assertContains('Chambre 02 (double standard) - 1 nuit', $pdfDescriptions);
        $this->assertContains('Matelas supplémentaire - Chambre 01 (double standard) - 1 nuit', $pdfDescriptions);
    }

    public function test_receptionist_can_extend_checked_in_stay_without_new_invoice(): void
    {
        $this->travelTo(Carbon::parse('2026-06-30 10:00:00'));

        $user = $this->createReceptionUser();
        $room = $this->createRoom('914');

        $this->createReservation([
            'client_name' => 'Client Prolongation',
            'customer_phone' => '0349000016',
            'customer_email' => 'client-prolongation@example.com',
            'check_in' => '2026-06-30',
            'check_out' => '2026-07-01',
            'room_ids' => [$room->id],
            'room_segments' => [
                [
                    'room_id' => $room->id,
                    'segment_start_date' => '2026-06-30',
                    'segment_end_date' => '2026-07-01',
                    'segment_extra_beds' => 0,
                    'segment_extra_mattresses' => 0,
                ],
            ],
            'room_prices' => [
                ['id' => $room->id, 'price' => 110000],
            ],
            'source' => 'Appel',
            'receptionist_name' => $user->name,
            'actor_role' => 'admin',
        ]);

        $reservation = Reservation::query()
            ->where('client_name', 'Client Prolongation')
            ->firstOrFail();

        $this->postJson("/api/reservations/{$reservation->id}/checkin", [
            'full_name' => 'Client Prolongation',
            'customer_phone' => '0349000016',
            'phone_number' => '0349000016',
            'date_of_birth' => '1989-07-07',
            'sex' => 'Homme',
            'id_type' => 'CIN',
            'id_number' => 'CIN-PROLONG-001',
            'id_document_number' => 'CIN-PROLONG-001',
            'checked_in_by_name' => $user->name,
            'checked_in_by_role' => $user->role,
        ])->assertOk();

        $initialFolio = $this->getJson("/api/reservations/{$reservation->id}/folio")->assertOk();
        $invoiceId = $initialFolio->json('id');
        $initialFolio->assertJsonPath('total_amount_ariary', 110000);

        $this->putJson("/api/reservations/{$reservation->id}", [
            'check_in' => '2026-06-30',
            'check_out' => '2026-07-03',
            'room_ids' => [$room->id],
            'room_segments' => [
                [
                    'room_id' => $room->id,
                    'segment_start_date' => '2026-06-30',
                    'segment_end_date' => '2026-07-01',
                    'segment_extra_beds' => 0,
                    'segment_extra_mattresses' => 0,
                ],
            ],
            'extra_beds' => 0,
            'extra_mattresses' => 0,
            'modified_by_name' => $user->name,
            'modified_by_role' => 'receptionist',
        ])->assertOk();

        $reservation->refresh()->load('rooms');
        $this->assertSame('2026-07-03', $reservation->check_out_date->toDateString());
        $this->assertSame(1, $reservation->rooms->count());
        $this->assertSame(
            ['2026-06-30:2026-07-03'],
            $reservation->rooms
                ->sortBy(fn (Room $room) => $room->pivot->segment_start_date)
                ->map(fn (Room $room) => $room->pivot->segment_start_date->toDateString() . ':' . $room->pivot->segment_end_date->toDateString())
                ->values()
                ->all(),
        );

        $folio = $this->getJson("/api/reservations/{$reservation->id}/folio")->assertOk();
        $this->assertSame($invoiceId, $folio->json('id'));
        $folio->assertJsonPath('total_amount_ariary', 330000);
        $descriptions = collect($folio->json('items'))->pluck('description')->all();
        $this->assertContains('Chambre 914 (double standard) - 3 nuits', $descriptions);
    }

    public function test_grouped_organization_folio_includes_all_selected_reservations(): void
    {
        $user = $this->createReceptionUser();
        $room1 = $this->createRoom('014');
        $room2 = $this->createRoom('015');
        $room3 = $this->createRoom('016');

        $reservation1 = $this->createGroupedOrganizationReservation(
            $user,
            'Organisme Groupe A',
            $room1,
            'Occupant 1',
        );
        $reservation2 = $this->createGroupedOrganizationReservation(
            $user,
            'Organisme Groupe A',
            $room2,
            'Occupant 2',
        );
        $reservation3 = $this->createGroupedOrganizationReservation(
            $user,
            'Organisme Groupe A',
            $room3,
            'Occupant 3',
        );

        $response = $this->getJson(
            "/api/reservations/{$reservation1->id}/folio?group_reservation_ids={$reservation1->id},{$reservation2->id},{$reservation3->id}",
        )->assertOk();

        $roomLines = collect($response->json('items'))
            ->pluck('description')
            ->filter(fn ($description) => str_starts_with((string) $description, 'Chambre '))
            ->values()
            ->all();

        $this->assertCount(3, $roomLines);
        $this->assertContains('Chambre 014 (double standard) - Occupant 1 - 1 nuit', $roomLines);
        $this->assertContains('Chambre 015 (double standard) - Occupant 2 - 1 nuit', $roomLines);
        $this->assertContains('Chambre 016 (double standard) - Occupant 3 - 1 nuit', $roomLines);
        $this->assertSame(330000, (int) $response->json('total_amount_ariary'));
    }

    public function test_grouped_organization_pdf_keeps_all_selected_reservations(): void
    {
        $user = $this->createReceptionUser();
        $room1 = $this->createRoom('020');
        $room2 = $this->createRoom('021');
        $room3 = $this->createRoom('022');

        $reservation1 = $this->createGroupedOrganizationReservation(
            $user,
            'Organisme PDF Groupe',
            $room1,
            'Occupant PDF 1',
        );
        $reservation2 = $this->createGroupedOrganizationReservation(
            $user,
            'Organisme PDF Groupe',
            $room2,
            'Occupant PDF 2',
        );
        $reservation3 = $this->createGroupedOrganizationReservation(
            $user,
            'Organisme PDF Groupe',
            $room3,
            'Occupant PDF 3',
        );

        $folio = $this->getJson(
            "/api/reservations/{$reservation1->id}/folio?group_reservation_ids={$reservation1->id},{$reservation2->id},{$reservation3->id}",
        )->assertOk();

        $invoiceId = $folio->json('id');
        $response = $this->postJson("/api/invoices/{$invoiceId}/generate-pdf", [
            'document_type' => 'facture',
            'billing_mode' => 'grouped',
            'currency_mode' => 'ariary',
            'group_reservation_ids' => "{$reservation1->id},{$reservation2->id},{$reservation3->id}",
            'actor_role' => $user->role,
        ])->assertOk();

        $roomLines = collect($response->json('invoice.items'))
            ->pluck('description')
            ->filter(fn ($description) => str_starts_with((string) $description, 'Chambre '))
            ->values()
            ->all();

        $this->assertCount(3, $roomLines);
        $this->assertContains('Chambre 020 (double standard) - Occupant PDF 1 - 1 nuit', $roomLines);
        $this->assertContains('Chambre 021 (double standard) - Occupant PDF 2 - 1 nuit', $roomLines);
        $this->assertContains('Chambre 022 (double standard) - Occupant PDF 3 - 1 nuit', $roomLines);
    }

    public function test_grouped_organization_folio_can_target_only_a_subset_of_reservations(): void
    {
        $user = $this->createReceptionUser();
        $room1 = $this->createRoom('017');
        $room2 = $this->createRoom('018');
        $room3 = $this->createRoom('019');

        $reservation1 = $this->createGroupedOrganizationReservation(
            $user,
            'Organisme Groupe B',
            $room1,
            'Occupant A',
        );
        $reservation2 = $this->createGroupedOrganizationReservation(
            $user,
            'Organisme Groupe B',
            $room2,
            'Occupant B',
        );
        $reservation3 = $this->createGroupedOrganizationReservation(
            $user,
            'Organisme Groupe B',
            $room3,
            'Occupant C',
        );

        $response = $this->getJson(
            "/api/reservations/{$reservation1->id}/folio?group_reservation_ids={$reservation1->id},{$reservation2->id}",
        )->assertOk();

        $roomLines = collect($response->json('items'))
            ->pluck('description')
            ->filter(fn ($description) => str_starts_with((string) $description, 'Chambre '))
            ->values()
            ->all();

        $this->assertCount(2, $roomLines);
        $this->assertContains('Chambre 017 (double standard) - Occupant A - 1 nuit', $roomLines);
        $this->assertContains('Chambre 018 (double standard) - Occupant B - 1 nuit', $roomLines);
        $this->assertNotContains('Chambre 019 (double standard) - Occupant C - 1 nuit', $roomLines);
        $this->assertSame(220000, (int) $response->json('total_amount_ariary'));
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
            'amount_ariary' => 32000,
            'quantity' => 1,
            'booking_room_id' => $bookingRoomId,
            'actor_name' => $receptionist->name,
            'actor_role' => 'receptionist',
        ])->assertForbidden();

        $this->putJson("/api/invoices/{$invoiceId}/items/{$itemId}", [
            'description' => 'Dîner',
            'type' => 'extra',
            'amount_ariary' => 32000,
            'quantity' => 1,
            'booking_room_id' => $bookingRoomId,
            'actor_name' => 'Admin Facturation',
            'actor_role' => 'admin',
        ])->assertOk()
            ->assertJsonPath('invoice.total_amount_ariary', 142000);

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
        return Room::updateOrCreate(
            ['room_number' => $roomNumber],
            [
                'type' => 'Chambre Double',
                'model' => 'Standard',
                'base_price_ariary' => $price,
                'is_fixed_price' => false,
            ],
        );
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

    private function createGroupedOrganizationReservation(
        User $user,
        string $organizationName,
        Room $room,
        string $occupantName,
    ): Reservation {
        $this->createReservation([
            'client_name' => $organizationName,
            'customer_phone' => '0349000100',
            'customer_email' => 'contact@group.example',
            'organization_name' => $organizationName,
            'organization_phone' => '020900010',
            'organization_contact_name' => 'Contact Organisme',
            'organization_contact_phone' => '0349000100',
            'organization_contact_email' => 'contact@group.example',
            'organization_email' => 'siege@group.example',
            'organization_billing_address' => 'Adresse Organisme',
            'organization_nif' => 'NIF-ORG-GROUP-001',
            'organization_stat' => 'STAT-ORG-GROUP-001',
            'check_in' => '2026-08-01',
            'check_out' => '2026-08-02',
            'room_ids' => [$room->id],
            'room_prices' => [
                ['id' => $room->id, 'price' => 110000],
            ],
            'source' => 'Appel',
            'receptionist_name' => $user->name,
        ]);

        $reservation = Reservation::query()
            ->where('client_name', $organizationName)
            ->where('check_in_date', '2026-08-01')
            ->whereHas('rooms', fn ($query) => $query->where('room_number', $room->room_number))
            ->latest('id')
            ->firstOrFail();

        $this->postJson("/api/reservations/{$reservation->id}/checkin", [
            'full_name' => $organizationName,
            'customer_phone' => '0349000100',
            'phone_number' => '0349000100',
            'date_of_birth' => '1988-08-08',
            'sex' => 'Homme',
            'id_type' => 'CIN',
            'id_number' => 'CIN-GROUP-' . $room->room_number,
            'id_document_number' => 'CIN-GROUP-' . $room->room_number,
            'room_checkins' => [
                [
                    'room_id' => $room->id,
                    'occupant_name' => $occupantName,
                    'occupant_phone' => '0349000101',
                    'occupant_email' => 'occ@group.example',
                    'occupant_date_of_birth' => '1991-01-01',
                    'occupant_sex' => 'Homme',
                    'occupant_id_type' => 'CIN',
                    'occupant_id_number' => 'CIN-OCC-' . $room->room_number,
                ],
            ],
            'checked_in_by_name' => $user->name,
            'checked_in_by_role' => $user->role,
        ])->assertOk();

        return $reservation;
    }
}
