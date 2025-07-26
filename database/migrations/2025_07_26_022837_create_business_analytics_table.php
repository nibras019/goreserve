<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_analytics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->integer('total_bookings')->default(0);
            $table->integer('completed_bookings')->default(0);
            $table->integer('cancelled_bookings')->default(0);
            $table->integer('no_show_bookings')->default(0);
            $table->decimal('revenue', 12, 2)->default(0);
            $table->decimal('refunded_amount', 12, 2)->default(0);
            $table->integer('new_customers')->default(0);
            $table->integer('returning_customers')->default(0);
            $table->decimal('average_booking_value', 10, 2)->default(0);
            $table->json('hourly_distribution')->nullable(); // Peak hours data
            $table->json('service_breakdown')->nullable(); // Revenue by service
            $table->timestamps();
            
            $table->unique(['business_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_analytics');
    }
};