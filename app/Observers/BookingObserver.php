<?php
namespace App\Observers;

use App\Models\Booking;
use App\Events\BookingCreated;
use App\Events\BookingCancelled;
use App\Services\NotificationService;
use App\Jobs\SendBookingReminder;
use Carbon\Carbon;

class BookingObserver
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Handle the Booking "created" event.
     */
    public function created(Booking $booking): void
    {
        // Auto-assign staff if not specified
        if (!$booking->staff_id && $booking->service->staff->isNotEmpty()) {
            $availableStaff = $booking->service->staff()
                ->where('is_active', true)
                ->get()
                ->filter(function ($staff) use ($booking) {
                    return $staff->isAvailable(
                        $booking->booking_date->format('Y-m-d'),
                        $booking->start_time,
                        $booking->end_time
                    );
                })
                ->first();

            if ($availableStaff) {
                $booking->update(['staff_id' => $availableStaff->id]);
            }
        }

        // Schedule booking reminders
        $this->scheduleReminders($booking);

        // Fire booking created event
        event(new BookingCreated($booking));

        // Log activity
        activity()
            ->performedOn($booking)
            ->causedBy($booking->user)
            ->withProperties([
                'booking_ref' => $booking->booking_ref,
                'service' => $booking->service->name,
                'amount' => $booking->amount
            ])
            ->log('Booking created');
    }

    /**
     * Handle the Booking "updated" event.
     */
    public function updated(Booking $booking): void
    {
        $changes = $booking->getChanges();

        // Handle status changes
        if (isset($changes['status'])) {
            $this->handleStatusChange($booking, $booking->getOriginal('status'), $changes['status']);
        }

        // Handle payment status changes
        if (isset($changes['payment_status'])) {
            $this->handlePaymentStatusChange($booking, $changes['payment_status']);
        }

        // Handle date/time changes (rescheduling)
        if (isset($changes['booking_date']) || isset($changes['start_time'])) {
            $this->handleRescheduling($booking, $changes);
        }

        // Log activity
        activity()
            ->performedOn($booking)
            ->causedBy(auth()->user())
            ->withProperties(['changes' => $changes])
            ->log('Booking updated');
    }

    /**
     * Handle the Booking "deleting" event.
     */
    public function deleting(Booking $booking): void
    {
        // Cancel any scheduled reminder jobs
        $this->cancelScheduledReminders($booking);

        // Log activity
        activity()
            ->performedOn($booking)
            ->causedBy(auth()->user())
            ->log('Booking deleted');
    }

    /**
     * Schedule booking reminders
     */
    protected function scheduleReminders(Booking $booking): void
    {
        $bookingDateTime = Carbon::parse($booking->booking_date->format('Y-m-d') . ' ' . $booking->start_time);
        
        // 24-hour reminder
        $reminder24h = $bookingDateTime->copy()->subHours(24);
        if ($reminder24h->isFuture()) {
            SendBookingReminder::dispatch($booking)
                ->delay($reminder24h);
        }

        // 2-hour reminder
        $reminder2h = $bookingDateTime->copy()->subHours(2);
        if ($reminder2h->isFuture()) {
            SendBookingReminder::dispatch($booking)
                ->delay($reminder2h);
        }
    }

    /**
     * Handle booking status changes
     */
    protected function handleStatusChange(Booking $booking, string $oldStatus, string $newStatus): void
    {
        switch ($newStatus) {
            case 'confirmed':
                if ($oldStatus === 'pending') {
                    $this->notificationService->sendBookingConfirmation($booking);
                }
                break;

            case 'cancelled':
                event(new BookingCancelled($booking, auth()->user(), $booking->cancellation_reason));
                $this->notificationService->sendBookingCancellation($booking, $booking->cancellation_reason);
                $this->cancelScheduledReminders($booking);
                break;

            case 'completed':
                // Schedule review request
                \App\Jobs\SendReviewRequest::dispatch($booking)
                    ->delay(now()->addHours(2));
                break;

            case 'no_show':
                // Handle no-show logic
                $this->handleNoShow($booking);
                break;
        }
    }

    /**
     * Handle payment status changes
     */
    protected function handlePaymentStatusChange(Booking $booking, string $newStatus): void
    {
        switch ($newStatus) {
            case 'paid':
                if ($booking->status === 'pending') {
                    $booking->update(['status' => 'confirmed']);
                }
                break;

            case 'refunded':
                // Handle refund notifications
                $this->notificationService->sendRefundNotification($booking);
                break;
        }
    }

    /**
     * Handle booking rescheduling
     */
    protected function handleRescheduling(Booking $booking, array $changes): void
    {
        // Cancel old reminders
        $this->cancelScheduledReminders($booking);
        
        // Schedule new reminders
        $this->scheduleReminders($booking);
        
        // Send rescheduling notifications
        $this->notificationService->sendRescheduleNotification($booking, $changes);
    }

    /**
     * Handle no-show bookings
     */
    protected function handleNoShow(Booking $booking): void
    {
        // Apply no-show penalty if configured
        $business = $booking->business;
        $noShowFee = $business->settings['no_show_fee'] ?? 0;
        
        if ($noShowFee > 0) {
            // Create penalty charge
            \App\Models\Penalty::create([
                'booking_id' => $booking->id,
                'user_id' => $booking->user_id,
                'amount' => $noShowFee,
                'reason' => 'No-show penalty',
                'status' => 'pending'
            ]);
        }

        // Update customer's no-show count
        $booking->user->increment('no_show_count');
    }

    /**
     * Cancel scheduled reminder jobs
     */
    protected function cancelScheduledReminders(Booking $booking): void
    {
        // This would cancel queued jobs if using a queue system that supports it
        // Implementation depends on the queue driver used
    }
}
