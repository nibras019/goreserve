<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
    $table->id();
    $table->string('booking_ref')->unique();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->foreignId('business_id')->constrained()->onDelete('cascade');
    $table->foreignId('service_id')->constrained()->onDelete('cascade');
    $table->foreignId('staff_id')->nullable()->constrained()->onDelete('set null');
    $table->date('booking_date');
    $table->time('start_time');
    $table->time('end_time');
    $table->decimal('amount', 10, 2);
    $table->enum('status', ['pending', 'confirmed', 'completed', 'cancelled', 'no_show'])
        ->default('pending');
    $table->enum('payment_status', ['pending', 'paid', 'partially_paid', 'refunded'])
        ->default('pending');
    $table->text('notes')->nullable();
    $table->text('cancellation_reason')->nullable();
    $table->timestamp('cancelled_at')->nullable();
    $table->timestamps();
    $table->index(['business_id', 'booking_date', 'status']);
    $table->index(['user_id', 'status']);
    $table->index(['booking_date', 'start_time']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
