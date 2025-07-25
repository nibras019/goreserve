<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'booking_id',
        'amount',
        'currency',
        'method',
        'status',
        'gateway_response',
        'paid_at'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'gateway_response' => 'array',
        'paid_at' => 'datetime'
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeForBusiness($query, $businessId)
    {
        return $query->whereHas('booking', function ($q) use ($businessId) {
            $q->where('business_id', $businessId);
        });
    }

    public function getFormattedAmountAttribute()
    {
        return '$' . number_format($this->amount, 2);
    }

    public function isRefundable()
    {
        return $this->status === 'completed' && 
               $this->booking->canBeCancelled() &&
               !$this->hasBeenRefunded();
    }

    public function hasBeenRefunded()
    {
        return $this->status === 'refunded' || $this->refunds()->exists();
    }

    public function calculateProcessingFee()
    {
        $feePercentage = match($this->method) {
            'stripe' => 0.029,
            'paypal' => 0.0349,
            'razorpay' => 0.02,
            default => 0,
        };

        $fixedFee = match($this->method) {
            'stripe' => 0.30,
            'paypal' => 0.49,
            default => 0,
        };

        return round(($this->amount * $feePercentage) + $fixedFee, 2);
    }
}
