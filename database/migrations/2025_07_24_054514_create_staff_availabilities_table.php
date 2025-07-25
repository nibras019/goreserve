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
        Schema::create('staff_availabilities', function (Blueprint $table) {
    $table->id();
    $table->foreignId('staff_id')->constrained()->onDelete('cascade');
    $table->date('date');
    $table->enum('type', ['available', 'vacation', 'sick', 'blocked']);
    $table->time('start_time')->nullable();
    $table->time('end_time')->nullable();
    $table->string('reason')->nullable();
    $table->timestamps();
    $table->index(['staff_id', 'date']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_availabilities');
    }
};
