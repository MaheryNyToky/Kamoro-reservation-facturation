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

        $bookingRoomReferences = $this->foreignKeyTargets('booking_room', 'invoice_id');
        $invoiceItemsReferences = $this->foreignKeyTargets('invoice_items', 'invoice_id');

        $needsBookingRoomRepair = in_array('invoices_old', $bookingRoomReferences, true);
        $needsInvoiceItemsRepair = in_array('invoices_old', $invoiceItemsReferences, true);

        if (! $needsBookingRoomRepair && ! $needsInvoiceItemsRepair) {
            return;
        }

        DB::statement('PRAGMA foreign_keys=off');

        if ($needsBookingRoomRepair && Schema::hasTable('booking_room')) {
            Schema::create('booking_room_repair', function (Blueprint $table) {
                $table->id();
                $table->foreignId('reservation_id')->constrained()->onDelete('cascade');
                $table->foreignId('room_id')->constrained()->onDelete('cascade');
                $table->integer('price_snapshot_ariary');
                $table->timestamps();
                $table->string('occupant_name')->nullable();
                $table->string('occupant_phone', 50)->nullable();
                $table->string('occupant_email')->nullable();
                $table->date('occupant_date_of_birth')->nullable();
                $table->string('occupant_sex', 20)->nullable();
                $table->string('occupant_id_type', 40)->nullable();
                $table->string('occupant_id_number', 100)->nullable();
                $table->timestamp('checked_in_at')->nullable();
                $table->string('checked_in_by_name', 120)->nullable();
                $table->string('checked_in_by_role', 40)->nullable();
                $table->foreignId('invoice_id')
                    ->nullable()
                    ->constrained('invoices')
                    ->nullOnDelete();
            });

            DB::statement(
                'INSERT INTO booking_room_repair (
                    id, reservation_id, room_id, price_snapshot_ariary, created_at, updated_at,
                    occupant_name, occupant_phone, occupant_email, occupant_date_of_birth,
                    occupant_sex, occupant_id_type, occupant_id_number, checked_in_at,
                    checked_in_by_name, checked_in_by_role, invoice_id
                )
                SELECT
                    id, reservation_id, room_id, price_snapshot_ariary, created_at, updated_at,
                    occupant_name, occupant_phone, occupant_email, occupant_date_of_birth,
                    occupant_sex, occupant_id_type, occupant_id_number, checked_in_at,
                    checked_in_by_name, checked_in_by_role, invoice_id
                FROM booking_room'
            );

            Schema::drop('booking_room');
            Schema::rename('booking_room_repair', 'booking_room');

            Schema::table('booking_room', function (Blueprint $table) {
                $table->index(['reservation_id', 'room_id'], 'booking_room_reservation_room_idx');
                $table->index(['room_id', 'reservation_id'], 'booking_room_room_reservation_idx');
            });
        }

        if ($needsInvoiceItemsRepair && Schema::hasTable('invoice_items')) {
            Schema::create('invoice_items_repair', function (Blueprint $table) {
                $table->id();
                $table->foreignId('invoice_id')->constrained('invoices')->onDelete('cascade');
                $table->string('description');
                $table->string('type');
                $table->integer('amount_ariary');
                $table->integer('quantity')->default(1);
                $table->timestamps();
                $table->foreignId('booking_room_id')
                    ->nullable()
                    ->constrained('booking_room')
                    ->nullOnDelete();
            });

            DB::statement(
                'INSERT INTO invoice_items_repair (
                    id, invoice_id, description, type, amount_ariary, quantity, created_at, updated_at, booking_room_id
                )
                SELECT
                    id, invoice_id, description, type, amount_ariary, quantity, created_at, updated_at, booking_room_id
                FROM invoice_items'
            );

            Schema::drop('invoice_items');
            Schema::rename('invoice_items_repair', 'invoice_items');
        }

        DB::statement('PRAGMA foreign_keys=on');
    }

    public function down(): void
    {
        // Réparation locale uniquement.
    }

    /**
     * @return array<int, string>
     */
    private function foreignKeyTargets(string $table, string $column): array
    {
        if (!Schema::hasTable($table)) {
            return [];
        }

        $rows = DB::select("PRAGMA foreign_key_list('$table')");

        return collect($rows)
            ->filter(fn ($row) => isset($row->from, $row->table) && $row->from === $column)
            ->map(fn ($row) => (string) $row->table)
            ->values()
            ->all();
    }
};
