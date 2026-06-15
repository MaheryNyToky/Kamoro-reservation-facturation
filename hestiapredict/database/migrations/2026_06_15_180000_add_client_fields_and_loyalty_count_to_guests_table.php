<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            if (! Schema::hasColumn('guests', 'first_name')) {
                $table->string('first_name')->nullable()->after('reservation_id');
            }

            if (! Schema::hasColumn('guests', 'last_name')) {
                $table->string('last_name')->nullable()->after('first_name');
            }

            if (! Schema::hasColumn('guests', 'phone_number')) {
                $table->string('phone_number')->nullable()->after('last_name');
            }

            if (! Schema::hasColumn('guests', 'id_document_number')) {
                $table->string('id_document_number')->nullable()->after('phone_number');
            }

            if (! Schema::hasColumn('guests', 'loyalty_count')) {
                $table->unsignedInteger('loyalty_count')->default(0)->after('id_document_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            if (Schema::hasColumn('guests', 'loyalty_count')) {
                $table->dropColumn('loyalty_count');
            }

            if (Schema::hasColumn('guests', 'id_document_number')) {
                $table->dropColumn('id_document_number');
            }

            if (Schema::hasColumn('guests', 'phone_number')) {
                $table->dropColumn('phone_number');
            }

            if (Schema::hasColumn('guests', 'last_name')) {
                $table->dropColumn('last_name');
            }

            if (Schema::hasColumn('guests', 'first_name')) {
                $table->dropColumn('first_name');
            }
        });
    }
};
