<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Http\Resources\ReviewResource;
use App\Jobs\UpdateBusinessRating;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReviewController extends Controller
{
    /**
     * Get all reviews
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => 'nullable|string|in:approved,pending,hidden',
            'rating' => 'nullable|integer|min:1|max:5',
            'has_reports' => 'nullable|boolean',
            'business_id' => 'nullable|exists:businesses,id',
            'user_id' => 'nullable|exists:users,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'sort_by' => 'nullable|string|in:created_at,rating,helpful_count,reports_count',
            'sort_order' => 'nullable|string|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        $query = Review::with(['user', 'business', 'booking.service']);

        // Filter by approval status
        if (!empty($validated['status'])) {
            if ($validated['status'] === 'approved') {
                $query->where('is_approved', true);
            } elseif ($validated['status'] === 'pending') {
                $query->where('is_approved', false)->whereNull('hidden_reason');
            } elseif ($validated['status'] === 'hidden') {
                $query->where('is_approved', false)->whereNotNull('hidden_reason');
            }
        }

        // Filter by rating
        if (!empty($validated['rating'])) {
            $query->where('rating', $validated['rating']);
        }

        // Filter by reports
        if (isset($validated['has_reports'])) {
            if ($validated['has_reports']) {
                $query->has('reports');
            } else {
                $query->doesntHave('reports');
            }
        }

        // Filter by business
        if (!empty($validated['business_id'])) {
            $query->where('business_id', $validated['business_id']);
        }

        // Filter by user
        if (!empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        // Filter by date
        if (!empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }
        if (!empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        // Add report count
        $query->withCount('reports');

        // Sorting
        $sortBy = $validated['sort_by'] ?? 'created_at';
        $sortOrder = $validated['sort_order'] ?? 'desc';
        
        if ($sortBy === 'reports_count') {
            $query->orderBy('reports_count', $sortOrder);
        } else {
            $query->orderBy($sortBy, $sortOrder);
        }

        $reviews = $query->paginate($validated['per_page'] ?? 20);

        // Get statistics
        $stats = [
            'total_reviews' => Review::count(),
            'pending_moderation' => Review::where('is_approved', false)
                ->whereNull('hidden_reason')
                ->count(),
            'reported_reviews' => Review::has('reports')->count(),
            'hidden_reviews' => Review::where('is_approved', false)
                ->whereNotNull('hidden_reason')
                ->count(),
            'average_rating' => Review::where('is_approved', true)->avg('rating'),
            'rating_distribution' => Review::where('is_approved', true)
                ->selectRaw('rating, COUNT(*) as count')
                ->groupBy('rating')
                ->pluck('count', 'rating'),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Reviews retrieved successfully',
            'data' => ReviewResource::collection($reviews),
            'stats' => $stats,
            'pagination' => [
                'total' => $reviews->total(),
                'count' => $reviews->count(),
                'per_page' => $reviews->perPage(),
                'current_page' => $reviews->currentPage(),
                'total_pages' => $reviews->lastPage()
            ]
        ]);
    }

    /**
     * Get review details
     */
    public function show(Review $review)
    {
        $review->load([
            'user',
            'business',
            'booking.service',
            'media',
            'reports' => function ($query) {
                $query->with('user')->latest();
            }
        ]);

        // Get review history
        $history = DB::table('activity_log')
            ->where('subject_type', Review::class)
            ->where('subject_id', $review->id)
            ->latest()
            ->get();

        return $this->success([
            'review' => new ReviewResource($review),
            'reports' => $review->reports,
            'history' => $history
        ], 'Review details retrieved successfully');
    }

    /**
     * Approve review
     */
    public function approve(Request $request, Review $review)
    {
        if ($review->is_approved) {
            return $this->error('Review is already approved', 422);
        }

        $review->update([
            'is_approved' => true,
            'hidden_reason' => null,
            'moderated_at' => now(),
            'moderated_by' => $request->user()->id
        ]);

        // Update business rating
        UpdateBusinessRating::dispatch($review->business_id);

        activity()
            ->causedBy($request->user())
            ->performedOn($review)
            ->log('Review approved');

        return $this->success(
            new ReviewResource($review),
            'Review approved successfully'
        );
    }

    /**
     * Hide review
     */
    public function hide(Request $request, Review $review)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
            'notify_user' => 'nullable|boolean'
        ]);

        $review->update([
            'is_approved' => false,
            'hidden_reason' => $validated['reason'],
            'moderated_at' => now(),
            'moderated_by' => $request->user()->id
        ]);

        // Update business rating
        UpdateBusinessRating::dispatch($review->business_id);

        // Notify user if requested
        if ($request->boolean('notify_user')) {
            $review->user->notify(new \App\Notifications\ReviewHidden($review, $validated['reason']));
        }

        activity()
            ->causedBy($request->user())
            ->performedOn($review)
            ->withProperties(['reason' => $validated['reason']])
            ->log('Review hidden');

        return $this->success(
            new ReviewResource($review),
            'Review hidden successfully'
        );
    }

    /**
     * Delete review
     */
    public function destroy(Request $request, Review $review)
    {
        $businessId = $review->business_id;
        
        $review->delete();

        // Update business rating
        UpdateBusinessRating::dispatch($businessId);

        activity()
            ->causedBy($request->user())
            ->log('Review deleted');

        return $this->success(null, 'Review deleted successfully');
    }

    /**
     * Handle review reports
     */
    public function handleReports(Request $request, Review $review)
    {
        $validated = $request->validate([
            'action' => 'required|in:dismiss,hide_review,warn_user,ban_user',
            'notes' => 'nullable|string|max:500'
        ]);

        DB::beginTransaction();

        try {
            switch ($validated['action']) {
                case 'dismiss':
                    // Mark all reports as reviewed
                    DB::table('review_reports')
                        ->where('review_id', $review->id)
                        ->update([
                            'status' => 'dismissed',
                            'reviewed_at' => now(),
                            'reviewed_by' => $request->user()->id,
                            'admin_notes' => $validated['notes'] ?? null
                        ]);
                    break;
                    
                case 'hide_review':
                    // Hide the review
                    $review->update([
                        'is_approved' => false,
                        'hidden_reason' => 'Multiple user reports',
                        'moderated_at' => now(),
                        'moderated_by' => $request->user()->id
                    ]);
                    
                    // Mark reports as actioned
                    DB::table('review_reports')
                        ->where('review_id', $review->id)
                        ->update([
                            'status' => 'actioned',
                            'reviewed_at' => now(),
                            'reviewed_by' => $request->user()->id,
                            'admin_notes' => $validated['notes'] ?? null
                        ]);
                    break;
                    
                case 'warn_user':
                    // Send warning to review author
                    $review->user->notify(new \App\Notifications\UserWarning(
                        'Your review has been reported and reviewed by our moderation team.',
                        $validated['notes'] ?? null
                    ));
                    
                    // Log warning
                    DB::table('user_warnings')->insert([
                        'user_id' => $review->user_id,
                        'type' => 'review_violation',
                        'reason' => 'Review reported by multiple users',
                        'admin_notes' => $validated['notes'] ?? null,
                        'issued_by' => $request->user()->id,
                        'created_at' => now()
                    ]);
                    break;
                    
                case 'ban_user':
                    // Suspend user account
                    $review->user->update(['status' => 'suspended']);
                    $review->user->tokens()->delete();
                    
                    // Hide all user's reviews
                    Review::where('user_id', $review->user_id)
                        ->update([
                            'is_approved' => false,
                            'hidden_reason' => 'User banned for violations'
                        ]);
                    
                    // Send notification
                    $review->user->notify(new \App\Notifications\AccountSuspended(
                        'Review policy violations',
                        $validated['notes'] ?? null
                    ));
                    break;
            }

            DB::commit();

            activity()
                ->causedBy($request->user())
                ->performedOn($review)
                ->withProperties([
                    'action' => $validated['action'],
                    'notes' => $validated['notes'] ?? null
                ])
                ->log('Review reports handled');

            return $this->success(null, 'Reports handled successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->error(
                'Failed to handle reports',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Get moderation queue
     */
    public function moderationQueue(Request $request)
    {
        $reviews = Review::where('is_approved', false)
            ->whereNull('hidden_reason')
            ->with(['user', 'business', 'booking.service'])
            ->withCount('reports')
            ->orderBy('reports_count', 'desc')
            ->orderBy('created_at', 'asc')
            ->paginate(20);

        return $this->successWithPagination(
            $reviews->through(fn ($review) => new ReviewResource($review)),
            'Moderation queue retrieved successfully'
        );
    }
}