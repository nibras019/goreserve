<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Carbon\Carbon;

class ValidWorkingHours implements ValidationRule
{
    protected $allowClosed;

    public function __construct($allowClosed = true)
    {
        $this->allowClosed = $allowClosed;
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_array($value)) {
            $fail('Working hours must be provided as an array.');
            return;
        }

        $validDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        
        foreach ($value as $day => $hours) {
            // Check if day is valid
            if (!in_array(strtolower($day), $validDays)) {
                $fail("Invalid day: {$day}");
                return;
            }

            // Skip if day is closed (null or empty array)
            if (empty($hours) || !is_array($hours)) {
                if (!$this->allowClosed) {
                    $fail("Business must be open on {$day}.");
                    return;
                }
                continue;
            }

            // Check required fields
            if (!isset($hours['open']) || !isset($hours['close'])) {
                $fail("Both open and close times are required for {$day}.");
                return;
            }

            // Validate time format
            if (!$this->isValidTimeFormat($hours['open'])) {
                $fail("Invalid open time format for {$day}. Use HH:MM format.");
                return;
            }

            if (!$this->isValidTimeFormat($hours['close'])) {
                $fail("Invalid close time format for {$day}. Use HH:MM format.");
                return;
            }

            // Check that open time is before close time
            $openTime = Carbon::createFromFormat('H:i', $hours['open']);
            $closeTime = Carbon::createFromFormat('H:i', $hours['close']);

            if ($openTime->gte($closeTime)) {
                $fail("Open time must be before close time for {$day}.");
                return;
            }

            // Check reasonable business hours (optional)
            if ($openTime->hour < 6 || $closeTime->hour > 23) {
                $fail("Business hours for {$day} seem unusual. Please verify times.");
                return;
            }

            // Check minimum operating hours (optional - at least 1 hour)
            if ($closeTime->diffInMinutes($openTime) < 60) {
                $fail("Business must be open for at least 1 hour on {$day}.");
                return;
            }

            // Handle lunch breaks or multiple shifts if provided
            if (isset($hours['breaks']) && is_array($hours['breaks'])) {
                foreach ($hours['breaks'] as $break) {
                    if (!isset($break['start']) || !isset($break['end'])) {
                        $fail("Break times must include both start and end times for {$day}.");
                        return;
                    }

                    if (!$this->isValidTimeFormat($break['start']) || !$this->isValidTimeFormat($break['end'])) {
                        $fail("Invalid break time format for {$day}. Use HH:MM format.");
                        return;
                    }

                    $breakStart = Carbon::createFromFormat('H:i', $break['start']);
                    $breakEnd = Carbon::createFromFormat('H:i', $break['end']);

                    if ($breakStart->gte($breakEnd)) {
                        $fail("Break start time must be before end time for {$day}.");
                        return;
                    }

                    if ($breakStart->lt($openTime) || $breakEnd->gt($closeTime)) {
                        $fail("Break times must be within business hours for {$day}.");
                        return;
                    }
                }
            }
        }

        // Check if business is open at least some days
        $openDays = collect($value)->filter(function ($hours) {
            return !empty($hours) && isset($hours['open']) && isset($hours['close']);
        });

        if ($openDays->isEmpty()) {
            $fail('Business must be open at least one day per week.');
            return;
        }
    }

    /**
     * Check if time format is valid (HH:MM)
     */
    protected function isValidTimeFormat($time): bool
    {
        try {
            Carbon::createFromFormat('H:i', $time);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Create rule that requires business to be open
     */
    public static function requireOpen()
    {
        return new static(false);
    }

    /**
     * Create rule that allows closed days
     */
    public static function allowClosed()
    {
        return new static(true);
    }
}