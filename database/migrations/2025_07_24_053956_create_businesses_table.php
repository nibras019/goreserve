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
       Schema::create('businesses', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->string('name');
    $table->string('slug')->unique();
    $table->enum('type', ['salon', 'spa', 'hotel', 'restaurant', 'other']);
    $table->text('description')->nullable();
    $table->string('phone');
    $table->string('email')->nullable();
    $table->text('address');
    $table->decimal('latitude', 10, 8)->nullable();
    $table->decimal('longitude', 11, 8)->nullable();
    $table->json('working_hours'); // {"monday": {"open": "09:00", "close": "18:00"}, ...}
    $table->json('settings')->nullable(); // Additional settings
    $table->enum('status', ['pending', 'approved', 'suspended'])->default('pending');
    $table->decimal('rating', 3, 2)->default(0);
    $table->integer('total_reviews')->default(0);
    $table->timestamps();
    $table->index(['status', 'type']);
    $table->index(['latitude', 'longitude']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
