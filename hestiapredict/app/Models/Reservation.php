<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Reservation extends Model
{
    public const ACTIVE_STATUSES = ['en_attente', 'arrive'];

    protected $fillable = [
        'client_name',
        'client_phone',
        'customer_phone',
        'customer_email',
        'organization_id',
        'booking_reference',
        'booking_type',
        'billing_mode',
        'source',
        'is_booking_com',
        'check_in_date',
        'check_out_date',
        'status',
        'payment_status',
        'cancelled_by_name',
        'cancelled_at',
        'user_id',
        'extra_beds',
        'extra_mattresses',
    ];

    protected $casts = [
        'is_booking_com' => 'boolean',
        'check_in_date' => 'date:Y-m-d',
        'check_out_date' => 'date:Y-m-d',
        'cancelled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class, 'booking_room')
            ->using(ReservationRoom::class)
            ->withPivot(
                'id',
                'price_snapshot_ariary',
                'segment_start_date',
                'segment_end_date',
                'segment_extra_beds',
                'segment_extra_mattresses',
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

    public function roomBookings(): HasMany
    {
        return $this->hasMany(ReservationRoom::class);
    }

    public function guest()
    {
        return $this->hasOne(Guest::class);
    }

    public function invoice()
    {
        return $this->hasOne(Invoice::class)->where('invoice_kind', 'master');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(ReservationAudit::class);
    }

    public function latestAudit(): HasOne
    {
        return $this->hasOne(ReservationAudit::class)->latestOfMany();
    }

    public function latestCheckInAudit(): HasOne
    {
        return $this->hasOne(ReservationAudit::class)
            ->where('action', 'check_in')
            ->latestOfMany();
    }

    public function latestModificationAudit(): HasOne
    {
        return $this->hasOne(ReservationAudit::class)
            ->where('action', 'modified')
            ->latestOfMany();
    }

    public function setClientPhoneAttribute(?string $value): void
    {
        $this->attributes['client_phone'] = PhoneNumber::normalize($value);
    }

    public function setCustomerPhoneAttribute(?string $value): void
    {
        $this->attributes['customer_phone'] = PhoneNumber::normalize($value);
    }

    public function scopeActiveDuring($query, string $date)
    {
        return $query
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->where('check_in_date', '<=', $date)
            ->where('check_out_date', '>', $date);
    }
}
