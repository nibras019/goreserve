<?php
// app/Exceptions/ApiExceptionHandler.php
namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Throwable;

class ApiExceptionHandler extends ExceptionHandler
{
    public function render($request, Throwable $e)
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return $this->handleApiException($request, $e);
        }

        return parent::render($request, $e);
    }

    private function handleApiException(Request $request, Throwable $e): JsonResponse
    {
        return match(true) {
            $e instanceof ValidationException => $this->handleValidationException($e),
            $e instanceof AuthenticationException => $this->handleAuthenticationException($e),
            $e instanceof ModelNotFoundException => $this->handleModelNotFoundException($e),
            $e instanceof NotFoundHttpException => $this->handleNotFoundHttpException($e),
            $e instanceof MethodNotAllowedHttpException => $this->handleMethodNotAllowedException($e),
            $e instanceof BookingConflictException => $this->handleBookingConflictException($e),
            $e instanceof InsufficientBalanceException => $this->handleInsufficientBalanceException($e),
            default => $this->handleGenericException($e)
        };
    }

    private function handleValidationException(ValidationException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $e->errors(),
            'timestamp' => now()->toISOString()
        ], 422);
    }

    private function handleAuthenticationException(AuthenticationException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Authentication required',
            'timestamp' => now()->toISOString()
        ], 401);
    }

    private function handleModelNotFoundException(ModelNotFoundException $e): JsonResponse
    {
        $model = strtolower(class_basename($e->getModel()));
        
        return response()->json([
            'success' => false,
            'message' => "The requested {$model} was not found",
            'timestamp' => now()->toISOString()
        ], 404);
    }

    private function handleNotFoundHttpException(NotFoundHttpException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Resource not found',
            'timestamp' => now()->toISOString()
        ], 404);
    }

    private function handleMethodNotAllowedException(MethodNotAllowedHttpException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Method not allowed',
            'allowed_methods' => $e->getHeaders()['Allow'] ?? '',
            'timestamp' => now()->toISOString()
        ], 405);
    }

    private function handleBookingConflictException(BookingConflictException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
            'error_code' => 'BOOKING_CONFLICT',
            'details' => $e->getConflictDetails(),
            'suggestions' => $e->getSuggestions(),
            'timestamp' => now()->toISOString()
        ], 409);
    }

    private function handleInsufficientBalanceException(InsufficientBalanceException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
            'error_code' => 'INSUFFICIENT_BALANCE',
            'required_amount' => $e->getRequiredAmount(),
            'available_amount' => $e->getAvailableAmount(),
            'shortage' => $e->getShortage(),
            'payment_options' => $e->getPaymentOptions(),
            'timestamp' => now()->toISOString()
        ], 402);
    }

    private function handleGenericException(Throwable $e): JsonResponse
    {
        $statusCode = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
        
        $response = [
            'success' => false,
            'message' => $statusCode === 500 ? 'Internal server error' : $e->getMessage(),
            'timestamp' => now()->toISOString()
        ];

        // Add debug info in development
        if (config('app.debug')) {
            $response['debug'] = [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTrace()
            ];
        }

        return response()->json($response, $statusCode);
    }
}