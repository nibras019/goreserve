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
use Carbon\Carbon;

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
                'settings' => array_merge([
                    'currency' => $validated['currency'] ?? 'USD',
                    'timezone' => $validated['timezone'] ?? config('app.timezone'),
                    'booking_slot_duration' => $validated['booking_slot_duration'] ?? 30,
                    'advance_booking_days' => $validated['advance_booking_days'] ?? 30,
                    'min_advance_hours' => $validated['min_advance_hours'] ?? 2,
                    'cancellation_hours' => $validated['cancellation_hours'] ?? 24,
                    'auto_confirm_bookings' => $validated['auto_confirm_bookings'] ?? false,
                    'require_deposit' => $validated['require_deposit'] ?? false,
                    'deposit_percentage' => $validated['deposit_percentage'] ?? 0,
                ], $validated['settings'] ?? []),
                'status' => config('goreserve.business.auto_approve') ? 'approved' : 'pending'
            ]);

            // Handle logo upload
            if ($request->hasFile('logo')) {
                $business->addMediaFromRequest('logo')
                    ->toMediaCollection('logo');
            }

            // Handle gallery images
            if ($request->hasFile('gallery')) {
                foreach ($request->file('gallery') as $index => $image) {
                    if ($index < config('goreserve.business.max_images', 10)) {
                        $business->addMedia($image)
                            ->toMediaCollection('gallery');
                    }
                }
            }

            // Handle business documents
            if ($request->hasFile('documents')) {
                foreach ($request->file('documents') as $type => $document) {
                    DB::table('business_documents')->insert([
                        'business_id' => $business->id,
                        'type' => $type,
                        'file_path' => $document->store('business-documents/' . $business->id, 'private'),
                        'original_name' => $document->getClientOriginalName(),
                        'mime_type' => $document->getMimeType(),
                        'size' => $document->getSize(),
                        'uploaded_at' => now(),
                        'created_at' => now()
                    ]);
                }
            }

            DB::commit();

            // Clear cache
            Cache::tags(['businesses'])->flush();

            // Send notification to admin for approval
            if ($business->status === 'pending') {
                \App\Models\User::role('admin')->each(function ($admin) use ($business) {
                    $admin->notify(new \App\Notifications\NewBusinessRegistration($business));
                });
            }

            // Send welcome email to vendor
            $request->user()->notify(new \App\Notifications\BusinessRegistered($business));

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
            // Update basic information
            $business->update($validated);

            // Update settings if provided
            if (isset($validated['settings'])) {
                $settings = array_merge($business->settings ?? [], $validated['settings']);
                $business->update(['settings' => $settings]);
            }

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
                $currentGalleryCount = $business->getMedia('gallery')->count();
                $maxImages = config('goreserve.business.max_images', 10);
                
                foreach ($request->file('gallery') as $index => $image) {
                    if ($currentGalleryCount + $index < $maxImages) {
                        $business->addMedia($image)
                            ->toMediaCollection('gallery');
                    }
                }
            }

            DB::commit();

            // Clear cache
            Cache::forget("business_{$business->id}");
            Cache::tags(['businesses'])->flush();

            return $this->success(
                new BusinessResource($business->fresh()->load('media')),
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

        // Check if business is approved
        if ($business->status !== 'approved') {
            return $this->success([
                'business' => new BusinessResource($business),
                'status' => $business->status,
                'message' => 'Your business is pending approval'
            ], 'Business pending approval');
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
                'occupancy_rate' => $this->calculateOccupancyRate($business, $today, $today),
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
                'average_booking_value' => $business->bookings()
                    ->whereBetween('booking_date', [$thisWeek, now()])
                    ->where('payment_status', 'paid')
                    ->avg('amount'),
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
                'top_services' => $this->getTopServices($business, $thisMonth),
            ],
            'overall' => [
                'total_bookings' => $business->bookings()->count(),
                'total_revenue' => $business->bookings()
                    ->where('payment_status', 'paid')
                    ->sum('amount'),
                'average_rating' => $business->rating,
                'total_reviews' => $business->total_reviews,
                'response_rate' => $this->calculateResponseRate($business),
                'repeat_customer_rate' => $this->calculateRepeatCustomerRate($business),
            ],
        ];

        // Get upcoming bookings
        $upcomingBookings = $business->bookings()
            ->with(['user', 'service', 'staff'])
            ->upcoming()
            ->limit(10)
            ->get()
            ->map(function ($booking) {
                return [
                    'id' => $booking->id,
                    'booking_ref' => $booking->booking_ref,
                    'customer' => [
                        'name' => $booking->user->name,
                        'email' => $booking->user->email,
                        'phone' => $booking->user->phone,
                    ],
                    'service' => $booking->service->name,
                    'staff' => $booking->staff?->name,
                    'date' => $booking->booking_date->format('Y-m-d'),
                    'time' => $booking->start_time . ' - ' . $booking->end_time,
                    'amount' => $booking->amount,
                    'status' => $booking->status,
                ];
            });

        // Get recent reviews
        $recentReviews = $business->reviews()
            ->with('user')
            ->where('is_approved', true)
            ->latest()
            ->limit(5)
            ->get()
            ->map(function ($review) {
                return [
                    'id' => $review->id,
                    'customer' => $review->user->name,
                    'rating' => $review->rating,
                    'comment' => $review->comment,
                    'created_at' => $review->created_at->diffForHumans(),
                    'has_response' => !empty($review->business_response),
                ];
            });

        // Get popular services
        $popularServices = $business->services()
            ->withCount(['bookings' => function ($query) use ($thisMonth) {
                $query->whereMonth('booking_date', now()->month)
                    ->whereYear('booking_date', now()->year);
            }])
            ->withSum(['bookings as monthly_revenue' => function ($query) use ($thisMonth) {
                $query->whereMonth('booking_date', now()->month)
                    ->whereYear('booking_date', now()->year)
                    ->where('payment_status', 'paid');
            }], 'amount')
            ->orderBy('bookings_count', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($service) {
                return [
                    'id' => $service->id,
                    'name' => $service->name,
                    'bookings' => $service->bookings_count,
                    'revenue' => $service->monthly_revenue ?? 0,
                    'average_price' => $service->price,
                ];
            });

        // Get staff performance
        $staffPerformance = $business->staff()
            ->where('is_active', true)
            ->withCount(['bookings as monthly_bookings' => function ($query) {
                $query->whereMonth('booking_date', now()->month)
                    ->whereYear('booking_date', now()->year);
            }])
            ->withSum(['bookings as monthly_revenue' => function ($query) {
                $query->whereMonth('booking_date', now()->month)
                    ->whereYear('booking_date', now()->year)
                    ->where('payment_status', 'paid');
            }], 'amount')
            ->orderBy('monthly_bookings', 'desc')
            ->get()
            ->map(function ($staff) {
                return [
                    'id' => $staff->id,
                    'name' => $staff->name,
                    'bookings' => $staff->monthly_bookings ?? 0,
                    'revenue' => $staff->monthly_revenue ?? 0,
                    'utilization_rate' => $this->calculateStaffUtilization($staff),
                    'is_available_today' => $this->isStaffAvailableToday($staff),
                ];
            });

        // Get notifications and alerts
        $notifications = $this->getBusinessNotifications($business);

        // Get quick actions
        $quickActions = $this->getQuickActions($business);

        // Get revenue chart data
        $revenueChart = $this->getRevenueChartData($business, 30);

        // Get booking trends
        $bookingTrends = $this->getBookingTrends($business, 7);

        return $this->success([
            'business' => new BusinessResource($business),
            'stats' => $stats,
            'upcoming_bookings' => $upcomingBookings,
            'recent_reviews' => $recentReviews,
            'popular_services' => $popularServices,
            'staff_performance' => $staffPerformance,
            'notifications' => $notifications,
            'quick_actions' => $quickActions,
            'charts' => [
                'revenue' => $revenueChart,
                'bookings' => $bookingTrends,
            ],
            'last_updated' => now()->toDateTimeString(),
        ], 'Dashboard data retrieved successfully');
    }

    /**
     * Update business hours
     */
    public function updateHours(Request $request)
    {
        $business = $request->user()->business;

        if (!$business) {
            return $this->error('No business found', 404);
        }

        $validated = $request->validate([
            'working_hours' => 'required|array',
            'working_hours.monday' => 'nullable|array',
            'working_hours.monday.open' => 'required_with:working_hours.monday|date_format:H:i',
            'working_hours.monday.close' => 'required_with:working_hours.monday|date_format:H:i|after:working_hours.monday.open',
            'working_hours.tuesday' => 'nullable|array',
            'working_hours.tuesday.open' => 'required_with:working_hours.tuesday|date_format:H:i',
            'working_hours.tuesday.close' => 'required_with:working_hours.tuesday|date_format:H:i|after:working_hours.tuesday.open',
            'working_hours.wednesday' => 'nullable|array',
            'working_hours.wednesday.open' => 'required_with:working_hours.wednesday|date_format:H:i',
            'working_hours.wednesday.close' => 'required_with:working_hours.wednesday|date_format:H:i|after:working_hours.wednesday.open',
            'working_hours.thursday' => 'nullable|array',
            'working_hours.thursday.open' => 'required_with:working_hours.thursday|date_format:H:i',
            'working_hours.thursday.close' => 'required_with:working_hours.thursday|date_format:H:i|after:working_hours.thursday.open',
            'working_hours.friday' => 'nullable|array',
            'working_hours.friday.open' => 'required_with:working_hours.friday|date_format:H:i',
            'working_hours.friday.close' => 'required_with:working_hours.friday|date_format:H:i|after:working_hours.friday.open',
            'working_hours.saturday' => 'nullable|array',
            'working_hours.saturday.open' => 'required_with:working_hours.saturday|date_format:H:i',
            'working_hours.saturday.close' => 'required_with:working_hours.saturday|date_format:H:i|after:working_hours.saturday.open',
            'working_hours.sunday' => 'nullable|array',
            'working_hours.sunday.open' => 'required_with:working_hours.sunday|date_format:H:i',
            'working_hours.sunday.close' => 'required_with:working_hours.sunday|date_format:H:i|after:working_hours.sunday.open',
            'special_hours' => 'nullable|array',
            'special_hours.*.date' => 'required|date|after_or_equal:today',
            'special_hours.*.open' => 'nullable|date_format:H:i',
            'special_hours.*.close' => 'nullable|date_format:H:i|after:special_hours.*.open',
            'special_hours.*.closed' => 'nullable|boolean',
        ]);

        DB::beginTransaction();

        try {
            $business->update(['working_hours' => $validated['working_hours']]);

            // Handle special hours (holidays, special days)
            if (!empty($validated['special_hours'])) {
                foreach ($validated['special_hours'] as $specialHour) {
                    DB::table('business_special_hours')->updateOrInsert(
                        [
                            'business_id' => $business->id,
                            'date' => $specialHour['date']
                        ],
                        [
                            'open' => $specialHour['open'] ?? null,
                            'close' => $specialHour['close'] ?? null,
                            'is_closed' => $specialHour['closed'] ?? false,
                            'updated_at' => now()
                        ]
                    );
                }
            }

            DB::commit();

            // Clear cache
            Cache::forget("business_hours_{$business->id}");

            return $this->success([
                'working_hours' => $business->working_hours,
                'special_hours' => DB::table('business_special_hours')
                    ->where('business_id', $business->id)
                    ->where('date', '>=', today())
                    ->orderBy('date')
                    ->get()
            ], 'Business hours updated successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->error(
                'Failed to update business hours',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Update business settings
     */
    public function updateSettings(Request $request)
    {
        $business = $request->user()->business;

        if (!$business) {
            return $this->error('No business found', 404);
        }

        $validated = $request->validate([
            'booking_slot_duration' => 'nullable|integer|in:15,30,45,60',
            'advance_booking_days' => 'nullable|integer|min:1|max:365',
            'min_advance_hours' => 'nullable|integer|min:0|max:168',
            'cancellation_hours' => 'nullable|integer|min:0|max:168',
            'auto_confirm_bookings' => 'nullable|boolean',
            'require_deposit' => 'nullable|boolean',
            'deposit_percentage' => 'nullable|integer|min:0|max:100',
            'allow_walk_ins' => 'nullable|boolean',
            'allow_waitlist' => 'nullable|boolean',
            'max_bookings_per_day' => 'nullable|integer|min:1',
            'currency' => 'nullable|string|size:3',
            'timezone' => 'nullable|timezone',
            'notification_settings' => 'nullable|array',
            'notification_settings.email' => 'nullable|boolean',
            'notification_settings.sms' => 'nullable|boolean',
            'notification_settings.push' => 'nullable|boolean',
            'booking_reminders' => 'nullable|array',
            'booking_reminders.enabled' => 'nullable|boolean',
            'booking_reminders.hours_before' => 'nullable|integer|min:1|max:72',
            'social_links' => 'nullable|array',
            'social_links.facebook' => 'nullable|url',
            'social_links.instagram' => 'nullable|url',
            'social_links.twitter' => 'nullable|url',
            'social_links.website' => 'nullable|url',
        ]);

        $settings = array_merge($business->settings ?? [], $validated);
        $business->update(['settings' => $settings]);

        // Clear cache
        Cache::forget("business_settings_{$business->id}");

        return $this->success([
            'settings' => $settings
        ], 'Settings updated successfully');
    }

    /**
     * Get business statistics
     */
    public function statistics(Request $request)
    {
        $business = $request->user()->business;

        if (!$business) {
            return $this->error('No business found', 404);
        }

        $validated = $request->validate([
            'period' => 'nullable|string|in:today,week,month,quarter,year,custom',
            'date_from' => 'required_if:period,custom|date',
            'date_to' => 'required_if:period,custom|date|after_or_equal:date_from',
            'compare' => 'nullable|boolean',
        ]);

        $period = $validated['period'] ?? 'month';
        $dateRange = $this->getDateRange($period, $validated['date_from'] ?? null, $validated['date_to'] ?? null);
        $previousRange = $this->getPreviousDateRange($dateRange);

        $stats = [
            'revenue' => [
                'current' => $this->getRevenueStats($business, $dateRange),
                'previous' => $request->boolean('compare') ? $this->getRevenueStats($business, $previousRange) : null,
            ],
            'bookings' => [
                'current' => $this->getBookingStats($business, $dateRange),
                'previous' => $request->boolean('compare') ? $this->getBookingStats($business, $previousRange) : null,
            ],
            'customers' => [
                'current' => $this->getCustomerStats($business, $dateRange),
                'previous' => $request->boolean('compare') ? $this->getCustomerStats($business, $previousRange) : null,
            ],
            'services' => $this->getServiceStats($business, $dateRange),
            'staff' => $this->getStaffStats($business, $dateRange),
            'peak_times' => $this->getPeakTimes($business, $dateRange),
            'cancellation_analysis' => $this->getCancellationAnalysis($business, $dateRange),
        ];

        return $this->success([
            'period' => $period,
            'date_range' => [
                'start' => $dateRange['start']->format('Y-m-d'),
                'end' => $dateRange['end']->format('Y-m-d'),
            ],
            'stats' => $stats,
            'generated_at' => now()->toDateTimeString(),
        ], 'Statistics retrieved successfully');
    }

    /**
     * Get business insights
     */
    public function insights(Request $request)
    {
        $business = $request->user()->business;

        if (!$business) {
            return $this->error('No business found', 404);
        }

        $insights = [
            'performance_score' => $this->calculatePerformanceScore($business),
            'opportunities' => $this->identifyOpportunities($business),
            'recommendations' => $this->generateRecommendations($business),
            'competitor_analysis' => $this->getCompetitorAnalysis($business),
            'customer_insights' => $this->getCustomerInsights($business),
            'revenue_forecast' => $this->generateRevenueForecast($business),
        ];

        return $this->success($insights, 'Business insights generated successfully');
    }

    /**
     * Helper methods
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

    private function calculateOccupancyRate($business, $startDate, $endDate)
    {
        $totalSlots = $this->calculateTotalSlots($business, $startDate, $endDate);
        $bookedSlots = $business->bookings()
            ->whereBetween('booking_date', [$startDate, $endDate])
            ->whereIn('status', ['confirmed', 'completed'])
            ->count();

        return $totalSlots > 0 ? round(($bookedSlots / $totalSlots) * 100, 2) : 0;
    }

    private function calculateTotalSlots($business, $startDate, $endDate)
    {
        $slots = 0;
        $slotDuration = $business->settings['booking_slot_duration'] ?? 30;
        
        $current = Carbon::parse($startDate);
        while ($current <= $endDate) {
            $dayOfWeek = strtolower($current->format('l'));
            $workingHours = $business->working_hours[$dayOfWeek] ?? null;
            
            if ($workingHours && isset($workingHours['open']) && isset($workingHours['close'])) {
                $open = Carbon::parse($current->format('Y-m-d') . ' ' . $workingHours['open']);
                $close = Carbon::parse($current->format('Y-m-d') . ' ' . $workingHours['close']);
                $dailySlots = $open->diffInMinutes($close) / $slotDuration;
                $slots += $dailySlots * $business->staff()->where('is_active', true)->count();
            }
            
            $current->addDay();
        }
        
        return $slots;
    }

    private function calculateResponseRate($business)
    {
        $totalReviews = $business->reviews()->count();
        $respondedReviews = $business->reviews()->whereNotNull('business_response')->count();
        
        return $totalReviews > 0 ? round(($respondedReviews / $totalReviews) * 100, 2) : 0;
    }

    private function calculateRepeatCustomerRate($business)
    {
        $totalCustomers = $business->bookings()->distinct('user_id')->count('user_id');
        $repeatCustomers = $business->bookings()
            ->selectRaw('user_id, COUNT(*) as booking_count')
            ->groupBy('user_id')
            ->having('booking_count', '>', 1)
            ->count();
        
        return $totalCustomers > 0 ? round(($repeatCustomers / $totalCustomers) * 100, 2) : 0;
    }

    private function getTopServices($business, $startDate)
    {
        return $business->services()
            ->withCount(['bookings' => function ($query) use ($startDate) {
                $query->where('booking_date', '>=', $startDate);
            }])
            ->orderBy('bookings_count', 'desc')
            ->limit(3)
            ->pluck('name', 'bookings_count');
    }

    private function calculateStaffUtilization($staff)
    {
        $totalWorkingHours = 0;
        $bookedHours = 0;
        
        // Calculate for current month
        $startDate = now()->startOfMonth();
        $endDate = now();
        
        $current = $startDate->copy();
        while ($current <= $endDate) {
            $dayOfWeek = strtolower($current->format('l'));
            $workingHours = $staff->working_hours[$dayOfWeek] ?? null;
            
            if ($workingHours && isset($workingHours['open']) && isset($workingHours['close'])) {
                $open = Carbon::parse($current->format('Y-m-d') . ' ' . $workingHours['open']);
                $close = Carbon::parse($current->format('Y-m-d') . ' ' . $workingHours['close']);
                $totalWorkingHours += $open->diffInHours($close);
            }
            
            $current->addDay();
        }
        
        $bookings = $staff->bookings()
            ->whereBetween('booking_date', [$startDate, $endDate])
            ->whereIn('status', ['confirmed', 'completed'])
            ->get();
        
        foreach ($bookings as $booking) {
            $start = Carbon::parse($booking->booking_date->format('Y-m-d') . ' ' . $booking->start_time);
            $end = Carbon::parse($booking->booking_date->format('Y-m-d') . ' ' . $booking->end_time);
            $bookedHours += $start->diffInHours($end);
        }
        
        return $totalWorkingHours > 0 ? round(($bookedHours / $totalWorkingHours) * 100, 2) : 0;
    }

    private function isStaffAvailableToday($staff)
    {
        $today = now();
        $dayOfWeek = strtolower($today->format('l'));
        
        // Check working hours
        if (!isset($staff->working_hours[$dayOfWeek])) {
            return false;
        }
        
        // Check for day off or vacation
        $hasAvailabilityBlock = DB::table('staff_availabilities')
            ->where('staff_id', $staff->id)
            ->where('date', $today->format('Y-m-d'))
            ->whereIn('type', ['vacation', 'sick', 'blocked'])
            ->exists();
            
        return !$hasAvailabilityBlock;
    }

    private function getBusinessNotifications($business)
    {
        $notifications = [];

        // Low availability warning
        $tomorrowAvailability = $this->calculateOccupancyRate(
            $business,
            now()->addDay()->startOfDay(),
            now()->addDay()->endOfDay()
        );
        
        if ($tomorrowAvailability > 80) {
            $notifications[] = [
                'type' => 'warning',
                'title' => 'High Demand Tomorrow',
                'message' => 'Tomorrow is ' . $tomorrowAvailability . '% booked. Consider opening additional slots.',
                'action' => 'manage_availability'
            ];
        }

        // Pending reviews
        $pendingReviews = $business->bookings()
            ->where('status', 'completed')
            ->whereDoesntHave('review')
            ->where('booking_date', '>', now()->subDays(30))
            ->count();
            
        if ($pendingReviews > 5) {
            $notifications[] = [
                'type' => 'info',
                'title' => 'Reviews Awaiting Response',
                'message' => 'You have ' . $pendingReviews . ' completed bookings without reviews.',
                'action' => 'request_reviews'
            ];
        }

        // Document expiry
        $expiringDocuments = DB::table('business_documents')
            ->where('business_id', $business->id)
            ->where('expires_at', '<=', now()->addDays(30))
            ->where('expires_at', '>', now())
            ->count();
            
        if ($expiringDocuments > 0) {
            $notifications[] = [
                'type' => 'urgent',
                'title' => 'Documents Expiring Soon',
                'message' => $expiringDocuments . ' business documents will expire within 30 days.',
                'action' => 'update_documents'
            ];
        }

        // Performance insights
        $lastMonthRevenue = $business->bookings()
            ->whereMonth('booking_date', now()->subMonth()->month)
            ->where('payment_status', 'paid')
            ->sum('amount');
            
        $thisMonthRevenue = $business->bookings()
            ->whereMonth('booking_date', now()->month)
            ->where('payment_status', 'paid')
            ->sum('amount');
            
        if ($lastMonthRevenue > 0 && $thisMonthRevenue < $lastMonthRevenue * 0.8) {
            $notifications[] = [
                'type' => 'alert',
                'title' => 'Revenue Down',
                'message' => 'Revenue is down ' . round((1 - $thisMonthRevenue / $lastMonthRevenue) * 100) . '% compared to last month.',
                'action' => 'view_insights'
            ];
        }

        return $notifications;
    }

    private function getQuickActions($business)
    {
        $actions = [];

        // Add service
        if ($business->services()->count() < 5) {
            $actions[] = [
                'icon' => 'plus-circle',
                'title' => 'Add New Service',
                'description' => 'Expand your service offerings',
                'route' => 'vendor.services.create'
            ];
        }

        // Add staff
        if ($business->staff()->where('is_active', true)->count() < 3) {
            $actions[] = [
                'icon' => 'user-plus',
                'title' => 'Add Staff Member',
                'description' => 'Increase your capacity',
                'route' => 'vendor.staff.create'
            ];
        }

        // Respond to reviews
        $unrespondedReviews = $business->reviews()
            ->whereNull('business_response')
            ->count();
            
        if ($unrespondedReviews > 0) {
            $actions[] = [
                'icon' => 'message-circle',
                'title' => 'Respond to Reviews',
                'description' => $unrespondedReviews . ' reviews need response',
                'route' => 'vendor.reviews.index'
            ];
        }

        // Update photos
        if ($business->getMedia('gallery')->count() < 5) {
            $actions[] = [
                'icon' => 'camera',
                'title' => 'Add Photos',
                'description' => 'Showcase your business',
                'route' => 'vendor.business.media'
            ];
        }

        return $actions;
    }

    private function getRevenueChartData($business, $days)
    {
        $data = [];
        
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $revenue = $business->bookings()
                ->whereDate('booking_date', $date)
                ->where('payment_status', 'paid')
                ->sum('amount');
                
            $data[] = [
                'date' => $date->format('Y-m-d'),
                'label' => $date->format('M d'),
                'revenue' => $revenue
            ];
        }
        
        return $data;
    }

    private function getBookingTrends($business, $days)
    {
        $data = [];
        
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $bookings = $business->bookings()
                ->whereDate('booking_date', $date)
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status');
                
            $data[] = [
                'date' => $date->format('Y-m-d'),
                'label' => $date->format('D'),
                'confirmed' => $bookings->get('confirmed', 0),
                'completed' => $bookings->get('completed', 0),
                'cancelled' => $bookings->get('cancelled', 0),
                'total' => $bookings->sum()
            ];
        }
        
        return $data;
    }

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

    private function getPreviousDateRange($currentRange)
    {
        $diff = $currentRange['start']->diffInDays($currentRange['end']);
        
        return [
            'start' => $currentRange['start']->copy()->subDays($diff + 1),
            'end' => $currentRange['start']->copy()->subDay()
        ];
    }

    private function getRevenueStats($business, $dateRange)
    {
        $revenue = $business->bookings()
            ->whereBetween('booking_date', [$dateRange['start'], $dateRange['end']])
            ->where('payment_status', 'paid')
            ->sum('amount');
            
        $bookingCount = $business->bookings()
            ->whereBetween('booking_date', [$dateRange['start'], $dateRange['end']])
            ->where('payment_status', 'paid')
            ->count();
            
        return [
            'total' => $revenue,
            'average_per_booking' => $bookingCount > 0 ? round($revenue / $bookingCount, 2) : 0,
            'booking_count' => $bookingCount,
            'daily_average' => round($revenue / max($dateRange['start']->diffInDays($dateRange['end']), 1), 2)
        ];
    }

    private function getBookingStats($business, $dateRange)
    {
        $bookings = $business->bookings()
            ->whereBetween('booking_date', [$dateRange['start'], $dateRange['end']])
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');
            
        $total = $bookings->sum();
        
        return [
            'total' => $total,
            'confirmed' => $bookings->get('confirmed', 0),
            'completed' => $bookings->get('completed', 0),
            'cancelled' => $bookings->get('cancelled', 0),
            'no_show' => $bookings->get('no_show', 0),
            'completion_rate' => $total > 0 ? round(($bookings->get('completed', 0) / $total) * 100, 2) : 0,
            'cancellation_rate' => $total > 0 ? round(($bookings->get('cancelled', 0) / $total) * 100, 2) : 0,
        ];
    }

    private function getCustomerStats($business, $dateRange)
    {
        $totalCustomers = $business->bookings()
            ->whereBetween('booking_date', [$dateRange['start'], $dateRange['end']])
            ->distinct('user_id')
            ->count('user_id');
            
        $newCustomers = $business->bookings()
            ->whereBetween('booking_date', [$dateRange['start'], $dateRange['end']])
            ->whereDoesntHave('user.bookings', function ($query) use ($business, $dateRange) {
                $query->where('business_id', $business->id)
                    ->where('booking_date', '<', $dateRange['start']);
            })
            ->distinct('user_id')
            ->count('user_id');
            
        $repeatCustomers = $totalCustomers - $newCustomers;
        
        return [
            'total' => $totalCustomers,
            'new' => $newCustomers,
            'repeat' => $repeatCustomers,
            'repeat_rate' => $totalCustomers > 0 ? round(($repeatCustomers / $totalCustomers) * 100, 2) : 0,
        ];
    }

    private function getServiceStats($business, $dateRange)
    {
        return $business->services()
            ->withCount(['bookings' => function ($query) use ($dateRange) {
                $query->whereBetween('booking_date', [$dateRange['start'], $dateRange['end']]);
            }])
            ->withSum(['bookings as revenue' => function ($query) use ($dateRange) {
                $query->whereBetween('booking_date', [$dateRange['start'], $dateRange['end']])
                    ->where('payment_status', 'paid');
            }], 'amount')
            ->orderBy('revenue', 'desc')
            ->get()
            ->map(function ($service) {
                return [
                    'id' => $service->id,
                    'name' => $service->name,
                    'bookings' => $service->bookings_count,
                    'revenue' => $service->revenue ?? 0,
                    'average_price' => $service->bookings_count > 0 
                        ? round(($service->revenue ?? 0) / $service->bookings_count, 2) 
                        : $service->price,
                ];
            });
    }

    private function getStaffStats($business, $dateRange)
    {
        return $business->staff()
            ->withCount(['bookings' => function ($query) use ($dateRange) {
                $query->whereBetween('booking_date', [$dateRange['start'], $dateRange['end']]);
            }])
            ->withSum(['bookings as revenue' => function ($query) use ($dateRange) {
                $query->whereBetween('booking_date', [$dateRange['start'], $dateRange['end']])
                    ->where('payment_status', 'paid');
            }], 'amount')
            ->withAvg(['bookings.review as rating' => function ($query) use ($dateRange) {
                $query->whereBetween('booking_date', [$dateRange['start'], $dateRange['end']])
                    ->whereHas('review');
            }], 'rating')
            ->get()
            ->map(function ($staff) {
                return [
                    'id' => $staff->id,
                    'name' => $staff->name,
                    'bookings' => $staff->bookings_count,
                    'revenue' => $staff->revenue ?? 0,
                    'rating' => round($staff->rating ?? 0, 1),
                    'utilization' => $this->calculateStaffUtilization($staff),
                ];
            });
    }

    private function getPeakTimes($business, $dateRange)
    {
        $bookings = $business->bookings()
            ->whereBetween('booking_date', [$dateRange['start'], $dateRange['end']])
            ->whereIn('status', ['confirmed', 'completed'])
            ->get();
            
        $hourlyDistribution = [];
        $dayDistribution = [];
        
        foreach ($bookings as $booking) {
            $hour = Carbon::parse($booking->start_time)->format('H');
            $day = $booking->booking_date->format('l');
            
            $hourlyDistribution[$hour] = ($hourlyDistribution[$hour] ?? 0) + 1;
            $dayDistribution[$day] = ($dayDistribution[$day] ?? 0) + 1;
        }
        
        arsort($hourlyDistribution);
        arsort($dayDistribution);
        
        return [
            'peak_hours' => array_slice($hourlyDistribution, 0, 5, true),
            'peak_days' => array_slice($dayDistribution, 0, 7, true),
        ];
    }

    private function getCancellationAnalysis($business, $dateRange)
    {
        $cancellations = $business->bookings()
            ->whereBetween('booking_date', [$dateRange['start'], $dateRange['end']])
            ->where('status', 'cancelled')
            ->get();
            
        $byNotice = [
            'last_minute' => 0, // < 2 hours
            'same_day' => 0,    // 2-24 hours
            'advance' => 0,     // > 24 hours
        ];
        
        foreach ($cancellations as $cancellation) {
            if (!$cancellation->cancelled_at) continue;
            
            $bookingDateTime = Carbon::parse($cancellation->booking_date->format('Y-m-d') . ' ' . $cancellation->start_time);
            $hoursNotice = $cancellation->cancelled_at->diffInHours($bookingDateTime);
            
            if ($hoursNotice < 2) {
                $byNotice['last_minute']++;
            } elseif ($hoursNotice < 24) {
                $byNotice['same_day']++;
            } else {
                $byNotice['advance']++;
            }
        }
        
        return [
            'total' => $cancellations->count(),
            'by_notice_period' => $byNotice,
            'reasons' => $cancellations->groupBy('cancellation_reason')->map->count()->take(5),
        ];
    }

    private function calculatePerformanceScore($business)
    {
        $score = 0;
        $weights = [
            'completion_rate' => 30,
            'rating' => 25,
            'response_rate' => 20,
            'occupancy_rate' => 15,
            'repeat_customer_rate' => 10,
        ];
        
        // Completion rate (0-30 points)
        $completionRate = $this->getBookingStats($business, $this->getDateRange('month'))['completion_rate'];
        $score += ($completionRate / 100) * $weights['completion_rate'];
        
        // Rating (0-25 points)
        $rating = $business->rating ?? 0;
        $score += ($rating / 5) * $weights['rating'];
        
        // Response rate (0-20 points)
        $responseRate = $this->calculateResponseRate($business);
        $score += ($responseRate / 100) * $weights['response_rate'];
        
        // Occupancy rate (0-15 points)
        $occupancyRate = $this->calculateOccupancyRate($business, now()->startOfMonth(), now());
        $score += min(($occupancyRate / 80) * $weights['occupancy_rate'], $weights['occupancy_rate']);
        
        // Repeat customer rate (0-10 points)
        $repeatRate = $this->calculateRepeatCustomerRate($business);
        $score += ($repeatRate / 100) * $weights['repeat_customer_rate'];
        
        return round($score);
    }

    private function identifyOpportunities($business)
    {
        $opportunities = [];
        
        // Low utilization times
        $peakTimes = $this->getPeakTimes($business, $this->getDateRange('month'));
        $allHours = range(9, 18);
        $lowUtilizationHours = array_diff($allHours, array_keys($peakTimes['peak_hours']));
        
        if (count($lowUtilizationHours) > 3) {
            $opportunities[] = [
                'type' => 'pricing',
                'title' => 'Off-Peak Pricing Opportunity',
                'description' => 'Consider offering discounts during low-demand hours to increase bookings',
                'potential_impact' => 'high',
                'data' => ['hours' => array_values($lowUtilizationHours)]
            ];
        }
        
        // Service expansion
        $topServices = $this->getServiceStats($business, $this->getDateRange('month'));
        if ($topServices->count() > 0 && $topServices->first()['bookings'] > 20) {
            $opportunities[] = [
                'type' => 'service',
                'title' => 'Popular Service Expansion',
                'description' => 'Your top service "' . $topServices->first()['name'] . '" is in high demand. Consider adding similar services.',
                'potential_impact' => 'medium',
                'data' => ['service' => $topServices->first()['name']]
            ];
        }
        
        // Customer retention
        $customerStats = $this->getCustomerStats($business, $this->getDateRange('month'));
        if ($customerStats['repeat_rate'] < 30) {
            $opportunities[] = [
                'type' => 'retention',
                'title' => 'Improve Customer Retention',
                'description' => 'Your repeat customer rate is below average. Consider implementing a loyalty program.',
                'potential_impact' => 'high',
                'data' => ['current_rate' => $customerStats['repeat_rate']]
            ];
        }
        
        return $opportunities;
    }

    private function generateRecommendations($business)
    {
        $recommendations = [];
        
        // Based on performance score
        $performanceScore = $this->calculatePerformanceScore($business);
        
        if ($performanceScore < 60) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'performance',
                'title' => 'Focus on Service Quality',
                'actions' => [
                    'Train staff on customer service excellence',
                    'Implement quality control measures',
                    'Follow up with customers after service'
                ]
            ];
        }
        
        // Based on reviews
        if ($business->total_reviews < 10) {
            $recommendations[] = [
                'priority' => 'medium',
                'category' => 'marketing',
                'title' => 'Encourage Customer Reviews',
                'actions' => [
                    'Send review requests after completed bookings',
                    'Offer incentives for honest reviews',
                    'Make review process simple and quick'
                ]
            ];
        }
        
        // Based on booking patterns
        $cancellationAnalysis = $this->getCancellationAnalysis($business, $this->getDateRange('month'));
        if ($cancellationAnalysis['by_notice_period']['last_minute'] > 5) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'operations',
                'title' => 'Reduce Last-Minute Cancellations',
                'actions' => [
                    'Send booking reminders 24 hours before',
                    'Implement cancellation fees for short notice',
                    'Confirm bookings 2 hours before appointment'
                ]
            ];
        }
        
        return $recommendations;
    }

    private function getCompetitorAnalysis($business)
    {
        // Get similar businesses in the area
        $competitors = Business::where('type', $business->type)
            ->where('status', 'approved')
            ->where('id', '!=', $business->id)
            ->when($business->latitude && $business->longitude, function ($query) use ($business) {
                $query->nearby($business->latitude, $business->longitude, 5);
            })
            ->withAvg('reviews', 'rating')
            ->withCount('bookings')
            ->get();
            
        $avgRating = $competitors->avg('reviews_avg_rating') ?? 0;
        $avgBookings = $competitors->avg('bookings_count') ?? 0;
        
        return [
            'market_position' => [
                'rating_vs_average' => $business->rating - $avgRating,
                'bookings_vs_average' => $business->bookings()->count() - $avgBookings,
                'price_position' => $this->analyzePricePosition($business, $competitors),
            ],
            'competitive_advantages' => $this->identifyCompetitiveAdvantages($business, $competitors),
            'improvement_areas' => $this->identifyImprovementAreas($business, $competitors),
        ];
    }

    private function analyzePricePosition($business, $competitors)
    {
        $avgPrice = $business->services()->avg('price');
        $competitorAvgPrices = [];
        
        foreach ($competitors as $competitor) {
            $competitorAvgPrices[] = $competitor->services()->avg('price') ?? 0;
        }
        
        $marketAvgPrice = count($competitorAvgPrices) > 0 ? array_sum($competitorAvgPrices) / count($competitorAvgPrices) : 0;
        
        if ($avgPrice > $marketAvgPrice * 1.2) {
            return 'premium';
        } elseif ($avgPrice < $marketAvgPrice * 0.8) {
            return 'budget';
        } else {
            return 'competitive';
        }
    }

    private function identifyCompetitiveAdvantages($business, $competitors)
    {
        $advantages = [];
        
        // Rating advantage
        $avgRating = $competitors->avg('reviews_avg_rating') ?? 0;
        if ($business->rating > $avgRating + 0.5) {
            $advantages[] = 'Superior customer satisfaction';
        }
        
        // Service variety
        $avgServices = $competitors->avg(function ($competitor) {
            return $competitor->services()->count();
        });
        if ($business->services()->count() > $avgServices * 1.5) {
            $advantages[] = 'Wide range of services';
        }
        
        // Operating hours
        $businessHours = count(array_filter($business->working_hours, fn($h) => isset($h['open'])));
        if ($businessHours >= 7) {
            $advantages[] = 'Extended operating hours';
        }
        
        return $advantages;
    }

    private function identifyImprovementAreas($business, $competitors)
    {
        $areas = [];
        
        // Online presence
        if (!$business->settings['social_links']['website'] ?? false) {
            $areas[] = 'Create a professional website';
        }
        
        // Photo gallery
        if ($business->getMedia('gallery')->count() < 5) {
            $areas[] = 'Add more photos to showcase your business';
        }
        
        // Response time
        $responseRate = $this->calculateResponseRate($business);
        if ($responseRate < 80) {
            $areas[] = 'Improve review response rate';
        }
        
        return $areas;
    }

    private function getCustomerInsights($business)
    {
        $customers = $business->bookings()
            ->with('user')
            ->whereMonth('booking_date', now()->month)
            ->get()
            ->groupBy('user_id');
            
        return [
            'demographics' => $this->analyzeCustomerDemographics($customers),
            'behavior_patterns' => $this->analyzeCustomerBehavior($customers),
            'preferences' => $this->analyzeCustomerPreferences($business),
            'lifetime_value' => $this->calculateCustomerLifetimeValue($business),
        ];
    }

    private function analyzeCustomerDemographics($customers)
    {
        // This would analyze customer age groups, locations, etc.
        // For now, return sample data
        return [
            'total_unique' => $customers->count(),
            'average_bookings_per_customer' => round($customers->map->count()->avg(), 1),
        ];
    }

    private function analyzeCustomerBehavior($customers)
    {
        return [
            'booking_frequency' => [
                'once' => $customers->filter(fn($bookings) => $bookings->count() === 1)->count(),
                'occasional' => $customers->filter(fn($bookings) => $bookings->count() >= 2 && $bookings->count() <= 3)->count(),
                'regular' => $customers->filter(fn($bookings) => $bookings->count() > 3)->count(),
            ],
            'preferred_booking_method' => 'online', // Would analyze actual booking sources
            'average_lead_time' => '3 days', // Would calculate actual average
        ];
    }

    private function analyzeCustomerPreferences($business)
    {
        $bookings = $business->bookings()
            ->whereMonth('booking_date', now()->month)
            ->with(['service', 'staff'])
            ->get();
            
        return [
            'top_services' => $bookings->groupBy('service.name')->map->count()->sortDesc()->take(3),
            'preferred_staff' => $bookings->whereNotNull('staff_id')->groupBy('staff.name')->map->count()->sortDesc()->take(3),
            'preferred_times' => $bookings->groupBy(function ($booking) {
                return Carbon::parse($booking->start_time)->format('H:00');
            })->map->count()->sortDesc()->take(3),
        ];
    }

    private function calculateCustomerLifetimeValue($business)
    {
        $customers = $business->bookings()
            ->selectRaw('user_id, COUNT(*) as booking_count, SUM(amount) as total_spent')
            ->where('payment_status', 'paid')
            ->groupBy('user_id')
            ->get();
            
        return [
            'average' => round($customers->avg('total_spent'), 2),
            'median' => round($customers->median('total_spent'), 2),
            'top_10_percent' => round($customers->sortByDesc('total_spent')->take($customers->count() * 0.1)->avg('total_spent'), 2),
        ];
    }

    private function generateRevenueForecast($business)
    {
        // Get historical data
        $historicalRevenue = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $revenue = $business->bookings()
                ->whereMonth('booking_date', $month->month)
                ->whereYear('booking_date', $month->year)
                ->where('payment_status', 'paid')
                ->sum('amount');
            $historicalRevenue[] = $revenue;
        }
        
        // Simple trend analysis
        $avgGrowthRate = 0;
        for ($i = 1; $i < count($historicalRevenue); $i++) {
            if ($historicalRevenue[$i - 1] > 0) {
                $avgGrowthRate += ($historicalRevenue[$i] - $historicalRevenue[$i - 1]) / $historicalRevenue[$i - 1];
            }
        }
        $avgGrowthRate = $avgGrowthRate / (count($historicalRevenue) - 1);
        
        // Forecast next 3 months
        $forecast = [];
        $lastRevenue = end($historicalRevenue);
        
        for ($i = 1; $i <= 3; $i++) {
            $forecastRevenue = $lastRevenue * (1 + $avgGrowthRate);
            $forecast[] = [
                'month' => now()->addMonths($i)->format('F Y'),
                'estimated_revenue' => round($forecastRevenue, 2),
                'confidence' => 'medium', // Would implement confidence calculation
            ];
            $lastRevenue = $forecastRevenue;
        }
        
        return [
            'historical_trend' => $historicalRevenue,
            'growth_rate' => round($avgGrowthRate * 100, 2),
            'forecast' => $forecast,
            'factors' => [
                'seasonal_adjustment' => 'Not applied', // Would implement seasonal adjustments
                'market_trends' => 'Stable', // Would analyze market trends
            ],
        ];
    }
}
