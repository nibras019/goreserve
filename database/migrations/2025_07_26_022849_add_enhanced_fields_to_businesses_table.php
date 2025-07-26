<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('website')->nullable()->after('email');
            $table->json('social_media')->nullable()->after('website');
            $table->json('amenities')->nullable()->after('social_media');
            $table->json('policies')->nullable()->after('amenities');
            $table->integer('booking_buffer_minutes')->default(15)->after('policies');
            $table->boolean('auto_confirm_bookings')->default(false)->after('booking_buffer_minutes');
            $table->boolean('require_deposit')->default(false)->after('auto_confirm_bookings');
            $table->integer('deposit_percentage')->default(0)->after('require_deposit');
            $table->text('cancellation_policy')->nullable()->after('deposit_percentage');
            $table->boolean('is_featured')->default(false)->after('cancellation_policy');
            $table->timestamp('featured_until')->nullable()->after('is_featured');
            $table->enum('verification_status', ['pending', 'verified', 'rejected'])->default('pending')->after('featured_until');
            $table->string('subscription_plan', 50)->default('basic')->after('verification_status');
            $table->timestamp('subscription_expires_at')->nullable()->after('subscription_plan');
            $table->integer('views_count')->default(0)->after('subscription_expires_at');
            $table->decimal('commission_rate', 5, 2)->default(10.00)->after('views_count'); // Platform commission
            $table->json('payment_methods')->nullable()->after('commission_rate'); // Accepted payment methods
            $table->text('special_instructions')->nullable()->after('payment_methods');
            
            $table->index('is_featured');
            $table->index('verification_status');
            $table->index('subscription_plan');
            $table->index(['type', 'status', 'is_featured']);
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn([
                'website', 'social_media', 'amenities', 'policies',
                'booking_buffer_minutes', 'auto_confirm_bookings', 'require_deposit',
                'deposit_percentage', 'cancellation_policy', 'is_featured', 'featured_until',
                'verification_status', 'subscription_plan', 'subscription_expires_at',
                'views_count', 'commission_rate', 'payment_methods', 'special_instructions'
            ]);
        });
    }
};