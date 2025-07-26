<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Notifications\BookingExpired;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CleanupExpiredBookings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bookings:cleanup-expired 
                            {--hours=2 : Hours after creation to consider booking expired}
                            {--dry-run : Run without actually cancelling bookings}
                            {--notify : Send notifications to users about expired bookings}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cancel unpaid bookings that have expired and release their time slots';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $expirationHours = $this->option('hours');
        $isDryRun = $this->option('dry-run');
        $shouldNotify = $this->option('notify');

        $this->info("🧹 Starting expired bookings cleanup...");
        $this->info("Expiration time: {$expirationHours} hours after creation");
        
        if ($isDryRun) {
            $this->warn("DRY RUN MODE - No bookings will be cancelled");
        }

        // Find expired unpaid bookings
        $expiredBookings = Booking::with(['user', 'business', 'service', 'staff'])
            ->where('status', 'pending')
            ->where('payment_status', 'pending')
            ->where('created_at', '<', now()->subHours($expirationHours))
            ->where(function ($query) {
                // Don't cancel if booking date has already passed
                $query->where('booking_date', '>', now()->toDateString())
                    ->orWhere(function ($q) {
                        $q->where('booking_date', now()->toDateString())
                          ->whereTime('start_time', '>', now()->format('H:i:s'));
                    });
            })
            ->orderBy('created_at', 'asc')
            ->get();

        $this->info("Found {$expiredBookings->count()} expired bookings to clean up.");

        if ($expiredBookings->isEmpty()) {
            $this->info("✅ No expired bookings found.");
            return Command::SUCCESS;
        }

        // Display table of bookings to be cancelled
        $this->table(
            ['Booking Ref', 'Customer', 'Service', 'Date & Time', 'Created', 'Amount'],
            $expiredBookings->map(function ($booking) {
                return [
                    $booking->booking_ref,
                    $booking->user->name,
                    $booking->service->name,
                    $booking->booking_date->format('M d') . ' ' . $booking->start_time,
                    $booking->created_at->diffForHumans(),
                    '$' . number_format($booking->amount, 2)
                ];
            })
        );

        if ($isDryRun) {
            $this->warn("🔍 Dry run completed. Above bookings would be cancelled.");
            return Command::SUCCESS;
        }

        if (!$this->confirm('Do you want to proceed with cancelling these bookings?')) {
            $this->info("Operation cancelled by user.");
            return Command::SUCCESS;
        }

        $this->output->progressStart($expiredBookings->count());

        $successCount = 0;
        $failureCount = 0;
        $totalAmountReleased = 0;

        foreach ($expiredBookings as $booking) {
            DB::beginTransaction();
            
            try {
                // Store original status for rollback if needed
                $originalStatus = $booking->status;
                
                // Cancel the booking
                $booking->update([
                    'status' => 'cancelled',
                    'cancellation_reason' => 'Payment timeout - booking expired after ' . $expirationHours . ' hours',
                    'cancelled_at' => now(),
                    'cancelled_by' => 'system'
                ]);

                // Log the cancellation
                $booking->activity_logs()->create([
                    'action' => 'auto_cancelled',
                    'description' => 'Booking automatically cancelled due to payment timeout',
                    'performed_by' => 'system',
                    'metadata' => [
                        'expiration_hours' => $expirationHours,
                        'created_at' => $booking->created_at->toDateTimeString(),
                        'expired_at' => now()->toDateTimeString()
                    ]
                ]);

                // Release any held payment authorizations
                if ($booking->payment_intent_id) {
                    try {
                        app(\App\Services\PaymentService::class)->cancelPaymentIntent($booking->payment_intent_id);
                    } catch (\Exception $e) {
                        \Log::warning("Failed to cancel payment intent for booking {$booking->booking_ref}", [
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                // Send notification if enabled
                if ($shouldNotify) {
                    try {
                        $booking->user->notify(new BookingExpired($booking));
                    } catch (\Exception $e) {
                        \Log::error("Failed to send expiration notification", [
                            'booking_id' => $booking->id,
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                DB::commit();
                
                $successCount++;
                $totalAmountReleased += $booking->amount;
                
                $this->output->progressAdvance();
                
                $this->line("  ✅ Cancelled: {$booking->booking_ref}");
                
            } catch (\Exception $e) {
                DB::rollBack();
                
                $failureCount++;
                
                $this->error("  ❌ Failed to cancel booking {$booking->booking_ref}: {$e->getMessage()}");
                
                \Log::error('Failed to cleanup expired booking', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        $this->output->progressFinish();

        // Show summary
        $this->newLine();
        $this->info("📊 Cleanup Summary:");
        $this->info("  ✅ Successfully cancelled: {$successCount} bookings");
        $this->info("  💰 Total amount released: $" . number_format($totalAmountReleased, 2));
        
        if ($failureCount > 0) {
            $this->error("  ❌ Failed to cancel: {$failureCount} bookings");
        }

        if ($shouldNotify) {
            $this->info("  📧 Notifications sent to affected users");
        }

        // Update business statistics
        $this->updateBusinessStats($expiredBookings->pluck('business_id')->unique());

        return $failureCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Update business statistics after cleanup
     */
    private function updateBusinessStats($businessIds)
    {
        foreach ($businessIds as $businessId) {
            try {
                DB::table('businesses')
                    ->where('id', $businessId)
                    ->update([
                        'last_cleanup_at' => now(),
                        'updated_at' => now()
                    ]);
            } catch (\Exception $e) {
                \Log::error("Failed to update business stats", [
                    'business_id' => $businessId,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}