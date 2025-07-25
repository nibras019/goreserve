<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class CreateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('vendor') && $this->user()->business;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:staff,email',
            'phone' => 'nullable|string|max:20',
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
            'is_active' => 'nullable|boolean',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'bio' => 'nullable|string|max:500',
            'specializations' => 'nullable|array',
            'specializations.*' => 'string|max:100',
            'service_ids' => 'nullable|array',
            'service_ids.*' => 'integer|exists:services,id',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Staff name is required.',
            'email.unique' => 'This email is already assigned to another staff member.',
            'working_hours.required' => 'Working hours are required.',
            'avatar.image' => 'Avatar must be an image file.',
            'avatar.max' => 'Avatar file size must not exceed 2MB.',
        ];
    }

    protected function prepareForValidation()
    {
        if ($this->has('service_ids') && is_string($this->service_ids)) {
            $this->merge([
                'service_ids' => json_decode($this->service_ids, true) ?: []
            ]);
        }

        if ($this->has('specializations') && is_string($this->specializations)) {
            $this->merge([
                'specializations' => json_decode($this->specializations, true) ?: []
            ]);
        }
    }
}
