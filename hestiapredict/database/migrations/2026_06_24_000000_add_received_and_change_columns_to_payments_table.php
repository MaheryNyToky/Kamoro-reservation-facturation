<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'amount_received_ariary')) {
                $table->integer('amount_received_ariary')->nullable()->after('amount_ariary');
            }

            if (! Schema::hasColumn('payments', 'change_given_ariary')) {
                $table->integer('change_given_ariary')->default(0)->after('amount_received_ariary');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'change_given_ariary')) {
                $table->dropColumn('change_given_ariary');
            }

            if (Schema::hasColumn('payments', 'amount_received_ariary')) {
                $table->dropColumn('amount_received_ariary');
            }
        });
    }
};
