<?php

namespace App\Http\Requests\Service;

use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $service = $this->route('service');
        return $this->user()->hasRole('vendor') && 
               $service->business_id === $this->user()->business->id;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'category' => 'required|string|max:100',
            'price' => 'required|numeric|min:0|max:999999.99',
            'duration' => 'required|integer|min:15|max:480',
            'is_active' => 'nullable|boolean',
            'max_bookings_per_slot' => 'nullable|integer|min:1|max:100',
            'advance_booking_days' => 'nullable|integer|min:1|max:365',
            'min_advance_hours' => 'nullable|integer|min:0|max:168',
            'cancellation_hours' => 'nullable|integer|min:0|max:168',
            'staff_ids' => 'nullable|array',
            'staff_ids.*' => 'integer|exists:staff,id',
            'images' => 'nullable|array|max:5',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048',
            'remove_image_ids' => 'nullable|array',
            'remove_image_ids.*' => 'integer|exists:media,id',
            'settings' => 'nullable|array',
        ];
    }

    protected function prepareForValidation()
    {
        if ($this->has('staff_ids') && is_string($this->staff_ids)) {
            $this->merge([
                'staff_ids' => json_decode($this->staff_ids, true) ?: []
            ]);
        }

        if ($this->has('remove_image_ids') && is_string($this->remove_image_ids)) {
            $this->merge([
                'remove_image_ids' => json_decode($this->remove_image_ids, true) ?: []
            ]);
        }
    }
}

