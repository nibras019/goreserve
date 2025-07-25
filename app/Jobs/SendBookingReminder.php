<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Notifications\BookingReminder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendBookingReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $booking;

    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    public function handle(): void
    {
        if ($this->booking->status === 'confirmed') {
            $this->booking->user->notify(new BookingReminder($this->booking));
        }
    }
}