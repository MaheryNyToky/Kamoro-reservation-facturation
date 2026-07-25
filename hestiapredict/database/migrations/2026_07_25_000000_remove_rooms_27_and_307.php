<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('rooms')
            ->whereIn('room_number', ['27', '307'])
            ->delete();
    }

    public function down(): void
    {
        // Les caractéristiques d'origine des chambres supprimées ne sont pas
        // restaurées automatiquement afin de ne pas recréer des chambres retirées.
    }
};
