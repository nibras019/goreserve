<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailIsVerified
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user() ||
            ($request->user() instanceof MustVerifyEmail &&
            !$request->user()->hasVerifiedEmail())) {
            
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your email address is not verified.',
                    'error_code' => 'EMAIL_NOT_VERIFIED'
                ], 409);
            }
            
            return redirect()->route('verification.notice');
        }

        return $next($request);
    }
}