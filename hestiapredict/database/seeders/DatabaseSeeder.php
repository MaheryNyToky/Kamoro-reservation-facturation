<?php

namespace Database\Seeders;

use App\Models\Reservation;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(KamoroHotelSeeder::class);

        if (Reservation::query()->count() === 0) {
            $this->call(ClientTestDatasetSeeder::class);
        }
    }
}
