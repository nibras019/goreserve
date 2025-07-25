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
        Schema::create('payments', function (Blueprint $table) {
    $table->id();
    $table->string('transaction_id')->unique();
    $table->foreignId('booking_id')->constrained()->onDelete('cascade');
    $table->decimal('amount', 10, 2);
    $table->string('currency', 3)->default('USD');
    $table->enum('method', ['stripe', 'razorpay', 'cash', 'other']);
    $table->enum('status', ['pending', 'completed', 'failed', 'refunded']);
    $table->json('gateway_response')->nullable();
    $table->timestamp('paid_at')->nullable();
    $table->timestamps();
    $table->index(['booking_id', 'status']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
