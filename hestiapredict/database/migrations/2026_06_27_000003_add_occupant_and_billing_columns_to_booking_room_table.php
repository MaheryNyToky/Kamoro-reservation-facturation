<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_room', function (Blueprint $table) {
            if (! Schema::hasColumn('booking_room', 'occupant_name')) {
                $table->string('occupant_name')->nullable()->after('price_snapshot_ariary');
            }

            if (! Schema::hasColumn('booking_room', 'occupant_phone')) {
                $table->string('occupant_phone', 50)->nullable()->after('occupant_name');
            }

            if (! Schema::hasColumn('booking_room', 'occupant_email')) {
                $table->string('occupant_email')->nullable()->after('occupant_phone');
            }

            if (! Schema::hasColumn('booking_room', 'occupant_date_of_birth')) {
                $table->date('occupant_date_of_birth')->nullable()->after('occupant_email');
            }

            if (! Schema::hasColumn('booking_room', 'occupant_sex')) {
                $table->string('occupant_sex', 20)->nullable()->after('occupant_date_of_birth');
            }

            if (! Schema::hasColumn('booking_room', 'occupant_id_type')) {
                $table->string('occupant_id_type', 40)->nullable()->after('occupant_sex');
            }

            if (! Schema::hasColumn('booking_room', 'occupant_id_number')) {
                $table->string('occupant_id_number', 100)->nullable()->after('occupant_id_type');
            }

            if (! Schema::hasColumn('booking_room', 'checked_in_at')) {
                $table->timestamp('checked_in_at')->nullable()->after('occupant_id_number');
            }

            if (! Schema::hasColumn('booking_room', 'checked_in_by_name')) {
                $table->string('checked_in_by_name', 120)->nullable()->after('checked_in_at');
            }

            if (! Schema::hasColumn('booking_room', 'checked_in_by_role')) {
                $table->string('checked_in_by_role', 40)->nullable()->after('checked_in_by_name');
            }

            if (! Schema::hasColumn('booking_room', 'invoice_id')) {
                $table->foreignId('invoice_id')
                    ->nullable()
                    ->after('checked_in_by_role')
                    ->constrained('invoices')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('booking_room', function (Blueprint $table) {
            if (Schema::hasColumn('booking_room', 'invoice_id')) {
                $table->dropConstrainedForeignId('invoice_id');
            }

            foreach ([
                'checked_in_by_role',
                'checked_in_by_name',
                'checked_in_at',
                'occupant_id_number',
                'occupant_id_type',
                'occupant_sex',
                'occupant_date_of_birth',
                'occupant_email',
                'occupant_phone',
                'occupant_name',
            ] as $column) {
                if (Schema::hasColumn('booking_room', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
