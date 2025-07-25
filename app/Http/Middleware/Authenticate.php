<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Symfony\Component\HttpFoundation\Response;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        return $request->expectsJson() ? null : route('login');
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ...$guards): Response
    {
        if ($request->expectsJson() && !$request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
                'error_code' => 'AUTHENTICATION_REQUIRED'
            ], 401);
        }

        $this->authenticate($request, $guards);

        return $next($request);
    }
}
