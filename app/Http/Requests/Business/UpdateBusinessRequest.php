<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->user()->business);
    }

    public function rules(): array
    {
        $businessId = $this->user()->business->id;
        
        return [
            'name' => "required|string|max:255|unique:businesses,name,{$businessId}",
            'type' => 'required|string|in:salon,spa,hotel,restaurant,other',
            'description' => 'nullable|string|max:1000',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'required|string|max:500',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'working_hours' => 'required|array',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'gallery' => 'nullable|array|max:10',
            'gallery.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048',
            'remove_gallery_ids' => 'nullable|array',
            'remove_gallery_ids.*' => 'integer|exists:media,id',
            'settings' => 'nullable|array',
        ];
    }
}