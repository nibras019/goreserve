<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\User;

class ServicePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true; // Anyone can view services
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Service $service): bool
    {
        // Public services can be viewed by anyone if business is approved
        if ($service->business->status === 'approved' && $service->is_active) {
            return true;
        }

        // Business owner can view their own services
        if ($user->hasRole('vendor') && $user->business) {
            return $user->business->id === $service->business_id;
        }

        // Admin can view any service
        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Only business owners can create services
        return $user->hasRole('vendor') && $user->business && $user->business->status === 'approved';
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Service $service): bool
    {
        // Business owner can update their own services
        if ($user->hasRole('vendor') && $user->business) {
            return $user->business->id === $service->business_id;
        }

        // Admin can update any service
        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Service $service): bool
    {
        // Check if service has future bookings
        $hasFutureBookings = $service->bookings()
            ->where('booking_date', '>=', today())
            ->where('status', '!=', 'cancelled')
            ->exists();

        if ($hasFutureBookings) {
            return false;
        }

        // Business owner can delete their own services
        if ($user->hasRole('vendor') && $user->business) {
            return $user->business->id === $service->business_id;
        }

        // Admin can delete any service
        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can manage service staff.
     */
    public function manageStaff(User $user, Service $service): bool
    {
        return $this->update($user, $service);
    }

    /**
     * Determine whether the user can view service analytics.
     */
    public function viewAnalytics(User $user, Service $service): bool
    {
        if ($user->hasRole('vendor') && $user->business) {
            return $user->business->id === $service->business_id;
        }

        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can toggle service status.
     */
    public function toggleStatus(User $user, Service $service): bool
    {
        return $this->update($user, $service);
    }
}