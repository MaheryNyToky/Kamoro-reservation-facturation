<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('reservations', 'organization_id')) {
                $table->foreignId('organization_id')
                    ->nullable()
                    ->after('client_name')
                    ->constrained('organizations')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('reservations', 'booking_type')) {
                $table->string('booking_type', 20)->default('individual')->after('organization_id');
            }

            if (! Schema::hasColumn('reservations', 'billing_mode')) {
                $table->string('billing_mode', 20)->default('grouped')->after('booking_type');
            }
        });

        if (Schema::hasColumn('reservations', 'booking_type')) {
            DB::table('reservations')
                ->whereNotNull('organization_id')
                ->update(['booking_type' => 'organization']);
        }
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            if (Schema::hasColumn('reservations', 'billing_mode')) {
                $table->dropColumn('billing_mode');
            }

            if (Schema::hasColumn('reservations', 'booking_type')) {
                $table->dropColumn('booking_type');
            }

            if (Schema::hasColumn('reservations', 'organization_id')) {
                $table->dropConstrainedForeignId('organization_id');
            }
        });
    }
};
