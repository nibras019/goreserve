<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add missing fields to bookings table
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('cancelled_by')->nullable()->after('cancellation_reason'); // 'user', 'business', 'system'
            $table->foreignId('promo_code_id')->nullable()->constrained('promo_codes')->after('cancelled_by');
            $table->decimal('discount_amount', 10, 2)->default(0)->after('promo_code_id');
            $table->decimal('tax_amount', 10, 2)->default(0)->after('discount_amount');
            $table->decimal('tip_amount', 10, 2)->default(0)->after('tax_amount');
            $table->json('metadata')->nullable()->after('tip_amount'); // Additional booking data
            $table->timestamp('reminder_sent_at')->nullable()->after('metadata');
            $table->integer('reminder_count')->default(0)->after('reminder_sent_at');
        });

        // Add missing fields to payments table
        Schema::table('payments', function (Blueprint $table) {
            $table->string('payment_intent_id')->nullable()->after('transaction_id');
            $table->decimal('processing_fee', 10, 2)->default(0)->after('amount');
            $table->string('gateway')->nullable()->after('method'); // 'stripe', 'paypal', etc.
            $table->json('metadata')->nullable()->after('gateway_response');
            $table->timestamp('failed_at')->nullable()->after('paid_at');
            $table->text('failure_reason')->nullable()->after('failed_at');
        });

        // Add missing fields to services table
        Schema::table('services', function (Blueprint $table) {
            $table->integer('max_bookings_per_slot')->default(1)->after('duration');
            $table->integer('advance_booking_days')->default(30)->after('max_bookings_per_slot');
            $table->integer('min_advance_hours')->default(2)->after('advance_booking_days');
            $table->integer('cancellation_hours')->default(24)->after('min_advance_hours');
            $table->json('settings')->nullable()->after('cancellation_hours');
            $table->decimal('tax_rate', 5, 2)->default(0)->after('settings');
            $table->boolean('allow_staff_selection')->default(true)->after('tax_rate');
        });

        // Add missing fields to staff table
        Schema::table('staff', function (Blueprint $table) {
            $table->decimal('commission_rate', 5, 2)->nullable()->after('is_active');
            $table->text('bio')->nullable()->after('commission_rate');
            $table->json('specializations')->nullable()->after('bio');
            $table->json('certifications')->nullable()->after('specializations');
            $table->date('hired_date')->nullable()->after('certifications');
            $table->boolean('receive_notifications')->default(true)->after('hired_date');
        });

        // Add missing fields to reviews table
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('hidden_reason')->nullable()->after('is_approved');
            $table->text('business_response')->nullable()->after('hidden_reason');
            $table->timestamp('business_responded_at')->nullable()->after('business_response');
            $table->integer('helpful_count')->default(0)->after('business_responded_at');
            $table->json('metadata')->nullable()->after('helpful_count'); // Service name, staff, etc.
            $table->timestamp('moderated_at')->nullable()->after('metadata');
            $table->foreignId('moderated_by')->nullable()->constrained('users')->after('moderated_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'cancelled_by', 'promo_code_id', 'discount_amount', 'tax_amount',
                'tip_amount', 'metadata', 'reminder_sent_at', 'reminder_count'
            ]);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'payment_intent_id', 'processing_fee', 'gateway', 'metadata',
                'failed_at', 'failure_reason'
            ]);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn([
                'max_bookings_per_slot', 'advance_booking_days', 'min_advance_hours',
                'cancellation_hours', 'settings', 'tax_rate', 'allow_staff_selection'
            ]);
        });

        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn([
                'commission_rate', 'bio', 'specializations', 'certifications',
                'hired_date', 'receive_notifications'
            ]);
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn([
                'hidden_reason', 'business_response', 'business_responded_at',
                'helpful_count', 'metadata', 'moderated_at', 'moderated_by'
            ]);
        });
    }
};