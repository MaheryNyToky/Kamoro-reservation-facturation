<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('rooms')
            ->where('room_number', '02')
            ->update([
                'type' => 'Chambre Double',
                'model' => 'Standard',
                'base_price_ariary' => 110000,
                'is_fixed_price' => false,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('rooms')
            ->where('room_number', '02')
            ->update([
                'type' => 'Chambre Double',
                'model' => 'Standard (état dégradé)',
                'base_price_ariary' => 95000,
                'is_fixed_price' => true,
                'updated_at' => now(),
            ]);
    }
};
