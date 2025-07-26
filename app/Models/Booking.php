<?php
// app/Models/Booking.php - Fixed version

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Booking extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'booking_ref', 'user_id', 'business_id', 'service_id', 'staff_id',
        'booking_date', 'start_time', 'end_time', 'amount', 'status',
        'payment_status', 'notes', 'cancellation_reason', 'cancelled_at',
        'cancelled_by', 'promo_code_id', 'discount_amount', 'tax_amount',
        'tip_amount', 'metadata', 'reminder_sent_at', 'reminder_count'
    ];

    protected $casts = [
        'booking_date' => 'date',
        'amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'tip_amount' => 'decimal:2',
        'cancelled_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'metadata' => 'array'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($booking) {
            if (!$booking->booking_ref) {
                $booking->booking_ref = 'BK' . strtoupper(Str::random(8));
            }
        });

        static::created(function ($booking) {
            // Fire booking created event
            event(new \App\Events\BookingCreated($booking));
        });

        static::updated(function ($booking) {
            // Fire booking updated event if status changed
            if ($booking->wasChanged('status')) {
                if ($booking->status === 'cancelled') {
                    event(new \App\Events\BookingCancelled($booking));
                }
            }
        });
    }

    // Activity Log Configuration
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'payment_status', 'amount', 'booking_date', 'start_time'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(BookingReminder::class);
    }

    public function walletTransactions(): MorphMany
    {
        return $this->morphMany(WalletTransaction::class, 'reference');
    }

    // Scopes
    public function scopeUpcoming($query)
    {
        return $query->where(function ($q) {
            $q->where('booking_date', '>', today())
                ->orWhere(function ($subQ) {
                    $subQ->where('booking_date', today())
                        ->where('start_time', '>', now()->format('H:i'));
                });
        })->whereNotIn('status', ['cancelled', 'completed']);
    }

    public function scopePast($query)
    {
        return $query->where(function ($q) {
            $q->where('booking_date', '<', today())
                ->orWhere(function ($subQ) {
                    $subQ->where('booking_date', today())
                        ->where('start_time', '<=', now()->format('H:i'));
                });
        });
    }

    public function scopeForTimeSlot($query, $date, $startTime, $endTime)
    {
        return $query->where('booking_date', $date)
            ->where('status', '!=', 'cancelled')
            ->where(function ($q) use ($startTime, $endTime) {
                $q->whereBetween('start_time', [$startTime, $endTime])
                    ->orWhereBetween('end_time', [$startTime, $endTime])
                    ->orWhere(function ($subQ) use ($startTime, $endTime) {
                        $subQ->where('start_time', '<=', $startTime)
                            ->where('end_time', '>=', $endTime);
                    });
            });
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByPaymentStatus($query, $paymentStatus)
    {
        return $query->where('payment_status', $paymentStatus);
    }

    // Business Logic Methods
    public function canBeCancelled(): bool
    {
        if (!in_array($this->status, ['pending', 'confirmed'])) {
            return false;
        }

        $cancellationHours = $this->service->cancellation_hours ?? 24;
        $bookingDateTime = $this->getBookingDateTime();
        
        return now()->addHours($cancellationHours)->lt($bookingDateTime);
    }

    public function calculateRefundAmount(): float
    {
        if (!$this->canBeCancelled()) {
            return 0;
        }

        $policies = $this->business->policies ?? [];
        $refundPolicy = $policies['refund'] ?? 'full';
        $hoursUntilBooking = now()->diffInHours($this->getBookingDateTime());

        return match($refundPolicy) {
            'full' => $this->amount,
            'partial' => $hoursUntilBooking >= 48 ? $this->amount : $this->amount * 0.5,
            'none' => 0,
            default => $this->amount
        };
    }

    public function cancel(string $reason = null, $cancelledBy = null): bool
    {
        if (!$this->canBeCancelled()) {
            return false;
        }

        $this->update([
            'status' => 'cancelled',
            'cancellation_reason' => $reason,
            'cancelled_at' => now(),
            'cancelled_by' => $cancelledBy
        ]);

        return true;
    }

    public function confirm(): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        $this->update(['status' => 'confirmed']);
        return true;
    }

    public function complete(): bool
    {
        if ($this->status !== 'confirmed') {
            return false;
        }

        $this->update(['status' => 'completed']);
        return true;
    }

    public function markAsNoShow(): bool
    {
        if (!in_array($this->status, ['confirmed', 'pending'])) {
            return false;
        }

        $this->update(['status' => 'no_show']);
        return true;
    }

    // Accessors
    public function getBookingDateTime(): Carbon
    {
        return $this->booking_date->setTimeFromTimeString($this->start_time);
    }

    public function getEndDateTime(): Carbon
    {
        return $this->booking_date->setTimeFromTimeString($this->end_time);
    }

    public function getTotalAmountAttribute(): float
    {
        return $this->amount + $this->tax_amount + $this->tip_amount - $this->discount_amount;
    }

    public function getFormattedTotalAmountAttribute(): string
    {
        return '$' . number_format($this->total_amount, 2);
    }

    public function getDurationAttribute(): int
    {
        $start = Carbon::parse($this->start_time);
        $end = Carbon::parse($this->end_time);
        return $start->diffInMinutes($end);
    }

    public function getFormattedDurationAttribute(): string
    {
        $hours = floor($this->duration / 60);
        $minutes = $this->duration % 60;
        
        if ($hours > 0 && $minutes > 0) {
            return "{$hours}h {$minutes}min";
        } elseif ($hours > 0) {
            return "{$hours}h";
        } else {
            return "{$minutes}min";
        }
    }

    public function getStatusBadgeAttribute(): array
    {
        return match($this->status) {
            'pending' => ['class' => 'warning', 'text' => 'Pending'],
            'confirmed' => ['class' => 'info', 'text' => 'Confirmed'],
            'completed' => ['class' => 'success', 'text' => 'Completed'],
            'cancelled' => ['class' => 'danger', 'text' => 'Cancelled'],
            'no_show' => ['class' => 'secondary', 'text' => 'No Show'],
            default => ['class' => 'light', 'text' => ucfirst($this->status)]
        };
    }

    public function getPaymentStatusBadgeAttribute(): array
    {
        return match($this->payment_status) {
            'pending' => ['class' => 'warning', 'text' => 'Pending'],
            'paid' => ['class' => 'success', 'text' => 'Paid'],
            'partially_paid' => ['class' => 'info', 'text' => 'Partial'],
            'refunded' => ['class' => 'secondary', 'text' => 'Refunded'],
            default => ['class' => 'light', 'text' => ucfirst($this->payment_status)]
        };
    }

    public function getTimeUntilBookingAttribute(): ?string
    {
        if (in_array($this->status, ['completed', 'cancelled'])) {
            return null;
        }

        $bookingDateTime = $this->getBookingDateTime();
        
        if (now()->gt($bookingDateTime)) {
            return 'Past due';
        }

        return now()->diffForHumans($bookingDateTime, true);
    }

    public function getCanBeReviewedAttribute(): bool
    {
        return $this->status === 'completed' && 
               !$this->review && 
               $this->booking_date->gt(now()->subDays(30));
    }

    public function getCanBeRescheduledAttribute(): bool
    {
        return in_array($this->status, ['pending', 'confirmed']) &&
               $this->getBookingDateTime()->gt(now()->addHours(24));
    }

    // Payment Methods
    public function getTotalPaidAmount(): float
    {
        return $this->payments()->where('status', 'completed')->sum('amount');
    }

    public function getRemainingAmount(): float
    {
        return max(0, $this->total_amount - $this->getTotalPaidAmount());
    }

    public function isFullyPaid(): bool
    {
        return $this->getRemainingAmount() <= 0.01; // Account for float precision
    }

    public function isPartiallyPaid(): bool
    {
        $paid = $this->getTotalPaidAmount();
        return $paid > 0 && $paid < $this->total_amount;
    }

    // Reminder Methods
    public function scheduleReminders(): void
    {
        $reminderTimes = [24, 2]; // 24 hours and 2 hours before
        
        foreach ($reminderTimes as $hours) {
            BookingReminder::scheduleForBooking($this, 'booking_reminder', $hours);
        }
    }

    public function sendReminder(int $hoursBefore, string $channel = 'email'): bool
    {
        // Check if reminder already sent
        $existingReminder = $this->reminders()
            ->where('type', 'booking_reminder')
            ->where('hours_before', $hoursBefore)
            ->where('channel', $channel)
            ->first();

        if ($existingReminder && $existingReminder->is_sent) {
            return false;
        }

        // Create or update reminder
        $reminder = $existingReminder ?: BookingReminder::scheduleForBooking(
            $this, 
            'booking_reminder', 
            $hoursBefore, 
            $channel
        );

        // Send notification based on channel
        $user = $this->user;
        $notificationClass = match($channel) {
            'email' => \App\Notifications\BookingReminderEmail::class,
            'sms' => \App\Notifications\BookingReminderSMS::class,
            default => \App\Notifications\BookingReminderEmail::class
        };

        try {
            $user->notify(new $notificationClass($this));
            $reminder->markAsSent();
            
            $this->increment('reminder_count');
            $this->update(['reminder_sent_at' => now()]);
            
            return true;
        } catch (\Exception $e) {
            \Log::error('Failed to send booking reminder', [
                'booking_id' => $this->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    // Static Methods
    public static function generateUniqueReference(): string
    {
        do {
            $ref = 'BK' . strtoupper(Str::random(8));
        } while (self::where('booking_ref', $ref)->exists());

        return $ref;
    }

    public static function getStatusOptions(): array
    {
        return [
            'pending' => 'Pending',
            'confirmed' => 'Confirmed',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'no_show' => 'No Show'
        ];
    }

    public static function getPaymentStatusOptions(): array
    {
        return [
            'pending' => 'Pending',
            'paid' => 'Paid',
            'partially_paid' => 'Partially Paid',
            'refunded' => 'Refunded'
        ];
    }
}
