<?php

namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use App\Events\BookingCreated;
use App\Events\BookingCancelled;
use App\Events\PaymentCompleted;
use App\Events\ReviewCreated;
use App\Listeners\SendBookingNotification;
use App\Listeners\UpdateBookingStats;
use App\Listeners\LogPaymentActivity;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
            \App\Listeners\SendWelcomeEmail::class,
        ],

        BookingCreated::class => [
            SendBookingNotification::class,
            UpdateBookingStats::class,
            \App\Listeners\ScheduleBookingReminders::class,
        ],

        BookingCancelled::class => [
            \App\Listeners\ProcessBookingCancellation::class,
            \App\Listeners\UpdateAvailability::class,
            \App\Listeners\ProcessRefund::class,
        ],

        PaymentCompleted::class => [
            LogPaymentActivity::class,
            \App\Listeners\SendPaymentReceipt::class,
            \App\Listeners\UpdateBusinessRevenue::class,
        ],

        ReviewCreated::class => [
            \App\Listeners\NotifyBusinessOwner::class,
            \App\Listeners\CheckReviewModerationNeeded::class,
        ],

        \App\Events\BusinessApproved::class => [
            \App\Listeners\SendBusinessApprovalNotification::class,
            \App\Listeners\CreateBusinessWelcomePackage::class,
        ],

        \App\Events\BusinessSuspended::class => [
            \App\Listeners\CancelFutureBookings::class,
            \App\Listeners\NotifyAffectedCustomers::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
