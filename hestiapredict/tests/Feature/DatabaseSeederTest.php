<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_bootstraps_demo_reservations_for_ai(): void
    {
        Artisan::call('db:seed', ['--force' => true]);

        $this->assertGreaterThanOrEqual(10, Reservation::query()->count());
        $this->assertGreaterThanOrEqual(10, Reservation::query()->whereIn('status', ['arrive', 'en_attente', 'annule'])->count());
    }

    public function test_database_seeder_does_not_reset_an_existing_superadmin_password(): void
    {
        $passwordHash = Hash::make('ancien-mot-de-passe');
        User::create([
            'name' => 'Superadmin existant',
            'email' => 'superadmin@kamorohotel.com',
            'password' => $passwordHash,
            'role' => 'superadmin',
            'is_blacklisted' => false,
        ]);

        Artisan::call('db:seed', ['--force' => true]);

        $user = User::where('email', 'superadmin@kamorohotel.com')->firstOrFail();
        $this->assertTrue(Hash::check('ancien-mot-de-passe', $user->password));
    }
}
