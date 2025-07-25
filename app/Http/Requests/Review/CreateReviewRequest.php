<?php
namespace App\Http\Requests\Review;

use Illuminate\Foundation\Http\FormRequest;

class CreateReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('customer');
    }

    public function rules(): array
    {
        return [
            'booking_id' => 'required|integer|exists:bookings,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
            'aspects' => 'nullable|array',
            'aspects.service_quality' => 'nullable|integer|min:1|max:5',
            'aspects.staff_friendliness' => 'nullable|integer|min:1|max:5',
            'aspects.cleanliness' => 'nullable|integer|min:1|max:5',
            'aspects.value_for_money' => 'nullable|integer|min:1|max:5',
            'aspects.punctuality' => 'nullable|integer|min:1|max:5',
            'photos' => 'nullable|array|max:5',
            'photos.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048',
        ];
    }

    public function messages(): array
    {
        return [
            'booking_id.required' => 'Booking ID is required.',
            'booking_id.exists' => 'Invalid booking selected.',
            'rating.required' => 'Rating is required.',
            'rating.min' => 'Rating must be at least 1 star.',
            'rating.max' => 'Rating cannot exceed 5 stars.',
            'comment.max' => 'Comment cannot exceed 1000 characters.',
            'photos.max' => 'You can upload maximum 5 photos.',
            'photos.*.image' => 'All files must be images.',
            'photos.*.max' => 'Each photo must not exceed 2MB.',
        ];
    }
}