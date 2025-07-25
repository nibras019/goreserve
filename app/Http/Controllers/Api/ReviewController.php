<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\Booking;
use App\Http\Resources\ReviewResource;
use App\Http\Requests\Review\CreateReviewRequest;
use App\Events\ReviewCreated;
use App\Jobs\UpdateBusinessRating;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReviewController extends Controller
{
    /**
     * Get reviews for a business
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'business_id' => 'required|exists:businesses,id',
            'rating' => 'nullable|integer|min:1|max:5',
            'sort_by' => 'nullable|string|in:latest,oldest,rating_high,rating_low,helpful',
            'with_photos' => 'nullable|boolean',
            'verified_only' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:50'
        ]);

        $query = Review::where('business_id', $validated['business_id'])
            ->where('is_approved', true)
            ->with(['user', 'booking.service', 'media']);

        // Filter by rating
        if (!empty($validated['rating'])) {
            $query->where('rating', $validated['rating']);
        }

        // Filter by photos
        if ($request->boolean('with_photos')) {
            $query->has('media');
        }

        // Filter by verified purchases
        if ($request->boolean('verified_only')) {
            $query->whereHas('booking', function ($q) {
                $q->where('status', 'completed');
            });
        }

        // Sorting
        switch ($validated['sort_by'] ?? 'latest') {
            case 'oldest':
                $query->oldest();
                break;
            case 'rating_high':
                $query->orderBy('rating', 'desc')->latest();
                break;
            case 'rating_low':
                $query->orderBy('rating', 'asc')->latest();
                break;
            case 'helpful':
                $query->orderBy('helpful_count', 'desc')->latest();
                break;
            case 'latest':
            default:
                $query->latest();
                break;
        }

        $reviews = $query->paginate($validated['per_page'] ?? 20);

        // Get rating summary
        $ratingSummary = Review::where('business_id', $validated['business_id'])
            ->where('is_approved', true)
            ->selectRaw('rating, COUNT(*) as count')
            ->groupBy('rating')
            ->pluck('count', 'rating')
            ->toArray();

        return response()->json([
            'success' => true,
            'message' => 'Reviews retrieved successfully',
            'data' => ReviewResource::collection($reviews),
            'summary' => [
                'average_rating' => Review::where('business_id', $validated['business_id'])
                    ->where('is_approved', true)
                    ->avg('rating'),
                'total_reviews' => array_sum($ratingSummary),
                'rating_distribution' => $ratingSummary,
                'verified_reviews' => Review::where('business_id', $validated['business_id'])
                    ->where('is_approved', true)
                    ->whereHas('booking', function ($q) {
                        $q->where('status', 'completed');
                    })
                    ->count()
            ],
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
     * Create a review
     */
    public function store(CreateReviewRequest $request)
    {
        $validated = $request->validated();

        // Check if booking exists and belongs to user
        $booking = Booking::where('id', $validated['booking_id'])
            ->where('user_id', $request->user()->id)
            ->where('status', 'completed')
            ->first();

        if (!$booking) {
            return $this->error('Invalid booking or booking not completed', 422);
        }

        // Check if review already exists
        if ($booking->review) {
            return $this->error('You have already reviewed this booking', 422);
        }

        // Check if booking is within review period (30 days)
        if ($booking->booking_date->lt(now()->subDays(30))) {
            return $this->error('Review period has expired for this booking', 422);
        }

        DB::beginTransaction();

        try {
            // Create review
            $review = Review::create([
                'user_id' => $request->user()->id,
                'business_id' => $booking->business_id,
                'booking_id' => $booking->id,
                'rating' => $validated['rating'],
                'comment' => $validated['comment'] ?? null,
                'is_approved' => true, // Auto-approve for now, can add moderation
                'metadata' => [
                    'service' => $booking->service->name,
                    'booking_date' => $booking->booking_date->format('Y-m-d'),
                    'verified_purchase' => true
                ]
            ]);

            // Handle review aspects (if provided)
            if (!empty($validated['aspects'])) {
                foreach ($validated['aspects'] as $aspect => $rating) {
                    $review->aspects()->create([
                        'aspect' => $aspect,
                        'rating' => $rating
                    ]);
                }
            }

            // Handle photos
            if ($request->hasFile('photos')) {
                foreach ($request->file('photos') as $photo) {
                    $review->addMedia($photo)
                        ->toMediaCollection('photos');
                }
            }

            DB::commit();

            // Load relationships
            $review->load(['user', 'booking.service', 'media', 'aspects']);

            // Fire event
            event(new ReviewCreated($review));

            // Queue job to update business rating
            UpdateBusinessRating::dispatch($booking->business_id)
                ->delay(now()->addMinutes(5));

            // Log activity
            activity()
                ->performedOn($review)
                ->causedBy($request->user())
                ->withProperties([
                    'business' => $booking->business->name,
                    'rating' => $review->rating
                ])
                ->log('Review created');

            return $this->success(
                new ReviewResource($review),
                'Thank you for your review!',
                201
            );

        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->error(
                'Failed to submit review',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Update review
     */
    public function update(Request $request, Review $review)
    {
        $this->authorize('update', $review);

        $validated = $request->validate([
            'rating' => 'sometimes|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
            'aspects' => 'nullable|array',
            'aspects.*' => 'integer|min:1|max:5'
        ]);

        // Check if review can be edited (within 7 days)
        if ($review->created_at->lt(now()->subDays(7))) {
            return $this->error('Review can only be edited within 7 days of posting', 422);
        }

        DB::beginTransaction();

        try {
            $review->update($validated);

            // Update aspects if provided
            if (isset($validated['aspects'])) {
                $review->aspects()->delete();
                foreach ($validated['aspects'] as $aspect => $rating) {
                    $review->aspects()->create([
                        'aspect' => $aspect,
                        'rating' => $rating
                    ]);
                }
            }

            DB::commit();

            // Queue job to update business rating
            UpdateBusinessRating::dispatch($review->business_id)
                ->delay(now()->addMinutes(5));

            return $this->success(
                new ReviewResource($review->fresh(['user', 'booking.service', 'media', 'aspects'])),
                'Review updated successfully'
            );

        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->error(
                'Failed to update review',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Delete review
     */
    public function destroy(Review $review)
    {
        $this->authorize('delete', $review);

        $businessId = $review->business_id;

        $review->delete();

        // Update business rating
        UpdateBusinessRating::dispatch($businessId);

        return $this->success(null, 'Review deleted successfully');
    }

    /**
     * Mark review as helpful
     */
    public function helpful(Request $request, Review $review)
    {
        $user = $request->user();

        // Check if user already marked this review
        $existing = DB::table('review_helpful')
            ->where('review_id', $review->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            // Remove helpful mark
            DB::table('review_helpful')
                ->where('review_id', $review->id)
                ->where('user_id', $user->id)
                ->delete();

            $review->decrement('helpful_count');

            return $this->success([
                'marked_helpful' => false,
                'helpful_count' => $review->helpful_count - 1
            ], 'Helpful mark removed');
        } else {
            // Add helpful mark
            DB::table('review_helpful')->insert([
                'review_id' => $review->id,
                'user_id' => $user->id,
                'created_at' => now()
            ]);

            $review->increment('helpful_count');

            return $this->success([
                'marked_helpful' => true,
                'helpful_count' => $review->helpful_count + 1
            ], 'Marked as helpful');
        }
    }

    /**
     * Report review
     */
    public function report(Request $request, Review $review)
    {
        $validated = $request->validate([
            'reason' => 'required|string|in:spam,offensive,fake,other',
            'details' => 'required_if:reason,other|string|max:500'
        ]);

        // Check if already reported by this user
        $existing = DB::table('review_reports')
            ->where('review_id', $review->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if ($existing) {
            return $this->error('You have already reported this review', 422);
        }

        DB::table('review_reports')->insert([
            'review_id' => $review->id,
            'user_id' => $request->user()->id,
            'reason' => $validated['reason'],
            'details' => $validated['details'] ?? null,
            'created_at' => now()
        ]);

        // Auto-hide review if it gets too many reports
        $reportCount = DB::table('review_reports')
            ->where('review_id', $review->id)
            ->count();

        if ($reportCount >= 5) {
            $review->update(['is_approved' => false, 'hidden_reason' => 'Multiple reports']);
        }

        // Notify admins
        if ($reportCount >= 3) {
            // Send notification to admins
        }

        return $this->success(null, 'Review reported successfully');
    }
}