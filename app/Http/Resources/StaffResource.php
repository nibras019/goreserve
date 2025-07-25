<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar' => $this->avatar ? asset('storage/' . $this->avatar) : null,
            'bio' => $this->bio,
            'specializations' => $this->specializations ?? [],
            'working_hours' => $this->working_hours,
            'is_active' => $this->is_active,
            'commission_rate' => $this->commission_rate,
            'services' => ServiceResource::collection($this->whenLoaded('services')),
            'business' => [
                'id' => $this->business->id,
                'name' => $this->business->name,
            ],
            'bookings_count' => $this->whenCounted('bookings'),
            'monthly_bookings' => $this->when(
                isset($this->monthly_bookings),
                $this->monthly_bookings
            ),
            'monthly_revenue' => $this->when(
                isset($this->monthly_revenue),
                $this->monthly_revenue
            ),
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
            'next_availability' => $this->when(
                $request->has('include_availability'),
                function () {
                    return $this->getNextAvailableSlot();
                }
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    protected function getNextAvailableSlot()
    {
        // Find next available slot for this staff member
        $today = now();
        for ($i = 0; $i < 7; $i++) {
            $date = $today->copy()->addDays($i);
            $dayOfWeek = strtolower($date->format('l'));
            
            if (isset($this->working_hours[$dayOfWeek])) {
                $hours = $this->working_hours[$dayOfWeek];
                if (isset($hours['open']) && isset($hours['close'])) {
                    // Check if staff is available
                    $isAvailable = $this->isAvailable(
                        $date->format('Y-m-d'),
                        $hours['open'],
                        $hours['close']
                    );
                    
                    if ($isAvailable) {
                        return [
                            'date' => $date->format('Y-m-d'),
                            'time' => $hours['open'],
                            'day' => $date->format('l'),
                        ];
                    }
                }
            }
        }
        return null;
    }
}
