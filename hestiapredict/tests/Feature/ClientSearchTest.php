<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClientSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_deduplicates_clients_with_same_identity(): void
    {
        $user = User::create([
            'name' => 'Reception Test',
            'email' => 'reception-test@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $firstReservationId = $this->createReservation($user->id, 'Alice Dupont', '0340000001');
        $secondReservationId = $this->createReservation($user->id, 'Alice Dupont', '0340000001');
        $otherReservationId = $this->createReservation($user->id, 'Bob Martin', '0340000002');

        Guest::create([
            'reservation_id' => $firstReservationId,
            'full_name' => 'Alice Dupont',
            'first_name' => 'Alice',
            'last_name' => 'Dupont',
            'phone_number' => '0340000001',
            'sex' => 'Femme',
            'passport_valid_from' => '2027-01-01',
            'passport_valid_until' => '2027-06-30',
            'id_document_number' => 'PP-OLD-123',
            'loyalty_count' => 2,
            'date_of_birth' => '1990-01-01',
            'id_type' => 'Passeport',
            'id_number' => 'PP-OLD-123',
            'id_photo_path' => null,
        ]);

        Guest::create([
            'reservation_id' => $secondReservationId,
            'full_name' => 'Alice Dupont',
            'first_name' => 'Alice',
            'last_name' => 'Dupont',
            'phone_number' => '0340000001',
            'sex' => 'Femme',
            'passport_valid_from' => '2028-01-01',
            'passport_valid_until' => '2029-12-31',
            'id_document_number' => 'PP-NEW-999',
            'loyalty_count' => 7,
            'date_of_birth' => '1990-01-01',
            'id_type' => 'Passeport',
            'id_number' => 'PP-NEW-999',
            'id_photo_path' => null,
        ]);

        Guest::create([
            'reservation_id' => $otherReservationId,
            'full_name' => 'Bob Martin',
            'first_name' => 'Bob',
            'last_name' => 'Martin',
            'phone_number' => '0340000002',
            'sex' => 'Homme',
            'id_document_number' => 'CIN-999999',
            'loyalty_count' => 1,
            'date_of_birth' => '1992-01-01',
            'id_type' => 'CIN',
            'id_number' => 'CIN-999999',
            'id_photo_path' => null,
        ]);

        $response = $this->getJson('/api/clients/search?q=Alice%20Dupont');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.full_name', 'Alice Dupont');
        $response->assertJsonPath('data.0.loyalty_count', 7);
    }

    public function test_search_prefers_name_matches_over_document_substrings(): void
    {
        $user = User::create([
            'name' => 'Reception Test',
            'email' => 'reception-name-test@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $aliceReservationId = $this->createReservation($user->id, 'Alice Dupont', '0341000010');
        $bobReservationId = $this->createReservation($user->id, 'Bob Martin', '0341000011');

        Guest::create([
            'reservation_id' => $aliceReservationId,
            'full_name' => 'Alice Dupont',
            'first_name' => 'Alice',
            'last_name' => 'Dupont',
            'phone_number' => '0341000010',
            'sex' => 'Femme',
            'id_document_number' => 'CIN-ALICE-001',
            'loyalty_count' => 1,
            'date_of_birth' => '1991-01-01',
            'id_type' => 'CIN',
            'id_number' => 'CIN-ALICE-001',
            'id_photo_path' => null,
        ]);

        Guest::create([
            'reservation_id' => $bobReservationId,
            'full_name' => 'Bob Martin',
            'first_name' => 'Bob',
            'last_name' => 'Martin',
            'phone_number' => '0341000011',
            'sex' => 'Homme',
            'id_document_number' => 'XALICE-999',
            'loyalty_count' => 3,
            'date_of_birth' => '1992-02-02',
            'id_type' => 'CIN',
            'id_number' => 'XALICE-999',
            'id_photo_path' => null,
        ]);

        $response = $this->getJson('/api/clients/search?q=Alice');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.full_name', 'Alice Dupont');
    }

    public function test_search_deduplicates_same_document_and_keeps_latest_phone_number(): void
    {
        $user = User::create([
            'name' => 'Reception Document Test',
            'email' => 'reception-document-test@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $oldReservationId = $this->createReservation(
            $user->id,
            'Vololonirina Manoa Andriamanamihaja',
            '0340000100',
        );
        $latestReservationId = $this->createReservation(
            $user->id,
            'Vololonirina Manoa Andriamanamihaja',
            '0320000200',
        );

        $oldGuest = Guest::create([
            'reservation_id' => $oldReservationId,
            'full_name' => 'Vololonirina Manoa Andriamanamihaja',
            'first_name' => 'Manoa',
            'last_name' => 'Vololonirina Andriamanamihaja',
            'phone_number' => '0340000100',
            'sex' => 'Femme',
            'id_document_number' => '101 242 110 130',
            'loyalty_count' => 9,
            'date_of_birth' => '1980-12-23',
            'id_type' => 'CIN',
            'id_number' => '101 242 110 130',
            'id_photo_path' => null,
        ]);
        $oldGuest->forceFill([
            'created_at' => '2026-01-01 10:00:00',
            'updated_at' => '2026-01-01 10:00:00',
        ])->saveQuietly();

        $latestGuest = Guest::create([
            'reservation_id' => $latestReservationId,
            'full_name' => 'Vololonirina Manoa Andriamanamihaja',
            'first_name' => 'Manoa',
            'last_name' => 'Vololonirina Andriamanamihaja',
            'phone_number' => '0320000200',
            'sex' => 'Femme',
            'id_document_number' => '101242110130',
            'loyalty_count' => 1,
            'date_of_birth' => '1980-12-23',
            'id_type' => 'CIN',
            'id_number' => '101242110130',
            'id_photo_path' => null,
        ]);
        $latestGuest->forceFill([
            'created_at' => '2026-07-26 10:00:00',
            'updated_at' => '2026-07-26 10:00:00',
        ])->saveQuietly();

        $response = $this->getJson('/api/clients/search?q=Vololonirina');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $latestGuest->id);
        $response->assertJsonPath('data.0.phone_number', '0320000200');
        $response->assertJsonPath('data.0.id_number', '101242110130');
    }

    public function test_search_returns_an_organization_room_occupant_without_a_guest_profile(): void
    {
        $user = User::create([
            'name' => 'Reception Occupant Test',
            'email' => 'reception-occupant-test@example.com',
            'password' => 'password',
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $reservationId = $this->createReservation(
            $user->id,
            'Organisme Recherche',
            '0341000020',
        );
        DB::table('reservations')->where('id', $reservationId)->update([
            'booking_type' => 'organization',
        ]);

        $roomId = DB::table('rooms')->insertGetId([
            'room_number' => 'SEARCH-OCC-01',
            'type' => 'Double',
            'model' => 'Standard',
            'base_price_ariary' => 120000,
            'is_fixed_price' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('booking_room')->insert([
            'reservation_id' => $reservationId,
            'room_id' => $roomId,
            'price_snapshot_ariary' => 120000,
            'occupant_name' => 'Rakoto',
            'occupant_first_name' => 'Secondaire',
            'occupant_phone' => '0341000021',
            'occupant_date_of_birth' => '1993-04-05',
            'occupant_sex' => 'Femme',
            'occupant_id_type' => 'Passeport',
            'occupant_id_number' => 'PP-OCC-SEARCH-001',
            'occupant_passport_valid_from' => '2026-01-01',
            'occupant_passport_valid_until' => '2031-01-01',
            'checked_in_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseMissing('guests', [
            'reservation_id' => $reservationId,
        ]);

        $response = $this->getJson('/api/clients/search?q=Secondaire');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.full_name', 'Rakoto Secondaire');
        $response->assertJsonPath('data.0.first_name', 'Secondaire');
        $response->assertJsonPath('data.0.last_name', 'Rakoto');
        $response->assertJsonPath('data.0.date_of_birth', '1993-04-05');
        $response->assertJsonPath('data.0.sex', 'Femme');
        $response->assertJsonPath('data.0.id_type', 'Passeport');
        $response->assertJsonPath('data.0.id_number', 'PP-OCC-SEARCH-001');
        $response->assertJsonPath(
            'data.0.passport_valid_until',
            '2031-01-01',
        );
    }

    private function createReservation(int $userId, string $clientName, string $clientPhone): int
    {
        return DB::table('reservations')->insertGetId([
            'user_id' => $userId,
            'client_name' => $clientName,
            'client_phone' => $clientPhone,
            'check_in_date' => '2026-06-16',
            'check_out_date' => '2026-06-17',
            'status' => 'arrive',
            'source' => 'direct',
            'booking_reference' => 'BR-' . uniqid(),
            'customer_phone' => $clientPhone,
            'customer_email' => null,
            'payment_status' => 'paid',
            'extra_beds' => 0,
            'extra_mattresses' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
