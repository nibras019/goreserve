<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar' => $this->avatar ? asset('storage/' . $this->avatar) : null,
            'status' => $this->status,
            'email_verified_at' => $this->email_verified_at,
            'roles' => $this->whenLoaded('roles', function () {
                return $this->roles->pluck('name');
            }),
            'business' => new BusinessResource($this->whenLoaded('business')),
            'wallet_balance' => $this->when(
                $request->user() && ($request->user()->id === $this->id || $request->user()->hasRole('admin')),
                $this->wallet_balance ?? 0
            ),
            'total_bookings' => $this->whenCounted('bookings'),
            'total_spent' => $this->when(
                $request->user() && $request->user()->id === $this->id,
                $this->bookings()->where('payment_status', 'paid')->sum('amount')
            ),
            'last_login_at' => $this->last_login_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}