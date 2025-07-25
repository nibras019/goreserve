<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Review extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'user_id',
        'business_id', 
        'booking_id',
        'rating',
        'comment',
        'is_approved',
        'hidden_reason',
        'business_response',
        'business_responded_at',
        'helpful_count',
        'metadata'
    ];

    protected $casts = [
        'is_approved' => 'boolean',
        'business_responded_at' => 'datetime',
        'metadata' => 'array'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function aspects()
    {
        return $this->hasMany(ReviewAspect::class);
    }

    public function reports()
    {
        return $this->hasMany(ReviewReport::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photos')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/gif']);
    }

    public function scopeApproved($query)
    {
        return $query->where('is_approved', true);
    }

    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeWithRating($query, $rating)
    {
        return $query->where('rating', $rating);
    }

    public function scopeVerifiedPurchase($query)
    {
        return $query->whereHas('booking', function ($q) {
            $q->where('status', 'completed');
        });
    }

    public function isVerifiedPurchase()
    {
        return $this->booking && $this->booking->status === 'completed';
    }

    public function canBeEditedBy(User $user)
    {
        return $this->user_id === $user->id && 
               $this->created_at->gt(now()->subDays(7));
    }

    public function markAsHelpful(User $user)
    {
        if (!$this->hasBeenMarkedHelpfulBy($user)) {
            \DB::table('review_helpful')->insert([
                'review_id' => $this->id,
                'user_id' => $user->id,
                'created_at' => now()
            ]);
            
            $this->increment('helpful_count');
            return true;
        }
        
        return false;
    }

    public function removeHelpfulMark(User $user)
    {
        $deleted = \DB::table('review_helpful')
            ->where('review_id', $this->id)
            ->where('user_id', $user->id)
            ->delete();
            
        if ($deleted) {
            $this->decrement('helpful_count');
            return true;
        }
        
        return false;
    }

    public function hasBeenMarkedHelpfulBy(User $user)
    {
        return \DB::table('review_helpful')
            ->where('review_id', $this->id)
            ->where('user_id', $user->id)
            ->exists();
    }
}