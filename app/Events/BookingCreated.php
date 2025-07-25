<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BookingCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The booking instance.
     *
     * @var \App\Models\Booking
     */
    public $booking;

    /**
     * Additional context data
     *
     * @var array
     */
    public $context;

    /**
     * Create a new event instance.
     *
     * @param  \App\Models\Booking  $booking
     * @param  array  $context
     * @return void
     */
    public function __construct(Booking $booking, array $context = [])
    {
        $this->booking = $booking->load(['user', 'business', 'service', 'staff']);
        $this->context = $context;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            // Private channel for the customer
            new PrivateChannel('user.' . $this->booking->user_id),
            
            // Private channel for the business owner
            new PrivateChannel('business.' . $this->booking->business_id),
            
            // Private channel for the assigned staff (if any)
            $this->booking->staff_id 
                ? new PrivateChannel('staff.' . $this->booking->staff_id)
                : null,
            
            // Presence channel for business dashboard
            new PresenceChannel('business.' . $this->booking->business_id . '.dashboard'),
        ];
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'booking' => [
                'id' => $this->booking->id,
                'booking_ref' => $this->booking->booking_ref,
                'status' => $this->booking->status,
                'payment_status' => $this->booking->payment_status,
                'booking_date' => $this->booking->booking_date->format('Y-m-d'),
                'start_time' => $this->booking->start_time,
                'end_time' => $this->booking->end_time,
                'amount' => $this->booking->amount,
                'created_at' => $this->booking->created_at->toIso8601String(),
                'customer' => [
                    'id' => $this->booking->user->id,
                    'name' => $this->booking->user->name,
                    'email' => $this->booking->user->email,
                    'phone' => $this->booking->user->phone,
                ],
                'service' => [
                    'id' => $this->booking->service->id,
                    'name' => $this->booking->service->name,
                    'duration' => $this->booking->service->duration,
                    'price' => $this->booking->service->price,
                ],
                'staff' => $this->booking->staff ? [
                    'id' => $this->booking->staff->id,
                    'name' => $this->booking->staff->name,
                ] : null,
                'business' => [
                    'id' => $this->booking->business->id,
                    'name' => $this->booking->business->name,
                    'phone' => $this->booking->business->phone,
                    'address' => $this->booking->business->address,
                ],
            ],
            'context' => $this->context,
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
        return 'booking.created';
    }

    /**
     * Determine if this event should broadcast.
     *
     * @return bool
     */
    public function broadcastWhen(): bool
    {
        // Only broadcast if real-time features are enabled
        return config('broadcasting.connections.pusher.key') !== null;
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
            'booking:created'
        ];
    }
}