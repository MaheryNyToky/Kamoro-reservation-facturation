<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('invoices', 'organization_billing_meta')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->json('organization_billing_meta')
                    ->nullable()
                    ->after('booking_room_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('invoices', 'organization_billing_meta')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->dropColumn('organization_billing_meta');
            });
        }
    }
};
