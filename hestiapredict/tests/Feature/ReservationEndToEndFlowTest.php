<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Reservation;
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
