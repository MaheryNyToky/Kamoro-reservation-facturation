<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (!Schema::hasColumn('organizations', 'phone')) {
                $table->string('phone', 50)->nullable()->after('name');
            }

            if (!Schema::hasColumn('organizations', 'contact_email')) {
                $table->string('contact_email')->nullable()->after('contact_phone');
            }

            if (!Schema::hasColumn('organizations', 'nif')) {
                $table->string('nif', 80)->nullable()->after('billing_address');
            }

            if (!Schema::hasColumn('organizations', 'stat')) {
                $table->string('stat', 80)->nullable()->after('nif');
            }
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            foreach (['stat', 'nif', 'contact_email', 'phone'] as $column) {
                if (Schema::hasColumn('organizations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
