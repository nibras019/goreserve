<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Http\Resources\BusinessResource;
use App\Http\Requests\Business\CreateBusinessRequest;
use App\Http\Requests\Business\UpdateBusinessRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class BusinessController extends Controller
{
    /**
     * Get vendor's business
     */
    public function show(Request $request)
    {
        $business = $request->user()->business;

        if (!$business) {
            return $this->error('No business found', 404);
        }

        $business->load(['services', 'staff', 'media']);

        return $this->success(
            new BusinessResource($business),
            'Business retrieved successfully'
        );
    }

    /**
     * Register a new business
     */
    public function store(CreateBusinessRequest $request)
    {
        // Check if user already has a business
        if ($request->user()->business) {
            return $this->error('You already have a business registered', 422);
        }

        $validated = $request->validated();

        DB::beginTransaction();

        try {
            $business = Business::create([
                'user_id' => $request->user()->id,
                'name' => $validated['name'],
                'type' => $validated['type'],
                'description' => $validated['description'] ?? null,
                'phone' => $validated['phone'],
                'email' => $validated['email'] ?? null,
                'address' => $validated['address'],
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
                'working_hours' => $validated['working_hours'],
                'settings' => $validated['settings'] ?? [],
                'status' => config('goreserve.business.auto_approve') ? 'approved' : 'pending'
            ]);

            // Handle logo upload
            if ($request->hasFile('logo')) {
                $business->addMediaFromRequest('logo')
                    ->toMediaCollection('logo');
            }

            // Handle gallery images
            if ($request->hasFile('gallery')) {
                foreach ($request->file('gallery') as $image) {
                    $business->addMedia($image)
                        ->toMediaCollection('gallery');
                }
            }

            DB::commit();

            // Clear cache
            Cache::tags(['businesses'])->flush();

            // Send notification to admin for approval
            if ($business->status === 'pending') {
                // Notify admin
            }

            return $this->success(
                new BusinessResource($business->load('media')),
                'Business registered successfully. ' . 
                ($business->status === 'pending' ? 'Awaiting approval.' : 'Your business is now active.'),
                201
            );

        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->error(
                'Failed to register business',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Update business details
     */
    public function update(UpdateBusinessRequest $request)
    {
        $business = $request->user()->business;

        if (!$business) {
            return $this->error('No business found', 404);
        }

        $validated = $request->validated();

        DB::beginTransaction();

        try {
            $business->update($validated);

            // Handle logo update
            if ($request->hasFile('logo')) {
                $business->clearMediaCollection('logo');
                $business->addMediaFromRequest('logo')
                    ->toMediaCollection('logo');
            }

            // Handle gallery update
            if ($request->has('remove_gallery_ids')) {
                $business->media()
                    ->whereIn('id', $request->remove_gallery_ids)
                    ->where('collection_name', 'gallery')
                    ->each(fn($media) => $media->delete());
            }

            if ($request->hasFile('gallery')) {
                foreach ($request->file('gallery') as $image) {
                    $business->addMedia($image)
                        ->toMediaCollection('gallery');
                }
            }

            DB::commit();

            // Clear cache
            Cache::forget("business_{$business->id}");
            Cache::tags(['businesses'])->flush();

            return $this->success(
                new BusinessResource($business->load('media')),
                'Business updated successfully'
            );

        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->error(
                'Failed to update business',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Get business dashboard
     */
    public function dashboard(Request $request)
    {
        $business = $request->user()->business;

        if (!$business) {
            return $this->error('No business found', 404);
        }

        $today = now()->startOfDay();
        $thisWeek = now()->startOfWeek();
        $thisMonth = now()->startOfMonth();
        $lastMonth = now()->subMonth()->startOfMonth();

        // Get statistics
        $stats = [
            'today' => [
                'bookings' => $business->bookings()
                    ->whereDate('booking_date', $today)
                    ->count(),
                'revenue' => $business->bookings()
                    ->whereDate('booking_date', $today)
                    ->where('payment_status', 'paid')
                    ->sum('amount'),
                'new_customers' => $business->bookings()
                    ->whereDate('created_at', $today)
                    ->distinct('user_id')
                    ->count('user_id'),
            ],
            'week' => [
                'bookings' => $business->bookings()
                    ->whereBetween('booking_date', [$thisWeek, now()])
                    ->count(),
                'revenue' => $business->bookings()
                    ->whereBetween('booking_date', [$thisWeek, now()])
                    ->where('payment_status', 'paid')
                    ->sum('amount'),
                'cancellations' => $business->bookings()
                    ->whereBetween('cancelled_at', [$thisWeek, now()])
                    ->count(),
            ],
            'month' => [
                'bookings' => $business->bookings()
                    ->whereMonth('booking_date', now()->month)
                    ->whereYear('booking_date', now()->year)
                    ->count(),
                'revenue' => $business->bookings()
                    ->whereMonth('booking_date', now()->month)
                    ->whereYear('booking_date', now()->year)
                    ->where('payment_status', 'paid')
                    ->sum('amount'),
                'growth' => $this->calculateGrowth($business, $thisMonth, $lastMonth),
            ],
            'overall' => [
                'total_bookings' => $business->bookings()->count(),
                'total_revenue' => $business->bookings()
                    ->where('payment_status', 'paid')
                    ->sum('amount'),
                'average_rating' => $business->rating,
                'total_reviews' => $business->total_reviews,
            ]
        ];

        // Get upcoming bookings
        $upcomingBookings = $business->bookings()
            ->with(['user', 'service', 'staff'])
            ->upcoming()
            ->limit(10)
            ->get();

        // Get recent reviews
        $recentReviews = $business->reviews()
            ->with('user')
            ->latest()
            ->limit(5)
            ->get();

        // Get popular services
        $popularServices = $business->services()
            ->withCount('bookings')
            ->orderBy('bookings_count', 'desc')
            ->limit(5)
            ->get();

        // Get staff performance
        $staffPerformance = $business->staff()
            ->with(['bookings' => function ($query) use ($thisMonth) {
                $query->whereMonth('booking_date', now()->month)
                    ->whereYear('booking_date', now()->year);
            }])
            ->get()
            ->map(function ($staff) {
                return [
                    'id' => $staff->id,
                    'name' => $staff->name,
                    'bookings_count' => $staff->bookings->count(),
                    'revenue' => $staff->bookings->where('payment_status', 'paid')->sum('amount'),
                    'utilization_rate' => $this->calculateUtilizationRate($staff),
                ];
            });

        return $this->success([
            'business' => new BusinessResource($business),
            'stats' => $stats,
            'upcoming_bookings' => $upcomingBookings,
            'recent_reviews' => $recentReviews,
            'popular_services' => $popularServices,
            'staff_performance' => $staffPerformance,
            'notifications' => [
                'low_availability' => $this->checkLowAvailability($business),
                'pending_reviews' => $this->getPendingReviewsCount($business),
            ]
        ], 'Dashboard data retrieved successfully');
    }

    /**
     * Calculate growth percentage
     */
    private function calculateGrowth($business, $currentPeriod, $previousPeriod)
    {
        $currentRevenue = $business->bookings()
            ->whereBetween('booking_date', [$currentPeriod, now()])
            ->where('payment_status', 'paid')
            ->sum('amount');

        $previousRevenue = $business->bookings()
            ->whereBetween('booking_date', [$previousPeriod, $previousPeriod->copy()->endOfMonth()])
            ->where('payment_status', 'paid')
            ->sum('amount');

        if ($previousRevenue == 0) {
            return $currentRevenue > 0 ? 100 : 0;
        }

        return round((($currentRevenue - $previousRevenue) / $previousRevenue) * 100, 2);
    }

    /**
     * Calculate staff utilization rate
     */
    private function calculateUtilizationRate($staff)
    {
        // Implementation would calculate based on working hours vs booked hours
        return rand(60, 95); // Placeholder
    }

    /**
     * Check for low availability
     */
    private function checkLowAvailability($business)
    {
        // Check next 7 days availability
        $nextWeek = now()->addWeek();
        $totalSlots = 0;
        $bookedSlots = 0;

        // Implementation would check actual availability
        return $bookedSlots / $totalSlots > 0.8;
    }

    /**
     * Get pending reviews count
     */
    private function getPendingReviewsCount($business)
    {
        return $business->bookings()
            ->where('status', 'completed')
            ->whereDoesntHave('review')
            ->where('booking_date', '>', now()->subDays(30))
            ->count();
    }
}