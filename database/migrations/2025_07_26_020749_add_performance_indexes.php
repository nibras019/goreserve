<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Helper function to check if index exists
        $indexExists = function ($table, $indexName) {
            $indexes = DB::select("PRAGMA index_list($table)");
            return collect($indexes)->pluck('name')->contains($indexName);
        };

        Schema::table('bookings', function (Blueprint $table) use ($indexExists) {
            // Only add indexes that don't exist
            if (!$indexExists('bookings', 'idx_bookings_business_date_status')) {
                $table->index(['business_id', 'booking_date', 'status'], 'idx_bookings_business_date_status');
            }
            if (!$indexExists('bookings', 'idx_bookings_user_status')) {
                $table->index(['user_id', 'status'], 'idx_bookings_user_status');
            }
            if (!$indexExists('bookings', 'idx_bookings_staff_date')) {
                $table->index(['staff_id', 'booking_date'], 'idx_bookings_staff_date');
            }
            if (!$indexExists('bookings', 'idx_bookings_service_date')) {
                $table->index(['service_id', 'booking_date'], 'idx_bookings_service_date');
            }
        });

        Schema::table('businesses', function (Blueprint $table) use ($indexExists) {
            if (!$indexExists('businesses', 'idx_businesses_status_type')) {
                $table->index(['status', 'type'], 'idx_businesses_status_type');
            }
            if (!$indexExists('businesses', 'idx_businesses_location')) {
                $table->index(['latitude', 'longitude'], 'idx_businesses_location');
            }
        });

        Schema::table('services', function (Blueprint $table) use ($indexExists) {
            if (!$indexExists('services', 'idx_services_business_active')) {
                $table->index(['business_id', 'is_active'], 'idx_services_business_active');
            }
            if (!$indexExists('services', 'idx_services_category_active')) {
                $table->index(['category', 'is_active'], 'idx_services_category_active');
            }
        });

        Schema::table('reviews', function (Blueprint $table) use ($indexExists) {
            if (!$indexExists('reviews', 'idx_reviews_business_approved_rating')) {
                $table->index(['business_id', 'is_approved', 'rating'], 'idx_reviews_business_approved_rating');
            }
            if (!$indexExists('reviews', 'idx_reviews_user_date')) {
                $table->index(['user_id', 'created_at'], 'idx_reviews_user_date');
            }
        });

        Schema::table('payments', function (Blueprint $table) use ($indexExists) {
            if (!$indexExists('payments', 'idx_payments_booking_status')) {
                $table->index(['booking_id', 'status'], 'idx_payments_booking_status');
            }
            if (!$indexExists('payments', 'idx_payments_status_date')) {
                $table->index(['status', 'created_at'], 'idx_payments_status_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'booking_date', 'status']);
            $table->dropIndex(['user_id', 'status']);
            $table->dropIndex(['staff_id', 'booking_date']);
            $table->dropIndex(['service_id', 'booking_date']);
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropIndex(['status', 'type']);
            $table->dropIndex(['latitude', 'longitude']);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'is_active']);
            $table->dropIndex(['category', 'is_active']);
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'is_approved', 'rating']);
            $table->dropIndex(['user_id', 'created_at']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['booking_id', 'status']);
            $table->dropIndex(['status', 'created_at']);
        });
    }
};