<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class CreateBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('vendor');
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255|unique:businesses,name',
            'type' => 'required|string|in:salon,spa,hotel,restaurant,other',
            'description' => 'nullable|string|max:1000',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'required|string|max:500',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'working_hours' => 'required|array',
            'working_hours.monday' => 'nullable|array',
            'working_hours.monday.open' => 'required_with:working_hours.monday|date_format:H:i',
            'working_hours.monday.close' => 'required_with:working_hours.monday|date_format:H:i|after:working_hours.monday.open',
            'working_hours.tuesday' => 'nullable|array',
            'working_hours.tuesday.open' => 'required_with:working_hours.tuesday|date_format:H:i',
            'working_hours.tuesday.close' => 'required_with:working_hours.tuesday|date_format:H:i|after:working_hours.tuesday.open',
            'working_hours.wednesday' => 'nullable|array',
            'working_hours.wednesday.open' => 'required_with:working_hours.wednesday|date_format:H:i',
            'working_hours.wednesday.close' => 'required_with:working_hours.wednesday|date_format:H:i|after:working_hours.wednesday.open',
            'working_hours.thursday' => 'nullable|array',
            'working_hours.thursday.open' => 'required_with:working_hours.thursday|date_format:H:i',
            'working_hours.thursday.close' => 'required_with:working_hours.thursday|date_format:H:i|after:working_hours.thursday.open',
            'working_hours.friday' => 'nullable|array',
            'working_hours.friday.open' => 'required_with:working_hours.friday|date_format:H:i',
            'working_hours.friday.close' => 'required_with:working_hours.friday|date_format:H:i|after:working_hours.friday.open',
            'working_hours.saturday' => 'nullable|array',
            'working_hours.saturday.open' => 'required_with:working_hours.saturday|date_format:H:i',
            'working_hours.saturday.close' => 'required_with:working_hours.saturday|date_format:H:i|after:working_hours.saturday.open',
            'working_hours.sunday' => 'nullable|array',
            'working_hours.sunday.open' => 'required_with:working_hours.sunday|date_format:H:i',
            'working_hours.sunday.close' => 'required_with:working_hours.sunday|date_format:H:i|after:working_hours.sunday.open',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'gallery' => 'nullable|array|max:10',
            'gallery.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048',
            'currency' => 'nullable|string|size:3',
            'timezone' => 'nullable|timezone',
            'booking_slot_duration' => 'nullable|integer|in:15,30,45,60',
            'advance_booking_days' => 'nullable|integer|min:1|max:365',
            'min_advance_hours' => 'nullable|integer|min:0|max:168',
            'cancellation_hours' => 'nullable|integer|min:0|max:168',
            'auto_confirm_bookings' => 'nullable|boolean',
            'require_deposit' => 'nullable|boolean',
            'deposit_percentage' => 'nullable|integer|min:0|max:100',
            'settings' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'A business with this name already exists.',
            'working_hours.required' => 'Working hours are required.',
            'logo.image' => 'Logo must be an image file.',
            'logo.max' => 'Logo file size must not exceed 2MB.',
            'gallery.max' => 'You can upload maximum 10 gallery images.',
            'gallery.*.image' => 'All gallery files must be images.',
        ];
    }
}
