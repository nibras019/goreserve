<?php

// app/Providers/AuthServiceProvider.php
namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\Business;
use App\Models\Booking;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Review;
use App\Policies\BusinessPolicy;
use App\Policies\BookingPolicy;
use App\Policies\ServicePolicy;
use App\Policies\StaffPolicy;
use App\Policies\ReviewPolicy;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Business::class => BusinessPolicy::class,
        Booking::class => BookingPolicy::class,
        Service::class => ServicePolicy::class,
        Staff::class => StaffPolicy::class,
        Review::class => ReviewPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        // Define gates
        Gate::define('access-admin', function ($user) {
            return $user->hasRole('admin') || $user->hasRole('super-admin');
        });

        Gate::define('access-vendor-panel', function ($user) {
            return $user->hasRole('vendor') && $user->business;
        });

        Gate::define('manage-business', function ($user, $business) {
            return $user->id === $business->user_id || $user->hasRole('admin');
        });

        Gate::define('moderate-content', function ($user) {
            return $user->hasRole('admin') || $user->hasRole('moderator');
        });

        Gate::define('view-analytics', function ($user, $business = null) {
            if ($user->hasRole('admin')) {
                return true;
            }

            if ($business && $user->hasRole('vendor')) {
                return $user->id === $business->user_id;
            }

            return false;
        });

        // Business-specific gates
        Gate::define('approve-business', function ($user) {
            return $user->hasRole('admin');
        });

        Gate::define('suspend-business', function ($user) {
            return $user->hasRole('admin');
        });

        // Booking-specific gates
        Gate::define('cancel-booking', function ($user, $booking) {
            return $user->id === $booking->user_id || 
                   $user->id === $booking->business->user_id ||
                   $user->hasRole('admin');
        });

        Gate::define('refund-booking', function ($user, $booking) {
            return $user->id === $booking->business->user_id || $user->hasRole('admin');
        });

        // Review-specific gates
        Gate::define('moderate-review', function ($user) {
            return $user->hasRole('admin') || $user->hasRole('moderator');
        });

        Gate::define('respond-to-review', function ($user, $review) {
            return $user->id === $review->business->user_id;
        });
    }
}

