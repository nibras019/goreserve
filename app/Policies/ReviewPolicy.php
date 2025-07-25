<?php
// app/Policies/ReviewPolicy.php - Complete Implementation
namespace App\Policies;

use App\Models\Review;
use App\Models\User;

class ReviewPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true; // Anyone can view approved reviews
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Review $review): bool
    {
        // Approved reviews can be viewed by anyone
        if ($review->is_approved) {
            return true;
        }

        // Author can view their own review
        if ($user->id === $review->user_id) {
            return true;
        }

        // Business owner can view reviews for their business
        if ($user->hasRole('vendor') && $user->business) {
            return $user->business->id === $review->business_id;
        }

        // Admin can view any review
        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Only customers can create reviews
        return $user->hasRole('customer');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Review $review): bool
    {
        // Author can edit their own review within 7 days
        if ($user->id === $review->user_id) {
            return $review->created_at->gt(now()->subDays(7));
        }

        // Admin can update any review
        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Review $review): bool
    {
        // Author can delete their own review
        if ($user->id === $review->user_id) {
            return true;
        }

        // Admin can delete any review
        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can moderate the review.
     */
    public function moderate(User $user, Review $review): bool
    {
        return $user->hasRole('admin') || $user->hasRole('moderator');
    }

    /**
     * Determine whether the user can respond to the review.
     */
    public function respond(User $user, Review $review): bool
    {
        // Business owner can respond to reviews for their business
        if ($user->hasRole('vendor') && $user->business) {
            return $user->business->id === $review->business_id;
        }

        return false;
    }

    /**
     * Determine whether the user can report the review.
     */
    public function report(User $user, Review $review): bool
    {
        // Any authenticated user can report a review (except the author)
        return $user->id !== $review->user_id;
    }

    /**
     * Determine whether the user can mark review as helpful.
     */
    public function markHelpful(User $user, Review $review): bool
    {
        // Any authenticated user can mark as helpful (except the author)
        return $user->id !== $review->user_id;
    }

    /**
     * Determine whether the user can approve the review.
     */
    public function approve(User $user, Review $review): bool
    {
        return $user->hasRole('admin') && !$review->is_approved;
    }

    /**
     * Determine whether the user can hide the review.
     */
    public function hide(User $user, Review $review): bool
    {
        return $user->hasRole('admin') && $review->is_approved;
    }

    /**
     * Determine whether the user can restore a deleted review.
     */
    public function restore(User $user, Review $review): bool
    {
        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can permanently delete the review.
     */
    public function forceDelete(User $user, Review $review): bool
    {
        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can view review reports.
     */
    public function viewReports(User $user, Review $review): bool
    {
        return $user->hasRole('admin') || $user->hasRole('moderator');
    }

    /**
     * Determine whether the user can handle review reports.
     */
    public function handleReports(User $user, Review $review): bool
    {
        return $user->hasRole('admin') || $user->hasRole('moderator');
    }

    /**
     * Determine whether the user can feature the review.
     */
    public function feature(User $user, Review $review): bool
    {
        // Business owner can feature reviews for their business
        if ($user->hasRole('vendor') && $user->business) {
            return $user->business->id === $review->business_id && $review->is_approved;
        }

        // Admin can feature any approved review
        return $user->hasRole('admin') && $review->is_approved;
    }

    /**
     * Determine whether the user can pin the review.
     */
    public function pin(User $user, Review $review): bool
    {
        // Business owner can pin reviews for their business
        if ($user->hasRole('vendor') && $user->business) {
            return $user->business->id === $review->business_id && $review->is_approved;
        }

        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can view review analytics.
     */
    public function viewAnalytics(User $user, Review $review): bool
    {
        // Business owner can view analytics for their business reviews
        if ($user->hasRole('vendor') && $user->business) {
            return $user->business->id === $review->business_id;
        }

        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can edit business response.
     */
    public function editResponse(User $user, Review $review): bool
    {
        // Business owner can edit their response within 24 hours
        if ($user->hasRole('vendor') && $user->business && $user->business->id === $review->business_id) {
            return !$review->business_responded_at || 
                   $review->business_responded_at->gt(now()->subHours(24));
        }

        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can delete business response.
     */
    public function deleteResponse(User $user, Review $review): bool
    {
        // Business owner can delete their response
        if ($user->hasRole('vendor') && $user->business) {
            return $user->business->id === $review->business_id && $review->business_response;
        }

        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can view review history.
     */
    public function viewHistory(User $user, Review $review): bool
    {
        // Author can view their own review history
        if ($user->id === $review->user_id) {
            return true;
        }

        // Business owner can view history of reviews for their business
        if ($user->hasRole('vendor') && $user->business) {
            return $user->business->id === $review->business_id;
        }

        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can export reviews.
     */
    public function export(User $user): bool
    {
        return $user->hasRole('admin') || 
               ($user->hasRole('vendor') && $user->business);
    }

    /**
     * Determine whether the user can bulk moderate reviews.
     */
    public function bulkModerate(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('moderator');
    }

    /**
     * Determine whether the user can set review guidelines.
     */
    public function setGuidelines(User $user): bool
    {
        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can configure review settings.
     */
    public function configureSettings(User $user): bool
    {
        // Business owner can configure review settings for their business
        if ($user->hasRole('vendor') && $user->business) {
            return true;
        }

        return $user->hasRole('admin');
    }
}