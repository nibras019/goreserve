<?php

namespace App\Observers;

use App\Models\Review;
use App\Jobs\UpdateBusinessRating;

class ReviewObserver
{
    /**
     * Handle the Review "created" event.
     */
    public function created(Review $review): void
    {
        // Update business rating
        UpdateBusinessRating::dispatch($review->business_id)->delay(now()->addMinutes(5));

        // Notify business owner
        $review->business->owner->notify(new \App\Notifications\NewReview($review));

        // Check if review needs moderation
        if ($this->needsModeration($review)) {
            $review->update(['is_approved' => false]);
            
            // Notify admins for review
            $admins = \App\Models\User::role('admin')->get();
            foreach ($admins as $admin) {
                $admin->notify(new \App\Notifications\ReviewNeedsModeration($review));
            }
        }

        // Log activity
        activity()
            ->performedOn($review)
            ->causedBy($review->user)
            ->withProperties([
                'rating' => $review->rating,
                'business' => $review->business->name,
                'booking_ref' => $review->booking->booking_ref
            ])
            ->log('Review created');
    }

    /**
     * Handle the Review "updated" event.
     */
    public function updated(Review $review): void
    {
        $changes = $review->getChanges();

        // If rating changed, update business rating
        if (isset($changes['rating']) || isset($changes['is_approved'])) {
            UpdateBusinessRating::dispatch($review->business_id)->delay(now()->addMinutes(5));
        }

        // Handle approval status changes
        if (isset($changes['is_approved'])) {
            $this->handleApprovalStatusChange($review, $changes['is_approved']);
        }

        // Log activity
        activity()
            ->performedOn($review)
            ->causedBy(auth()->user())
            ->withProperties(['changes' => $changes])
            ->log('Review updated');
    }

    /**
     * Handle the Review "deleted" event.
     */
    public function deleted(Review $review): void
    {
        // Update business rating
        UpdateBusinessRating::dispatch($review->business_id)->delay(now()->addMinutes(5));

        // Log activity
        activity()
            ->performedOn($review)
            ->causedBy(auth()->user())
            ->log('Review deleted');
    }

    /**
     * Check if review needs moderation
     */
    protected function needsModeration(Review $review): bool
    {
        // Auto-moderate reviews with very low ratings
        if ($review->rating <= 2) {
            return true;
        }

        // Check for inappropriate content
        $inappropriateWords = config('moderation.inappropriate_words', []);
        $content = strtolower($review->comment ?? '');
        
        foreach ($inappropriateWords as $word) {
            if (str_contains($content, strtolower($word))) {
                return true;
            }
        }

        // Check user's review history
        $userReviewCount = $review->user->reviews()->count();
        if ($userReviewCount <= 2) {
            return true; // New users need moderation
        }

        return false;
    }

    /**
     * Handle review approval status changes
     */
    protected function handleApprovalStatusChange(Review $review, bool $isApproved): void
    {
        if ($isApproved) {
            // Notify user that their review was approved
            $review->user->notify(new \App\Notifications\ReviewApproved($review));
        } else {
            // Notify user that their review was hidden
            if ($review->hidden_reason) {
                $review->user->notify(new \App\Notifications\ReviewHidden($review, $review->hidden_reason));
            }
        }
    }
}