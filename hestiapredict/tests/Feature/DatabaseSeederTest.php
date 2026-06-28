<?php

namespace Tests\Feature;

use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
}
