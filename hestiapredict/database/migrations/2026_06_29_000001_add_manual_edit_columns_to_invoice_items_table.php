<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_items', 'created_by_name')) {
                $table->string('created_by_name', 120)->nullable()->after('quantity');
            }

            if (! Schema::hasColumn('invoice_items', 'created_by_role')) {
                $table->string('created_by_role', 30)->nullable()->after('created_by_name');
            }

            if (! Schema::hasColumn('invoice_items', 'updated_by_name')) {
                $table->string('updated_by_name', 120)->nullable()->after('created_by_role');
            }

            if (! Schema::hasColumn('invoice_items', 'updated_by_role')) {
                $table->string('updated_by_role', 30)->nullable()->after('updated_by_name');
            }

            if (! Schema::hasColumn('invoice_items', 'manual_override_at')) {
                $table->timestamp('manual_override_at')->nullable()->after('updated_by_role');
            }

            if (! Schema::hasColumn('invoice_items', 'deleted_at')) {
                $table->softDeletes()->after('manual_override_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            foreach (['deleted_at', 'manual_override_at', 'updated_by_role', 'updated_by_name', 'created_by_role', 'created_by_name'] as $column) {
                if (Schema::hasColumn('invoice_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
