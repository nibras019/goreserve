<?php
// app/Policies/BookingPolicy.php
namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true; // Users can view their own bookings
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Booking $booking): bool
    {
        // User can view their own booking, business owner can view bookings for their business
        return $user->id === $booking->user_id || 
               ($user->hasRole('vendor') && $user->business && $user->business->id === $booking->business_id) ||
               $user->hasRole('admin');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('customer') || $user->hasRole('vendor');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Booking $booking): bool
    {
        // Customer can reschedule their own booking (if not too late)
        if ($user->id === $booking->user_id) {
            return $booking->canBeCancelled() && in_array($booking->status, ['pending', 'confirmed']);
        }

        // Business owner can update bookings for their business
        if ($user->hasRole('vendor') && $user->business) {
            return $user->business->id === $booking->business_id;
        }

        // Admin can update any booking
        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Booking $booking): bool
    {
        return $this->cancel($user, $booking);
    }

    /**
     * Determine whether the user can cancel the booking.
     */
    public function cancel(User $user, Booking $booking): bool
    {
        // Customer can cancel their own booking
        if ($user->id === $booking->user_id) {
            return $booking->canBeCancelled();
        }

        // Business owner can cancel bookings for their business
        if ($user->hasRole('vendor') && $user->business) {
            return $user->business->id === $booking->business_id;
        }

        // Admin can cancel any booking
        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can reschedule the booking.
     */
    public function reschedule(User $user, Booking $booking): bool
    {
        return $this->update($user, $booking) && $booking->canBeCancelled();
    }

    /**
     * Determine whether the user can mark booking as completed.
     */
    public function complete(User $user, Booking $booking): bool
    {
        // Only business owner or staff can mark as completed
        if ($user->hasRole('vendor') && $user->business) {
            return $user->business->id === $booking->business_id;
        }

        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can mark booking as no-show.
     */
    public function markNoShow(User $user, Booking $booking): bool
    {
        return $this->complete($user, $booking);
    }
}