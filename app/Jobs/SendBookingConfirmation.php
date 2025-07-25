<?php
// app/Jobs/SendBookingConfirmation.php

namespace App\Jobs;

use App\Models\Booking;
use App\Notifications\BookingConfirmation;
use App\Notifications\NewBookingNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendBookingConfirmation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $booking;

    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    public function handle(): void
    {
        try {
            // Load necessary relationships
            $this->booking->load(['user', 'business.owner', 'service', 'staff']);

            // Send confirmation to customer
            $this->booking->user->notify(new BookingConfirmation($this->booking));

            // Send notification to business owner
            $this->booking->business->owner->notify(new NewBookingNotification($this->booking));

            // Send notification to assigned staff if any
            if ($this->booking->staff) {
                $this->booking->staff->notify(new NewBookingNotification($this->booking));
            }

            Log::info('Booking confirmation sent successfully', [
                'booking_id' => $this->booking->id,
                'booking_ref' => $this->booking->booking_ref
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send booking confirmation', [
                'booking_id' => $this->booking->id,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendBookingConfirmation job failed', [
            'booking_id' => $this->booking->id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}