<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Broadcast::routes();

        require base_path('routes/channels.php');

        // Define custom broadcast channels
        Broadcast::channel('user.{id}', function ($user, $id) {
            return (int) $user->id === (int) $id;
        });

        Broadcast::channel('business.{businessId}', function ($user, $businessId) {
            return $user->hasRole('vendor') && 
                   $user->business && 
                   $user->business->id == $businessId;
        });

        Broadcast::channel('business.{businessId}.dashboard', function ($user, $businessId) {
            return $user->hasRole('vendor') && 
                   $user->business && 
                   $user->business->id == $businessId;
        });

        Broadcast::channel('staff.{staffId}', function ($user, $staffId) {
            $staff = \App\Models\Staff::find($staffId);
            return $staff && $user->business && $user->business->id === $staff->business_id;
        });

        Broadcast::channel('admin.notifications', function ($user) {
            return $user->hasRole('admin');
        });

        Broadcast::channel('admin.finance', function ($user) {
            return $user->hasRole('admin') || $user->hasPermission('view-finance');
        });
    }
}