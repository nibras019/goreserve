<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'price' => $this->price,
            'formatted_price' => '$' . number_format($this->price, 2),
            'duration' => $this->duration,
            'formatted_duration' => $this->getFormattedDurationAttribute(),
            'is_active' => $this->is_active,
            'max_bookings_per_slot' => $this->max_bookings_per_slot ?? 1,
            'advance_booking_days' => $this->advance_booking_days ?? 30,
            'min_advance_hours' => $this->min_advance_hours ?? 2,
            'cancellation_hours' => $this->cancellation_hours ?? 24,
            'images' => $this->getMedia('images')->map(function ($media) {
                return [
                    'id' => $media->id,
                    'url' => $media->getUrl(),
                    'thumb' => $media->getUrl('thumb'),
                ];
            }),
            'staff' => StaffResource::collection($this->whenLoaded('staff')),
            'business' => [
                'id' => $this->business->id,
                'name' => $this->business->name,
                'slug' => $this->business->slug,
            ],
            'bookings_count' => $this->whenCounted('bookings'),
            'average_rating' => $this->when(
                $this->relationLoaded('bookings'),
                function () {
                    return $this->bookings()
                        ->whereHas('review')
                        ->with('review')
                        ->get()
                        ->avg('review.rating');
                }
            ),
            'settings' => $this->settings ?? [],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}