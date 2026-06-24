<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'amount_ariary',
        'amount_received_ariary',
        'change_given_ariary',
        'payment_method',
        'payment_operator',
        'payment_context',
        'reference',
        'processed_by_name',
        'processed_by_role',
    ];

    protected $casts = [
        'amount_ariary' => 'integer',
        'amount_received_ariary' => 'integer',
        'change_given_ariary' => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
