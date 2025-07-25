<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        Commands\SendBookingReminders::class,
        Commands\CleanupExpiredBookings::class,
        Commands\GenerateMonthlyReports::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Send booking reminders every hour
        $schedule->command('bookings:send-reminders')
            ->hourly()
            ->between('8:00', '20:00') // Only send between 8 AM and 8 PM
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/booking-reminders.log'))
            ->onFailure(function () {
                // Notify admin of failure
                \Log::critical('Booking reminder job failed');
            });

        // Send booking reminders for next day at 9 AM
        $schedule->command('bookings:send-reminders --hours=24')
            ->dailyAt('09:00')
            ->withoutOverlapping()
            ->runInBackground();

        // Send same-day reminders at multiple times
        $schedule->command('bookings:send-reminders --hours=2')
            ->dailyAt('08:00')
            ->dailyAt('12:00')
            ->dailyAt('16:00')
            ->withoutOverlapping()
            ->runInBackground();

        // Clean up expired bookings every 30 minutes
        $schedule->command('bookings:cleanup-expired')
            ->everyThirtyMinutes()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/booking-cleanup.log'));

        // Generate monthly reports on the 1st of each month at 2 AM
        $schedule->command('reports:generate-monthly --queue')
            ->monthlyOn(1, '02:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/monthly-reports.log'))
            ->onSuccess(function () {
                \Log::info('Monthly reports generated successfully');
            })
            ->onFailure(function () {
                // Send notification to admin
                \App\Models\User::role('admin')->each(function ($admin) {
                    $admin->notify(new \App\Notifications\SystemAlert(
                        'Monthly report generation failed',
                        'error'
                    ));
                });
            });

        // Generate weekly summary reports every Monday at 6 AM
        $schedule->command('reports:generate-weekly --type=summary')
            ->weeklyOn(1, '06:00')
            ->withoutOverlapping()
            ->runInBackground();

        // Database backup
        $schedule->command('backup:run --only-db')
            ->dailyAt('03:00')
            ->withoutOverlapping();

        // Clean old activity logs (older than 90 days)
        $schedule->command('activitylog:clean')
            ->daily()
            ->withoutOverlapping();

        // Update business ratings based on recent reviews
        $schedule->command('business:update-ratings')
            ->twiceDaily(1, 13)
            ->withoutOverlapping();

        // Send payment reminders for unpaid bookings
        $schedule->command('payments:send-reminders')
            ->dailyAt('10:00')
            ->withoutOverlapping();

        // Clear expired password reset tokens
        $schedule->command('auth:clear-resets')
            ->weekly();

        // Optimize database tables
        $schedule->command('db:optimize')
            ->weekly()
            ->sundays()
            ->at('04:00');

        // Generate sitemap
        $schedule->command('sitemap:generate')
            ->daily()
            ->at('02:00');

        // Check and notify about low availability
        $schedule->command('availability:check-low')
            ->dailyAt('09:00')
            ->weekdays();

        // Sync with external calendar services
        $schedule->command('calendar:sync')
            ->everyFifteenMinutes()
            ->between('06:00', '22:00');

        // Process queued notifications
        $schedule->command('queue:work --queue=notifications --tries=3 --max-time=300')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        // Monitor system health
        $schedule->command('monitor:health')
            ->everyFiveMinutes()
            ->runInBackground()
            ->withoutOverlapping()
            ->onFailure(function () {
                // Critical: System health check failed
                \Log::critical('System health check failed');
            });
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }

    /**
     * Get the timezone that should be used by default for scheduled events.
     */
    protected function scheduleTimezone(): string
    {
        return config('app.timezone', 'UTC');
    }
}