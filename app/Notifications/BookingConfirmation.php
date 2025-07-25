<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingConfirmation extends Notification implements ShouldQueue
{
    use Queueable;

    protected $booking;

    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Booking Confirmation - ' . $this->booking->booking_ref)
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Your booking has been confirmed.')
            ->line('**Booking Details:**')
            ->line('Service: ' . $this->booking->service->name)
            ->line('Date: ' . $this->booking->booking_date->format('l, F j, Y'))
            ->line('Time: ' . $this->booking->start_time . ' - ' . $this->booking->end_time)
            ->line('Location: ' . $this->booking->business->address)
            ->action('View Booking', url('/bookings/' . $this->booking->id))
            ->line('Thank you for choosing ' . $this->booking->business->name . '!');
    }

    public function toArray($notifiable): array
    {
        return [
            'booking_id' => $this->booking->id,
            'booking_ref' => $this->booking->booking_ref,
            'message' => 'Your booking for ' . $this->booking->service->name . ' has been confirmed.',
            'booking_date' => $this->booking->booking_date->format('Y-m-d'),
            'start_time' => $this->booking->start_time
        ];
    }
}