<?php

namespace App\Events;

use App\Models\Payment;
use App\Models\Booking;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The payment instance.
     *
     * @var \App\Models\Payment
     */
    public $payment;

    /**
     * The booking instance.
     *
     * @var \App\Models\Booking
     */
    public $booking;

    /**
     * Payment processing details
     *
     * @var array
     */
    public $processingDetails;

    /**
     * Create a new event instance.
     *
     * @param  \App\Models\Payment  $payment
     * @param  array  $processingDetails
     * @return void
     */
    public function __construct(Payment $payment, array $processingDetails = [])
    {
        $this->payment = $payment;
        $this->booking = $payment->booking->load(['user', 'business', 'service', 'staff']);
        $this->processingDetails = $processingDetails;
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
            
            // Private channel for accounting/finance dashboard
            new PrivateChannel('business.' . $this->booking->business_id . '.finance'),
            
            // Presence channel for business dashboard
            new PresenceChannel('business.' . $this->booking->business_id . '.dashboard'),
            
            // Admin finance channel
            new PrivateChannel('admin.finance'),
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
            'payment' => [
                'id' => $this->payment->id,
                'transaction_id' => $this->payment->transaction_id,
                'amount' => $this->payment->amount,
                'currency' => $this->payment->currency,
                'method' => $this->payment->method,
                'status' => $this->payment->status,
                'paid_at' => $this->payment->paid_at?->toIso8601String(),
                'processing_fee' => $this->calculateProcessingFee(),
                'net_amount' => $this->calculateNetAmount(),
            ],
            'booking' => [
                'id' => $this->booking->id,
                'booking_ref' => $this->booking->booking_ref,
                'status' => $this->booking->status,
                'payment_status' => $this->booking->payment_status,
                'booking_date' => $this->booking->booking_date->format('Y-m-d'),
                'start_time' => $this->booking->start_time,
                'service' => [
                    'id' => $this->booking->service->id,
                    'name' => $this->booking->service->name,
                ],
                'customer' => [
                    'id' => $this->booking->user->id,
                    'name' => $this->booking->user->name,
                    'email' => $this->booking->user->email,
                ],
            ],
            'business' => [
                'id' => $this->booking->business->id,
                'name' => $this->booking->business->name,
            ],
            'processing' => array_merge($this->processingDetails, [
                'completed_at' => now()->toIso8601String(),
                'confirmation_sent' => true,
                'invoice_generated' => $this->isInvoiceGenerated(),
            ]),
            'analytics' => [
                'payment_time' => $this->calculatePaymentTime(),
                'is_early_payment' => $this->isEarlyPayment(),
                'payment_type' => $this->determinePaymentType(),
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
        return 'payment.completed';
    }

    /**
     * Calculate processing fee based on payment method
     *
     * @return float
     */
    private function calculateProcessingFee(): float
    {
        $feePercentage = match($this->payment->method) {
            'stripe' => 0.029, // 2.9% + $0.30
            'razorpay' => 0.02, // 2%
            'paypal' => 0.0349, // 3.49% + $0.49
            default => 0,
        };

        $fixedFee = match($this->payment->method) {
            'stripe' => 0.30,
            'paypal' => 0.49,
            default => 0,
        };

        return round(($this->payment->amount * $feePercentage) + $fixedFee, 2);
    }

    /**
     * Calculate net amount after fees
     *
     * @return float
     */
    private function calculateNetAmount(): float
    {
        return round($this->payment->amount - $this->calculateProcessingFee(), 2);
    }

    /**
     * Calculate time taken from booking creation to payment
     *
     * @return array
     */
    private function calculatePaymentTime(): array
    {
        $bookingCreated = $this->booking->created_at;
        $paymentCompleted = $this->payment->paid_at ?? now();
        
        $diffInMinutes = $bookingCreated->diffInMinutes($paymentCompleted);
        
        return [
            'minutes' => $diffInMinutes,
            'human_readable' => $bookingCreated->diffForHumans($paymentCompleted, true),
            'is_immediate' => $diffInMinutes < 5,
        ];
    }

    /**
     * Determine if payment was made well before the booking date
     *
     * @return bool
     */
    private function isEarlyPayment(): bool
    {
        $bookingDateTime = $this->booking->booking_date->setTimeFromTimeString($this->booking->start_time);
        $paymentDate = $this->payment->paid_at ?? now();
        
        return $paymentDate->diffInDays($bookingDateTime) >= 7;
    }

    /**
     * Determine payment type
     *
     * @return string
     */
    private function determinePaymentType(): string
    {
        if ($this->booking->created_at->diffInMinutes($this->payment->paid_at ?? now()) < 5) {
            return 'immediate';
        }
        
        if ($this->payment->amount < $this->booking->amount) {
            return 'partial';
        }
        
        if ($this->payment->amount > $this->booking->amount) {
            return 'overpayment';
        }
        
        return 'full';
    }

    /**
     * Check if invoice has been generated
     *
     * @return bool
     */
    private function isInvoiceGenerated(): bool
    {
        return \DB::table('invoices')
            ->where('payment_id', $this->payment->id)
            ->exists();
    }

    /**
     * Get the tags that should be assigned to the event.
     *
     * @return array
     */
    public function tags(): array
    {
        return [
            'payment:' . $this->payment->id,
            'booking:' . $this->booking->id,
            'business:' . $this->booking->business_id,
            'user:' . $this->booking->user_id,
            'payment:completed',
            'payment_method:' . $this->payment->method,
        ];
    }

    /**
     * Determine if this event should broadcast.
     *
     * @return bool
     */
    public function broadcastWhen(): bool
    {
        // Only broadcast if payment was successful
        return $this->payment->status === 'completed' && 
               config('broadcasting.connections.pusher.key') !== null;
    }

    /**
     * Get the queue name for the event.
     *
     * @return string
     */
    public function broadcastQueue(): string
    {
        return 'payments';
    }
}