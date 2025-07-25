<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Exception;

class PaymentService
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    public function createPaymentIntent(Booking $booking): array
    {
        try {
            $paymentIntent = PaymentIntent::create([
                'amount' => $booking->amount * 100, // Convert to cents
                'currency' => 'usd',
                'metadata' => [
                    'booking_id' => $booking->id,
                    'booking_ref' => $booking->booking_ref
                ]
            ]);

            return [
                'success' => true,
                'client_secret' => $paymentIntent->client_secret,
                'payment_intent_id' => $paymentIntent->id
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function confirmPayment(string $paymentIntentId, Booking $booking): bool
    {
        try {
            $paymentIntent = PaymentIntent::retrieve($paymentIntentId);
            
            if ($paymentIntent->status === 'succeeded') {
                Payment::create([
                    'transaction_id' => $paymentIntent->id,
                    'booking_id' => $booking->id,
                    'amount' => $paymentIntent->amount / 100,
                    'currency' => $paymentIntent->currency,
                    'method' => 'stripe',
                    'status' => 'completed',
                    'gateway_response' => $paymentIntent->toArray(),
                    'paid_at' => now()
                ]);

                $booking->update([
                    'payment_status' => 'paid',
                    'status' => 'confirmed'
                ]);

                return true;
            }

            return false;
        } catch (Exception $e) {
            logger()->error('Payment confirmation failed', [
                'error' => $e->getMessage(),
                'booking_id' => $booking->id
            ]);
            return false;
        }
    }

    public function refund(Payment $payment, float $amount = null): bool
    {
        try {
            $refund = \Stripe\Refund::create([
                'payment_intent' => $payment->transaction_id,
                'amount' => $amount ? ($amount * 100) : null, // Partial refund if amount specified
            ]);

            if ($refund->status === 'succeeded') {
                $payment->update(['status' => 'refunded']);
                $payment->booking->update(['payment_status' => 'refunded']);
                return true;
            }

            return false;
        } catch (Exception $e) {
            logger()->error('Refund failed', [
                'error' => $e->getMessage(),
                'payment_id' => $payment->id
            ]);
            return false;
        }
    }
}