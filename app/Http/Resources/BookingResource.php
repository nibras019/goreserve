<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_ref' => $this->booking_ref,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'amount' => $this->amount,
            'booking_date' => $this->booking_date->format('Y-m-d'),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'duration' => $this->service->duration,
            'notes' => $this->notes,
            'can_cancel' => $this->canBeCancelled(),
            'business' => [
                'id' => $this->business->id,
                'name' => $this->business->name,
                'phone' => $this->business->phone,
                'address' => $this->business->address,
            ],
            'service' => [
                'id' => $this->service->id,
                'name' => $this->service->name,
                'price' => $this->service->price,
                'duration' => $this->service->formatted_duration,
            ],
            'staff' => $this->when($this->staff, [
                'id' => $this->staff?->id,
                'name' => $this->staff?->name,
            ]),
            'user' => new UserResource($this->whenLoaded('user')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'review' => new ReviewResource($this->whenLoaded('review')),
            'cancelled_at' => $this->cancelled_at,
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}