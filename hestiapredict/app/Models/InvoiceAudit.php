<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceAudit extends Model
{
    protected $fillable = [
        'invoice_id', 'action', 'actor_user_id', 'actor_name', 'actor_role', 'details',
    ];

    protected $casts = ['details' => 'array'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
