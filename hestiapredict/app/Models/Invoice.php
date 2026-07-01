<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'reservation_id',
        'organization_id',
        'invoice_number',
        'total_amount_ariary',
        'tax_amount_ariary',
        'discount_mode',
        'discount_value',
        'discount_amount_ariary',
        'deposit_amount_ariary',
        'pdf_path',
        'finalized_at',
        'status',
        'document_type',
        'billing_mode',
        'invoice_kind',
        'parent_invoice_id',
        'booking_room_id',
    ];

    protected $casts = [
        'total_amount_ariary' => 'integer',
        'tax_amount_ariary' => 'integer',
        'discount_value' => 'decimal:2',
        'discount_amount_ariary' => 'integer',
        'deposit_amount_ariary' => 'integer',
        'finalized_at' => 'datetime',
    ];

    protected $appends = [
        'paid_amount_ariary',
        'balance_amount_ariary',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $invoice): void {
            if ($invoice->parent_invoice_id !== null && (int) $invoice->parent_invoice_id === (int) $invoice->id) {
                $invoice->parent_invoice_id = null;
            }
        });
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function parentInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_invoice_id');
    }

    public function childInvoices(): HasMany
    {
        return $this->hasMany(self::class, 'parent_invoice_id');
    }

    public function roomBooking(): BelongsTo
    {
        return $this->belongsTo(ReservationRoom::class, 'booking_room_id');
    }

    public function getPaidAmountAriaryAttribute(): int
    {
        $paid = $this->relationLoaded('payments')
            ? (int) $this->payments->sum('amount_ariary')
            : (int) $this->payments()->sum('amount_ariary');

        if (($this->invoice_kind ?? 'master') === 'master') {
            $paid += (int) $this->childInvoices()
                ->with('payments')
                ->get()
                ->sum(fn (self $childInvoice) => (int) $childInvoice->payments->sum('amount_ariary'));
        }

        return $paid;
    }

    public function getBalanceAmountAriaryAttribute(): int
    {
        return max(0, (int) $this->total_amount_ariary - $this->paid_amount_ariary);
    }
}
