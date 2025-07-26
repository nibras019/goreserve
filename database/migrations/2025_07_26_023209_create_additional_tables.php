<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Promo codes table
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->enum('type', ['percentage', 'fixed']);
            $table->decimal('value', 10, 2);
            $table->decimal('minimum_amount', 10, 2)->nullable();
            $table->decimal('maximum_discount', 10, 2)->nullable();
            $table->integer('usage_limit')->nullable();
            $table->integer('used_count')->default(0);
            $table->timestamp('valid_from');
            $table->timestamp('valid_until');
            $table->boolean('is_active')->default(true);
            $table->foreignId('business_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade'); // User-specific
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['code', 'is_active']);
            $table->index(['business_id', 'is_active']);
        });

        // Review aspects table (for detailed reviews)
        Schema::create('review_aspects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->onDelete('cascade');
            $table->string('aspect'); // 'service_quality', 'cleanliness', 'staff_friendliness'
            $table->integer('rating'); // 1-5
            $table->timestamps();
            
            $table->index(['review_id', 'aspect']);
        });

        // Review reports table
        Schema::create('review_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('reason', ['spam', 'offensive', 'fake', 'other']);
            $table->text('details')->nullable();
            $table->enum('status', ['pending', 'reviewed', 'dismissed', 'actioned'])->default('pending');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->text('admin_notes')->nullable();
            $table->timestamps();
            
            $table->index(['review_id', 'status']);
            $table->unique(['review_id', 'user_id']);
        });

        // Review helpful votes
        Schema::create('review_helpful', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamps();
            
            $table->unique(['review_id', 'user_id']);
        });

        // Business images table
        Schema::create('business_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['logo', 'gallery', 'cover']);
            $table->string('path');
            $table->string('alt_text')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            
            $table->index(['business_id', 'type']);
        });

        // Booking reminders table
        Schema::create('booking_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['booking_reminder', 'payment_reminder', 'review_request']);
            $table->integer('hours_before')->nullable(); // For booking reminders
            $table->timestamp('sent_at');
            $table->enum('channel', ['email', 'sms', 'push']);
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['booking_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_reminders');
        Schema::dropIfExists('business_images');
        Schema::dropIfExists('review_helpful');
        Schema::dropIfExists('review_reports');
        Schema::dropIfExists('review_aspects');
        Schema::dropIfExists('promo_codes');
    }
};