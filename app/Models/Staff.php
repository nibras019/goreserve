<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Staff extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'name', 'email', 'phone', 
        'avatar', 'working_hours', 'is_active'
    ];

    protected $casts = [
        'working_hours' => 'array',
        'is_active' => 'boolean'
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function services()
    {
        return $this->belongsToMany(Service::class, 'service_staff');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function availabilities()
    {
        return $this->hasMany(StaffAvailability::class);
    }

    public function isAvailable($date, $startTime, $endTime)
    {
        // Check if staff has any blocking availability
        $blocked = $this->availabilities()
            ->where('date', $date)
            ->whereIn('type', ['vacation', 'sick', 'blocked'])
            ->exists();

        if ($blocked) {
            return false;
        }

        // Check if time is within working hours
        $dayOfWeek = strtolower(date('l', strtotime($date)));
        if (!isset($this->working_hours[$dayOfWeek])) {
            return false;
        }

        $workingHours = $this->working_hours[$dayOfWeek];
        if ($startTime < $workingHours['open'] || $endTime > $workingHours['close']) {
            return false;
        }

        // Check for conflicting bookings
        $hasConflict = $this->bookings()
            ->where('booking_date', $date)
            ->where('status', '!=', 'cancelled')
            ->where(function ($query) use ($startTime, $endTime) {
                $query->whereBetween('start_time', [$startTime, $endTime])
                    ->orWhereBetween('end_time', [$startTime, $endTime])
                    ->orWhere(function ($q) use ($startTime, $endTime) {
                        $q->where('start_time', '<=', $startTime)
                          ->where('end_time', '>=', $endTime);
                    });
            })
            ->exists();

        return !$hasConflict;
    }
}