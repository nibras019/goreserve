<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Http\Resources\StaffResource;
use App\Http\Requests\Staff\CreateStaffRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class StaffController extends Controller
{
    /**
     * Get all staff members
     */
    public function index(Request $request)
    {
        $business = $request->user()->business;

        if (!$business) {
            return $this->error('No business found', 404);
        }

        $validated = $request->validate([
            'is_active' => 'nullable|boolean',
            'service_id' => 'nullable|exists:services,id',
            'available_on' => 'nullable|date',
            'sort_by' => 'nullable|string|in:name,bookings,rating',
            'sort_order' => 'nullable|string|in:asc,desc'
        ]);

        $query = $business->staff()
            ->with(['services', 'availabilities'])
            ->withCount(['bookings' => function ($query) {
                $query->whereMonth('booking_date', now()->month);
            }]);

        // Filters
        if (isset($validated['is_active'])) {
            $query->where('is_active', $validated['is_active']);
        }

        if (!empty($validated['service_id'])) {
            $query->whereHas('services', function ($q) use ($validated) {
                $q->where('services.id', $validated['service_id']);
            });
        }

        if (!empty($validated['available_on'])) {
            $date = Carbon::parse($validated['available_on']);
            $dayOfWeek = strtolower($date->format('l'));
            
            $query->whereJsonContains("working_hours->{$dayOfWeek}", function ($value) {
                return isset($value['open']) && isset($value['close']);
            })->whereDoesntHave('availabilities', function ($q) use ($date) {
                $q->where('date', $date->format('Y-m-d'))
                  ->whereIn('type', ['vacation', 'sick', 'blocked']);
            });
        }

        // Sorting
        $sortBy = $validated['sort_by'] ?? 'name';
        $sortOrder = $validated['sort_order'] ?? 'asc';

        switch ($sortBy) {
            case 'bookings':
                $query->orderBy('bookings_count', $sortOrder);
                break;
            case 'rating':
                $query->withAvg(['bookings.review' => function ($query) {
                    $query->where('is_approved', true);
                }], 'rating')
                ->orderBy('bookings_review_avg_rating', $sortOrder);
                break;
            default:
                $query->orderBy('name', $sortOrder);
        }

        $staff = $query->get();

        // Get staff statistics
        $stats = [
            'total_staff' => $staff->count(),
            'active_staff' => $staff->where('is_active', true)->count(),
            'on_vacation' => $business->staff()
                ->whereHas('availabilities', function ($q) {
                    $q->where('date', today())
                      ->where('type', 'vacation');
                })->count(),
        ];

        return $this->success([
            'staff' => StaffResource::collection($staff),
            'stats' => $stats
        ], 'Staff retrieved successfully');
    }

    /**
     * Create new staff member
     */
    public function store(CreateStaffRequest $request)
    {
        $business = $request->user()->business;

        if (!$business) {
            return $this->error('No business found', 404);
        }

        $validated = $request->validated();

        DB::beginTransaction();

        try {
            $staff = $business->staff()->create([
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'working_hours' => $validated['working_hours'],
                'is_active' => $validated['is_active'] ?? true,
                'commission_rate' => $validated['commission_rate'] ?? null,
                'bio' => $validated['bio'] ?? null,
                'specializations' => $validated['specializations'] ?? []
            ]);

            // Assign services
            if (!empty($validated['service_ids'])) {
                $staff->services()->attach($validated['service_ids']);
            }

            // Handle avatar
            if ($request->hasFile('avatar')) {
                $path = $request->file('avatar')->store('staff-avatars', 'public');
                $staff->update(['avatar' => $path]);
            }

            DB::commit();

            return $this->success(
                new StaffResource($staff->load('services')),
                'Staff member added successfully',
                201
            );

        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->error(
                'Failed to add staff member',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Get staff member details
     */
    public function show(Request $request, Staff $staff)
    {
        // Verify ownership
        if ($staff->business_id !== $request->user()->business->id) {
            return $this->error('Staff member not found', 404);
        }

        $staff->load(['services', 'availabilities' => function ($query) {
            $query->where('date', '>=', today())
                  ->orderBy('date');
        }]);

        // Get staff statistics
        $stats = [
            'bookings_today' => $staff->bookings()
                ->whereDate('booking_date', today())
                ->count(),
            'bookings_this_week' => $staff->bookings()
                ->whereBetween('booking_date', [now()->startOfWeek(), now()->endOfWeek()])
                ->count(),
            'bookings_this_month' => $staff->bookings()
                ->whereMonth('booking_date', now()->month)
                ->count(),
            'revenue_this_month' => $staff->bookings()
                ->whereMonth('booking_date', now()->month)
                ->where('payment_status', 'paid')
                ->sum('amount'),
            'average_rating' => $staff->bookings()
                ->whereHas('review')
                ->with('review')
                ->get()
                ->avg('review.rating'),
            'utilization_rate' => $this->calculateUtilizationRate($staff),
        ];

        // Get upcoming schedule
        $upcomingSchedule = $this->getUpcomingSchedule($staff, 7);

        return $this->success([
            'staff' => new StaffResource($staff),
            'stats' => $stats,
            'upcoming_schedule' => $upcomingSchedule
        ], 'Staff details retrieved successfully');
    }

    /**
     * Update staff member
     */
    public function update(UpdateStaffRequest $request, Staff $staff)
    {
        // Verify ownership
        if ($staff->business_id !== $request->user()->business->id) {
            return $this->error('Staff member not found', 404);
        }

        $validated = $request->validated();

        DB::beginTransaction();

        try {
            $staff->update($validated);

            // Update service assignments
            if (isset($validated['service_ids'])) {
                $staff->services()->sync($validated['service_ids']);
            }

            // Handle avatar update
            if ($request->hasFile('avatar')) {
                // Delete old avatar
                if ($staff->avatar && \Storage::disk('public')->exists($staff->avatar)) {
                    \Storage::disk('public')->delete($staff->avatar);
                }
                
                $path = $request->file('avatar')->store('staff-avatars', 'public');
                $staff->update(['avatar' => $path]);
            }

            DB::commit();

            return $this->success(
                new StaffResource($staff->fresh('services')),
                'Staff member updated successfully'
            );

        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->error(
                'Failed to update staff member',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Delete staff member
     */
    public function destroy(Request $request, Staff $staff)
    {
        // Verify ownership
        if ($staff->business_id !== $request->user()->business->id) {
            return $this->error('Staff member not found', 404);
        }

        // Check for future bookings
        $hasBookings = $staff->bookings()
            ->where('booking_date', '>=', today())
            ->where('status', '!=', 'cancelled')
            ->exists();

        if ($hasBookings) {
            return $this->error(
                'Cannot delete staff member with active bookings',
                422,
                ['active_bookings' => true]
            );
        }

        $staff->delete();

        return $this->success(null, 'Staff member deleted successfully');
    }

    /**
     * Set staff availability
     */
    public function setAvailability(Request $request, Staff $staff)
    {
        // Verify ownership
        if ($staff->business_id !== $request->user()->business->id) {
            return $this->error('Staff member not found', 404);
        }

        $validated = $request->validate([
            'availabilities' => 'required|array',
            'availabilities.*.date' => 'required|date|after_or_equal:today',
            'availabilities.*.type' => 'required|in:available,vacation,sick,blocked',
            'availabilities.*.start_time' => 'nullable|date_format:H:i',
            'availabilities.*.end_time' => 'nullable|date_format:H:i|after:availabilities.*.start_time',
            'availabilities.*.reason' => 'nullable|string|max:255'
        ]);

        DB::beginTransaction();

        try {
            foreach ($validated['availabilities'] as $availability) {
                $staff->availabilities()->updateOrCreate(
                    ['date' => $availability['date']],
                    [
                        'type' => $availability['type'],
                        'start_time' => $availability['start_time'] ?? null,
                        'end_time' => $availability['end_time'] ?? null,
                        'reason' => $availability['reason'] ?? null
                    ]
                );

                // Cancel bookings if staff is unavailable
                if (in_array($availability['type'], ['vacation', 'sick', 'blocked'])) {
                    $bookingsToCancel = $staff->bookings()
                        ->where('booking_date', $availability['date'])
                        ->whereIn('status', ['pending', 'confirmed'])
                        ->get();

                    foreach ($bookingsToCancel as $booking) {
                        $booking->cancel('Staff unavailable - ' . $availability['type']);
                        // Send notification to customer
                    }
                }
            }

            DB::commit();

            return $this->success(
                $staff->availabilities()->where('date', '>=', today())->get(),
                'Availability updated successfully'
            );

        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->error(
                'Failed to update availability',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Get staff schedule
     */
    public function schedule(Request $request, Staff $staff)
    {
        // Verify ownership
        if ($staff->business_id !== $request->user()->business->id) {
            return $this->error('Staff member not found', 404);
        }

        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date'
        ]);

        $schedule = $this->getSchedule(
            $staff,
            Carbon::parse($validated['start_date']),
            Carbon::parse($validated['end_date'])
        );

        return $this->success([
            'staff' => [
                'id' => $staff->id,
                'name' => $staff->name
            ],
            'schedule' => $schedule,
            'working_hours' => $staff->working_hours
        ], 'Schedule retrieved successfully');
    }

    /**
     * Calculate staff utilization rate
     */
    private function calculateUtilizationRate($staff)
    {
        $startOfMonth = now()->startOfMonth();
        $today = now();
        
        $workingDays = 0;
        $totalWorkingMinutes = 0;
        $bookedMinutes = 0;

        $period = Carbon::parse($startOfMonth)->daysUntil($today);
        
        foreach ($period as $date) {
            $dayOfWeek = strtolower($date->format('l'));
            
            if (isset($staff->working_hours[$dayOfWeek])) {
                $hours = $staff->working_hours[$dayOfWeek];
                if (isset($hours['open']) && isset($hours['close'])) {
                    $workingDays++;
                    $open = Carbon::parse($date->format('Y-m-d') . ' ' . $hours['open']);
                    $close = Carbon::parse($date->format('Y-m-d') . ' ' . $hours['close']);
                    $totalWorkingMinutes += $close->diffInMinutes($open);
                }
            }
        }

        if ($totalWorkingMinutes === 0) {
            return 0;
        }

        $bookings = $staff->bookings()
            ->whereBetween('booking_date', [$startOfMonth, $today])
            ->where('status', '!=', 'cancelled')
            ->get();

        foreach ($bookings as $booking) {
            $start = Carbon::parse($booking->booking_date->format('Y-m-d') . ' ' . $booking->start_time);
            $end = Carbon::parse($booking->booking_date->format('Y-m-d') . ' ' . $booking->end_time);
            $bookedMinutes += $end->diffInMinutes($start);
        }

        return round(($bookedMinutes / $totalWorkingMinutes) * 100, 2);
    }

   /**
 * Get upcoming schedule for staff
 */
private function getUpcomingSchedule($staff, $days)
{
    $schedule = [];
    $startDate = now();
    $endDate = now()->addDays($days);

    $period = Carbon::parse($startDate)->daysUntil($endDate);

    foreach ($period as $date) {
        $dayOfWeek = strtolower($date->format('l'));
        $workingHours = $staff->working_hours[$dayOfWeek] ?? null;

        // Get bookings for this day
        $dayBookings = $staff->bookings()
            ->where('booking_date', $date->format('Y-m-d'))
            ->where('status', '!=', 'cancelled')
            ->with(['service', 'user'])
            ->orderBy('start_time')
            ->get();

        // Check for availability blocks
        $availability = $staff->availabilities()
            ->where('date', $date->format('Y-m-d'))
            ->first();

        $daySchedule = [
            'date' => $date->format('Y-m-d'),
            'day_name' => $date->format('l'),
            'is_working_day' => !empty($workingHours),
            'working_hours' => $workingHours,
            'availability_status' => $this->getAvailabilityStatus($availability),
            'bookings' => $dayBookings->map(function ($booking) {
                return [
                    'id' => $booking->id,
                    'booking_ref' => $booking->booking_ref,
                    'start_time' => $booking->start_time,
                    'end_time' => $booking->end_time,
                    'service' => $booking->service->name,
                    'customer' => [
                        'name' => $booking->user->name,
                        'phone' => $booking->user->phone
                    ],
                    'status' => $booking->status,
                    'amount' => $booking->amount
                ];
            }),
            'total_bookings' => $dayBookings->count(),
            'total_revenue' => $dayBookings->where('payment_status', 'paid')->sum('amount'),
            'utilization_hours' => $this->calculateDayUtilization($dayBookings, $workingHours)
        ];

        $schedule[] = $daySchedule;
    }

    return $schedule;
}

/**
 * Get availability status for a day
 */
private function getAvailabilityStatus($availability)
{
    if (!$availability) {
        return 'available';
    }

    return match($availability->type) {
        'vacation' => 'on_vacation',
        'sick' => 'sick_leave',
        'blocked' => 'unavailable',
        default => 'available'
    };
}

/**
 * Calculate day utilization
 */
private function calculateDayUtilization($bookings, $workingHours)
{
    if (!$workingHours || !isset($workingHours['open']) || !isset($workingHours['close'])) {
        return 0;
    }

    $totalWorkingMinutes = Carbon::parse($workingHours['close'])->diffInMinutes(Carbon::parse($workingHours['open']));
    
    $bookedMinutes = $bookings->sum(function ($booking) {
        return Carbon::parse($booking->end_time)->diffInMinutes(Carbon::parse($booking->start_time));
    });

    return $totalWorkingMinutes > 0 ? round(($bookedMinutes / $totalWorkingMinutes) * 100, 2) : 0;
}

/**
 * Get schedule for date range
 */
private function getSchedule($staff, $startDate, $endDate)
{
    $schedule = [];
    $period = Carbon::parse($startDate)->daysUntil($endDate);

    foreach ($period as $date) {
        $dayOfWeek = strtolower($date->format('l'));
        $workingHours = $staff->working_hours[$dayOfWeek] ?? null;

        // Get bookings for this day
        $dayBookings = $staff->bookings()
            ->where('booking_date', $date->format('Y-m-d'))
            ->where('status', '!=', 'cancelled')
            ->with(['service', 'user'])
            ->orderBy('start_time')
            ->get();

        // Check for availability overrides
        $availability = $staff->availabilities()
            ->where('date', $date->format('Y-m-d'))
            ->first();

        // Generate time slots
        $timeSlots = [];
        if ($workingHours && !$this->isBlockedDay($availability)) {
            $timeSlots = $this->generateTimeSlots($workingHours, $dayBookings, $availability);
        }

        $schedule[] = [
            'date' => $date->format('Y-m-d'),
            'day_name' => $date->format('l'),
            'working_hours' => $workingHours,
            'is_available' => $this->isDayAvailable($workingHours, $availability),
            'availability_note' => $availability?->reason,
            'bookings' => $dayBookings->map(function ($booking) {
                return [
                    'id' => $booking->id,
                    'booking_ref' => $booking->booking_ref,
                    'start_time' => $booking->start_time,
                    'end_time' => $booking->end_time,
                    'duration' => Carbon::parse($booking->end_time)->diffInMinutes(Carbon::parse($booking->start_time)),
                    'service' => [
                        'id' => $booking->service->id,
                        'name' => $booking->service->name
                    ],
                    'customer' => [
                        'id' => $booking->user->id,
                        'name' => $booking->user->name,
                        'phone' => $booking->user->phone ?? null
                    ],
                    'status' => $booking->status,
                    'amount' => $booking->amount
                ];
            }),
            'time_slots' => $timeSlots,
            'stats' => [
                'total_bookings' => $dayBookings->count(),
                'total_duration' => $dayBookings->sum(function ($booking) {
                    return Carbon::parse($booking->end_time)->diffInMinutes(Carbon::parse($booking->start_time));
                }),
                'total_revenue' => $dayBookings->where('payment_status', 'paid')->sum('amount')
            ]
        ];
    }

    }
} 