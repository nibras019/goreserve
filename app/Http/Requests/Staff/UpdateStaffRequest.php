<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $staff = $this->route('staff');
        return $this->user()->hasRole('vendor') && 
               $staff->business_id === $this->user()->business->id;
    }

    public function rules(): array
    {
        $staffId = $this->route('staff')->id;
        
        return [
            'name' => 'required|string|max:255',
            'email' => "nullable|email|unique:staff,email,{$staffId}",
            'phone' => 'nullable|string|max:20',
            'working_hours' => 'required|array',
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
