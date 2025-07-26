<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('wallet_balance', 10, 2)->default(0)->after('status');
            $table->timestamp('last_login_at')->nullable()->after('wallet_balance');
            $table->integer('login_count')->default(0)->after('last_login_at');
            $table->json('preferences')->nullable()->after('login_count');
            $table->string('timezone', 50)->default('UTC')->after('preferences');
            $table->string('locale', 10)->default('en')->after('timezone');
            $table->date('date_of_birth')->nullable()->after('locale');
            $table->enum('gender', ['male', 'female', 'other', 'prefer_not_to_say'])->nullable()->after('date_of_birth');
            $table->text('bio')->nullable()->after('gender');
            $table->boolean('email_notifications')->default(true)->after('bio');
            $table->boolean('sms_notifications')->default(false)->after('email_notifications');
            $table->boolean('marketing_emails')->default(true)->after('sms_notifications');
            
            $table->index('wallet_balance');
            $table->index('last_login_at');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'wallet_balance', 'last_login_at', 'login_count', 'preferences',
                'timezone', 'locale', 'date_of_birth', 'gender', 'bio',
                'email_notifications', 'sms_notifications', 'marketing_emails'
            ]);
        });
    }
};