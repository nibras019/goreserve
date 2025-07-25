<?php

namespace App\Services;

use App\Models\Service;
use App\Models\Booking;
use App\Models\Staff;
use App\Notifications\BookingConfirmation;
use App\Notifications\NewBookingNotification;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class BookingService
{
    public function checkAvailability(
        Service $service,
        string $date,
        string $startTime,
        string $endTime,
        ?int $staffId = null
    ): bool {
        $business = $service->business;
        
        // Check if business is open
        $dateTime = Carbon::parse($date . ' ' . $startTime);
        if (!$business->isOpen($dateTime)) {
            return false;
        }

        // If specific staff requested, check their availability
        if ($staffId) {
            $staff = Staff::find($staffId);
            if (!$staff || !$staff->isAvailable($date, $startTime, $endTime)) {
                return false;
            }
            return true;
        }

        // Check if any staff is available
        $availableStaff = $service->staff()
            ->where('is_active', true)
            ->get()
            ->filter(function ($staff) use ($date, $startTime, $endTime) {
                return $staff->isAvailable($date, $startTime, $endTime);
            });

        return $availableStaff->isNotEmpty();
    }

    public function getAvailableSlots(
        Service $service,
        string $date,
        ?int $staffId = null
    ): array {
        $business = $service->business;
        $slots = [];
        
        // Get working hours for the day
        $dayOfWeek = strtolower(Carbon::parse($date)->format('l'));
        $workingHours = $business->working_hours[$dayOfWeek] ?? null;
        
        if (!$workingHours) {
            return $slots;
        }

        $openTime = Carbon::parse($date . ' ' . $workingHours['open']);
        $closeTime = Carbon::parse($date . ' ' . $workingHours['close']);
        $duration = $service->duration;

        // Generate time slots
        $currentTime = $openTime->copy();
        while ($currentTime->copy()->addMinutes($duration)->lte($closeTime)) {
            $startTime = $currentTime->format('H:i');
            $endTime = $currentTime->copy()->addMinutes($duration)->format('H:i');
            
            // Check if slot is available
            if ($this->checkAvailability($service, $date, $startTime, $endTime, $staffId)) {
                $slots[] = [
                    'start' => $startTime,
                    'end' => $endTime,
                    'available' => true
                ];
            }
            
            $currentTime->addMinutes(30); // 30-minute intervals
        }

        return $slots;
    }

    public function sendBookingNotifications(Booking $booking): void
    {
        // Send to customer
        $booking->user->notify(new BookingConfirmation($booking));

        // Send to business owner
        $booking->business->owner->notify(new NewBookingNotification($booking));
    }

    public function processRefund(Booking $booking): bool
    {
        // Implementation depends on payment gateway
        // This is a placeholder
        return true;
    }
}