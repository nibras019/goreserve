<?php

namespace App\Events;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BookingCancelled implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The booking instance.
     *
     * @var \App\Models\Booking
     */
    public $booking;

    /**
     * The user who cancelled the booking.
     *
     * @var \App\Models\User|string|null
     */
    public $cancelledBy;

    /**
     * The cancellation reason.
     *
     * @var string|null
     */
    public $reason;

    /**
     * Whether a refund is being processed.
     *
     * @var bool
     */
    public $refundInitiated;

    /**
     * Create a new event instance.
     *
     * @param  \App\Models\Booking  $booking
     * @param  \App\Models\User|string|null  $cancelledBy
     * @param  string|null  $reason
     * @param  bool  $refundInitiated
     * @return void
     */
    public function __construct(
        Booking $booking, 
        $cancelledBy = null, 
        ?string $reason = null,
        bool $refundInitiated = false
    ) {
        $this->booking = $booking->load(['user', 'business', 'service', 'staff']);
        $this->cancelledBy = $cancelledBy;
        $this->reason = $reason;
        $this->refundInitiated = $refundInitiated;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            // Private channel for the customer
            new PrivateChannel('user.' . $this->booking->user_id),
            
            // Private channel for the business owner
            new PrivateChannel('business.' . $this->booking->business_id),
            
            // Presence channel for business dashboard
            new PresenceChannel('business.' . $this->booking->business_id . '.dashboard'),
        ];

        // Add staff channel if assigned
        if ($this->booking->staff_id) {
            $channels[] = new PrivateChannel('staff.' . $this->booking->staff_id);
        }

        // Add admin channel if cancelled by system
        if ($this->cancelledBy === 'system') {
            $channels[] = new PrivateChannel('admin.notifications');
        }

        return array_filter($channels);
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $cancelledByData = null;
        
        if ($this->cancelledBy instanceof User) {
            $cancelledByData = [
                'type' => 'user',
                'id' => $this->cancelledBy->id,
                'name' => $this->cancelledBy->name,
                'role' => $this->cancelledBy->roles->first()?->name,
            ];
        } elseif (is_string($this->cancelledBy)) {
            $cancelledByData = [
                'type' => $this->cancelledBy,
                'id' => null,
                'name' => ucfirst($this->cancelledBy),
                'role' => $this->cancelledBy,
            ];
        }

        return [
            'booking' => [
                'id' => $this->booking->id,
                'booking_ref' => $this->booking->booking_ref,
                'booking_date' => $this->booking->booking_date->format('Y-m-d'),
                'start_time' => $this->booking->start_time,
                'end_time' => $this->booking->end_time,
                'amount' => $this->booking->amount,
                'cancelled_at' => $this->booking->cancelled_at?->toIso8601String(),
                'customer' => [
                    'id' => $this->booking->user->id,
                    'name' => $this->booking->user->name,
                    'email' => $this->booking->user->email,
                ],
                'service' => [
                    'id' => $this->booking->service->id,
                    'name' => $this->booking->service->name,
                ],
                'staff' => $this->booking->staff ? [
                    'id' => $this->booking->staff->id,
                    'name' => $this->booking->staff->name,
                ] : null,
            ],
            'cancellation' => [
                'cancelled_by' => $cancelledByData,
                'reason' => $this->reason,
                'refund_initiated' => $this->refundInitiated,
                'cancelled_at' => now()->toIso8601String(),
            ],
            'business' => [
                'id' => $this->booking->business->id,
                'name' => $this->booking->business->name,
            ],
            'impact' => [
                'freed_slot' => true,
                'lost_revenue' => $this->booking->amount,
                'notice_hours' => $this->calculateNoticeHours(),
            ],
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'booking.cancelled';
    }

    /**
     * Calculate hours of notice given before cancellation
     *
     * @return float|null
     */
    private function calculateNoticeHours(): ?float
    {
        if (!$this->booking->cancelled_at) {
            return null;
        }

        $bookingDateTime = $this->booking->booking_date->setTimeFromTimeString($this->booking->start_time);
        $cancelledAt = $this->booking->cancelled_at;

        return round($bookingDateTime->diffInHours($cancelledAt, false), 1);
    }

    /**
     * Get the tags that should be assigned to the event.
     *
     * @return array
     */
    public function tags(): array
    {
        return [
            'booking:' . $this->booking->id,
            'business:' . $this->booking->business_id,
            'user:' . $this->booking->user_id,
            'booking:cancelled',
            'cancelled_by:' . (is_string($this->cancelledBy) ? $this->cancelledBy : 'user'),
        ];
    }

    /**
     * Determine if this event should broadcast.
     *
     * @return bool
     */
    public function broadcastWhen(): bool
    {
        return config('broadcasting.connections.pusher.key') !== null;
    }
}