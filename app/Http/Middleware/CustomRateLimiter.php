<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class CustomRateLimiter
{
    public function handle(Request $request, Closure $next, string $key, int $maxAttempts = 60, int $decayMinutes = 1): Response
    {
        $identifier = $this->getIdentifier($request, $key);
        
        if (RateLimiter::tooManyAttempts($identifier, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($identifier);
            
            return response()->json([
                'success' => false,
                'message' => 'Too many requests. Please try again later.',
                'retry_after' => $seconds,
                'timestamp' => now()->toISOString()
            ], 429);
        }

        RateLimiter::hit($identifier, $decayMinutes * 60);

        $response = $next($request);

        // Add rate limit headers
        $response->headers->set('X-RateLimit-Limit', $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', RateLimiter::remaining($identifier, $maxAttempts));

        return $response;
    }

    private function getIdentifier(Request $request, string $key): string
    {
        $user = $request->user();
        
        return match($key) {
            'booking' => 'booking:' . ($user?->id ?? $request->ip()),
            'payment' => 'payment:' . ($user?->id ?? $request->ip()),
            'auth' => 'auth:' . $request->ip(),
            default => $key . ':' . ($user?->id ?? $request->ip())
        };
    }
}