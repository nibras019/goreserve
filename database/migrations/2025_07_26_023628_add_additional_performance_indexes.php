<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Use different index names to avoid conflicts
            $table->index(['user_id', 'status', 'booking_date'], 'idx_user_bookings_enhanced');
            $table->index(['staff_id', 'booking_date', 'status'], 'idx_staff_schedule_enhanced');
            $table->index(['service_id', 'booking_date'], 'idx_service_bookings_enhanced');
            $table->index(['payment_status', 'status'], 'idx_payment_status_enhanced');
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->index(['latitude', 'longitude', 'type', 'status'], 'idx_location_search_enhanced');
            $table->index(['status', 'type', 'rating'], 'idx_business_listing_enhanced');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['status', 'method', 'created_at'], 'idx_revenue_analysis_enhanced');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->index(['category', 'is_active', 'price'], 'idx_service_search_enhanced');
        });

        Schema::table('staff', function (Blueprint $table) {
            $table->index(['business_id', 'is_active'], 'idx_business_staff_enhanced');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('idx_user_bookings_enhanced');
            $table->dropIndex('idx_staff_schedule_enhanced');
            $table->dropIndex('idx_service_bookings_enhanced');
            $table->dropIndex('idx_payment_status_enhanced');
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropIndex('idx_location_search_enhanced');
            $table->dropIndex('idx_business_listing_enhanced');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('idx_revenue_analysis_enhanced');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex('idx_service_search_enhanced');
        });

        Schema::table('staff', function (Blueprint $table) {
            $table->dropIndex('idx_business_staff_enhanced');
        });
    }
};