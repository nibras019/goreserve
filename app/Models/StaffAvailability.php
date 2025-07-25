<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StaffAvailability extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_id',
        'date',
        'type',
        'start_time',
        'end_time',
        'reason'
    ];

    protected $casts = [
        'date' => 'date'
    ];

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function scopeForDate($query, $date)
    {
        return $query->where('date', $date);
    }

    public function scopeBlocking($query)
    {
        return $query->whereIn('type', ['vacation', 'sick', 'blocked']);
    }

    public function scopeAvailable($query)
    {
        return $query->where('type', 'available');
    }

    public function isBlocking()
    {
        return in_array($this->type, ['vacation', 'sick', 'blocked']);
    }

    public function getFormattedTypeAttribute()
    {
        return match($this->type) {
            'vacation' => 'On Vacation',
            'sick' => 'Sick Leave',
            'blocked' => 'Unavailable',
            'available' => 'Available',
            default => ucfirst($this->type)
        };
    }
}
