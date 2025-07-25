<?php

namespace App\Policies;

use App\Models\Business;
use App\Models\User;

class BusinessPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true; // Anyone can view public business listings
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Business $business): bool
    {
        // Public businesses can be viewed by anyone
        if ($business->status === 'approved') {
            return true;
        }

        // Owner can view their own business
        if ($user->id === $business->user_id) {
            return true;
        }

        // Admin can view any business
        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Only vendors can create businesses, and only one per user
        return $user->hasRole('vendor') && !$user->business;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Business $business): bool
    {
        // Owner can update their own business
        if ($user->id === $business->user_id) {
            return true;
        }

        // Admin can update any business
        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Business $business): bool
    {
        // Only admin can delete businesses
        return $user->hasRole('admin');
    }

    /**
     * Determine whether the user can manage the business.
     */
    public function manage(User $user, Business $business): bool
    {
        return $user->id === $business->user_id || $user->hasRole('admin');
    }

    /**
     * Determine whether the user can approve the business.
     */
    public function approve(User $user, Business $business): bool
    {
        return $user->hasRole('admin') && $business->status === 'pending';
    }

    /**
     * Determine whether the user can suspend the business.
     */
    public function suspend(User $user, Business $business): bool
    {
        return $user->hasRole('admin') && $business->status !== 'suspended';
    }

    /**
     * Determine whether the user can view business analytics.
     */
    public function viewAnalytics(User $user, Business $business): bool
    {
        return $user->id === $business->user_id || $user->hasRole('admin');
    }

    /**
     * Determine whether the user can manage business settings.
     */
    public function manageSettings(User $user, Business $business): bool
    {
        return $user->id === $business->user_id;
    }

    /**
     * Determine whether the user can view business financial data.
     */
    public function viewFinancials(User $user, Business $business): bool
    {
        return $user->id === $business->user_id || $user->hasRole('admin');
    }
}
