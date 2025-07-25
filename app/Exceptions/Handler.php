<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;
use Illuminate\Support\Facades\Log;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
        'payment_token',
        'card_number',
        'cvv',
        'stripe_token',
    ];

    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        BookingConflictException::class => 'warning',
        InsufficientBalanceException::class => 'warning',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        // Report specific exceptions with custom handling
        $this->reportable(function (BookingConflictException $e) {
            Log::channel('bookings')->warning('Booking conflict detected', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'booking_data' => $e->getBookingData(),
                'conflict_details' => $e->getConflictDetails(),
                'user_id' => request()->user()?->id,
                'ip' => request()->ip(),
            ]);
        });

        $this->reportable(function (InsufficientBalanceException $e) {
            Log::channel('payments')->warning('Insufficient balance for transaction', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'required_amount' => $e->getRequiredAmount(),
                'available_amount' => $e->getAvailableAmount(),
                'shortage' => $e->getShortage(),
                'user_id' => $e->getUserId(),
                'context' => $e->getContext(),
            ]);
        });

        // Custom rendering for API responses
        $this->renderable(function (BookingConflictException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error_code' => 'BOOKING_CONFLICT',
                    'details' => $e->getConflictDetails(),
                    'suggestions' => $e->getSuggestions(),
                ], 409);
            }
        });

        $this->renderable(function (InsufficientBalanceException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error_code' => 'INSUFFICIENT_BALANCE',
                    'required_amount' => $e->getRequiredAmount(),
                    'available_amount' => $e->getAvailableAmount(),
                    'shortage' => $e->getShortage(),
                    'payment_options' => $e->getPaymentOptions(),
                ], 402);
            }
        });

        $this->renderable(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Resource not found',
                    'error_code' => 'NOT_FOUND',
                ], 404);
            }
        });

        $this->renderable(function (ModelNotFoundException $e, Request $request) {
            if ($request->expectsJson()) {
                $modelName = strtolower(class_basename($e->getModel()));
                return response()->json([
                    'message' => "The requested {$modelName} was not found",
                    'error_code' => 'MODEL_NOT_FOUND',
                    'model' => $modelName,
                ], 404);
            }
        });

        $this->renderable(function (MethodNotAllowedHttpException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Method not allowed',
                    'error_code' => 'METHOD_NOT_ALLOWED',
                    'allowed_methods' => $e->getHeaders()['Allow'] ?? '',
                ], 405);
            }
        });

        $this->renderable(function (ValidationException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'error_code' => 'VALIDATION_ERROR',
                    'errors' => $e->errors(),
                ], 422);
            }
        });
    }

    /**
     * Determine if the exception should be reported.
     */
    public function shouldReport(Throwable $e): bool
    {
        // Don't report certain exceptions in production
        if (app()->environment('production')) {
            $dontReport = [
                AuthenticationException::class,
                ValidationException::class,
                ModelNotFoundException::class,
                NotFoundHttpException::class,
            ];

            foreach ($dontReport as $type) {
                if ($e instanceof $type) {
                    return false;
                }
            }
        }

        return parent::shouldReport($e);
    }

    /**
     * Prepare exception for rendering.
     */
    public function render($request, Throwable $e)
    {
        // Log additional context for debugging
        if ($this->shouldReport($e) && !app()->environment('testing')) {
            $this->logAdditionalContext($e);
        }

        return parent::render($request, $e);
    }

    /**
     * Convert an authentication exception into a response.
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Unauthenticated',
                'error_code' => 'UNAUTHENTICATED',
            ], 401);
        }

        return redirect()->guest($exception->redirectTo() ?? route('login'));
    }

    /**
     * Log additional context for better debugging
     */
    private function logAdditionalContext(Throwable $e): void
    {
        $context = [
            'url' => request()->fullUrl(),
            'method' => request()->method(),
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ];

        if ($user = request()->user()) {
            $context['user'] = [
                'id' => $user->id,
                'email' => $user->email,
                'role' => $user->roles->pluck('name')->first(),
            ];
        }

        if (!empty(request()->all())) {
            $context['input'] = request()->except($this->dontFlash);
        }

        Log::channel('exceptions')->error('Exception context', $context);
    }

    /**
     * Get the default context variables for logging.
     */
    protected function context(): array
    {
        return array_merge(parent::context(), [
            'url' => request()->fullUrl(),
            'input' => request()->except($this->dontFlash),
            'user_id' => request()->user()?->id,
        ]);
    }
}