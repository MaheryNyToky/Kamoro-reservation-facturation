<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PmsGuestUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkin_updates_guest_information_without_losing_visit_history(): void
    {
        $user = User::create([
            'name' => 'Reception Test',
            'email' => 'reception-test@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $reservationId = $this->createReservation($user->id);

        Guest::create([
            'reservation_id' => $reservationId,
            'full_name' => 'Marie Razafindrakoto',
            'first_name' => 'Marie',
            'last_name' => 'Razafindrakoto',
            'phone_number' => '0341000001',
            'sex' => 'Femme',
            'id_document_number' => 'CIN-OLD-001',
            'loyalty_count' => 5,
            'date_of_birth' => '1988-01-01',
            'id_type' => 'CIN',
            'id_number' => 'CIN-OLD-001',
            'id_photo_path' => null,
        ]);

        $response = $this->postJson("/api/reservations/{$reservationId}/checkin", [
            'full_name' => 'Marie Razafindrakoto',
            'first_name' => 'Marie',
            'last_name' => 'Razafindrakoto',
            'customer_phone' => '0341000001',
            'phone_number' => '0341000001',
            'date_of_birth' => '1989-02-02',
            'sex' => 'Homme',
            'id_type' => 'CIN',
            'id_number' => 'CIN-OLD-001',
            'id_document_number' => 'CIN-OLD-001',
            'loyalty_count' => 5,
        ]);

        $response->assertOk();

        $guest = Guest::query()->where('reservation_id', $reservationId)->firstOrFail();
        $this->assertSame('1989-02-02', $guest->date_of_birth->toDateString());
        $this->assertSame('Homme', $guest->sex);
        $this->assertSame(5, (int) $guest->loyalty_count);

        $searchResponse = $this->getJson('/api/clients/search?q=CIN-OLD-001');
        $searchResponse->assertOk();
        $searchResponse->assertJsonPath('data.0.date_of_birth', '1989-02-02');
        $searchResponse->assertJsonPath('data.0.sex', 'Homme');
    }

    public function test_passport_validity_range_is_required_and_saved(): void
    {
        $user = User::create([
            'name' => 'Reception Test',
            'email' => 'reception-passport@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $reservationId = $this->createReservation($user->id);

        $missingValidity = $this->postJson("/api/reservations/{$reservationId}/checkin", [
            'full_name' => 'Jean Rabe',
            'first_name' => 'Jean',
            'last_name' => 'Rabe',
            'customer_phone' => '0341000002',
            'phone_number' => '0341000002',
            'date_of_birth' => '1987-03-03',
            'sex' => 'Homme',
            'id_type' => 'Passeport',
            'id_number' => 'PP-NEW-777',
            'id_document_number' => 'PP-NEW-777',
            'passport_valid_from' => '2029-01-01',
            'loyalty_count' => 1,
        ]);

        $missingValidity->assertStatus(422);

        $response = $this->postJson("/api/reservations/{$reservationId}/checkin", [
            'full_name' => 'Jean Rabe',
            'first_name' => 'Jean',
            'last_name' => 'Rabe',
            'customer_phone' => '0341000002',
            'phone_number' => '0341000002',
            'date_of_birth' => '1987-03-03',
            'sex' => 'Homme',
            'id_type' => 'Passeport',
            'id_number' => 'PP-NEW-777',
            'id_document_number' => 'PP-NEW-777',
            'passport_valid_from' => '2029-01-01',
            'passport_valid_until' => '2029-12-31',
            'loyalty_count' => 1,
        ]);

        $response->assertOk();

        $guest = Guest::query()->where('reservation_id', $reservationId)->firstOrFail();
        $this->assertSame('2029-01-01', $guest->passport_valid_from?->toDateString());
        $this->assertSame('2029-12-31', $guest->passport_valid_until?->toDateString());
    }

    public function test_autre_document_requires_and_saves_validity_range(): void
    {
        $user = User::create([
            'name' => 'Reception Test',
            'email' => 'reception-autre@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $reservationId = $this->createReservation($user->id);

        $missingValidity = $this->postJson("/api/reservations/{$reservationId}/checkin", [
            'full_name' => 'Autre Test',
            'first_name' => 'Autre',
            'last_name' => 'Test',
            'customer_phone' => '0341000008',
            'phone_number' => '0341000008',
            'date_of_birth' => '1986-06-06',
            'sex' => 'Femme',
            'id_type' => 'Autre',
            'id_number' => 'AUT-NEW-001',
            'id_document_number' => 'AUT-NEW-001',
            'passport_valid_from' => '2029-01-01',
            'loyalty_count' => 1,
        ]);

        $missingValidity->assertStatus(422);

        $response = $this->postJson("/api/reservations/{$reservationId}/checkin", [
            'full_name' => 'Autre Test',
            'first_name' => 'Autre',
            'last_name' => 'Test',
            'customer_phone' => '0341000008',
            'phone_number' => '0341000008',
            'date_of_birth' => '1986-06-06',
            'sex' => 'Femme',
            'id_type' => 'Autre',
            'id_number' => 'AUT-NEW-001',
            'id_document_number' => 'AUT-NEW-001',
            'passport_valid_from' => '2029-01-01',
            'passport_valid_until' => '2029-12-31',
            'loyalty_count' => 1,
        ]);

        $response->assertOk();

        $guest = Guest::query()->where('reservation_id', $reservationId)->firstOrFail();
        $this->assertSame('Autre', $guest->id_type);
        $this->assertSame('2029-01-01', $guest->passport_valid_from?->toDateString());
        $this->assertSame('2029-12-31', $guest->passport_valid_until?->toDateString());
    }

    public function test_checkin_can_reuse_existing_guest_data_when_form_is_partial(): void
    {
        $user = User::create([
            'name' => 'Reception Test',
            'email' => 'reception-partial@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $reservationId = $this->createReservation($user->id);

        Guest::create([
            'reservation_id' => $reservationId,
            'full_name' => 'Jean Miora',
            'first_name' => 'Jean',
            'last_name' => 'Miora',
            'phone_number' => '0341000003',
            'sex' => 'Homme',
            'id_document_number' => 'CIN-EXIST-123',
            'loyalty_count' => 2,
            'date_of_birth' => '1985-05-05',
            'id_type' => 'CIN',
            'id_number' => 'CIN-EXIST-123',
            'id_photo_path' => null,
        ]);

        $response = $this->postJson("/api/reservations/{$reservationId}/checkin", [
            'full_name' => 'Jean Miora',
            'customer_phone' => '0341000003',
            'phone_number' => '0341000003',
            'checked_in_by_name' => 'Reception Test',
            'checked_in_by_role' => 'receptionist',
        ]);

        $response->assertOk();

        $guest = Guest::query()->where('reservation_id', $reservationId)->firstOrFail();
        $this->assertSame('1985-05-05', $guest->date_of_birth->toDateString());
        $this->assertSame('Homme', $guest->sex);
        $this->assertSame('CIN', $guest->id_type);
    }

    public function test_individual_checkin_applies_one_identity_to_all_rooms(): void
    {
        $user = User::create([
            'name' => 'Reception Test',
            'email' => 'reception-multi-room@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $room1 = Room::create([
            'room_number' => '801',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 110000,
            'is_fixed_price' => false,
        ]);

        $room2 = Room::create([
            'room_number' => '802',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 110000,
            'is_fixed_price' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'Famille Rakoto',
            'client_phone' => '0341000099',
            'customer_phone' => '0341000099',
            'customer_email' => 'famille@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'booking_type' => 'individual',
            'billing_mode' => 'grouped',
            'source' => 'Appel',
            'check_in_date' => '2026-06-20',
            'check_out_date' => '2026-06-22',
            'status' => 'en_attente',
            'payment_status' => 'unbilled',
            'extra_beds' => 0,
            'extra_mattresses' => 0,
        ]);

        $reservation->rooms()->attach($room1->id, [
            'price_snapshot_ariary' => 110000,
        ]);
        $reservation->rooms()->attach($room2->id, [
            'price_snapshot_ariary' => 110000,
        ]);

        $response = $this->postJson("/api/reservations/{$reservation->id}/checkin", [
            'full_name' => 'Famille Rakoto',
            'first_name' => 'Famille',
            'last_name' => 'Rakoto',
            'customer_phone' => '0341000099',
            'phone_number' => '0341000099',
            'date_of_birth' => '1984-04-04',
            'sex' => 'Femme',
            'id_type' => 'CIN',
            'id_number' => 'CIN-ONE-ROOMS-001',
            'id_document_number' => 'CIN-ONE-ROOMS-001',
            'checked_in_by_name' => 'Reception Test',
            'checked_in_by_role' => 'receptionist',
        ]);

        $response->assertOk();

        $reservation->refresh()->load('rooms');
        foreach ($reservation->rooms as $room) {
            $this->assertSame('Famille Rakoto', $room->pivot->occupant_name);
            $this->assertSame('CIN-ONE-ROOMS-001', $room->pivot->occupant_id_number);
        }
    }

    public function test_organization_checkin_allows_passport_without_validity_dates(): void
    {
        $user = User::create([
            'name' => 'Reception Test',
            'email' => 'reception-org-passport@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $room = Room::create([
            'room_number' => '804',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 110000,
            'is_fixed_price' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'Organisme Passeport',
            'client_phone' => '0341000104',
            'customer_phone' => '0341000104',
            'customer_email' => 'organisme@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'booking_type' => 'organization',
            'source' => 'direct',
            'check_in_date' => '2026-06-28',
            'check_out_date' => '2026-06-29',
            'status' => 'en_attente',
            'payment_status' => 'unbilled',
            'extra_beds' => 0,
            'extra_mattresses' => 0,
        ]);

        $reservation->rooms()->attach($room->id, [
            'price_snapshot_ariary' => 110000,
        ]);

        $response = $this->postJson("/api/reservations/{$reservation->id}/checkin", [
            'full_name' => 'Organisme Passeport',
            'customer_phone' => '0341000104',
            'phone_number' => '0341000104',
            'date_of_birth' => '1988-08-08',
            'sex' => 'Homme',
            'id_type' => 'Passeport',
            'id_number' => 'PP-ORG-001',
            'id_document_number' => 'PP-ORG-001',
            'checked_in_by_name' => 'Reception Test',
            'checked_in_by_role' => 'receptionist',
        ]);

        $response->assertOk();
    }

    public function test_organization_checkin_accepts_carte_de_sejour_with_validity_dates(): void
    {
        $user = User::create([
            'name' => 'Reception Test',
            'email' => 'reception-org-card@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $room = Room::create([
            'room_number' => '805',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 110000,
            'is_fixed_price' => false,
        ]);

        $organization = Organization::create([
            'name' => 'Organisme Carte',
            'phone' => '020100000',
            'contact_name' => 'Contact Initial',
            'contact_phone' => '0341000105',
            'contact_email' => 'initial-contact@example.com',
            'email' => 'initial-org@example.com',
            'billing_address' => 'Adresse initiale',
            'nif' => 'NIF-INIT-001',
            'stat' => 'STAT-INIT-001',
            'tax_id' => 'NIF-INIT-001',
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'Organisme Carte',
            'client_phone' => '0341000105',
            'customer_phone' => '0341000105',
            'customer_email' => 'organisme-card@example.com',
            'organization_id' => $organization->id,
            'booking_reference' => 'BR-' . uniqid(),
            'booking_type' => 'organization',
            'source' => 'direct',
            'check_in_date' => '2026-06-28',
            'check_out_date' => '2026-06-29',
            'status' => 'en_attente',
            'payment_status' => 'unbilled',
            'extra_beds' => 0,
            'extra_mattresses' => 0,
        ]);

        $reservation->rooms()->attach($room->id, [
            'price_snapshot_ariary' => 110000,
        ]);

        $response = $this->postJson("/api/reservations/{$reservation->id}/checkin", [
            'full_name' => 'Organisme Carte',
            'organization_name' => 'Organisme Carte',
            'organization_phone' => '020100001',
            'organization_contact_name' => 'Nouveau Contact',
            'organization_contact_phone' => '0341000199',
            'organization_contact_email' => 'contact.organisme@example.com',
            'organization_email' => 'organisme@example.com',
            'organization_billing_address' => 'Nouvelle adresse',
            'organization_nif' => 'NIF-ORG-001',
            'organization_stat' => 'STAT-ORG-001',
            'customer_phone' => '0341000105',
            'phone_number' => '0341000105',
            'date_of_birth' => '1988-08-08',
            'sex' => 'Femme',
            'id_type' => 'Carte de séjour',
            'id_number' => 'CDS-ORG-001',
            'id_document_number' => 'CDS-ORG-001',
            'passport_valid_from' => '2026-01-01',
            'passport_valid_until' => '2027-01-01',
            'checked_in_by_name' => 'Reception Test',
            'checked_in_by_role' => 'receptionist',
            'room_checkins' => [
                [
                    'room_id' => $room->id,
                    'occupant_name' => 'Occupant Chambre 1',
                    'occupant_first_name' => 'Jean',
                    'occupant_date_of_birth' => '1990-02-03',
                    'occupant_sex' => 'Homme',
                    'occupant_id_type' => 'CIN',
                    'occupant_id_number' => 'CIN-ROOM-001',
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('reservation.organization.contact_name', 'Nouveau Contact');
        $response->assertJsonPath('reservation.rooms.0.pivot.occupant_first_name', 'Jean');

        $guest = Guest::query()->where('reservation_id', $reservation->id)->firstOrFail();
        $this->assertSame('Carte de séjour', $guest->id_type);
        $this->assertSame('2026-01-01', $guest->passport_valid_from?->toDateString());
        $this->assertSame('2027-01-01', $guest->passport_valid_until?->toDateString());

        $organization->refresh();
        $this->assertSame('Organisme Carte', $organization->name);
        $this->assertSame('020100001', $organization->phone);
        $this->assertSame('Nouveau Contact', $organization->contact_name);
        $this->assertSame('0341000199', $organization->contact_phone);
        $this->assertSame('contact.organisme@example.com', $organization->contact_email);
        $this->assertSame('organisme@example.com', $organization->email);
        $this->assertSame('Nouvelle adresse', $organization->billing_address);
        $this->assertSame('NIF-ORG-001', $organization->nif);
        $this->assertSame('STAT-ORG-001', $organization->stat);

        $roomBooking = DB::table('booking_room')
            ->where('reservation_id', $reservation->id)
            ->where('room_id', $room->id)
            ->first();
        $this->assertNotNull($roomBooking);
        $this->assertSame('Occupant Chambre 1', $roomBooking->occupant_name);
        $this->assertSame('Jean', $roomBooking->occupant_first_name);
        $this->assertNull($roomBooking->occupant_phone);
        $this->assertNull($roomBooking->occupant_email);
    }

    public function test_organization_room_checkin_requires_document_validity_dates_for_passport_like_documents(): void
    {
        $user = User::create([
            'name' => 'Reception Test',
            'email' => 'reception-org-doc-validity@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $room = Room::create([
            'room_number' => '806',
            'type' => 'Chambre Double',
            'model' => 'Standard',
            'base_price_ariary' => 110000,
            'is_fixed_price' => false,
        ]);

        $reservation = Reservation::create([
            'user_id' => $user->id,
            'client_name' => 'Organisme Autre',
            'client_phone' => '0341000106',
            'customer_phone' => '0341000106',
            'customer_email' => 'organisme-autre@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'booking_type' => 'organization',
            'source' => 'direct',
            'check_in_date' => '2026-06-28',
            'check_out_date' => '2026-06-29',
            'status' => 'en_attente',
            'payment_status' => 'unbilled',
            'extra_beds' => 0,
            'extra_mattresses' => 0,
        ]);

        $reservation->rooms()->attach($room->id, [
            'price_snapshot_ariary' => 110000,
        ]);

        $missingValidity = $this->postJson("/api/reservations/{$reservation->id}/checkin", [
            'full_name' => 'Organisme Autre',
            'customer_phone' => '0341000106',
            'phone_number' => '0341000106',
            'date_of_birth' => '1988-08-08',
            'sex' => 'Homme',
            'id_type' => 'CIN',
            'id_number' => 'CIN-ORG-001',
            'id_document_number' => 'CIN-ORG-001',
            'checked_in_by_name' => 'Reception Test',
            'checked_in_by_role' => 'receptionist',
            'room_checkins' => [
                [
                    'room_id' => $room->id,
                    'occupant_name' => 'Occupant Chambre 1',
                    'occupant_date_of_birth' => '1990-02-03',
                    'occupant_sex' => 'Homme',
                    'occupant_id_type' => 'Autre',
                    'occupant_id_number' => 'AUTRE-ROOM-001',
                ],
            ],
        ]);

        $missingValidity->assertStatus(422);

        $response = $this->postJson("/api/reservations/{$reservation->id}/checkin", [
            'full_name' => 'Organisme Autre',
            'customer_phone' => '0341000106',
            'phone_number' => '0341000106',
            'date_of_birth' => '1988-08-08',
            'sex' => 'Homme',
            'id_type' => 'CIN',
            'id_number' => 'CIN-ORG-001',
            'id_document_number' => 'CIN-ORG-001',
            'checked_in_by_name' => 'Reception Test',
            'checked_in_by_role' => 'receptionist',
            'room_checkins' => [
                [
                    'room_id' => $room->id,
                    'occupant_name' => 'Occupant Chambre 1',
                    'occupant_date_of_birth' => '1990-02-03',
                    'occupant_sex' => 'Homme',
                    'occupant_id_type' => 'Autre',
                    'occupant_id_number' => 'AUTRE-ROOM-001',
                    'occupant_passport_valid_from' => '2026-01-01',
                    'occupant_passport_valid_until' => '2027-01-01',
                ],
            ],
        ]);

        $response->assertOk();

        $roomBooking = DB::table('booking_room')
            ->where('reservation_id', $reservation->id)
            ->where('room_id', $room->id)
            ->first();

        $this->assertNotNull($roomBooking);
        $this->assertSame('2026-01-01', $roomBooking->occupant_passport_valid_from);
        $this->assertSame('2027-01-01', $roomBooking->occupant_passport_valid_until);
    }

    private function createReservation(int $userId): int
    {
        return DB::table('reservations')->insertGetId([
            'user_id' => $userId,
            'client_name' => 'Marie Razafindrakoto',
            'client_phone' => '0341000001',
            'customer_phone' => '0341000001',
            'customer_email' => 'marie@example.com',
            'booking_reference' => 'BR-' . uniqid(),
            'source' => 'direct',
            'check_in_date' => '2026-06-16',
            'check_out_date' => '2026-06-17',
            'status' => 'en_attente',
            'payment_status' => 'unbilled',
            'extra_beds' => 0,
            'extra_mattresses' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
