<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Http\Resources\PaymentResource;
use App\Events\PaymentCompleted;
use App\Exceptions\InsufficientBalanceException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    protected $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Process checkout for booking
     */
    public function checkout(Request $request)
    {
        $validated = $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'payment_method' => 'required|string|in:card,wallet,paypal,google_pay,apple_pay',
            'save_payment_method' => 'nullable|boolean',
            'payment_method_id' => 'nullable|string', // For saved payment methods
            'return_url' => 'nullable|url'
        ]);

        $booking = Booking::where('id', $validated['booking_id'])
            ->where('user_id', $request->user()->id)
            ->where('payment_status', 'pending')
            ->first();

        if (!$booking) {
            return $this->error('Invalid booking or already paid', 422);
        }

        DB::beginTransaction();

        try {
            // Check wallet balance if using wallet
            if ($validated['payment_method'] === 'wallet') {
                $walletBalance = $request->user()->wallet_balance ?? 0;
                
                if ($walletBalance < $booking->amount) {
                    throw InsufficientBalanceException::forWallet(
                        $booking->amount,
                        $walletBalance,
                        $request->user()
                    );
                }
            }

            // Create payment intent based on method
            $paymentData = match($validated['payment_method']) {
                'wallet' => $this->processWalletPayment($booking, $request->user()),
                'card' => $this->paymentService->createPaymentIntent($booking),
                'paypal' => $this->paymentService->createPaypalPayment($booking, $validated['return_url']),
                default => $this->paymentService->createPaymentIntent($booking)
            };

            if (!$paymentData['success']) {
                throw new \Exception($paymentData['error'] ?? 'Payment initialization failed');
            }

            // Create payment record
            $payment = Payment::create([
                'booking_id' => $booking->id,
                'transaction_id' => $paymentData['payment_intent_id'] ?? $paymentData['transaction_id'],
                'amount' => $booking->amount,
                'currency' => config('goreserve.payment.currency', 'USD'),
                'method' => $validated['payment_method'],
                'status' => $validated['payment_method'] === 'wallet' ? 'completed' : 'pending',
                'gateway_response' => $paymentData,
                'paid_at' => $validated['payment_method'] === 'wallet' ? now() : null
            ]);

            // Update booking if wallet payment
            if ($validated['payment_method'] === 'wallet') {
                $booking->update([
                    'payment_status' => 'paid',
                    'status' => 'confirmed'
                ]);

                // Deduct from wallet
                $request->user()->decrement('wallet_balance', $booking->amount);

                // Fire payment completed event
                event(new PaymentCompleted($payment, [
                    'method' => 'wallet',
                    'instant' => true
                ]));
            }

            DB::commit();

            return $this->success([
                'payment_id' => $payment->id,
                'client_secret' => $paymentData['client_secret'] ?? null,
                'payment_url' => $paymentData['payment_url'] ?? null,
                'status' => $payment->status,
                'requires_action' => $payment->status === 'pending',
                'next_action' => $paymentData['next_action'] ?? null
            ], 'Payment initialized successfully');

        } catch (InsufficientBalanceException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->error(
                'Payment initialization failed',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Confirm payment
     */
    public function confirm(Request $request)
    {
        $validated = $request->validate([
            'payment_id' => 'required|exists:payments,id',
            'payment_intent_id' => 'required|string'
        ]);

        $payment = Payment::where('id', $validated['payment_id'])
            ->whereHas('booking', function ($q) use ($request) {
                $q->where('user_id', $request->user()->id);
            })
            ->where('status', 'pending')
            ->first();

        if (!$payment) {
            return $this->error('Invalid payment or already processed', 422);
        }

        DB::beginTransaction();

        try {
            // Confirm payment with payment service
            $confirmed = $this->paymentService->confirmPayment(
                $validated['payment_intent_id'],
                $payment->booking
            );

            if (!$confirmed) {
                throw new \Exception('Payment confirmation failed');
            }

            // Payment is handled in the service, just reload
            $payment->refresh();

            DB::commit();

            return $this->success([
                'payment' => new PaymentResource($payment->load('booking')),
                'booking_status' => $payment->booking->status,
                'message' => 'Payment confirmed successfully'
            ], 'Payment confirmed successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->error(
                'Payment confirmation failed',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Get payment history
     */
    public function history(Request $request)
    {
        $validated = $request->validate([
            'status' => 'nullable|string|in:pending,completed,failed,refunded',
            'method' => 'nullable|string|in:card,wallet,paypal,google_pay,apple_pay',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        $query = Payment::whereHas('booking', function ($q) use ($request) {
                $q->where('user_id', $request->user()->id);
            })
            ->with(['booking.business', 'booking.service']);

        // Filters
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['method'])) {
            $query->where('method', $validated['method']);
        }

        if (!empty($validated['from_date'])) {
            $query->whereDate('created_at', '>=', $validated['from_date']);
        }

        if (!empty($validated['to_date'])) {
            $query->whereDate('created_at', '<=', $validated['to_date']);
        }

        $payments = $query->latest()
            ->paginate($validated['per_page'] ?? 20);

        return $this->successWithPagination(
            $payments->through(fn ($payment) => new PaymentResource($payment)),
            'Payment history retrieved successfully'
        );
    }

    /**
     * Get payment methods
     */
    public function paymentMethods(Request $request)
    {
        $user = $request->user();
        
        $methods = [
            [
                'type' => 'card',
                'name' => 'Credit/Debit Card',
                'icon' => 'credit-card',
                'enabled' => true,
                'saved_methods' => $this->paymentService->getSavedCards($user)
            ],
            [
                'type' => 'wallet',
                'name' => 'Wallet',
                'icon' => 'wallet',
                'enabled' => true,
                'balance' => $user->wallet_balance ?? 0,
                'currency' => config('goreserve.payment.currency', 'USD')
            ],
            [
                'type' => 'paypal',
                'name' => 'PayPal',
                'icon' => 'paypal',
                'enabled' => config('services.paypal.client_id') !== null
            ],
            [
                'type' => 'google_pay',
                'name' => 'Google Pay',
                'icon' => 'google',
                'enabled' => config('services.google_pay.enabled', false)
            ],
            [
                'type' => 'apple_pay',
                'name' => 'Apple Pay',
                'icon' => 'apple',
                'enabled' => config('services.apple_pay.enabled', false)
            ]
        ];

        return $this->success([
            'payment_methods' => $methods,
            'default_method' => $user->default_payment_method ?? 'card',
            'stripe_publishable_key' => config('services.stripe.key')
        ], 'Payment methods retrieved successfully');
    }

    /**
     * Process wallet payment
     */
    private function processWalletPayment(Booking $booking, $user)
    {
        return [
            'success' => true,
            'transaction_id' => 'WALLET_' . strtoupper(uniqid()),
            'payment_type' => 'wallet',
            'amount_deducted' => $booking->amount,
            'remaining_balance' => $user->wallet_balance - $booking->amount
        ];
    }

    /**
     * Add funds to wallet
     */
    public function topUpWallet(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:10|max:1000',
            'payment_method' => 'required|string|in:card,paypal',
            'payment_method_id' => 'nullable|string'
        ]);

        try {
            $paymentData = $this->paymentService->createWalletTopUp(
                $request->user(),
                $validated['amount'],
                $validated['payment_method']
            );

            if (!$paymentData['success']) {
                throw new \Exception($paymentData['error'] ?? 'Top-up initialization failed');
            }

            return $this->success([
                'transaction_id' => $paymentData['transaction_id'],
                'client_secret' => $paymentData['client_secret'] ?? null,
                'payment_url' => $paymentData['payment_url'] ?? null,
                'amount' => $validated['amount']
            ], 'Wallet top-up initialized successfully');

        } catch (\Exception $e) {
            return $this->error(
                'Failed to initialize wallet top-up',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }
}