<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_room', function (Blueprint $table) {
            if (! Schema::hasColumn('booking_room', 'segment_extra_beds')) {
                $table->integer('segment_extra_beds')->default(0)->after('segment_end_date');
            }

            if (! Schema::hasColumn('booking_room', 'segment_extra_mattresses')) {
                $table->integer('segment_extra_mattresses')->default(0)->after('segment_extra_beds');
            }
        });
    }

    public function down(): void
    {
        Schema::table('booking_room', function (Blueprint $table) {
            foreach (['segment_extra_mattresses', 'segment_extra_beds'] as $column) {
                if (Schema::hasColumn('booking_room', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
