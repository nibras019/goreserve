<?php

return [
    'booking' => [
        'advance_days' => env('BOOKING_ADVANCE_DAYS', 30),
        'min_advance_hours' => env('BOOKING_MIN_ADVANCE_HOURS', 2),
        'cancellation_hours' => env('BOOKING_CANCELLATION_HOURS', 24),
        'slot_interval' => env('BOOKING_SLOT_INTERVAL', 30), // minutes
        'reminder_hours' => env('BOOKING_REMINDER_HOURS', 24),
    ],
    
    'business' => [
        'auto_approve' => env('BUSINESS_AUTO_APPROVE', false),
        'max_images' => env('BUSINESS_MAX_IMAGES', 10),
        'image_max_size' => env('BUSINESS_IMAGE_MAX_SIZE', 5120), // KB
    ],
    
    'payment' => [
        'currency' => env('PAYMENT_CURRENCY', 'USD'),
        'partial_payment_percentage' => env('PARTIAL_PAYMENT_PERCENTAGE', 50),
        'refund_percentage' => env('REFUND_PERCENTAGE', 100),
    ],
    
    'notifications' => [
        'sms_enabled' => env('SMS_NOTIFICATIONS_ENABLED', false),
        'email_enabled' => env('EMAIL_NOTIFICATIONS_ENABLED', true),
    ],
];