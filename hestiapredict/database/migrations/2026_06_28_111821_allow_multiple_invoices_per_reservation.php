<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::dropIfExists('invoices_new');
            DB::statement('PRAGMA foreign_keys=off');

            Schema::create('invoices_new', function (Blueprint $table) {
                $table->id();
                $table->foreignId('reservation_id')->constrained()->onDelete('cascade');
                $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
                $table->string('invoice_number')->nullable();
                $table->integer('total_amount_ariary')->default(0);
                $table->integer('tax_amount_ariary')->default(0);
                $table->string('discount_mode')->nullable();
                $table->decimal('discount_value', 10, 2)->nullable();
                $table->integer('discount_amount_ariary')->default(0);
                $table->integer('deposit_amount_ariary')->default(0);
                $table->string('pdf_path')->nullable();
                $table->timestamp('finalized_at')->nullable();
                $table->string('status')->default('open');
                $table->string('document_type')->default('facture');
                $table->string('billing_mode', 20)->default('grouped');
                $table->string('invoice_kind', 20)->default('master');
                $table->foreignId('parent_invoice_id')->nullable()->constrained('invoices_new')->nullOnDelete();
                $table->foreignId('booking_room_id')->nullable()->constrained('booking_room')->nullOnDelete();
                $table->timestamps();
                $table->unique('invoice_number');
            });

            DB::statement(
                'INSERT INTO invoices_new (
                    id, reservation_id, organization_id, invoice_number, total_amount_ariary, tax_amount_ariary,
                    discount_mode, discount_value, discount_amount_ariary, deposit_amount_ariary, pdf_path,
                    finalized_at, status, document_type, billing_mode, invoice_kind, parent_invoice_id,
                    booking_room_id, created_at, updated_at
                )
                SELECT
                    id, reservation_id, organization_id, invoice_number, total_amount_ariary, tax_amount_ariary,
                    discount_mode, discount_value, discount_amount_ariary, deposit_amount_ariary, pdf_path,
                    finalized_at, status, COALESCE(document_type, "facture"), COALESCE(billing_mode, "grouped"),
                    COALESCE(invoice_kind, "master"), parent_invoice_id, booking_room_id, created_at, updated_at
                FROM invoices'
            );

            Schema::drop('invoices');
            Schema::rename('invoices_new', 'invoices');
            DB::statement('PRAGMA foreign_keys=on');
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'reservation_id')) {
                $table->dropUnique('invoices_reservation_id_unique');
            }
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::dropIfExists('invoices_old');
            DB::statement('PRAGMA foreign_keys=off');

            Schema::create('invoices_old', function (Blueprint $table) {
                $table->id();
                $table->foreignId('reservation_id')->constrained()->onDelete('cascade');
                $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
                $table->string('invoice_number')->nullable();
                $table->integer('total_amount_ariary')->default(0);
                $table->integer('tax_amount_ariary')->default(0);
                $table->string('discount_mode')->nullable();
                $table->decimal('discount_value', 10, 2)->nullable();
                $table->integer('discount_amount_ariary')->default(0);
                $table->integer('deposit_amount_ariary')->default(0);
                $table->string('pdf_path')->nullable();
                $table->timestamp('finalized_at')->nullable();
                $table->string('status')->default('open');
                $table->string('document_type')->default('facture');
                $table->string('billing_mode', 20)->default('grouped');
                $table->string('invoice_kind', 20)->default('master');
                $table->foreignId('parent_invoice_id')->nullable()->constrained('invoices_old')->nullOnDelete();
                $table->foreignId('booking_room_id')->nullable()->constrained('booking_room')->nullOnDelete();
                $table->timestamps();
                $table->unique('reservation_id');
                $table->unique('invoice_number');
            });

            DB::statement(
                'INSERT INTO invoices_old (
                    id, reservation_id, organization_id, invoice_number, total_amount_ariary, tax_amount_ariary,
                    discount_mode, discount_value, discount_amount_ariary, deposit_amount_ariary, pdf_path,
                    finalized_at, status, document_type, billing_mode, invoice_kind, parent_invoice_id,
                    booking_room_id, created_at, updated_at
                )
                SELECT
                    id, reservation_id, organization_id, invoice_number, total_amount_ariary, tax_amount_ariary,
                    discount_mode, discount_value, discount_amount_ariary, deposit_amount_ariary, pdf_path,
                    finalized_at, status, COALESCE(document_type, "facture"), COALESCE(billing_mode, "grouped"),
                    COALESCE(invoice_kind, "master"), parent_invoice_id, booking_room_id, created_at, updated_at
                FROM invoices'
            );

            Schema::drop('invoices');
            Schema::rename('invoices_old', 'invoices');
            DB::statement('PRAGMA foreign_keys=on');
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->unique('reservation_id');
        });
    }
};
