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
       Schema::create('services', function (Blueprint $table) {
    $table->id();
    $table->foreignId('business_id')->constrained()->onDelete('cascade');
    $table->string('name');
    $table->text('description')->nullable();
    $table->string('category');
    $table->decimal('price', 10, 2);
    $table->integer('duration'); // in minutes
    $table->boolean('is_active')->default(true);
    $table->json('availability')->nullable(); // Custom availability rules
    $table->timestamps();
    $table->index(['business_id', 'is_active']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
