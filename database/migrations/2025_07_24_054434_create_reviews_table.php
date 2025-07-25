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
       Schema::create('reviews', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->foreignId('business_id')->constrained()->onDelete('cascade');
    $table->foreignId('booking_id')->constrained()->onDelete('cascade');
    $table->integer('rating'); // 1-5
    $table->text('comment')->nullable();
    $table->boolean('is_approved')->default(true);
    $table->timestamps();
    $table->unique(['user_id', 'booking_id']);
    $table->index(['business_id', 'is_approved']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
