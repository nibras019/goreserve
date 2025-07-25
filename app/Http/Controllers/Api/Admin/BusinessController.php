<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Http\Resources\BusinessResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class BusinessController extends Controller
{
    /**
     * Get all businesses
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => 'nullable|string|in:pending,approved,suspended',
            'type' => 'nullable|string|in:salon,spa,hotel,restaurant,other',
            'search' => 'nullable|string',
            'has_violations' => 'nullable|boolean',
            'sort_by' => 'nullable|string|in:name,created_at,rating,revenue,bookings',
            'sort_order' => 'nullable|string|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        $query = Business::with(['owner', 'media']);

        // Filter by status
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        // Filter by type
        if (!empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        // Search
        if (!empty($validated['search'])) {
            $query->where(function ($q) use ($validated) {
                $q->where('name', 'like', "%{$validated['search']}%")
                  ->orWhere('email', 'like', "%{$validated['search']}%")
                  ->orWhere('phone', 'like', "%{$validated['search']}%")
                  ->orWhereHas('owner', function ($oq) use ($validated) {
                      $oq->where('name', 'like', "%{$validated['search']}%");
                  });
            });
        }

        // Filter by violations
        if (isset($validated['has_violations'])) {
            if ($validated['has_violations']) {
                $query->whereHas('violations');
            } else {
                $query->doesntHave('violations');
            }
        }

        // Add statistics
        $query->withCount(['services', 'staff', 'bookings', 'reviews'])
              ->withSum('bookings as total_revenue', 'amount')
              ->withAvg('reviews as average_rating', 'rating');

        // Sorting
        $sortBy = $validated['sort_by'] ?? 'created_at';
        $sortOrder = $validated['sort_order'] ?? 'desc';

        switch ($sortBy) {
            case 'revenue':
                $query->orderBy('total_revenue', $sortOrder);
                break;
            case 'bookings':
                $query->orderBy('bookings_count', $sortOrder);
                break;
            case 'rating':
                $query->orderBy('average_rating', $sortOrder);
                break;
            default:
                $query->orderBy($sortBy, $sortOrder);
        }

        $businesses = $query->paginate($validated['per_page'] ?? 20);

        // Get summary statistics
        $stats = [
            'total' => Business::count(),
            'by_status' => Business::selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status'),
            'by_type' => Business::selectRaw('type, COUNT(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type'),
            'pending_approval' => Business::where('status', 'pending')->count(),
            'new_this_week' => Business::whereBetween('created_at', [now()->startOfWeek(), now()])->count(),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Businesses retrieved successfully',
            'data' => BusinessResource::collection($businesses),
            'stats' => $stats,
            'pagination' => [
                'total' => $businesses->total(),
                'count' => $businesses->count(),
                'per_page' => $businesses->perPage(),
                'current_page' => $businesses->currentPage(),
                'total_pages' => $businesses->lastPage()
            ]
        ]);
    }

    /**
     * Get business details
     */
    public function show(Business $business)
    {
        $business->load([
            'owner',
            'services',
            'staff',
            'media',
            'reviews' => function ($query) {
                $query->latest()->limit(10);
            }
        ]);

        // Get business statistics
        $stats = [
            'bookings' => [
                'total' => $business->bookings()->count(),
                'this_month' => $business->bookings()
                    ->whereMonth('booking_date', now()->month)
                    ->count(),
                'completed' => $business->bookings()
                    ->where('status', 'completed')
                    ->count(),
                'cancelled' => $business->bookings()
                    ->where('status', 'cancelled')
                    ->count(),
            ],
            'revenue' => [
                'total' => $business->bookings()
                    ->where('payment_status', 'paid')
                    ->sum('amount'),
                'this_month' => $business->bookings()
                    ->whereMonth('booking_date', now()->month)
                    ->where('payment_status', 'paid')
                    ->sum('amount'),
                'average_booking' => $business->bookings()
                    ->where('payment_status', 'paid')
                    ->avg('amount'),
                'platform_fees' => $this->calculatePlatformFees($business),
            ],
            'reviews' => [
                'total' => $business->reviews()->count(),
                'average_rating' => $business->reviews()->avg('rating'),
                'rating_distribution' => $business->reviews()
                    ->selectRaw('rating, COUNT(*) as count')
                    ->groupBy('rating')
                    ->pluck('count', 'rating'),
            ],
            'compliance' => [
                'violations' => $this->getBusinessViolations($business),
                'last_inspection' => $business->last_inspection_at,
                'compliance_score' => $this->calculateComplianceScore($business),
                'warnings_count' => $this->getWarningsCount($business),
            ],
            'performance' => [
                'response_rate' => $this->calculateResponseRate($business),
                'completion_rate' => $this->calculateCompletionRate($business),
                'cancellation_rate' => $this->calculateCancellationRate($business),
            ]
        ];

        // Get activity logs
        $activities = DB::table('activity_log')
            ->where('subject_type', Business::class)
            ->where('subject_id', $business->id)
            ->latest()
            ->limit(20)
            ->get();

        // Get audit trail
        $auditTrail = $this->getBusinessAuditTrail($business);

        return $this->success([
            'business' => new BusinessResource($business),
            'stats' => $stats,
            'activities' => $activities,
            'audit_trail' => $auditTrail
        ], 'Business details retrieved successfully');
    }

    /**
     * Approve business
     */
    public function approve(Request $request, Business $business)
    {
        if ($business->status !== 'pending') {
            return $this->error('Only pending businesses can be approved', 422);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string|max:500',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'featured' => 'nullable|boolean',
            'featured_until' => 'nullable|date|after:today',
            'send_notification' => 'nullable|boolean'
        ]);

        DB::beginTransaction();

        try {
            // Update business status
            $business->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => $request->user()->id,
                'approval_notes' => $validated['notes'] ?? null
            ]);

            // Update business settings if provided
            if (isset($validated['commission_rate']) || isset($validated['featured'])) {
                $settings = $business->settings ?? [];
                
                if (isset($validated['commission_rate'])) {
                    $settings['commission_rate'] = $validated['commission_rate'];
                }
                
                if (isset($validated['featured'])) {
                    $settings['featured'] = $validated['featured'];
                    $settings['featured_until'] = $validated['featured_until'] ?? null;
                }
                
                $business->update(['settings' => $settings]);
            }

            // Send notification
            if ($request->boolean('send_notification', true)) {
                $business->owner->notify(new \App\Notifications\BusinessApproved($business));
            }

            // Create welcome package
            $this->createWelcomePackage($business);

            // Clear cache
            Cache::tags(['businesses'])->flush();

            DB::commit();

            // Log activity
            activity()
                ->causedBy($request->user())
                ->performedOn($business)
                ->withProperties([
                    'notes' => $validated['notes'] ?? null,
                    'commission_rate' => $validated['commission_rate'] ?? null
                ])
                ->log('Business approved');

            return $this->success(
                new BusinessResource($business->fresh()),
                'Business approved successfully'
            );

        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->error(
                'Failed to approve business',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Reject business application
     */
    public function reject(Request $request, Business $business)
    {
        if ($business->status !== 'pending') {
            return $this->error('Only pending businesses can be rejected', 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
            'categories' => 'nullable|array',
            'categories.*' => 'string|in:incomplete_info,invalid_documents,policy_violation,duplicate,other',
            'allow_reapplication' => 'nullable|boolean',
            'send_notification' => 'nullable|boolean'
        ]);

        DB::beginTransaction();

        try {
            $business->update([
                'status' => 'rejected',
                'rejected_at' => now(),
                'rejected_by' => $request->user()->id,
                'rejection_reason' => $validated['reason'],
                'rejection_categories' => $validated['categories'] ?? [],
                'can_reapply' => $validated['allow_reapplication'] ?? true
            ]);

            // Send notification
            if ($request->boolean('send_notification', true)) {
                $business->owner->notify(new \App\Notifications\BusinessRejected(
                    $business,
                    $validated['reason'],
                    $validated['allow_reapplication'] ?? true
                ));
            }

            DB::commit();

            // Log activity
            activity()
                ->causedBy($request->user())
                ->performedOn($business)
                ->withProperties([
                    'reason' => $validated['reason'],
                    'categories' => $validated['categories'] ?? []
                ])
                ->log('Business application rejected');

            return $this->success(
                new BusinessResource($business),
                'Business application rejected'
            );

        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->error(
                'Failed to reject business',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Suspend business
     */
    public function suspend(Request $request, Business $business)
    {
        if ($business->status === 'suspended') {
            return $this->error('Business is already suspended', 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
            'severity' => 'required|in:low,medium,high,critical',
            'duration_days' => 'nullable|integer|min:1|max:365',
            'permanent' => 'nullable|boolean',
            'cancel_bookings' => 'nullable|boolean',
            'disable_new_bookings' => 'nullable|boolean',
            'send_notification' => 'nullable|boolean',
            'public_notice' => 'nullable|string|max:500'
        ]);

        DB::beginTransaction();

        try {
            $suspendedUntil = null;
            
            if (!$request->boolean('permanent') && !empty($validated['duration_days'])) {
                $suspendedUntil = now()->addDays($validated['duration_days']);
            }

            $business->update([
                'status' => 'suspended',
                'suspended_at' => now(),
                'suspended_by' => $request->user()->id,
                'suspension_reason' => $validated['reason'],
                'suspension_severity' => $validated['severity'],
                'suspended_until' => $suspendedUntil,
                'suspension_public_notice' => $validated['public_notice'] ?? null
            ]);

            // Disable new bookings if requested
            if ($request->boolean('disable_new_bookings', true)) {
                $settings = $business->settings ?? [];
                $settings['bookings_enabled'] = false;
                $business->update(['settings' => $settings]);
            }

            // Cancel future bookings if requested
            if ($request->boolean('cancel_bookings')) {
                $cancelledCount = $this->cancelFutureBookings($business, $validated['reason']);
            }

            // Create violation record
            DB::table('business_violations')->insert([
                'business_id' => $business->id,
                'violation_type' => 'suspension',
                'severity' => $validated['severity'],
                'description' => $validated['reason'],
                'action_taken' => 'Business suspended' . ($suspendedUntil ? ' until ' . $suspendedUntil->format('Y-m-d') : ' permanently'),
                'created_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Send notifications
            if ($request->boolean('send_notification', true)) {
                // Notify business owner
                $business->owner->notify(new \App\Notifications\BusinessSuspended(
                    $business,
                    $validated['reason'],
                    $suspendedUntil,
                    $validated['severity']
                ));

                // Notify customers with upcoming bookings
                if (!$request->boolean('cancel_bookings')) {
                    $this->notifyAffectedCustomers($business);
                }
            }

            // Clear cache
            Cache::tags(['businesses'])->flush();

            DB::commit();

            // Log activity
            activity()
                ->causedBy($request->user())
                ->performedOn($business)
                ->withProperties([
                    'reason' => $validated['reason'],
                    'severity' => $validated['severity'],
                    'duration_days' => $validated['duration_days'] ?? null,
                    'permanent' => $request->boolean('permanent'),
                    'bookings_cancelled' => $cancelledCount ?? 0
                ])
                ->log('Business suspended');

            return $this->success([
                'business' => new BusinessResource($business->fresh()),
                'bookings_cancelled' => $cancelledCount ?? 0,
                'suspended_until' => $suspendedUntil?->format('Y-m-d H:i:s')
            ], 'Business suspended successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->error(
                'Failed to suspend business',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Unsuspend business
     */
    public function unsuspend(Request $request, Business $business)
    {
        if ($business->status !== 'suspended') {
            return $this->error('Business is not suspended', 422);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string|max:500',
            'conditions' => 'nullable|array',
            'conditions.*' => 'string',
            'probation_days' => 'nullable|integer|min:1|max:90',
            'send_notification' => 'nullable|boolean'
        ]);

        DB::beginTransaction();

        try {
            $business->update([
                'status' => 'approved',
                'suspended_at' => null,
                'suspended_by' => null,
                'suspension_reason' => null,
                'suspension_severity' => null,
                'suspended_until' => null,
                'suspension_public_notice' => null,
                'unsuspended_at' => now(),
                'unsuspended_by' => $request->user()->id,
                'unsuspension_notes' => $validated['notes'] ?? null
            ]);

            // Set probation period if specified
            if (!empty($validated['probation_days'])) {
                $settings = $business->settings ?? [];
                $settings['probation_until'] = now()->addDays($validated['probation_days']);
                $settings['probation_conditions'] = $validated['conditions'] ?? [];
                $business->update(['settings' => $settings]);
            }

            // Re-enable bookings
            $settings = $business->settings ?? [];
            $settings['bookings_enabled'] = true;
            $business->update(['settings' => $settings]);

            // Send notification
            if ($request->boolean('send_notification', true)) {
                $business->owner->notify(new \App\Notifications\BusinessUnsuspended(
                    $business,
                    $validated['notes'] ?? null,
                    $validated['probation_days'] ?? null
                ));
            }

            // Clear cache
            Cache::tags(['businesses'])->flush();

            DB::commit();

            // Log activity
            activity()
                ->causedBy($request->user())
                ->performedOn($business)
                ->withProperties([
                    'notes' => $validated['notes'] ?? null,
                    'probation_days' => $validated['probation_days'] ?? null
                ])
                ->log('Business unsuspended');

            return $this->success(
                new BusinessResource($business->fresh()),
                'Business unsuspended successfully'
            );

        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->error(
                'Failed to unsuspend business',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Issue warning to business
     */
    public function warn(Request $request, Business $business)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
            'severity' => 'required|in:minor,moderate,severe',
            'categories' => 'required|array|min:1',
            'categories.*' => 'string|in:service_quality,policy_violation,customer_complaints,payment_issues,other',
            'required_actions' => 'nullable|array',
            'required_actions.*' => 'string',
            'deadline_days' => 'nullable|integer|min:1|max:30',
            'send_notification' => 'nullable|boolean'
        ]);

        DB::beginTransaction();

        try {
            // Create warning record
            $warning = DB::table('business_warnings')->insertGetId([
                'business_id' => $business->id,
                'reason' => $validated['reason'],
                'severity' => $validated['severity'],
                'categories' => json_encode($validated['categories']),
                'required_actions' => json_encode($validated['required_actions'] ?? []),
                'deadline' => !empty($validated['deadline_days']) ? now()->addDays($validated['deadline_days']) : null,
                'issued_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Update business warning count
            $business->increment('warnings_count');

            // Create violation record
            DB::table('business_violations')->insert([
                'business_id' => $business->id,
                'violation_type' => 'warning',
                'severity' => $validated['severity'],
                'description' => $validated['reason'],
                'action_taken' => 'Warning issued',
                'reference_id' => $warning,
                'created_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Send notification
            if ($request->boolean('send_notification', true)) {
                $business->owner->notify(new \App\Notifications\BusinessWarning(
                    $business,
                    $validated['reason'],
                    $validated['severity'],
                    $validated['required_actions'] ?? [],
                    $validated['deadline_days'] ?? null
                ));
            }

            DB::commit();

            // Log activity
            activity()
                ->causedBy($request->user())
                ->performedOn($business)
                ->withProperties($validated)
                ->log('Business warning issued');

            return $this->success([
                'warning_id' => $warning,
                'total_warnings' => $business->warnings_count + 1,
                'message' => 'Warning issued successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->error(
                'Failed to issue warning',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Update business settings
     */
    public function updateSettings(Request $request, Business $business)
    {
        $validated = $request->validate([
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'featured' => 'nullable|boolean',
            'featured_until' => 'nullable|date|after:today',
            'priority_support' => 'nullable|boolean',
            'max_advance_booking_days' => 'nullable|integer|min:1|max:365',
            'min_advance_booking_hours' => 'nullable|integer|min:0|max:168',
            'auto_confirm_bookings' => 'nullable|boolean',
            'allow_cancellations' => 'nullable|boolean',
            'cancellation_hours' => 'nullable|integer|min:0|max:168',
            'require_deposit' => 'nullable|boolean',
            'deposit_percentage' => 'nullable|integer|min:0|max:100',
            'custom_settings' => 'nullable|array'
        ]);

        $settings = $business->settings ?? [];
        
        foreach ($validated as $key => $value) {
            if ($key !== 'custom_settings') {
                $settings[$key] = $value;
            }
        }

        // Merge custom settings
        if (!empty($validated['custom_settings'])) {
            $settings = array_merge($settings, $validated['custom_settings']);
        }

        $business->update(['settings' => $settings]);

        // Clear cache
        Cache::forget("business_settings_{$business->id}");

        // Log activity
        activity()
            ->causedBy($request->user())
            ->performedOn($business)
            ->withProperties(['settings' => $validated])
            ->log('Business settings updated');

        return $this->success([
            'settings' => $settings
        ], 'Business settings updated successfully');
    }

    /**
     * Verify business documents
     */
    public function verifyDocuments(Request $request, Business $business)
    {
        $validated = $request->validate([
            'documents' => 'required|array',
            'documents.*.type' => 'required|string|in:license,insurance,tax_certificate,identity,other',
            'documents.*.status' => 'required|in:verified,rejected,pending_review',
            'documents.*.notes' => 'nullable|string',
            'overall_status' => 'required|in:verified,partially_verified,rejected'
        ]);

        DB::beginTransaction();

        try {
            foreach ($validated['documents'] as $doc) {
                DB::table('business_documents')
                    ->where('business_id', $business->id)
                    ->where('type', $doc['type'])
                    ->update([
                        'verification_status' => $doc['status'],
                        'verification_notes' => $doc['notes'] ?? null,
                        'verified_by' => $request->user()->id,
                        'verified_at' => now(),
                        'updated_at' => now()
                    ]);
            }

            // Update business verification status
            $business->update([
                'documents_verified' => $validated['overall_status'] === 'verified',
                'documents_verification_status' => $validated['overall_status'],
                'documents_verified_at' => now(),
                'documents_verified_by' => $request->user()->id
            ]);

            DB::commit();

            // Send notification
            $business->owner->notify(new \App\Notifications\DocumentsVerified(
                $business,
                $validated['overall_status']
            ));

            // Log activity
            activity()
                ->causedBy($request->user())
                ->performedOn($business)
                ->withProperties($validated)
                ->log('Business documents verified');

            return $this->success(null, 'Documents verification completed');

        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->error(
                'Failed to verify documents',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Get business analytics
     */
    public function analytics(Request $request, Business $business)
    {
        $validated = $request->validate([
            'period' => 'nullable|in:week,month,quarter,year',
            'compare' => 'nullable|boolean'
        ]);

        $period = $validated['period'] ?? 'month';
        $dateRanges = $this->getDateRanges($period);

        $analytics = [
            'revenue' => $this->getRevenueAnalytics($business, $dateRanges),
            'bookings' => $this->getBookingAnalytics($business, $dateRanges),
            'customers' => $this->getCustomerAnalytics($business, $dateRanges),
            'services' => $this->getServiceAnalytics($business, $dateRanges),
            'staff' => $this->getStaffAnalytics($business, $dateRanges),
            'reviews' => $this->getReviewAnalytics($business, $dateRanges),
            'compliance' => $this->getComplianceAnalytics($business),
        ];

        if ($request->boolean('compare')) {
            $analytics['comparison'] = $this->getComparativeAnalytics($business, $dateRanges);
        }

        return $this->success($analytics, 'Business analytics retrieved successfully');
    }

    /**
     * Helper methods
     */
    private function calculatePlatformFees($business)
    {
        $commissionRate = $business->settings['commission_rate'] ?? config('goreserve.platform.commission_rate', 0.10);
        
        return $business->bookings()
            ->where('payment_status', 'paid')
            ->sum('amount') * $commissionRate;
    }

    private function getBusinessViolations($business)
    {
        return DB::table('business_violations')
            ->where('business_id', $business->id)
            ->latest()
            ->limit(10)
            ->get();
    }

    private function calculateComplianceScore($business)
    {
        $violations = DB::table('business_violations')
            ->where('business_id', $business->id)
            ->where('created_at', '>', now()->subMonths(6))
            ->get();

        $warnings = DB::table('business_warnings')
            ->where('business_id', $business->id)
            ->where('created_at', '>', now()->subMonths(6))
            ->count();

        $complaints = DB::table('complaints')
            ->where('business_id', $business->id)
            ->where('created_at', '>', now()->subMonths(6))
            ->where('status', 'valid')
            ->count();

        $score = 100;
        
        // Deduct points based on severity
        foreach ($violations as $violation) {
            $deduction = match($violation->severity) {
                'critical' => 25,
                'high' => 15,
                'medium' => 10,
                'low' => 5,
                default => 0
            };
            $score -= $deduction;
        }

        $score -= ($warnings * 5);
        $score -= ($complaints * 3);

        return max(0, $score);
    }

    private function getWarningsCount($business)
    {
        return DB::table('business_warnings')
            ->where('business_id', $business->id)
            ->count();
    }

    private function calculateResponseRate($business)
    {
        $totalInquiries = DB::table('customer_inquiries')
            ->where('business_id', $business->id)
            ->where('created_at', '>', now()->subMonth())
            ->count();

        $respondedInquiries = DB::table('customer_inquiries')
            ->where('business_id', $business->id)
            ->where('created_at', '>', now()->subMonth())
            ->whereNotNull('responded_at')
            ->count();

        return $totalInquiries > 0 ? round(($respondedInquiries / $totalInquiries) * 100, 2) : 100;
    }

    private function calculateCompletionRate($business)
    {
        $totalBookings = $business->bookings()
            ->where('booking_date', '<', now())
            ->whereIn('status', ['confirmed', 'completed'])
            ->count();

        $completedBookings = $business->bookings()
            ->where('status', 'completed')
            ->count();

        return $totalBookings > 0 ? round(($completedBookings / $totalBookings) * 100, 2) : 0;
    }

    private function calculateCancellationRate($business)
    {
        $totalBookings = $business->bookings()
            ->where('created_at', '>', now()->subMonth())
            ->count();

        $cancelledBookings = $business->bookings()
            ->where('created_at', '>', now()->subMonth())
            ->where('status', 'cancelled')
            ->count();

        return $totalBookings > 0 ? round(($cancelledBookings / $totalBookings) * 100, 2) : 0;
    }

    private function getBusinessAuditTrail($business)
    {
        return DB::table('business_audit_trail')
            ->where('business_id', $business->id)
            ->latest()
            ->limit(50)
            ->get()
            ->map(function ($audit) {
                $audit->changes = json_decode($audit->changes);
                return $audit;
            });
    }

    private function createWelcomePackage($business)
    {
        // Create welcome bonus credits
        if (config('goreserve.welcome.credits_enabled')) {
            DB::table('business_credits')->insert([
                'business_id' => $business->id,
                'type' => 'welcome_bonus',
                'amount' => config('goreserve.welcome.credits_amount', 100),
                'expires_at' => now()->addDays(config('goreserve.welcome.credits_validity_days', 30)),
                'created_at' => now()
            ]);
        }

        // Schedule onboarding emails
        $this->scheduleOnboardingEmails($business);

        // Assign dedicated support if eligible
        if ($business->settings['priority_support'] ?? false) {
            $this->assignDedicatedSupport($business);
        }
    }

    private function cancelFutureBookings($business, $reason)
    {
        $bookingsToCancel = $business->bookings()
            ->where('booking_date', '>=', today())
            ->whereIn('status', ['pending', 'confirmed'])
            ->get();

        foreach ($bookingsToCancel as $booking) {
            $booking->cancel('Business suspended: ' . $reason);
            
            // Send cancellation notification
            $booking->user->notify(new \App\Notifications\BookingCancelledDueToSuspension(
                $booking,
                $reason
            ));

            // Process refund if payment was made
            if ($booking->payment_status === 'paid') {
                app(\App\Services\PaymentService::class)->processRefund($booking, 1.0); // Full refund
            }
        }

        return $bookingsToCancel->count();
    }

    private function notifyAffectedCustomers($business)
    {
        $affectedBookings = $business->bookings()
            ->where('booking_date', '>=', today())
            ->whereIn('status', ['pending', 'confirmed'])
            ->with('user')
            ->get();

        $uniqueCustomers = $affectedBookings->pluck('user')->unique('id');

        foreach ($uniqueCustomers as $customer) {
            $customer->notify(new \App\Notifications\BusinessSuspensionNotice(
                $business,
                $affectedBookings->where('user_id', $customer->id)
            ));
        }
    }

    private function scheduleOnboardingEmails($business)
    {
        $schedule = [
            ['delay' => 0, 'type' => 'welcome'],
            ['delay' => 1, 'type' => 'setup_guide'],
            ['delay' => 3, 'type' => 'best_practices'],
            ['delay' => 7, 'type' => 'marketing_tips'],
            ['delay' => 14, 'type' => 'check_in'],
        ];

        foreach ($schedule as $email) {
            \App\Jobs\SendOnboardingEmail::dispatch($business, $email['type'])
                ->delay(now()->addDays($email['delay']));
        }
    }

    private function assignDedicatedSupport($business)
    {
        // Find available support agent
        $agent = DB::table('support_agents')
            ->where('is_available', true)
            ->where('business_count', '<', DB::raw('max_businesses'))
            ->orderBy('business_count', 'asc')
            ->first();

        if ($agent) {
            DB::table('business_support_assignments')->insert([
                'business_id' => $business->id,
                'agent_id' => $agent->id,
                'assigned_at' => now(),
                'created_at' => now()
            ]);

            DB::table('support_agents')
                ->where('id', $agent->id)
                ->increment('business_count');
        }
    }

    private function getDateRanges($period)
    {
        $now = now();
        
        switch ($period) {
            case 'week':
                return [
                    'current' => [
                        'start' => $now->copy()->startOfWeek(),
                        'end' => $now->copy()->endOfWeek()
                    ],
                    'previous' => [
                        'start' => $now->copy()->subWeek()->startOfWeek(),
                        'end' => $now->copy()->subWeek()->endOfWeek()
                    ]
                ];
                
            case 'quarter':
                return [
                    'current' => [
                        'start' => $now->copy()->startOfQuarter(),
                        'end' => $now->copy()->endOfQuarter()
                    ],
                    'previous' => [
                        'start' => $now->copy()->subQuarter()->startOfQuarter(),
                        'end' => $now->copy()->subQuarter()->endOfQuarter()
                    ]
                ];
                
            case 'year':
                return [
                    'current' => [
                        'start' => $now->copy()->startOfYear(),
                        'end' => $now->copy()->endOfYear()
                    ],
                    'previous' => [
                        'start' => $now->copy()->subYear()->startOfYear(),
                        'end' => $now->copy()->subYear()->endOfYear()
                    ]
                ];
                
            case 'month':
            default:
                return [
                    'current' => [
                        'start' => $now->copy()->startOfMonth(),
                        'end' => $now->copy()->endOfMonth()
                    ],
                    'previous' => [
                        'start' => $now->copy()->subMonth()->startOfMonth(),
                        'end' => $now->copy()->subMonth()->endOfMonth()
                    ]
                ];
        }
    }

    private function getRevenueAnalytics($business, $dateRanges)
    {
        $currentRevenue = $business->bookings()
            ->whereBetween('booking_date', [$dateRanges['current']['start'], $dateRanges['current']['end']])
            ->where('payment_status', 'paid')
            ->sum('amount');

        $previousRevenue = $business->bookings()
            ->whereBetween('booking_date', [$dateRanges['previous']['start'], $dateRanges['previous']['end']])
            ->where('payment_status', 'paid')
            ->sum('amount');

        return [
            'current' => $currentRevenue,
            'previous' => $previousRevenue,
            'growth' => $previousRevenue > 0 ? round((($currentRevenue - $previousRevenue) / $previousRevenue) * 100, 2) : 0,
            'daily_average' => $currentRevenue / $dateRanges['current']['start']->diffInDays($dateRanges['current']['end']),
            'platform_fees' => $currentRevenue * ($business->settings['commission_rate'] ?? 0.10)
        ];
    }

    private function getBookingAnalytics($business, $dateRanges)
    {
        $currentBookings = $business->bookings()
            ->whereBetween('booking_date', [$dateRanges['current']['start'], $dateRanges['current']['end']])
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return [
            'total' => $currentBookings->sum(),
            'by_status' => $currentBookings,
            'completion_rate' => $currentBookings->sum() > 0 
                ? round(($currentBookings->get('completed', 0) / $currentBookings->sum()) * 100, 2) 
                : 0,
            'cancellation_rate' => $currentBookings->sum() > 0 
                ? round(($currentBookings->get('cancelled', 0) / $currentBookings->sum()) * 100, 2) 
                : 0,
        ];
    }

    private function getCustomerAnalytics($business, $dateRanges)
    {
        $customers = $business->bookings()
            ->whereBetween('booking_date', [$dateRanges['current']['start'], $dateRanges['current']['end']])
            ->distinct('user_id')
            ->count('user_id');

        $newCustomers = $business->bookings()
            ->whereBetween('booking_date', [$dateRanges['current']['start'], $dateRanges['current']['end']])
            ->whereDoesntHave('user.bookings', function ($query) use ($business, $dateRanges) {
                $query->where('business_id', $business->id)
                    ->where('booking_date', '<', $dateRanges['current']['start']);
            })
            ->distinct('user_id')
            ->count('user_id');

        return [
            'total' => $customers,
            'new' => $newCustomers,
            'returning' => $customers - $newCustomers,
            'retention_rate' => $customers > 0 ? round((($customers - $newCustomers) / $customers) * 100, 2) : 0,
        ];
    }

    private function getServiceAnalytics($business, $dateRanges)
    {
        return $business->services()
            ->withCount(['bookings' => function ($query) use ($dateRanges) {
                $query->whereBetween('booking_date', [$dateRanges['current']['start'], $dateRanges['current']['end']]);
            }])
            ->withSum(['bookings as revenue' => function ($query) use ($dateRanges) {
                $query->whereBetween('booking_date', [$dateRanges['current']['start'], $dateRanges['current']['end']])
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
                ];
            });
    }

    private function getStaffAnalytics($business, $dateRanges)
    {
        return $business->staff()
            ->withCount(['bookings' => function ($query) use ($dateRanges) {
                $query->whereBetween('booking_date', [$dateRanges['current']['start'], $dateRanges['current']['end']]);
            }])
            ->withSum(['bookings as revenue' => function ($query) use ($dateRanges) {
                $query->whereBetween('booking_date', [$dateRanges['current']['start'], $dateRanges['current']['end']])
                    ->where('payment_status', 'paid');
            }], 'amount')
            ->get()
            ->map(function ($staff) {
                return [
                    'id' => $staff->id,
                    'name' => $staff->name,
                    'bookings' => $staff->bookings_count,
                    'revenue' => $staff->revenue ?? 0,
                    'utilization' => $this->calculateStaffUtilization($staff),
                ];
            });
    }

    private function getReviewAnalytics($business, $dateRanges)
    {
        $reviews = $business->reviews()
            ->whereBetween('created_at', [$dateRanges['current']['start'], $dateRanges['current']['end']])
            ->get();

        return [
            'total' => $reviews->count(),
            'average_rating' => $reviews->avg('rating'),
            'rating_distribution' => $reviews->groupBy('rating')->map->count(),
            'response_rate' => $this->calculateReviewResponseRate($business, $dateRanges),
        ];
    }

    private function getComplianceAnalytics($business)
    {
        return [
            'score' => $this->calculateComplianceScore($business),
            'violations_count' => $this->getBusinessViolations($business)->count(),
            'warnings_count' => $this->getWarningsCount($business),
            'last_inspection' => $business->last_inspection_at,
            'risk_level' => $this->assessRiskLevel($business),
        ];
    }

    private function getComparativeAnalytics($business, $dateRanges)
    {
        // Compare with similar businesses
        $similarBusinesses = Business::where('type', $business->type)
            ->where('status', 'approved')
            ->where('id', '!=', $business->id)
            ->withAvg(['bookings' => function ($query) use ($dateRanges) {
                $query->whereBetween('booking_date', [$dateRanges['current']['start'], $dateRanges['current']['end']])
                    ->where('payment_status', 'paid');
            }], 'amount')
            ->get();

        return [
            'industry_average_booking_value' => $similarBusinesses->avg('bookings_avg_amount'),
            'percentile_rank' => $this->calculatePercentileRank($business, $similarBusinesses),
            'market_share' => $this->calculateMarketShare($business, $similarBusinesses),
        ];
    }

    private function calculateStaffUtilization($staff)
    {
        // Implementation would calculate actual utilization
        return rand(60, 95);
    }

    private function calculateReviewResponseRate($business, $dateRanges)
    {
        $totalReviews = $business->reviews()
            ->whereBetween('created_at', [$dateRanges['current']['start'], $dateRanges['current']['end']])
            ->count();

        $respondedReviews = $business->reviews()
            ->whereBetween('created_at', [$dateRanges['current']['start'], $dateRanges['current']['end']])
            ->whereNotNull('business_response')
            ->count();

        return $totalReviews > 0 ? round(($respondedReviews / $totalReviews) * 100, 2) : 0;
    }

    private function assessRiskLevel($business)
    {
        $complianceScore = $this->calculateComplianceScore($business);
        
        if ($complianceScore >= 90) return 'low';
        if ($complianceScore >= 70) return 'medium';
        if ($complianceScore >= 50) return 'high';
        return 'critical';
    }

    private function calculatePercentileRank($business, $similarBusinesses)
    {
        // Implementation would calculate actual percentile
        return rand(50, 95);
    }

    private function calculateMarketShare($business, $similarBusinesses)
    {
        // Implementation would calculate actual market share
        return rand(5, 25);
    }

    /**
     * Export businesses data
     */
    public function export(Request $request)
    {
        $validated = $request->validate([
            'format' => 'required|in:csv,excel,pdf',
            'filters' => 'nullable|array',
            'include' => 'nullable|array',
            'include.*' => 'in:owner,services,staff,stats,compliance'
        ]);

        $query = Business::query();

        // Apply filters
        if (!empty($validated['filters']['status'])) {
            $query->where('status', $validated['filters']['status']);
        }

        if (!empty($validated['filters']['type'])) {
            $query->where('type', $validated['filters']['type']);
        }

        if (!empty($validated['filters']['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['filters']['date_from']);
        }

        if (!empty($validated['filters']['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['filters']['date_to']);
        }

        // Include relationships
        $includes = $validated['include'] ?? [];
        if (in_array('owner', $includes)) {
            $query->with('owner');
        }

        if (in_array('stats', $includes)) {
            $query->withCount(['services', 'staff', 'bookings'])
                  ->withSum('bookings as revenue', 'amount');
        }

        $businesses = $query->get();

        switch ($validated['format']) {
            case 'csv':
                return $this->exportToCsv($businesses, $includes);
            case 'excel':
                return $this->exportToExcel($businesses, $includes);
            case 'pdf':
                return $this->exportToPdf($businesses, $includes);
        }
    }

    /**
     * Export to CSV
     */
    private function exportToCsv($businesses, $includes)
    {
        $headers = ['ID', 'Name', 'Type', 'Status', 'Phone', 'Email', 'Created At'];
        
        if (in_array('owner', $includes)) {
            $headers = array_merge($headers, ['Owner Name', 'Owner Email']);
        }
        
        if (in_array('stats', $includes)) {
            $headers = array_merge($headers, ['Services', 'Staff', 'Bookings', 'Revenue']);
        }
        
        if (in_array('compliance', $includes)) {
            $headers = array_merge($headers, ['Compliance Score', 'Warnings']);
        }
        
        $csv = implode(',', $headers) . "\n";

        foreach ($businesses as $business) {
            $row = [
                $business->id,
                $business->name,
                $business->type,
                $business->status,
                $business->phone,
                $business->email ?? 'N/A',
                $business->created_at->format('Y-m-d')
            ];
            
            if (in_array('owner', $includes)) {
                $row = array_merge($row, [
                    $business->owner->name ?? 'N/A',
                    $business->owner->email ?? 'N/A'
                ]);
            }
            
            if (in_array('stats', $includes)) {
                $row = array_merge($row, [
                    $business->services_count ?? 0,
                    $business->staff_count ?? 0,
                    $business->bookings_count ?? 0,
                    $business->revenue ?? 0
                ]);
            }
            
            if (in_array('compliance', $includes)) {
                $row = array_merge($row, [
                    $this->calculateComplianceScore($business),
                    $this->getWarningsCount($business)
                ]);
            }

            $csv .= implode(',', array_map(fn($value) => '"' . str_replace('"', '""', $value) . '"', $row)) . "\n";
        }

        return response($csv)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'attachment; filename="businesses_export_' . now()->format('Y-m-d') . '.csv"');
    }

    /**
     * Export to Excel
     */
    private function exportToExcel($businesses, $includes)
    {
        // This would use PhpSpreadsheet or similar library
        return $this->error('Excel export not implemented', 501);
    }

    /**
     * Export to PDF
     */
    private function exportToPdf($businesses, $includes)
    {
        // This would use DomPDF or similar library
        return $this->error('PDF export not implemented', 501);
    }
}