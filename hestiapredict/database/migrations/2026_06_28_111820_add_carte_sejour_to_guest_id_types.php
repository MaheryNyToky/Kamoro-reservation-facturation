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
            DB::statement('PRAGMA foreign_keys=off');
            Schema::dropIfExists('guests_old');
            Schema::dropIfExists('guests_new');

            Schema::rename('guests', 'guests_old');
            Schema::create('guests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('reservation_id')->constrained()->onDelete('cascade');
                $table->string('full_name');
                $table->string('first_name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('phone_number')->nullable();
                $table->string('sex')->nullable();
                $table->date('date_of_birth');
                $table->string('id_type', 40);
                $table->string('id_number');
                $table->string('id_document_number')->nullable();
                $table->string('id_photo_path')->nullable();
                $table->date('passport_valid_from')->nullable();
                $table->date('passport_valid_until')->nullable();
                $table->integer('loyalty_count')->default(0);
                $table->timestamps();
            });

            DB::statement(
                'INSERT INTO guests (
                    id, reservation_id, full_name, first_name, last_name, phone_number, sex,
                    date_of_birth, id_type, id_number, id_document_number, id_photo_path,
                    passport_valid_from, passport_valid_until, loyalty_count, created_at, updated_at
                )
                SELECT
                    id, reservation_id, full_name, first_name, last_name, phone_number, sex,
                    date_of_birth, id_type, id_number, id_document_number, id_photo_path,
                    passport_valid_from, passport_valid_until, loyalty_count, created_at, updated_at
                FROM guests_old'
            );

            Schema::drop('guests_old');
            Schema::table('guests', function (Blueprint $table) {
                $table->unique('reservation_id');
            });
            DB::statement('PRAGMA foreign_keys=on');
            return;
        }

        if (Schema::hasTable('guests')) {
            DB::statement(
                "ALTER TABLE guests MODIFY id_type VARCHAR(40) NOT NULL"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=off');
            Schema::dropIfExists('guests_old');
            Schema::dropIfExists('guests_new');

            Schema::rename('guests', 'guests_new');
            Schema::create('guests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('reservation_id')->constrained()->onDelete('cascade');
                $table->string('full_name');
                $table->string('first_name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('phone_number')->nullable();
                $table->string('sex')->nullable();
                $table->date('date_of_birth');
                $table->enum('id_type', ['CIN', 'Passeport', 'Permis']);
                $table->string('id_number');
                $table->string('id_document_number')->nullable();
                $table->string('id_photo_path')->nullable();
                $table->date('passport_valid_from')->nullable();
                $table->date('passport_valid_until')->nullable();
                $table->integer('loyalty_count')->default(0);
                $table->timestamps();
            });

            DB::statement(
                'INSERT INTO guests (
                    id, reservation_id, full_name, first_name, last_name, phone_number, sex,
                    date_of_birth, id_type, id_number, id_document_number, id_photo_path,
                    passport_valid_from, passport_valid_until, loyalty_count, created_at, updated_at
                )
                SELECT
                    id, reservation_id, full_name, first_name, last_name, phone_number, sex,
                    date_of_birth, id_type, id_number, id_document_number, id_photo_path,
                    passport_valid_from, passport_valid_until, loyalty_count, created_at, updated_at
                FROM guests_new'
            );

            Schema::drop('guests_new');
            Schema::table('guests', function (Blueprint $table) {
                $table->unique('reservation_id');
            });
            DB::statement('PRAGMA foreign_keys=on');
            return;
        }

        if (Schema::hasTable('guests')) {
            DB::statement(
                "ALTER TABLE guests MODIFY id_type ENUM('CIN', 'Passeport', 'Permis') NOT NULL"
            );
        }
    }
};
