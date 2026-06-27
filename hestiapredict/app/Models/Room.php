<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Room extends Model
{
    protected $fillable = [
        'room_number',
        'type',
        'model',
        'base_price_ariary',
        'is_fixed_price',
    ];

    protected $casts = [
        'base_price_ariary' => 'integer',
        'is_fixed_price' => 'boolean',
    ];

    public function reservations(): BelongsToMany
    {
        return $this->belongsToMany(Reservation::class, 'booking_room')
            ->using(ReservationRoom::class)
            ->withPivot(
                'id',
                'price_snapshot_ariary',
                'occupant_name',
                'occupant_phone',
                'occupant_email',
                'occupant_date_of_birth',
                'occupant_sex',
                'occupant_id_type',
                'occupant_id_number',
                'checked_in_at',
                'checked_in_by_name',
                'checked_in_by_role',
                'invoice_id',
            )
            ->withTimestamps();
    }

    public function getIdentifierAttribute(): string
    {
        return "{$this->type} - {$this->model}";
    }
}
