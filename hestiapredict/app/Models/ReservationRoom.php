<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ReservationRoom extends Pivot
{
    protected $table = 'booking_room';

    protected $fillable = [
        'reservation_id',
        'room_id',
        'price_snapshot_ariary',
        'occupant_name',
        'occupant_phone',
        'occupant_email',
        'occupant_date_of_birth',
        'occupant_sex',
        'occupant_id_type',
        'occupant_id_number',
        'occupant_passport_valid_from',
        'occupant_passport_valid_until',
        'checked_in_at',
        'checked_in_by_name',
        'checked_in_by_role',
        'invoice_id',
    ];

    protected $casts = [
        'price_snapshot_ariary' => 'integer',
        'occupant_date_of_birth' => 'date:Y-m-d',
        'occupant_passport_valid_from' => 'date:Y-m-d',
        'occupant_passport_valid_until' => 'date:Y-m-d',
        'checked_in_at' => 'datetime',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
