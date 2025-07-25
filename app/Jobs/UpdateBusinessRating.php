<?php

namespace App\Jobs;

use App\Models\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class UpdateBusinessRating implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $businessId;

    public function __construct($businessId)
    {
        $this->businessId = $businessId;
    }

    public function handle(): void
    {
        try {
            $business = Business::find($this->businessId);
            
            if (!$business) {
                return;
            }

            // Calculate new rating and review count
            $reviewStats = $business->reviews()
                ->where('is_approved', true)
                ->selectRaw('AVG(rating) as average_rating, COUNT(*) as total_reviews')
                ->first();

            $averageRating = $reviewStats->average_rating ? round($reviewStats->average_rating, 2) : 0;
            $totalReviews = $reviewStats->total_reviews ?? 0;

            // Update business
            $business->update([
                'rating' => $averageRating,
                'total_reviews' => $totalReviews
            ]);

            \Log::info('Business rating updated', [
                'business_id' => $this->businessId,
                'rating' => $averageRating,
                'total_reviews' => $totalReviews
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to update business rating', [
                'business_id' => $this->businessId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }
}