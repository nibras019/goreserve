<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->index(['business_id', 'booking_date', 'status', 'staff_id'], 'idx_booking_performance');
            $table->index(['user_id', 'status', 'booking_date'], 'idx_user_bookings');
            $table->index(['staff_id', 'booking_date', 'status'], 'idx_staff_schedule');
            $table->index(['service_id', 'booking_date'], 'idx_service_bookings');
            $table->index(['payment_status', 'status'], 'idx_payment_status');
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->index(['latitude', 'longitude', 'type', 'status'], 'idx_location_search');
            $table->index(['status', 'type', 'rating'], 'idx_business_listing');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['status', 'method', 'created_at'], 'idx_revenue_analysis');
            $table->index(['booking_id', 'status'], 'idx_booking_payments');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->index(['business_id', 'is_active', 'category'], 'idx_business_services');
            $table->index(['category', 'is_active', 'price'], 'idx_service_search');
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->index(['business_id', 'is_approved', 'rating', 'created_at'], 'idx_business_reviews');
            $table->index(['user_id', 'created_at'], 'idx_user_reviews');
        });

        Schema::table('staff', function (Blueprint $table) {
            $table->index(['business_id', 'is_active'], 'idx_business_staff');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('idx_booking_performance');
            $table->dropIndex('idx_user_bookings');
            $table->dropIndex('idx_staff_schedule');
            $table->dropIndex('idx_service_bookings');
            $table->dropIndex('idx_payment_status');
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropIndex('idx_location_search');
            $table->dropIndex('idx_business_listing');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('idx_revenue_analysis');
            $table->dropIndex('idx_booking_payments');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex('idx_business_services');
            $table->dropIndex('idx_service_search');
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex('idx_business_reviews');
            $table->dropIndex('idx_user_reviews');
        });

        Schema::table('staff', function (Blueprint $table) {
            $table->dropIndex('idx_business_staff');
        });
    }
};