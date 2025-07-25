<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_ref', 'user_id', 'business_id', 'service_id', 'staff_id',
        'booking_date', 'start_time', 'end_time', 'amount', 'status',
        'payment_status', 'notes', 'cancellation_reason', 'cancelled_at'
    ];

    protected $casts = [
        'booking_date' => 'date',
        'amount' => 'decimal:2',
        'cancelled_at' => 'datetime'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($booking) {
            $booking->booking_ref = 'BK' . strtoupper(Str::random(8));
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function review()
    {
        return $this->hasOne(Review::class);
    }

    public function scopeUpcoming($query)
    {
        return $query->where('booking_date', '>=', today())
            ->where('status', '!=', 'cancelled')
            ->orderBy('booking_date')
            ->orderBy('start_time');
    }

    public function scopePast($query)
    {
        return $query->where('booking_date', '<', today())
            ->orWhere(function ($q) {
                $q->where('booking_date', today())
                  ->where('end_time', '<', now()->format('H:i'));
            });
    }

    public function canBeCancelled()
    {
        return in_array($this->status, ['pending', 'confirmed']) &&
               $this->booking_date > today();
    }

    public function cancel($reason = null)
    {
        $this->update([
            'status' => 'cancelled',
            'cancellation_reason' => $reason,
            'cancelled_at' => now()
        ]);
    }

    public function getDateTimeAttribute()
    {
        return $this->booking_date->format('Y-m-d') . ' ' . $this->start_time;
    }
}