<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_room', function (Blueprint $table) {
            if (! Schema::hasColumn('booking_room', 'occupant_passport_valid_from')) {
                $table->date('occupant_passport_valid_from')->nullable()->after('occupant_id_number');
            }

            if (! Schema::hasColumn('booking_room', 'occupant_passport_valid_until')) {
                $table->date('occupant_passport_valid_until')->nullable()->after('occupant_passport_valid_from');
            }
        });
    }

    public function down(): void
    {
        Schema::table('booking_room', function (Blueprint $table) {
            foreach (['occupant_passport_valid_until', 'occupant_passport_valid_from'] as $column) {
                if (Schema::hasColumn('booking_room', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
