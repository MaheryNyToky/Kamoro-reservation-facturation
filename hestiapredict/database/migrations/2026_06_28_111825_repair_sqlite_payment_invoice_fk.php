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

        if (!Schema::hasTable('payments')) {
            return;
        }

        $targets = collect(DB::select("PRAGMA foreign_key_list('payments')"))
            ->filter(fn ($row) => ($row->from ?? null) === 'invoice_id')
            ->map(fn ($row) => (string) ($row->table ?? ''))
            ->values()
            ->all();

        if (!in_array('invoices_old', $targets, true)) {
            return;
        }

        DB::statement('PRAGMA foreign_keys=off');
        Schema::create('payments_repair', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->onDelete('cascade');
            $table->integer('amount_ariary');
            $table->string('payment_method');
            $table->timestamps();
            $table->string('reference')->nullable();
            $table->string('processed_by_name')->nullable();
            $table->string('processed_by_role')->nullable();
            $table->string('payment_context')->default('payment');
            $table->string('payment_operator')->nullable();
            $table->integer('amount_received_ariary')->nullable();
            $table->integer('change_given_ariary')->default(0);
        });

        DB::statement(
            'INSERT INTO payments_repair (
                id, invoice_id, amount_ariary, payment_method, created_at, updated_at,
                reference, processed_by_name, processed_by_role, payment_context,
                payment_operator, amount_received_ariary, change_given_ariary
            )
            SELECT
                id, invoice_id, amount_ariary, payment_method, created_at, updated_at,
                reference, processed_by_name, processed_by_role, payment_context,
                payment_operator, amount_received_ariary, change_given_ariary
            FROM payments'
        );

        Schema::drop('payments');
        Schema::rename('payments_repair', 'payments');

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['invoice_id', 'payment_context', 'created_at'], 'payments_invoice_context_created_idx');
        });

        DB::statement('PRAGMA foreign_keys=on');
    }

    public function down(): void
    {
        // Réparation locale uniquement.
    }
};
