<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Http\Resources\BookingResource;
use App\Services\CalendarService;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    protected $calendarService;

    public function __construct(CalendarService $calendarService)
    {
        $this->calendarService = $calendarService;
    }

    /**
     * Get all bookings for vendor's business
     */
    public function index(Request $request)
    {
        $business = $request->user()->business;

        if (!$business) {
            return $this->error('No business found', 404);
        }

        $validated = $request->validate([
            'status' => 'nullable|string|in:pending,confirmed,completed,cancelled,no_show',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'service_id' => 'nullable|exists:services,id',
            'staff_id' => 'nullable|exists:staff,id',
            'search' => 'nullable|string',
            'sort_by' => 'nullable|string|in:date,created,customer,service,amount',
            'sort_order' => 'nullable|string|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        $query = $business->bookings()
            ->with(['user', 'service', 'staff', 'payments']);

        // Filters
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['date_from'])) {
            $query->whereDate('booking_date', '>=', $validated['date_from']);
        }

        if (!empty($validated['date_to'])) {
            $query->whereDate('booking_date', '<=', $validated['date_to']);
        }

        if (!empty($validated['service_id'])) {
            $query->where('service_id', $validated['service_id']);
        }

        if (!empty($validated['staff_id'])) {
            $query->where('staff_id', $validated['staff_id']);
        }

        if (!empty($validated['search'])) {
            $query->where(function ($q) use ($validated) {
                $q->where('booking_ref', 'like', "%{$validated['search']}%")
                  ->orWhereHas('user', function ($uq) use ($validated) {
                      $uq->where('name', 'like', "%{$validated['search']}%")
                        ->orWhere('email', 'like', "%{$validated['search']}%");
                  });
            });
        }

        // Sorting
        $sortBy = $validated['sort_by'] ?? 'date';
        $sortOrder = $validated['sort_order'] ?? 'desc';

        switch ($sortBy) {
            case 'date':
                $query->orderBy('booking_date', $sortOrder)
                      ->orderBy('start_time', $sortOrder);
                break;
            case 'created':
                $query->orderBy('created_at', $sortOrder);
                break;
            case 'customer':
                $query->join('users', 'bookings.user_id', '=', 'users.id')
                      ->orderBy('users.name', $sortOrder)
                      ->select('bookings.*');
                break;
            case 'service':
                $query->join('services', 'bookings.service_id', '=', 'services.id')
                      ->orderBy('services.name', $sortOrder)
                      ->select('bookings.*');
                break;
            case 'amount':
                $query->orderBy('amount', $sortOrder);
                break;
            default:
                $query->orderBy('booking_date', 'desc');
        }

        $bookings = $query->paginate($validated['per_page'] ?? 20);

        // Get summary stats
        $stats = [
            'total_bookings' => $business->bookings()->count(),
            'today_bookings' => $business->bookings()->whereDate('booking_date', today())->count(),
            'pending_bookings' => $business->bookings()->where('status', 'pending')->count(),
            'this_week_revenue' => $business->bookings()
                ->whereBetween('booking_date', [now()->startOfWeek(), now()->endOfWeek()])
                ->where('payment_status', 'paid')
                ->sum('amount')
        ];

        return response()->json([
            'success' => true,
            'message' => 'Bookings retrieved successfully',
            'data' => BookingResource::collection($bookings),
            'stats' => $stats,
            'pagination' => [
                'total' => $bookings->total(),
                'count' => $bookings->count(),
                'per_page' => $bookings->perPage(),
                'current_page' => $bookings->currentPage(),
                'total_pages' => $bookings->lastPage()
            ]
        ]);
    }

    /**
     * Update booking status or details
     */
    public function update(Request $request, Booking $booking)
    {
        $business = $request->user()->business;

        if (!$business || $booking->business_id !== $business->id) {
            return $this->error('Booking not found', 404);
        }

        $validated = $request->validate([
            'status' => 'nullable|string|in:pending,confirmed,completed,cancelled,no_show',
            'notes' => 'nullable|string|max:500',
            'staff_id' => 'nullable|exists:staff,id',
            'booking_date' => 'nullable|date|after_or_equal:today',
            'start_time' => 'nullable|date_format:H:i',
            'cancellation_reason' => 'nullable|string|max:500'
        ]);

        DB::beginTransaction();

        try {
            $oldStatus = $booking->status;
            $changes = [];

            // Handle status change
            if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
                $changes['status'] = ['old' => $oldStatus, 'new' => $validated['status']];
                
                if ($validated['status'] === 'cancelled') {
                    $booking->update([
                        'status' => 'cancelled',
                        'cancellation_reason' => $validated['cancellation_reason'] ?? 'Cancelled by business',
                        'cancelled_at' => now(),
                        'cancelled_by' => 'business'
                    ]);
                } else {
                    $booking->update(['status' => $validated['status']]);
                }

                // Handle specific status changes
                $this->handleStatusChange($booking, $oldStatus, $validated['status']);
            }

            // Handle other updates
            $updateData = array_filter([
                'notes' => $validated['notes'] ?? $booking->notes,
                'staff_id' => $validated['staff_id'] ?? $booking->staff_id,
                'booking_date' => $validated['booking_date'] ?? $booking->booking_date,
                'start_time' => $validated['start_time'] ?? $booking->start_time,
            ]);

            if (!empty($updateData)) {
                $booking->update($updateData);
            }

            DB::commit();

            // Log activity
            activity()
                ->performedOn($booking)
                ->causedBy($request->user())
                ->withProperties(['changes' => $changes])
                ->log('Booking updated by business');

            return $this->success(
                new BookingResource($booking->fresh(['user', 'service', 'staff'])),
                'Booking updated successfully'
            );

        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->error(
                'Failed to update booking',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Get calendar view of bookings
     */
    public function calendar(Request $request)
    {
        $business = $request->user()->business;

        if (!$business) {
            return $this->error('No business found', 404);
        }

        $validated = $request->validate([
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2020|max:2030',
            'view' => 'nullable|string|in:month,week,day',
            'staff_id' => 'nullable|exists:staff,id'
        ]);

        $month = $validated['month'] ?? now()->month;
        $year = $validated['year'] ?? now()->year;
        $view = $validated['view'] ?? 'month';

        if ($view === 'month') {
            $calendar = $this->calendarService->getBusinessCalendar($business, $month, $year);
        } else {
            // For week/day views, get different data structure
            $calendar = $this->getWeeklyCalendar($business, $month, $year, $validated['staff_id'] ?? null);
        }

        return $this->success([
            'calendar' => $calendar,
            'view' => $view,
            'current_date' => now()->format('Y-m-d')
        ], 'Calendar data retrieved successfully');
    }

    /**
     * Get booking statistics
     */
    public function statistics(Request $request)
    {
        $business = $request->user()->business;

        if (!$business) {
            return $this->error('No business found', 404);
        }

        $validated = $request->validate([
            'period' => 'nullable|string|in:today,week,month,quarter,year',
            'compare_previous' => 'nullable|boolean'
        ]);

        $period = $validated['period'] ?? 'month';
        $dateRange = $this->getDateRangeForPeriod($period);

        $stats = [
            'current_period' => $this->getBookingStatistics($business, $dateRange),
            'comparison' => null
        ];

        if ($request->boolean('compare_previous')) {
            $previousRange = $this->getPreviousDateRange($dateRange, $period);
            $stats['comparison'] = $this->getBookingStatistics($business, $previousRange);
        }

        return $this->success($stats, 'Statistics retrieved successfully');
    }

    /**
     * Handle status changes
     */
    private function handleStatusChange(Booking $booking, string $oldStatus, string $newStatus): void
    {
        switch ($newStatus) {
            case 'confirmed':
                // Send confirmation notification
                $booking->user->notify(new \App\Notifications\BookingConfirmed($booking));
                break;

            case 'completed':
                // Mark as completed, send review request
                \App\Jobs\SendReviewRequest::dispatch($booking)->delay(now()->addHours(2));
                break;

            case 'cancelled':
                // Handle cancellation, process refund if needed
                if ($booking->payment_status === 'paid') {
                    \App\Jobs\ProcessRefund::dispatch($booking, 1.0); // Full refund
                }
                break;

            case 'no_show':
                // Handle no-show penalties
                $this->handleNoShow($booking);
                break;
        }
    }

    /**
     * Handle no-show booking
     */
    private function handleNoShow(Booking $booking): void
    {
        $business = $booking->business;
        $noShowFee = $business->settings['no_show_fee'] ?? 0;

        if ($noShowFee > 0) {
            // Apply no-show fee
            \App\Models\Penalty::create([
                'booking_id' => $booking->id,
                'user_id' => $booking->user_id,
                'amount' => $noShowFee,
                'reason' => 'No-show penalty',
                'status' => 'pending'
            ]);
        }

        // Increment no-show count
        $booking->user->increment('no_show_count');
    }

    /**
     * Get weekly calendar data
     */
    private function getWeeklyCalendar($business, $month, $year, $staffId = null)
    {
        $startDate = Carbon::createFromDate($year, $month, 1)->startOfWeek();
        $endDate = $startDate->copy()->addWeeks(5)->endOfWeek();

        $query = $business->bookings()
            ->whereBetween('booking_date', [$startDate, $endDate])
            ->with(['user', 'service', 'staff']);

        if ($staffId) {
            $query->where('staff_id', $staffId);
        }

        $bookings = $query->get();

        // Group by week and day
        $weeks = [];
        $current = $startDate->copy();

        while ($current <= $endDate) {
            $weekStart = $current->copy()->startOfWeek();
            $weekKey = $weekStart->format('Y-W');

            if (!isset($weeks[$weekKey])) {
                $weeks[$weekKey] = [
                    'start_date' => $weekStart->format('Y-m-d'),
                    'days' => []
                ];
            }

            $dayBookings = $bookings->filter(function ($booking) use ($current) {
                return $booking->booking_date->format('Y-m-d') === $current->format('Y-m-d');
            });

            $weeks[$weekKey]['days'][] = [
                'date' => $current->format('Y-m-d'),
                'day_name' => $current->format('l'),
                'bookings' => $dayBookings->values(),
                'booking_count' => $dayBookings->count()
            ];

            $current->addDay();
        }

        return array_values($weeks);
    }

    /**
     * Get date range for period
     */
    private function getDateRangeForPeriod($period)
    {
        $now = now();

        return match($period) {
            'today' => [
                'start' => $now->copy()->startOfDay(),
                'end' => $now->copy()->endOfDay()
            ],
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

    /**
     * Get previous date range
     */
    private function getPreviousDateRange($currentRange, $period)
    {
        switch ($period) {
            case 'today':
                return [
                    'start' => $currentRange['start']->copy()->subDay(),
                    'end' => $currentRange['end']->copy()->subDay()
                ];
            case 'week':
                return [
                    'start' => $currentRange['start']->copy()->subWeek(),
                    'end' => $currentRange['end']->copy()->subWeek()
                ];
            case 'quarter':
                return [
                    'start' => $currentRange['start']->copy()->subQuarter(),
                    'end' => $currentRange['end']->copy()->subQuarter()
                ];
            case 'year':
                return [
                    'start' => $currentRange['start']->copy()->subYear(),
                    'end' => $currentRange['end']->copy()->subYear()
                ];
            default:
                return [
                    'start' => $currentRange['start']->copy()->subMonth(),
                    'end' => $currentRange['end']->copy()->subMonth()
                ];
        }
    }

    /**
     * Get booking statistics for date range
     */
    private function getBookingStatistics($business, $dateRange)
    {
        $bookings = $business->bookings()
            ->whereBetween('booking_date', [$dateRange['start'], $dateRange['end']])
            ->get();

        return [
            'total_bookings' => $bookings->count(),
            'confirmed_bookings' => $bookings->where('status', 'confirmed')->count(),
            'completed_bookings' => $bookings->where('status', 'completed')->count(),
            'cancelled_bookings' => $bookings->where('status', 'cancelled')->count(),
            'no_shows' => $bookings->where('status', 'no_show')->count(),
            'total_revenue' => $bookings->where('payment_status', 'paid')->sum('amount'),
            'average_booking_value' => $bookings->where('payment_status', 'paid')->avg('amount'),
            'completion_rate' => $bookings->count() > 0 
                ? round(($bookings->where('status', 'completed')->count() / $bookings->count()) * 100, 2) 
                : 0,
            'cancellation_rate' => $bookings->count() > 0 
                ? round(($bookings->where('status', 'cancelled')->count() / $bookings->count()) * 100, 2) 
                : 0
        ];
    }
}