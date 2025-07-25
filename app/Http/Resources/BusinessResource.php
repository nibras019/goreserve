<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'description' => $this->description,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'coordinates' => [
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
            ],
            'working_hours' => $this->working_hours,
            'rating' => $this->rating,
            'total_reviews' => $this->total_reviews,
            'status' => $this->status,
            'is_open' => $this->isOpen(),
            'images' => [
                'logo' => $this->getFirstMediaUrl('logo'),
                'gallery' => $this->getMedia('gallery')->map(function ($media) {
                    return [
                        'id' => $media->id,
                        'url' => $media->getUrl(),
                        'thumb' => $media->getUrl('thumb'),
                    ];
                }),
            ],
            'services_count' => $this->whenCounted('services'),
            'services' => ServiceResource::collection($this->whenLoaded('services')),
            'staff' => StaffResource::collection($this->whenLoaded('staff')),
            'owner' => new UserResource($this->whenLoaded('owner')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}