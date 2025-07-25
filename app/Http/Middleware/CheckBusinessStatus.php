<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckBusinessStatus
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        
        if ($user && $user->hasRole('vendor') && $user->business) {
            $business = $user->business;
            
            if ($business->status === 'suspended') {
                return response()->json([
                    'success' => false,
                    'message' => 'Your business has been suspended. Please contact support.',
                    'error_code' => 'BUSINESS_SUSPENDED'
                ], 403);
            }
            
            if ($business->status === 'rejected') {
                return response()->json([
                    'success' => false,
                    'message' => 'Your business application was rejected.',
                    'error_code' => 'BUSINESS_REJECTED'
                ], 403);
            }
        }

        return $next($request);
    }
}
