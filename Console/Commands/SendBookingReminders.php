<?php
namespace App\Console\Commands;

use App\Models\Booking;
use App\Jobs\SendBookingReminder;
use Illuminate\Console\Command;
use Carbon\Carbon;

class SendBookingReminders extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'bookings:send-reminders 
                            {--hours=24 : Hours before booking to send reminder}
                            {--dry-run : Run without actually sending reminders}
                            {--limit=100 : Maximum number of reminders to send}';

    /**
     * The console command description.
     */
    protected $description = 'Send booking reminders to customers';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $hoursBeforeBooking = $this->option('hours');
        $isDryRun = $this->option('dry-run');
        $limit = $this->option('limit');

        $this->info("🔔 Sending booking reminders...");
        $this->info("Reminder time: {$hoursBeforeBooking} hours before booking");

        if ($isDryRun) {
            $this->warn("DRY RUN MODE - No reminders will be sent");
        }

        // Calculate target time for reminders
        $targetTime = now()->addHours($hoursBeforeBooking);
        $startOfHour = $targetTime->copy()->startOfHour();
        $endOfHour = $targetTime->copy()->endOfHour();

        // Find bookings that need reminders
        $bookingsQuery = Booking::with(['user', 'business', 'service'])
            ->where('status', 'confirmed')
            ->whereBetween('booking_date', [$startOfHour->toDateString(), $endOfHour->toDateString()])
            ->where(function ($query) use ($startOfHour, $endOfHour) {
                $query->whereTime('start_time', '>=', $startOfHour->format('H:i'))
                      ->whereTime('start_time', '<=', $endOfHour->format('H:i'));
            })
            ->whereDoesntHave('reminders', function ($query) use ($hoursBeforeBooking) {
                $query->where('hours_before', $hoursBeforeBooking)
                      ->where('sent_at', '>', now()->subHours(1)); // Prevent duplicates
            });

        if ($limit) {
            $bookingsQuery->limit($limit);
        }

        $bookings = $bookingsQuery->get();

        $this->info("Found {$bookings->count()} bookings requiring reminders.");

        if ($bookings->isEmpty()) {
            $this->info("✅ No reminders to send.");
            return Command::SUCCESS;
        }

        $this->output->progressStart($bookings->count());

        $successCount = 0;
        $failureCount = 0;

        foreach ($bookings as $booking) {
            try {
                if (!$isDryRun) {
                    // Queue the reminder job
                    SendBookingReminder::dispatch($booking, $hoursBeforeBooking);

                    // Create reminder record
                    $booking->reminders()->create([
                        'type' => 'booking_reminder',
                        'hours_before' => $hoursBeforeBooking,
                        'sent_at' => now(),
                        'channel' => 'email'
                    ]);
                }

                $successCount++;
                $this->output->progressAdvance();
                
                if ($this->output->isVerbose()) {
                    $this->line("  ✅ Reminder queued for booking {$booking->booking_ref}");
                }

            } catch (\Exception $e) {
                $failureCount++;
                $this->error("  ❌ Failed to queue reminder for booking {$booking->booking_ref}: {$e->getMessage()}");
                
                \Log::error('Failed to queue booking reminder', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->output->progressFinish();
        $this->newLine();

        // Show summary
        $this->info("📊 Reminder Summary:");
        $this->info("  ✅ Successfully queued: {$successCount} reminders");
        
        if ($failureCount > 0) {
            $this->error("  ❌ Failed to queue: {$failureCount} reminders");
        }

        if ($isDryRun) {
            $this->warn("🔍 Dry run completed. No reminders were actually sent.");
        }

        return $failureCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
