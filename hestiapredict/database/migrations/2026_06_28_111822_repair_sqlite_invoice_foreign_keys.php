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

        if (!Schema::hasTable('booking_room_old')) {
            return;
        }

        DB::statement('PRAGMA foreign_keys=off');

        if (Schema::hasTable('booking_room')) {
            Schema::rename('booking_room', 'booking_room_old');

            Schema::create('booking_room', function (Blueprint $table) {
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
                'INSERT INTO booking_room (
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
                FROM booking_room_old'
            );

            Schema::drop('booking_room_old');

            Schema::table('booking_room', function (Blueprint $table) {
                $table->index(['reservation_id', 'room_id'], 'booking_room_reservation_room_idx');
                $table->index(['room_id', 'reservation_id'], 'booking_room_room_reservation_idx');
            });
        }

        if (Schema::hasTable('invoice_items')) {
            Schema::rename('invoice_items', 'invoice_items_old');

            Schema::create('invoice_items', function (Blueprint $table) {
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
                'INSERT INTO invoice_items (
                    id, invoice_id, description, type, amount_ariary, quantity, created_at, updated_at, booking_room_id
                )
                SELECT
                    id, invoice_id, description, type, amount_ariary, quantity, created_at, updated_at, booking_room_id
                FROM invoice_items_old'
            );

            Schema::drop('invoice_items_old');
        }

        DB::statement('PRAGMA foreign_keys=on');
    }

    public function down(): void
    {
        // Réparation locale uniquement. On ne tente pas d’inverser les clés SQLite.
    }
};
