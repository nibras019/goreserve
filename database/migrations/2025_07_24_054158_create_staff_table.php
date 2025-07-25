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
       Schema::create('staff', function (Blueprint $table) {
    $table->id();
    $table->foreignId('business_id')->constrained()->onDelete('cascade');
    $table->string('name');
    $table->string('email')->nullable();
    $table->string('phone')->nullable();
    $table->string('avatar')->nullable();
    $table->json('working_hours'); // Same format as business hours
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
