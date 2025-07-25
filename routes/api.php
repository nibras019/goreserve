// routes/api.php
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\{
    AuthController,
    BusinessController,
    BookingController,
    ReviewController,
    PaymentController
};
use App\Http\Controllers\Api\Vendor;
use App\Http\Controllers\Api\Admin;

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Public business routes
Route::get('/businesses', [BusinessController::class, 'index']);
Route::get('/businesses/{business}', [BusinessController::class, 'show']);
Route::get('/businesses/{business}/services', [BusinessController::class, 'services']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Customer routes
    Route::prefix('customer')->group(function () {
        Route::get('/bookings', [BookingController::class, 'index']);
        Route::post('/bookings', [BookingController::class, 'store']);
        Route::get('/bookings/{booking}', [BookingController::class, 'show']);
        Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel']);
        Route::get('/available-slots', [BookingController::class, 'availableSlots']);
        
        Route::post('/reviews', [ReviewController::class, 'store']);
        
        Route::post('/payment/checkout', [PaymentController::class, 'checkout']);
        Route::post('/payment/confirm', [PaymentController::class, 'confirm']);
    });

    // Vendor routes
    Route::prefix('vendor')->middleware('role:vendor')->group(function () {
        // Business management
        Route::post('/business', [Vendor\BusinessController::class, 'store']);
        Route::put('/business', [Vendor\BusinessController::class, 'update']);
        Route::get('/dashboard', [Vendor\BusinessController::class, 'dashboard']);
        
        // Service management
        Route::get('/services', [Vendor\ServiceController::class, 'index']);
        Route::post('/services', [Vendor\ServiceController::class, 'store']);
        Route::put('/services/{service}', [Vendor\ServiceController::class, 'update']);
        Route::delete('/services/{service}', [Vendor\ServiceController::class, 'destroy']);
        
        // Staff management
        Route::get('/staff', [Vendor\StaffController::class, 'index']);
        Route::post('/staff', [Vendor\StaffController::class, 'store']);
        Route::put('/staff/{staff}', [Vendor\StaffController::class, 'update']);
        Route::delete('/staff/{staff}', [Vendor\StaffController::class, 'destroy']);
        Route::post('/staff/{staff}/availability', [Vendor\StaffController::class, 'availability']);
        
        // Booking management
        Route::get('/bookings', [Vendor\BookingController::class, 'index']);
        Route::put('/bookings/{booking}', [Vendor\BookingController::class, 'update']);
        Route::get('/calendar', [Vendor\BookingController::class, 'calendar']);
    });

    // Admin routes
    Route::prefix('admin')->middleware('role:admin')->group(function () {
        Route::get('/dashboard', [Admin\DashboardController::class, 'index']);
        
        // User management
        Route::get('/users', [Admin\UserController::class, 'index']);
        Route::put('/users/{user}', [Admin\UserController::class, 'update']);
        
        // Business management
        Route::get('/businesses', [Admin\BusinessController::class, 'index']);
        Route::put('/businesses/{business}/approve', [Admin\BusinessController::class, 'approve']);
        Route::put('/businesses/{business}/suspend', [Admin\BusinessController::class, 'suspend']);
        
        // Review moderation
        Route::get('/reviews', [Admin\ReviewController::class, 'index']);
        Route::put('/reviews/{review}/approve', [Admin\ReviewController::class, 'approve']);
        Route::delete('/reviews/{review}', [Admin\ReviewController::class, 'destroy']);
        
        // Reports
        Route::get('/reports/revenue', [Admin\ReportController::class, 'revenue']);
        Route::get('/reports/bookings', [Admin\ReportController::class, 'bookings']);
    });
});