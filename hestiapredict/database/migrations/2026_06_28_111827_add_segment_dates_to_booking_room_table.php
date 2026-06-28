<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_room', function (Blueprint $table) {
            if (! Schema::hasColumn('booking_room', 'segment_start_date')) {
                $table->date('segment_start_date')->nullable()->after('price_snapshot_ariary');
            }

            if (! Schema::hasColumn('booking_room', 'segment_end_date')) {
                $table->date('segment_end_date')->nullable()->after('segment_start_date');
            }
        });

        if (Schema::hasTable('booking_room') && Schema::hasTable('reservations')) {
            DB::statement(
                'UPDATE booking_room
                 SET segment_start_date = COALESCE(segment_start_date, (
                        SELECT check_in_date
                        FROM reservations
                        WHERE reservations.id = booking_room.reservation_id
                    )),
                     segment_end_date = COALESCE(segment_end_date, (
                        SELECT check_out_date
                        FROM reservations
                        WHERE reservations.id = booking_room.reservation_id
                    ))'
            );
        }
    }

    public function down(): void
    {
        Schema::table('booking_room', function (Blueprint $table) {
            if (Schema::hasColumn('booking_room', 'segment_end_date')) {
                $table->dropColumn('segment_end_date');
            }

            if (Schema::hasColumn('booking_room', 'segment_start_date')) {
                $table->dropColumn('segment_start_date');
            }
        });
    }
};
