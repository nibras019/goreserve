<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Events\PaymentCompleted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $booking;
    protected $paymentData;

    public function __construct(Booking $booking, array $paymentData)
    {
        $this->booking = $booking;
        $this->paymentData = $paymentData;
    }

    public function handle(PaymentService $paymentService): void
    {
        try {
            // Process the payment based on method
            $result = match($this->paymentData['method']) {
                'stripe' => $paymentService->processStripePayment($this->booking, $this->paymentData),
                'paypal' => $paymentService->processPaypalPayment($this->booking, $this->paymentData),
                'wallet' => $paymentService->processWalletPayment($this->booking, $this->paymentData),
                default => throw new \InvalidArgumentException('Unsupported payment method')
            };

            if ($result['success']) {
                // Create payment record
                $payment = Payment::create([
                    'booking_id' => $this->booking->id,
                    'transaction_id' => $result['transaction_id'],
                    'amount' => $this->booking->amount,
                    'currency' => $this->paymentData['currency'] ?? 'USD',
                    'method' => $this->paymentData['method'],
                    'status' => 'completed',
                    'gateway_response' => $result['gateway_response'] ?? [],
                    'paid_at' => now()
                ]);

                // Update booking status
                $this->booking->update([
                    'payment_status' => 'paid',
                    'status' => 'confirmed'
                ]);

                // Fire payment completed event
                event(new PaymentCompleted($payment, $result));

                Log::info('Payment processed successfully', [
                    'booking_id' => $this->booking->id,
                    'payment_id' => $payment->id,
                    'amount' => $payment->amount
                ]);

            } else {
                throw new \Exception($result['error'] ?? 'Payment processing failed');
            }

        } catch (\Exception $e) {
            Log::error('Payment processing failed', [
                'booking_id' => $this->booking->id,
                'error' => $e->getMessage()
            ]);

            // Update booking with failed payment
            $this->booking->update([
                'payment_status' => 'failed'
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessPayment job failed', [
            'booking_id' => $this->booking->id,
            'error' => $exception->getMessage()
        ]);

        // Mark payment as failed
        $this->booking->update([
            'payment_status' => 'failed'
        ]);
    }
}