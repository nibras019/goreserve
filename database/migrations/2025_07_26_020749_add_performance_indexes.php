<?php
// database/migrations/2024_01_01_000000_add_performance_indexes.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Booking performance indexes
        Schema::table('bookings', function (Blueprint $table) {
            $table->index(['business_id', 'booking_date', 'status'], 'idx_bookings_business_date_status');
            $table->index(['user_id', 'status'], 'idx_bookings_user_status');
            $table->index(['staff_id', 'booking_date'], 'idx_bookings_staff_date');
            $table->index(['service_id', 'booking_date'], 'idx_bookings_service_date');
        });

        // Business performance indexes
        Schema::table('businesses', function (Blueprint $table) {
            $table->index(['status', 'type'], 'idx_businesses_status_type');
            $table->index(['latitude', 'longitude'], 'idx_businesses_location');
            $table->fullText(['name', 'description', 'address'], 'idx_businesses_search');
        });

        // Service performance indexes
        Schema::table('services', function (Blueprint $table) {
            $table->index(['business_id', 'is_active'], 'idx_services_business_active');
            $table->index(['category', 'is_active'], 'idx_services_category_active');
            $table->fullText(['name', 'description'], 'idx_services_search');
        });

        // Review performance indexes
        Schema::table('reviews', function (Blueprint $table) {
            $table->index(['business_id', 'is_approved', 'rating'], 'idx_reviews_business_approved_rating');
            $table->index(['user_id', 'created_at'], 'idx_reviews_user_date');
        });

        // Payment performance indexes
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['booking_id', 'status'], 'idx_payments_booking_status');
            $table->index(['status', 'created_at'], 'idx_payments_status_date');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('idx_bookings_business_date_status');
            $table->dropIndex('idx_bookings_user_status');
            $table->dropIndex('idx_bookings_staff_date');
            $table->dropIndex('idx_bookings_service_date');
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropIndex('idx_businesses_status_type');
            $table->dropIndex('idx_businesses_location');
            $table->dropFullText('idx_businesses_search');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex('idx_services_business_active');
            $table->dropIndex('idx_services_category_active');
            $table->dropFullText('idx_services_search');
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex('idx_reviews_business_approved_rating');
            $table->dropIndex('idx_reviews_user_date');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('idx_payments_booking_status');
            $table->dropIndex('idx_payments_status_date');
        });
    }
};