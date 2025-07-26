<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Check if notifications table exists
        if (!Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                
                $table->index(['notifiable_type', 'notifiable_id']);
                $table->index('read_at');
            });
        } else {
            // Table exists, just add any missing columns
            Schema::table('notifications', function (Blueprint $table) {
                // Add any additional columns that might be missing
                if (!Schema::hasColumn('notifications', 'priority')) {
                    $table->enum('priority', ['low', 'normal', 'high'])->default('normal')->after('type');
                }
                if (!Schema::hasColumn('notifications', 'channel')) {
                    $table->string('channel')->default('database')->after('priority');
                }
                if (!Schema::hasColumn('notifications', 'metadata')) {
                    $table->json('metadata')->nullable()->after('data');
                }
            });
        }
    }

    public function down(): void
    {
        // Only drop if we created it
        if (Schema::hasColumn('notifications', 'priority')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->dropColumn(['priority', 'channel', 'metadata']);
            });
        }
    }
};