<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvoiceItem extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'invoice_id',
        'booking_room_id',
        'description',
        'type',
        'amount_ariary',
        'quantity',
        'created_by_name',
        'created_by_role',
        'updated_by_name',
        'updated_by_role',
        'manual_override_at',
    ];

    protected $casts = [
        'amount_ariary' => 'integer',
        'quantity' => 'integer',
        'manual_override_at' => 'datetime',
        'deleted_at' => 'datetime',
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
