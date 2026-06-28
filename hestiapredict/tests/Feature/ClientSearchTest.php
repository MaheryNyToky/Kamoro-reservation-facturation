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
