<?php

namespace App\Services;

use App\Models\User;
use App\Models\Business;
use App\Models\Booking;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Send booking confirmation notifications
     */
    public function sendBookingConfirmation(Booking $booking): void
    {
        try {
            // Email to customer
            Mail::to($booking->user->email)->queue(new \App\Mail\BookingConfirmation($booking));

            // Email to business owner
            Mail::to($booking->business->owner->email)->queue(new \App\Mail\NewBookingNotification($booking));

            // Email to assigned staff if any
            if ($booking->staff) {
                Mail::to($booking->staff->email)->queue(new \App\Mail\StaffBookingNotification($booking));
            }

            // Push notifications
            $this->sendPushNotification($booking->user, 'Booking Confirmed', 
                "Your booking for {$booking->service->name} has been confirmed.");

            $this->sendPushNotification($booking->business->owner, 'New Booking', 
                "New booking received for {$booking->service->name}.");

        } catch (\Exception $e) {
            Log::error('Failed to send booking confirmation notifications', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send booking reminder notifications
     */
    public function sendBookingReminder(Booking $booking, int $hoursBeforeBooking = 24): void
    {
        try {
            $reminderTime = $booking->booking_date->subHours($hoursBeforeBooking);
            
            if (now()->gte($reminderTime)) {
                Mail::to($booking->user->email)->queue(new \App\Mail\BookingReminder($booking));
                
                $this->sendPushNotification($booking->user, 'Booking Reminder', 
                    "Don't forget your appointment tomorrow at {$booking->start_time}.");
            }
        } catch (\Exception $e) {
            Log::error('Failed to send booking reminder', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send booking cancellation notifications
     */
    public function sendBookingCancellation(Booking $booking, string $reason = null): void
    {
        try {
            Mail::to($booking->user->email)->queue(new \App\Mail\BookingCancellation($booking, $reason));
            Mail::to($booking->business->owner->email)->queue(new \App\Mail\BookingCancellationBusiness($booking, $reason));

            $this->sendPushNotification($booking->user, 'Booking Cancelled', 
                "Your booking for {$booking->service->name} has been cancelled.");

        } catch (\Exception $e) {
            Log::error('Failed to send booking cancellation notifications', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send payment receipt
     */
    public function sendPaymentReceipt(\App\Models\Payment $payment): void
    {
        try {
            Mail::to($payment->booking->user->email)->queue(new \App\Mail\PaymentReceipt($payment));
            
            $this->sendPushNotification($payment->booking->user, 'Payment Confirmed', 
                "Payment of \${$payment->amount} has been processed successfully.");

        } catch (\Exception $e) {
            Log::error('Failed to send payment receipt', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send welcome email to new users
     */
    public function sendWelcomeEmail(User $user): void
    {
        try {
            Mail::to($user->email)->queue(new \App\Mail\WelcomeEmail($user));
        } catch (\Exception $e) {
            Log::error('Failed to send welcome email', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send business application status notifications
     */
    public function sendBusinessApplicationStatus(Business $business, string $status, string $reason = null): void
    {
        try {
            switch ($status) {
                case 'approved':
                    Mail::to($business->owner->email)->queue(new \App\Mail\BusinessApproved($business));
                    break;
                case 'rejected':
                    Mail::to($business->owner->email)->queue(new \App\Mail\BusinessRejected($business, $reason));
                    break;
                case 'suspended':
                    Mail::to($business->owner->email)->queue(new \App\Mail\BusinessSuspended($business, $reason));
                    break;
            }
        } catch (\Exception $e) {
            Log::error('Failed to send business application status notification', [
                'business_id' => $business->id,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send push notification (placeholder for actual implementation)
     */
    protected function sendPushNotification(User $user, string $title, string $message): void
    {
        // This would integrate with FCM, Pusher, or other push notification service
        Log::info('Push notification sent', [
            'user_id' => $user->id,
            'title' => $title,
            'message' => $message
        ]);
    }

    /**
     * Send SMS notification (placeholder for actual implementation)
     */
    public function sendSMS(string $phoneNumber, string $message): void
    {
        // This would integrate with Twilio, Nexmo, or other SMS service
        Log::info('SMS sent', [
            'phone' => $phoneNumber,
            'message' => $message
        ]);
    }

    /**
     * Send bulk notifications
     */
    public function sendBulkNotification(array $userIds, string $title, string $message, array $data = []): void
    {
        try {
            $users = User::whereIn('id', $userIds)->get();
            
            foreach ($users as $user) {
                $this->sendPushNotification($user, $title, $message);
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to send bulk notifications', [
                'user_count' => count($userIds),
                'error' => $e->getMessage()
            ]);
        }
    }
}