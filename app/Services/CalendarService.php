<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Staff;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class CalendarService
{
    public function generateTimeSlots(Business $business, Carbon $date)
    {
        $dayOfWeek = strtolower($date->format('l'));
        $workingHours = $business->working_hours[$dayOfWeek] ?? null;
        
        if (!$workingHours || !isset($workingHours['open']) || !isset($workingHours['close'])) {
            return [];
        }

        $slots = [];
        $interval = config('goreserve.booking.slot_interval', 30);
        
        $start = Carbon::parse($date->format('Y-m-d') . ' ' . $workingHours['open']);
        $end = Carbon::parse($date->format('Y-m-d') . ' ' . $workingHours['close']);
        
        $period = CarbonPeriod::create($start, "{$interval} minutes", $end);
        
        foreach ($period as $slot) {
            if ($slot->lt($end)) {
                $slots[] = [
                    'time' => $slot->format('H:i'),
                    'display' => $slot->format('g:i A'),
                    'available' => true
                ];
            }
        }
        
        return $slots;
    }

    public function getStaffSchedule(Staff $staff, Carbon $startDate, Carbon $endDate)
    {
        $schedule = [];
        $period = CarbonPeriod::create($startDate, $endDate);
        
        foreach ($period as $date) {
            $dayOfWeek = strtolower($date->format('l'));
            $workingHours = $staff->working_hours[$dayOfWeek] ?? null;
            
            // Check for availability blocks
            $availability = $staff->availabilities()
                ->where('date', $date->format('Y-m-d'))
                ->first();
            
            if ($availability && in_array($availability->type, ['vacation', 'sick', 'blocked'])) {
                $schedule[$date->format('Y-m-d')] = [
                    'available' => false,
                    'reason' => $availability->type,
                    'note' => $availability->reason
                ];
            } elseif ($workingHours) {
                $schedule[$date->format('Y-m-d')] = [
                    'available' => true,
                    'hours' => $workingHours,
                    'bookings' => $staff->bookings()
                        ->where('booking_date', $date->format('Y-m-d'))
                        ->where('status', '!=', 'cancelled')
                        ->get()
                ];
            } else {
                $schedule[$date->format('Y-m-d')] = [
                    'available' => false,
                    'reason' => 'day_off'
                ];
            }
        }
        
        return $schedule;
    }

    public function getBusinessCalendar(Business $business, $month, $year)
    {
        $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();
        
        $calendar = [];
        $period = CarbonPeriod::create($startDate, $endDate);
        
        foreach ($period as $date) {
            $dayBookings = $business->bookings()
                ->where('booking_date', $date->format('Y-m-d'))
                ->where('status', '!=', 'cancelled')
                ->with(['service', 'user', 'staff'])
                ->get();
            
            $calendar[] = [
                'date' => $date->format('Y-m-d'),
                'day' => $date->format('j'),
                'dayOfWeek' => $date->format('l'),
                'isToday' => $date->isToday(),
                'bookingsCount' => $dayBookings->count(),
                'revenue' => $dayBookings->where('payment_status', 'paid')->sum('amount'),
                'bookings' => $dayBookings
            ];
        }
        
        return [
            'month' => $startDate->format('F Y'),
            'days' => $calendar,
            'stats' => [
                'total_bookings' => collect($calendar)->sum('bookingsCount'),
                'total_revenue' => collect($calendar)->sum('revenue')
            ]
        ];
    }
}