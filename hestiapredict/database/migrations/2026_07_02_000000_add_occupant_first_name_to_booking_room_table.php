<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_room', function (Blueprint $table) {
            if (!Schema::hasColumn('booking_room', 'occupant_first_name')) {
                $table->string('occupant_first_name', 255)->nullable()->after('occupant_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('booking_room', function (Blueprint $table) {
            if (Schema::hasColumn('booking_room', 'occupant_first_name')) {
                $table->dropColumn('occupant_first_name');
            }
        });
    }
};
