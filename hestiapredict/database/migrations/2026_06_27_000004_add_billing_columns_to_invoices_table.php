<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'organization_id')) {
                $table->foreignId('organization_id')
                    ->nullable()
                    ->after('reservation_id')
                    ->constrained('organizations')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('invoices', 'billing_mode')) {
                $table->string('billing_mode', 20)->default('grouped')->after('document_type');
            }

            if (! Schema::hasColumn('invoices', 'invoice_kind')) {
                $table->string('invoice_kind', 20)->default('master')->after('billing_mode');
            }

            if (! Schema::hasColumn('invoices', 'parent_invoice_id')) {
                $table->foreignId('parent_invoice_id')
                    ->nullable()
                    ->after('invoice_kind')
                    ->constrained('invoices')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('invoices', 'booking_room_id')) {
                $table->foreignId('booking_room_id')
                    ->nullable()
                    ->after('parent_invoice_id')
                    ->constrained('booking_room')
                    ->nullOnDelete();
            }
        });

        DB::table('invoices')
            ->whereNull('invoice_kind')
            ->update([
                'invoice_kind' => 'master',
                'billing_mode' => 'grouped',
            ]);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'booking_room_id')) {
                $table->dropConstrainedForeignId('booking_room_id');
            }
            if (Schema::hasColumn('invoices', 'parent_invoice_id')) {
                $table->dropConstrainedForeignId('parent_invoice_id');
            }
            if (Schema::hasColumn('invoices', 'invoice_kind')) {
                $table->dropColumn('invoice_kind');
            }
            if (Schema::hasColumn('invoices', 'billing_mode')) {
                $table->dropColumn('billing_mode');
            }
            if (Schema::hasColumn('invoices', 'organization_id')) {
                $table->dropConstrainedForeignId('organization_id');
            }
        });
    }
};
