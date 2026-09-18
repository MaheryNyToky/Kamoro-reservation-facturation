<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_audits', function (Blueprint $table) {
            $table->index(['reservation_id', 'action', 'created_at'], 'reservation_audits_res_action_created_idx');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->index('parent_invoice_id', 'invoices_parent_invoice_id_idx');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->index('client_name', 'reservations_client_name_idx');
        });
    }

    public function down(): void
    {
        Schema::table('reservation_audits', function (Blueprint $table) {
            $table->dropIndex('reservation_audits_res_action_created_idx');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_parent_invoice_id_idx');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex('reservations_client_name_idx');
        });
    }
};
