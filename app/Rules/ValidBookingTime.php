<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use App\Models\Service;
use App\Models\Business;
use Carbon\Carbon;

class ValidBookingTime implements ValidationRule
{
    protected $serviceId;
    protected $bookingDate;
    protected $excludeBookingId;

    public function __construct($serviceId, $bookingDate, $excludeBookingId = null)
    {
        $this->serviceId = $serviceId;
        $this->bookingDate = $bookingDate;
        $this->excludeBookingId = $excludeBookingId;
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $service = Service::find($this->serviceId);
        if (!$service) {
            $fail('Invalid service selected.');
            return;
        }

        $business = $service->business;
        $bookingDate = Carbon::parse($this->bookingDate);
        $startTime = Carbon::parse($value);
        
        // Check if the time is in the future
        if ($bookingDate->isToday() && $startTime->lt(now())) {
            $fail('Booking time must be in the future.');
            return;
        }

        // Check if business is open at this time
        $dayOfWeek = strtolower($bookingDate->format('l'));
        $workingHours = $business->working_hours[$dayOfWeek] ?? null;

        if (!$workingHours || !isset($workingHours['open']) || !isset($workingHours['close'])) {
            $fail('Business is closed on this day.');
            return;
        }

        $openTime = Carbon::parse($workingHours['open']);
        $closeTime = Carbon::parse($workingHours['close']);
        $endTime = $startTime->copy()->addMinutes($service->duration);

        if ($startTime->lt($openTime) || $endTime->gt($closeTime)) {
            $fail('Booking time is outside business hours.');
            return;
        }

        // Check for conflicts with existing bookings
        $conflictingBookings = $service->bookings()
            ->where('booking_date', $bookingDate->format('Y-m-d'))
            ->where('status', '!=', 'cancelled')
            ->where(function ($query) use ($value, $endTime) {
                $query->where(function ($q) use ($value, $endTime) {
                    $q->where('start_time', '<', $endTime->format('H:i'))
                      ->where('end_time', '>', $value);
                });
            });

        if ($this->excludeBookingId) {
            $conflictingBookings->where('id', '!=', $this->excludeBookingId);
        }

        if ($conflictingBookings->exists()) {
            $fail('This time slot is already booked.');
            return;
        }

        // Check minimum advance booking time
        $minAdvanceHours = $service->min_advance_hours ?? $business->settings['min_advance_hours'] ?? 2;
        $bookingDateTime = Carbon::parse($bookingDate->format('Y-m-d') . ' ' . $value);
        
        if ($bookingDateTime->lt(now()->addHours($minAdvanceHours))) {
            $fail("Bookings must be made at least {$minAdvanceHours} hours in advance.");
            return;
        }

        // Check maximum advance booking time
        $maxAdvanceDays = $service->advance_booking_days ?? $business->settings['advance_booking_days'] ?? 30;
        
        if ($bookingDate->gt(now()->addDays($maxAdvanceDays))) {
            $fail("Bookings cannot be made more than {$maxAdvanceDays} days in advance.");
            return;
        }
    }

    /**
     * Create rule for new booking
     */
    public static function forNewBooking($serviceId, $bookingDate)
    {
        return new static($serviceId, $bookingDate);
    }

    /**
     * Create rule for updating existing booking
     */
    public static function forUpdateBooking($serviceId, $bookingDate, $bookingId)
    {
        return new static($serviceId, $bookingDate, $bookingId);
    }
}
