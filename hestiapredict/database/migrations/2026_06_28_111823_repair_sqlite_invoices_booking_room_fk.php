<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        if (!Schema::hasTable('invoices_old')) {
            return;
        }

        DB::statement('PRAGMA foreign_keys=off');
        DB::statement('DROP INDEX IF EXISTS invoices_invoice_number_unique');
        Schema::dropIfExists('invoices');

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->onDelete('cascade');
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('invoice_number')->unique()->nullable();
            $table->integer('total_amount_ariary')->default(0);
            $table->integer('tax_amount_ariary')->default(0);
            $table->string('discount_mode')->nullable();
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->integer('discount_amount_ariary')->default(0);
            $table->integer('deposit_amount_ariary')->default(0);
            $table->string('pdf_path')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->string('status')->default('open');
            $table->string('document_type')->default('facture');
            $table->string('billing_mode')->default('grouped');
            $table->string('invoice_kind')->default('master');
            $table->foreignId('parent_invoice_id')
                ->nullable()
                ->constrained('invoices')
                ->nullOnDelete();
            $table->foreignId('booking_room_id')
                ->nullable()
                ->constrained('booking_room')
                ->nullOnDelete();
            $table->timestamps();
        });

        DB::statement(
            'INSERT INTO invoices (
                id, reservation_id, organization_id, invoice_number, total_amount_ariary, tax_amount_ariary,
                discount_mode, discount_value, discount_amount_ariary, deposit_amount_ariary, pdf_path,
                finalized_at, status, document_type, billing_mode, invoice_kind, parent_invoice_id,
                booking_room_id, created_at, updated_at
            )
            SELECT
                id, reservation_id, organization_id, invoice_number, total_amount_ariary, tax_amount_ariary,
                discount_mode, discount_value, discount_amount_ariary, deposit_amount_ariary, pdf_path,
                finalized_at, status, document_type, billing_mode, invoice_kind, parent_invoice_id,
                booking_room_id, created_at, updated_at
            FROM invoices_old'
        );

        Schema::drop('invoices_old');
        DB::statement('PRAGMA foreign_keys=on');
    }

    public function down(): void
    {
        // Réparation locale uniquement.
    }
};
