<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_id' => 'required|exists:services,id',
            'staff_id' => 'nullable|exists:staff,id',
            'booking_date' => 'required|date|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'notes' => 'nullable|string|max:500'
        ];
    }

    public function messages(): array
    {
        return [
            'booking_date.after_or_equal' => 'Booking date must be today or a future date.',
            'start_time.date_format' => 'Start time must be in HH:MM format.',
        ];
    }
}