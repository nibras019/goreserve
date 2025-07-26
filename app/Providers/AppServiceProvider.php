<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Pagination\Paginator;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Review;
use App\Observers\BookingObserver;
use App\Observers\PaymentObserver;
use App\Observers\ReviewObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind services to container
        $this->app->bind(
            \App\Services\PaymentService::class,
            \App\Services\PaymentService::class
        );

        $this->app->bind(
            \App\Services\BookingService::class,
            \App\Services\BookingService::class
        );

        $this->app->bind(
            \App\Services\NotificationService::class,
            \App\Services\NotificationService::class
        );

        $this->app->bind(
            \App\Services\ReportService::class,
            \App\Services\ReportService::class
        );

        $this->app->bind(
            \App\Services\GeolocationService::class,
            \App\Services\GeolocationService::class
        );

        // Development helpers - FIXED VERSION
        if ($this->app->environment('local')) {
            // Only register Telescope if it's actually installed
            if (class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
                $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            }
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Set default string length for MySQL
        Schema::defaultStringLength(191);

        // Use Bootstrap for pagination views
        Paginator::useBootstrapFive();

        // Register model observers
        Booking::observe(BookingObserver::class);
        Payment::observe(PaymentObserver::class);
        Review::observe(ReviewObserver::class);

        // Register custom validation rules
        \Validator::extend('working_hours', function ($attribute, $value, $parameters, $validator) {
            if (!is_array($value)) {
                return false;
            }

            foreach ($value as $day => $hours) {
                if (!is_array($hours) || !isset($hours['open']) || !isset($hours['close'])) {
                    continue;
                }

                if (strtotime($hours['open']) >= strtotime($hours['close'])) {
                    return false;
                }
            }

            return true;
        });

        // Super admin gate
        \Gate::before(function ($user, $ability) {
            return $user->hasRole('super-admin') ? true : null;
        });

        // Global query scopes
        if (config('app.env') === 'production') {
            // Only show approved businesses in production
            \App\Models\Business::addGlobalScope('approved', function ($builder) {
                $builder->where('status', 'approved');
            });
        }
    }
}