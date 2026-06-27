<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'booking_room_id',
        'description',
        'type',
        'amount_ariary',
        'quantity',
    ];

    protected $casts = [
        'amount_ariary' => 'integer',
        'quantity' => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function bookingRoom(): BelongsTo
    {
        return $this->belongsTo(ReservationRoom::class, 'booking_room_id');
    }
}
