<?php

namespace App\Observers;

use App\Models\Payment;
use App\Events\PaymentCompleted;
use App\Services\NotificationService;
use App\Jobs\GenerateInvoice;

class PaymentObserver
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Handle the Payment "created" event.
     */
    public function created(Payment $payment): void
    {
        // Log activity
        activity()
            ->performedOn($payment)
            ->causedBy($payment->booking->user)
            ->withProperties([
                'amount' => $payment->amount,
                'method' => $payment->method,
                'booking_ref' => $payment->booking->booking_ref
            ])
            ->log('Payment created');
    }

    /**
     * Handle the Payment "updated" event.
     */
    public function updated(Payment $payment): void
    {
        $changes = $payment->getChanges();

        // Handle payment status changes
        if (isset($changes['status'])) {
            $this->handleStatusChange($payment, $payment->getOriginal('status'), $changes['status']);
        }

        // Log activity
        activity()
            ->performedOn($payment)
            ->withProperties(['changes' => $changes])
            ->log('Payment updated');
    }

    /**
     * Handle payment status changes
     */
    protected function handleStatusChange(Payment $payment, string $oldStatus, string $newStatus): void
    {
        switch ($newStatus) {
            case 'completed':
                $this->handleCompletedPayment($payment);
                break;

            case 'failed':
                $this->handleFailedPayment($payment);
                break;

            case 'refunded':
                $this->handleRefundedPayment($payment);
                break;

            case 'disputed':
                $this->handleDisputedPayment($payment);
                break;
        }
    }

    /**
     * Handle completed payment
     */
    protected function handleCompletedPayment(Payment $payment): void
    {
        // Update booking status
        $payment->booking->update([
            'payment_status' => 'paid',
            'status' => 'confirmed'
        ]);

        // Send payment receipt
        $this->notificationService->sendPaymentReceipt($payment);

        // Generate invoice
        GenerateInvoice::dispatch($payment)->delay(now()->addMinutes(5));

        // Fire payment completed event
        event(new PaymentCompleted($payment));

        // Update business revenue
        $this->updateBusinessRevenue($payment);
    }

    /**
     * Handle failed payment
     */
    protected function handleFailedPayment(Payment $payment): void
    {
        // Update booking status
        $payment->booking->update(['payment_status' => 'failed']);

        // Send failure notification
        $this->notificationService->sendPaymentFailureNotification($payment);

        // Schedule retry reminder
        \App\Jobs\SendPaymentRetryReminder::dispatch($payment)
            ->delay(now()->addHours(1));
    }

    /**
     * Handle refunded payment
     */
    protected function handleRefundedPayment(Payment $payment): void
    {
        // Update booking status
        $payment->booking->update(['payment_status' => 'refunded']);

        // Send refund confirmation
        $this->notificationService->sendRefundConfirmation($payment);

        // Update business revenue
        $this->updateBusinessRevenue($payment, true);
    }

    /**
     * Handle disputed payment
     */
    protected function handleDisputedPayment(Payment $payment): void
    {
        // Notify admin
        $admins = \App\Models\User::role('admin')->get();
        foreach ($admins as $admin) {
            $admin->notify(new \App\Notifications\PaymentDisputed($payment));
        }

        // Create dispute record
        \App\Models\PaymentDispute::create([
            'payment_id' => $payment->id,
            'amount' => $payment->amount,
            'status' => 'open',
            'created_at' => now()
        ]);
    }

    /**
     * Update business revenue tracking
     */
    protected function updateBusinessRevenue(Payment $payment, bool $isRefund = false): void
    {
        $business = $payment->booking->business;
        $amount = $isRefund ? -$payment->amount : $payment->amount;
        
        // Update monthly revenue
        $revenueRecord = \App\Models\BusinessRevenue::firstOrCreate([
            'business_id' => $business->id,
            'year' => now()->year,
            'month' => now()->month
        ], [
            'revenue' => 0,
            'transactions' => 0
        ]);

        $revenueRecord->increment('revenue', $amount);
        $revenueRecord->increment('transactions', $isRefund ? -1 : 1);
    }
}