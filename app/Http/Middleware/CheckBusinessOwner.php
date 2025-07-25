<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckBusinessOwner
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required'
            ], 401);
        }

        if (!$user->hasRole('vendor')) {
            return response()->json([
                'success' => false,
                'message' => 'Vendor access required'
            ], 403);
        }

        if (!$user->business) {
            return response()->json([
                'success' => false,
                'message' => 'No business found for this vendor'
            ], 404);
        }

        return $next($request);
    }
}    