<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReportService
{
    public function generateBusinessReport(Business $business, $startDate, $endDate)
    {
        $bookings = $business->bookings()
            ->whereBetween('booking_date', [$startDate, $endDate])
            ->with(['service', 'staff', 'user'])
            ->get();

        return [
            'period' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d')
            ],
            'summary' => [
                'total_bookings' => $bookings->count(),
                'completed_bookings' => $bookings->where('status', 'completed')->count(),
                'cancelled_bookings' => $bookings->where('status', 'cancelled')->count(),
                'no_shows' => $bookings->where('status', 'no_show')->count(),
                'total_revenue' => $bookings->where('payment_status', 'paid')->sum('amount'),
                'average_booking_value' => $bookings->where('payment_status', 'paid')->avg('amount'),
            ],
            'services' => $this->getServiceStats($bookings),
            'staff' => $this->getStaffStats($bookings),
            'daily_breakdown' => $this->getDailyBreakdown($bookings),
            'peak_hours' => $this->getPeakHours($bookings),
            'customer_insights' => $this->getCustomerInsights($bookings)
        ];
    }

    private function getServiceStats($bookings)
    {
        return $bookings->groupBy('service_id')->map(function ($group) {
            $service = $group->first()->service;
            return [
                'name' => $service->name,
                'bookings' => $group->count(),
                'revenue' => $group->where('payment_status', 'paid')->sum('amount'),
                'average_price' => $group->avg('amount')
            ];
        })->sortByDesc('revenue')->values();
    }

    private function getStaffStats($bookings)
    {
        return $bookings->whereNotNull('staff_id')->groupBy('staff_id')->map(function ($group) {
            $staff = $group->first()->staff;
            return [
                'name' => $staff->name,
                'bookings' => $group->count(),
                'revenue' => $group->where('payment_status', 'paid')->sum('amount'),
                'utilization_rate' => $this->calculateUtilizationRate($staff, $group)
            ];
        })->sortByDesc('revenue')->values();
    }

    private function getDailyBreakdown($bookings)
    {
        return $bookings->groupBy(function ($booking) {
            return $booking->booking_date->format('Y-m-d');
        })->map(function ($group, $date) {
            return [
                'date' => $date,
                'bookings' => $group->count(),
                'revenue' => $group->where('payment_status', 'paid')->sum('amount')
            ];
        })->sortKeys();
    }

    private function getPeakHours($bookings)
    {
        return $bookings->groupBy(function ($booking) {
            return Carbon::parse($booking->start_time)->format('H');
        })->map(function ($group, $hour) {
            return [
                'hour' => $hour . ':00',
                'bookings' => $group->count()
            ];
        })->sortByDesc('bookings')->take(5);
    }

    private function getCustomerInsights($bookings)
    {
        $uniqueCustomers = $bookings->pluck('user_id')->unique()->count();
        $repeatCustomers = $bookings->groupBy('user_id')
            ->filter(function ($group) {
                return $group->count() > 1;
            })->count();

        return [
            'unique_customers' => $uniqueCustomers,
            'repeat_customers' => $repeatCustomers,
            'repeat_rate' => $uniqueCustomers > 0 ? round(($repeatCustomers / $uniqueCustomers) * 100, 2) : 0,
            'top_customers' => $bookings->groupBy('user_id')
                ->map(function ($group) {
                    $user = $group->first()->user;
                    return [
                        'name' => $user->name,
                        'bookings' => $group->count(),
                        'total_spent' => $group->where('payment_status', 'paid')->sum('amount')
                    ];
                })
                ->sortByDesc('total_spent')
                ->take(10)
                ->values()
        ];
    }

    private function calculateUtilizationRate($staff, $bookings)
    {
        // Calculate based on total available hours vs booked hours
        $totalBookedMinutes = $bookings->sum(function ($booking) {
            return Carbon::parse($booking->end_time)->diffInMinutes(Carbon::parse($booking->start_time));
        });
        
        // Assuming 8 hours per day, 5 days per week
        $totalAvailableMinutes = $bookings->pluck('booking_date')->unique()->count() * 8 * 60;
        
        return $totalAvailableMinutes > 0 
            ? round(($totalBookedMinutes / $totalAvailableMinutes) * 100, 2) 
            : 0;
    }
}