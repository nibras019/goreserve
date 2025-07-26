<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, HasWallet;

    protected $fillable = [
        'name', 'email', 'password', 'phone', 'avatar', 'status',
        'wallet_balance', 'preferences', 'timezone', 'locale'
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'last_login_at' => 'datetime',
        'preferences' => 'array',
        'wallet_balance' => 'decimal:2'
    ];

    public function business()
    {
        return $this->hasOne(Business::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function isVendor()
    {
        return $this->hasRole('vendor');
    }

    public function isAdmin()
    {
        return $this->hasRole('admin');
    }
     // Wallet functionality
    public function debitWallet(float $amount, string $description = null): bool
    {
        if ($this->wallet_balance < $amount) {
            throw new InsufficientFundsException();
        }

        DB::transaction(function () use ($amount, $description) {
            $this->decrement('wallet_balance', $amount);
            $this->walletTransactions()->create([
                'type' => 'debit',
                'amount' => $amount,
                'description' => $description
            ]);
        });

        return true;
    }

    public function creditWallet(float $amount, string $description = null): bool
    {
        DB::transaction(function () use ($amount, $description) {
            $this->increment('wallet_balance', $amount);
            $this->walletTransactions()->create([
                'type' => 'credit',
                'amount' => $amount,
                'description' => $description
            ]);
        });

        return true;
    }

    public function walletTransactions()
    {
        return $this->hasMany(WalletTransaction::class);
    }

    // Analytics
    public function getBookingStatsAttribute()
    {
        return Cache::remember("user_{$this->id}_booking_stats", 3600, function () {
            return [
                'total' => $this->bookings()->count(),
                'completed' => $this->bookings()->where('status', 'completed')->count(),
                'upcoming' => $this->bookings()->upcoming()->count(),
                'total_spent' => $this->bookings()
                    ->where('payment_status', 'paid')
                    ->sum('amount')
            ];
        });
    }
}