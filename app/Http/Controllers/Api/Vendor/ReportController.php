<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class ReportController extends Controller
{
    protected $reportService;

    public function __construct(ReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    /**
     * Get business revenue report
     */
    public function revenue(Request $request)
    {
        $business = $request->user()->business;

        if (!$business) {
            return $this->error('No business found', 404);
        }

        $validated = $request->validate([
            'period' => 'nullable|string|in:week,month,quarter,year,custom',
            'date_from' => 'required_if:period,custom|date',
            'date_to' => 'required_if:period,custom|date|after_or_equal:date_from',
            'group_by' => 'nullable|string|in:day,week,month',
            'compare_previous' => 'nullable|boolean'
        ]);

        $period = $validated['period'] ?? 'month';
        $dateRange = $this->getDateRange($period, $validated['date_from'] ?? null, $validated['date_to'] ?? null);
        $groupBy = $validated['group_by'] ?? 'day';

        // Get revenue data
        $revenueData = $this->getRevenueData($business, $dateRange, $groupBy);

        // Get comparison data if requested
        $comparisonData = null;
        if ($request->boolean('compare_previous')) {
            $previousRange = $this->getPreviousDateRange($dateRange);
            $comparisonData = $this->getRevenueData($business, $previousRange, $groupBy);
        }

        return $this->success([
            'period' => [
                'type' => $period,
                'from' => $dateRange['start']->format('Y-m-d'),
                'to' => $dateRange['end']->format('Y-m-d')
            ],
            'revenue' => $revenueData,
            'comparison' => $comparisonData,
            'summary' => [
                'total_revenue' => collect($revenueData)->sum('revenue'),
                'total_bookings' => collect($revenueData)->sum('bookings'),
                'average_booking_value' => collect($revenueData)->where('bookings', '>', 0)->avg('average_value'),
            ]
        ], 'Revenue report generated successfully');
    }

    /**
     * Get bookings report
     */
    public function bookings(Request $request)
    {
        $business = $request->user()->business;

        if (!$business) {
            return $this->error('No business found', 404);
        }

        $validated = $request->validate([
            'period' => 'nullable|string|in:week,month,quarter,year,custom',
            'date_from' => 'required_if:period,custom|date',
            'date_to' => 'required_if:period,custom|date|after_or_equal:date_from',
            'status' => 'nullable|array',
            'status.*' => 'string|in:pending,confirmed,completed,cancelled,no_show',
            'service_id' => 'nullable|exists:services,id',
            'staff_id' => 'nullable|exists:staff,id'
        ]);

        $period = $validated['period'] ?? 'month';
        $dateRange = $this->getDateRange($period, $validated['date_from'] ?? null, $validated['date_to'] ?? null);

        $bookingsData = $this->getBookingsData($business, $dateRange, $validated);

        return $this->success([
            'period' => [
                'type' => $period,
                'from' => $dateRange['start']->format('Y-m-d'),
                'to' => $dateRange['end']->format('Y-m-d')
            ],
            'bookings' => $bookingsData,
            'summary' => [
                'total_bookings' => collect($bookingsData['by_status'])->sum(),
                'completion_rate' => $this->calculateCompletionRate($bookingsData['by_status']),
                'cancellation_rate' => $this->calculateCancellationRate($bookingsData['by_status']),
                'no_show_rate' => $this->calculateNoShowRate($bookingsData['by_status'])
            ]
        ], 'Bookings report generated successfully');
    }

    /**
     * Get customer analytics report
     */
    public function customers(Request $request)
    {
        $business = $request->user()->business;

        if (!$business) {
            return $this->error('No business found', 404);
        }

        $validated = $request->validate([
            'period' => 'nullable|string|in:week,month,quarter,year,custom',
            'date_from' => 'required_if:period,custom|date',
            'date_to' => 'required_if:period,custom|date|after_or_equal:date_from'
        ]);

        $period = $validated['period'] ?? 'month';
        $dateRange = $this->getDateRange($period, $validated['date_from'] ?? null, $validated['date_to'] ?? null);

        $customerData = $this->getCustomerAnalytics($business, $dateRange);

        return $this->success([
            'period' => [
                'type' => $period,
                'from' => $dateRange['start']->format('Y-m-d'),
                'to' => $dateRange['end']->format('Y-m-d')
            ],
            'customers' => $customerData
        ], 'Customer analytics generated successfully');
    }

    /**
     * Get staff performance report
     */
    public function staffPerformance(Request $request)
    {
        $business = $request->user()->business;

        if (!$business) {
            return $this->error('No business found', 404);
        }

        $validated = $request->validate([
            'period' => 'nullable|string|in:week,month,quarter,year,custom',
            'date_from' => 'required_if:period,custom|date',
            'date_to' => 'required_if:period,custom|date|after_or_equal:date_from',
            'staff_id' => 'nullable|exists:staff,id'
        ]);

        $period = $validated['period'] ?? 'month';
        $dateRange = $this->getDateRange($period, $validated['date_from'] ?? null, $validated['date_to'] ?? null);

        $staffData = $this->getStaffPerformance($business, $dateRange, $validated['staff_id'] ?? null);

        return $this->success([
            'period' => [
                'type' => $period,
                'from' => $dateRange['start']->format('Y-m-d'),
                'to' => $dateRange['end']->format('Y-m-d')
            ],
            'staff_performance' => $staffData
        ], 'Staff performance report generated successfully');
    }

    /**
     * Export report to PDF
     */
    public function exportPdf(Request $request)
    {
        $business = $request->user()->business;

        if (!$business) {
            return $this->error('No business found', 404);
        }

        $validated = $request->validate([
            'report_type' => 'required|string|in:revenue,bookings,customers,staff',
            'period' => 'nullable|string|in:week,month,quarter,year,custom',
            'date_from' => 'required_if:period,custom|date',
            'date_to' => 'required_if:period,custom|date|after_or_equal:date_from'
        ]);

        try {
            $period = $validated['period'] ?? 'month';
            $dateRange = $this->getDateRange($period, $validated['date_from'] ?? null, $validated['date_to'] ?? null);

            // Generate report data based on type
            $reportData = match($validated['report_type']) {
                'revenue' => $this->getRevenueData($business, $dateRange, 'day'),
                'bookings' => $this->getBookingsData($business, $dateRange, []),
                'customers' => $this->getCustomerAnalytics($business, $dateRange),
                'staff' => $this->getStaffPerformance($business, $dateRange),
            };

            // Generate PDF
            $pdf = PDF::loadView('reports.vendor.' . $validated['report_type'], [
                'business' => $business,
                'reportData' => $reportData,
                'period' => $period,
                'dateRange' => $dateRange,
                'generatedAt' => now()
            ]);

            $filename = $business->slug . '-' . $validated['report_type'] . '-report-' . $dateRange['start']->format('Y-m-d') . '.pdf';

            return $pdf->download($filename);

        } catch (\Exception $e) {
            return $this->error('Failed to generate PDF report', 500, [
                'error' => config('app.debug') ? $e->getMessage() : 'PDF generation failed'
            ]);
        }
    }

    /**
     * Helper methods
     */
    private function getDateRange($period, $customStart = null, $customEnd = null)
    {
        if ($period === 'custom' && $customStart && $customEnd) {
            return [
                'start' => Carbon::parse($customStart),
                'end' => Carbon::parse($customEnd)
            ];
        }

        $now = now();
        
        return match($period) {
            'week' => [
                'start' => $now->copy()->startOfWeek(),
                'end' => $now->copy()->endOfWeek()
            ],
            'quarter' => [
                'start' => $now->copy()->startOfQuarter(),
                'end' => $now->copy()->endOfQuarter()
            ],
            'year' => [
                'start' => $now->copy()->startOfYear(),
                'end' => $now->copy()->endOfYear()
            ],
            default => [
                'start' => $now->copy()->startOfMonth(),
                'end' => $now->copy()->endOfMonth()
            ]
        };
    }

    private function getPreviousDateRange($currentRange)
    {
        $diff = $currentRange['start']->diffInDays($currentRange['end']);
        
        return [
            'start' => $currentRange['start']->copy()->subDays($diff + 1),
            'end' => $currentRange['start']->copy()->subDay()
        ];
    }

    private function getRevenueData($business, $dateRange, $groupBy)
    {
        // Implementation would group by specified period and return revenue data
        return $business->bookings()
            ->whereBetween('booking_date', [$dateRange['start'], $dateRange['end']])
            ->where('payment_status', 'paid')
            ->selectRaw("DATE(booking_date) as date, SUM(amount) as revenue, COUNT(*) as bookings, AVG(amount) as average_value")
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }

    private function getBookingsData($business, $dateRange, $filters)
    {
        $query = $business->bookings()
            ->whereBetween('booking_date', [$dateRange['start'], $dateRange['end']]);

        if (!empty($filters['status'])) {
            $query->whereIn('status', $filters['status']);
        }

        if (!empty($filters['service_id'])) {
            $query->where('service_id', $filters['service_id']);
        }

        if (!empty($filters['staff_id'])) {
            $query->where('staff_id', $filters['staff_id']);
        }

        $bookings = $query->get();

        return [
            'by_status' => $bookings->groupBy('status')->map->count(),
            'by_service' => $bookings->groupBy('service.name')->map->count(),
            'by_staff' => $bookings->whereNotNull('staff_id')->groupBy('staff.name')->map->count(),
            'by_date' => $bookings->groupBy(function ($booking) {
                return $booking->booking_date->format('Y-m-d');
            })->map->count(),
            'peak_hours' => $bookings->groupBy(function ($booking) {
                return \Carbon\Carbon::parse($booking->start_time)->format('H');
            })->map->count()->sortDesc()->take(5)
        ];
    }

    private function getCustomerAnalytics($business, $dateRange)
    {
        $bookings = $business->bookings()
            ->whereBetween('booking_date', [$dateRange['start'], $dateRange['end']])
            ->with('user')
            ->get();

        $customers = $bookings->groupBy('user_id');
        $newCustomers = $customers->filter(function ($customerBookings, $userId) use ($business, $dateRange) {
            return !$business->bookings()
                ->where('user_id', $userId)
                ->where('booking_date', '<', $dateRange['start'])
                ->exists();
        });

        return [
            'total_customers' => $customers->count(),
            'new_customers' => $newCustomers->count(),
            'returning_customers' => $customers->count() - $newCustomers->count(),
            'customer_retention_rate' => $customers->count() > 0 
                ? round((($customers->count() - $newCustomers->count()) / $customers->count()) * 100, 2) 
                : 0,
            'average_bookings_per_customer' => $customers->count() > 0 
                ? round($bookings->count() / $customers->count(), 2) 
                : 0,
            'top_customers' => $customers->map(function ($customerBookings) {
                $user = $customerBookings->first()->user;
                return [
                    'name' => $user->name,
                    'email' => $user->email,
                    'bookings_count' => $customerBookings->count(),
                    'total_spent' => $customerBookings->where('payment_status', 'paid')->sum('amount')
                ];
            })->sortByDesc('total_spent')->take(10)->values()
        ];
    }

    private function getStaffPerformance($business, $dateRange, $staffId = null)
    {
        $query = $business->staff()->with(['bookings' => function ($q) use ($dateRange) {
            $q->whereBetween('booking_date', [$dateRange['start'], $dateRange['end']]);
        }]);

        if ($staffId) {
            $query->where('id', $staffId);
        }

        $staff = $query->get();

        return $staff->map(function ($staffMember) use ($dateRange) {
            $bookings = $staffMember->bookings;
            
            return [
                'id' => $staffMember->id,
                'name' => $staffMember->name,
                'total_bookings' => $bookings->count(),
                'completed_bookings' => $bookings->where('status', 'completed')->count(),
                'cancelled_bookings' => $bookings->where('status', 'cancelled')->count(),
                'no_shows' => $bookings->where('status', 'no_show')->count(),
                'total_revenue' => $bookings->where('payment_status', 'paid')->sum('amount'),
                'average_rating' => $bookings->whereHas('review')->with('review')->get()->avg('review.rating'),
                'utilization_rate' => $this->calculateStaffUtilization($staffMember, $dateRange),
                'completion_rate' => $bookings->count() > 0 
                    ? round(($bookings->where('status', 'completed')->count() / $bookings->count()) * 100, 2) 
                    : 0
            ];
        });
    }

    private function calculateCompletionRate($statusCounts)
    {
        $total = collect($statusCounts)->sum();
        $completed = $statusCounts['completed'] ?? 0;
        
        return $total > 0 ? round(($completed / $total) * 100, 2) : 0;
    }

    private function calculateCancellationRate($statusCounts)
    {
        $total = collect($statusCounts)->sum();
        $cancelled = $statusCounts['cancelled'] ?? 0;
        
        return $total > 0 ? round(($cancelled / $total) * 100, 2) : 0;
    }

    private function calculateNoShowRate($statusCounts)
    {
        $total = collect($statusCounts)->sum();
        $noShows = $statusCounts['no_show'] ?? 0;
        
        return $total > 0 ? round(($noShows / $total) * 100, 2) : 0;
    }

    private function calculateStaffUtilization($staff, $dateRange)
    {
        // Calculate working hours in the period
        $totalWorkingMinutes = 0;
        $current = $dateRange['start']->copy();
        
        while ($current <= $dateRange['end']) {
            $dayOfWeek = strtolower($current->format('l'));
            $workingHours = $staff->working_hours[$dayOfWeek] ?? null;
            
            if ($workingHours && isset($workingHours['open']) && isset($workingHours['close'])) {
                $open = \Carbon\Carbon::parse($workingHours['open']);
                $close = \Carbon\Carbon::parse($workingHours['close']);
                $totalWorkingMinutes += $close->diffInMinutes($open);
            }
            
            $current->addDay();
        }
        
        // Calculate booked minutes
        $bookedMinutes = $staff->bookings()
            ->whereBetween('booking_date', [$dateRange['start'], $dateRange['end']])
            ->where('status', '!=', 'cancelled')
            ->get()
            ->sum(function ($booking) {
                $start = \Carbon\Carbon::parse($booking->start_time);
                $end = \Carbon\Carbon::parse($booking->end_time);
                return $end->diffInMinutes($start);
            });
        
        return $totalWorkingMinutes > 0 
            ? round(($bookedMinutes / $totalWorkingMinutes) * 100, 2) 
            : 0;
    }
}