<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->repairSelfParentInvoices();
        $this->deduplicateBookingRooms();

        if (Schema::hasTable('booking_room')) {
            Schema::table('booking_room', function (Blueprint $table) {
                if (Schema::hasColumn('booking_room', 'reservation_id') && Schema::hasColumn('booking_room', 'room_id')) {
                    try {
                        $table->dropIndex('booking_room_reservation_room_idx');
                    } catch (\Throwable) {
                        // Index may already be absent on partially migrated databases.
                    }

                    $table->unique(['reservation_id', 'room_id'], 'booking_room_reservation_room_unique');
                }

                if (
                    Schema::hasColumn('booking_room', 'room_id')
                    && Schema::hasColumn('booking_room', 'segment_start_date')
                    && Schema::hasColumn('booking_room', 'segment_end_date')
                ) {
                    $table->index(['room_id', 'segment_start_date', 'segment_end_date'], 'booking_room_room_dates_idx');
                }
            });
        }

        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                if (Schema::hasColumn('invoices', 'reservation_id') && Schema::hasColumn('invoices', 'invoice_kind')) {
                    $table->index(['reservation_id', 'invoice_kind'], 'invoices_reservation_kind_idx');
                }

                if (Schema::hasColumn('invoices', 'reservation_id') && Schema::hasColumn('invoices', 'booking_room_id')) {
                    $table->index(['reservation_id', 'booking_room_id'], 'invoices_reservation_booking_room_idx');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                if (Schema::hasColumn('invoices', 'reservation_id') && Schema::hasColumn('invoices', 'booking_room_id')) {
                    $table->dropIndex('invoices_reservation_booking_room_idx');
                }

                if (Schema::hasColumn('invoices', 'reservation_id') && Schema::hasColumn('invoices', 'invoice_kind')) {
                    $table->dropIndex('invoices_reservation_kind_idx');
                }
            });
        }

        if (Schema::hasTable('booking_room')) {
            Schema::table('booking_room', function (Blueprint $table) {
                if (
                    Schema::hasColumn('booking_room', 'room_id')
                    && Schema::hasColumn('booking_room', 'segment_start_date')
                    && Schema::hasColumn('booking_room', 'segment_end_date')
                ) {
                    $table->dropIndex('booking_room_room_dates_idx');
                }

                if (Schema::hasColumn('booking_room', 'reservation_id') && Schema::hasColumn('booking_room', 'room_id')) {
                    $table->dropUnique('booking_room_reservation_room_unique');
                    $table->index(['reservation_id', 'room_id'], 'booking_room_reservation_room_idx');
                }
            });
        }
    }

    private function repairSelfParentInvoices(): void
    {
        if (! Schema::hasTable('invoices') || ! Schema::hasColumn('invoices', 'parent_invoice_id')) {
            return;
        }

        $selfParentInvoices = DB::table('invoices')
            ->whereColumn('parent_invoice_id', 'id')
            ->get(['id', 'reservation_id']);

        foreach ($selfParentInvoices as $invoice) {
            $masterInvoiceId = DB::table('invoices')
                ->where('reservation_id', $invoice->reservation_id)
                ->where('invoice_kind', 'master')
                ->orderBy('id')
                ->value('id');

            DB::table('invoices')
                ->where('id', $invoice->id)
                ->update([
                    'parent_invoice_id' => $masterInvoiceId,
                ]);
        }
    }

    private function deduplicateBookingRooms(): void
    {
        if (! Schema::hasTable('booking_room')) {
            return;
        }

        $duplicates = DB::table('booking_room')
            ->select('reservation_id', 'room_id', DB::raw('COUNT(*) as total'))
            ->groupBy('reservation_id', 'room_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicateGroup) {
            $rows = DB::table('booking_room')
                ->where('reservation_id', $duplicateGroup->reservation_id)
                ->where('room_id', $duplicateGroup->room_id)
                ->orderBy('id')
                ->get(['id', 'invoice_id']);

            if ($rows->count() < 2) {
                continue;
            }

            $keeper = $rows->first(fn ($row) => $row->invoice_id !== null) ?? $rows->first();
            $duplicateIds = $rows->pluck('id')
                ->filter(fn ($id) => (int) $id !== (int) $keeper->id)
                ->values();

            if ($keeper->invoice_id === null) {
                $firstInvoiceId = $rows
                    ->first(fn ($row) => $row->invoice_id !== null && (int) $row->id !== (int) $keeper->id)?->invoice_id;

                if ($firstInvoiceId !== null) {
                    DB::table('booking_room')
                        ->where('id', $keeper->id)
                        ->update(['invoice_id' => $firstInvoiceId]);
                }
            }

            if ($duplicateIds->isNotEmpty()) {
                if (Schema::hasTable('invoice_items') && Schema::hasColumn('invoice_items', 'booking_room_id')) {
                    DB::table('invoice_items')
                        ->whereIn('booking_room_id', $duplicateIds)
                        ->update(['booking_room_id' => $keeper->id]);
                }

                if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'booking_room_id')) {
                    DB::table('invoices')
                        ->whereIn('booking_room_id', $duplicateIds)
                        ->update(['booking_room_id' => $keeper->id]);
                }

                DB::table('booking_room')
                    ->whereIn('id', $duplicateIds)
                    ->delete();
            }
        }
    }
};
