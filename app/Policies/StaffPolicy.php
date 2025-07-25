<?php

namespace App\Policies;

use App\Models\Staff;
use App\Models\User;

class StaffPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('vendor') || $user->hasRole('admin');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Staff $staff): bool
    {
        // Business owner can view their own staff
        if ($user->hasRole('vendor') && $user->business) {
            return $user->business->id === $staff->business_id;
        }

        // Admin can view any staff
        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Only business owners can create staff
        return $user->hasRole('vendor') && $user->business && $user->business->status === 'approved';
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Staff $staff): bool
    {
        // Business owner can update their own staff
        if ($user->hasRole('vendor') && $user->business) {
            return $user->business->id === $staff->business_id;
        }

        // Admin can update any staff
        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Staff $staff): bool
    {
        // Check if staff has future bookings
        $hasFutureBookings = $staff->bookings()
            ->where('booking_date', '>=', today())
            ->where('status', '!=', 'cancelled')
            ->exists();

        if ($hasFutureBookings) {
            return false;
        }

        // Business owner can delete their own staff
        if ($user->hasRole('vendor') && $user->business) {
            return $user->business->id === $staff->business_id;
        }

        // Admin can delete any staff
        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can manage staff availability.
     */
    public function manageAvailability(User $user, Staff $staff): bool
    {
        return $this->update($user, $staff);
    }

    /**
     * Determine whether the user can assign services to staff.
     */
    public function assignServices(User $user, Staff $staff): bool
    {
        return $this->update($user, $staff);
    }

    /**
     * Determine whether the user can view staff schedule.
     */
    public function viewSchedule(User $user, Staff $staff): bool
    {
        return $this->view($user, $staff);
    }

    /**
     * Determine whether the user can view staff performance.
     */
    public function viewPerformance(User $user, Staff $staff): bool
    {
        if ($user->hasRole('vendor') && $user->business) {
            return $user->business->id === $staff->business_id;
        }

        return $user->hasRole('admin');
    }
}