<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('client_name')->nullable();
            $table->string('client_phone')->nullable();
            $table->string('client_email')->nullable();
            $table->string('invoice_category')->default('stay');
            $table->timestamp('issued_at')->nullable();
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignId('reservation_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn(['client_name', 'client_phone', 'client_email', 'invoice_category', 'issued_at']);
        });
    }
};
