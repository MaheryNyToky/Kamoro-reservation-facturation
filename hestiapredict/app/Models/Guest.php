<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Guest extends Model
{
    use HasFactory;

    protected $fillable = [
        'reservation_id',
        'first_name',
        'last_name',
        'phone_number',
        'id_document_number',
        'loyalty_count',
        'full_name',
        'date_of_birth',
        'id_type',
        'id_number',
        'id_photo_path',
    ];

    protected $casts = [
        'date_of_birth' => 'date:Y-m-d',
        'loyalty_count' => 'integer',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
