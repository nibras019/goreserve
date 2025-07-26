<?php

namespace App\Repositories;

use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Repositories\Contracts\BookingRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class BookingRepository implements BookingRepositoryInterface
{
    public function create(array $data): Booking
    {
        return Booking::create($data);
    }

    public function findById(int $id): ?Booking
    {
        return Cache::remember("booking:{$id}", 300, function () use ($id) {
            return Booking::with(['user', 'business', 'service', 'staff'])->find($id);
        });
    }

    public function findByReference(string $bookingRef): ?Booking
    {
        return Cache::remember("booking_ref:{$bookingRef}", 300, function () use ($bookingRef) {
            return Booking::with(['user', 'business', 'service', 'staff'])
                ->where('booking_ref', $bookingRef)
                ->first();
        });
    }

    public function getForUser(int $userId, array $filters = []): LengthAwarePaginator
    {
        $query = Booking::with(['business', 'service', 'staff'])
            ->where('user_id', $userId);

        $this->applyFilters($query, $filters);

        return $query->orderBy('booking_date', 'desc')
            ->orderBy('start_time', 'desc')
            ->paginate($filters['per_page'] ?? 20);
    }

    public function getForBusiness(int $businessId, array $filters = []): LengthAwarePaginator
    {
        $query = Booking::with(['user', 'service', 'staff'])
            ->where('business_id', $businessId);

        $this->applyFilters($query, $filters);

        return $query->orderBy('booking_date', 'desc')
            ->orderBy('start_time', 'desc')
            ->paginate($filters['per_page'] ?? 20);
    }

    public function checkAvailability(Service $service, Carbon $date, string $startTime, string $endTime, ?int $staffId = null): bool
    {
        $cacheKey = "availability:{$service->id}:{$date->format('Y-m-d')}:{$startTime}:{$endTime}:" . ($staffId ?? 'any');
        
        return Cache::remember($cacheKey, 60, function () use ($service, $date, $startTime, $endTime, $staffId) {
            // Check business hours
            if (!$service->business->isOpen(Carbon::parse($date->format('Y-m-d') . ' ' . $startTime))) {
                return false;
            }

            // Check for conflicts
            $conflicts = $this->getConflictingBookings($service, $date, $startTime, $endTime);
            
            if ($staffId) {
                return !$conflicts->where('staff_id', $staffId)->isNotEmpty();
            }

            // Check if any staff is available
            $availableStaff = $service->staff()
                ->where('is_active', true)
                ->whereDoesntHave('bookings', function ($query) use ($date, $startTime, $endTime) {
                    $query->where('booking_date', $date->format('Y-m-d'))
                        ->where('status', '!=', 'cancelled')
                        ->where(function ($q) use ($startTime, $endTime) {
                            $q->whereBetween('start_time', [$startTime, $endTime])
                              ->orWhereBetween('end_time', [$startTime, $endTime])
                              ->orWhere(function ($subQ) use ($startTime, $endTime) {
                                  $subQ->where('start_time', '<=', $startTime)
                                       ->where('end_time', '>=', $endTime);
                              });
                        });
                })
                ->exists();

            return $availableStaff;
        });
    }

    public function getAvailableSlots(Service $service, Carbon $date, ?int $staffId = null): Collection
    {
        $cacheKey = "slots:{$service->id}:{$date->format('Y-m-d')}:" . ($staffId ?? 'any');
        
        return Cache::remember($cacheKey, 300, function () use ($service, $date, $staffId) {
            $business = $service->business;
            $dayOfWeek = strtolower($date->format('l'));
            $workingHours = $business->working_hours[$dayOfWeek] ?? null;

            if (!$workingHours || !isset($workingHours['open']) || !isset($workingHours['close'])) {
                return collect([]);
            }

            $slots = collect();
            $slotDuration = 30; // 30-minute slots
            $serviceDuration = $service->duration;

            $currentTime = Carbon::parse($date->format('Y-m-d') . ' ' . $workingHours['open']);
            $endTime = Carbon::parse($date->format('Y-m-d') . ' ' . $workingHours['close']);

            while ($currentTime->copy()->addMinutes($serviceDuration)->lte($endTime)) {
                $slotStart = $currentTime->format('H:i');
                $slotEnd = $currentTime->copy()->addMinutes($serviceDuration)->format('H:i');

                if ($this->checkAvailability($service, $date, $slotStart, $slotEnd, $staffId)) {
                    $slots->push([
                        'start' => $slotStart,
                        'end' => $slotEnd,
                        'display' => $currentTime->format('g:i A'),
                        'available' => true
                    ]);
                }

                $currentTime->addMinutes($slotDuration);
            }

            return $slots;
        });
    }

    public function getUpcoming(int $userId): Collection
    {
        return Booking::with(['business', 'service', 'staff'])
            ->where('user_id', $userId)
            ->where('booking_date', '>=', today())
            ->where('status', '!=', 'cancelled')
            ->orderBy('booking_date')
            ->orderBy('start_time')
            ->get();
    }

    public function getConflictingBookings(Service $service, Carbon $date, string $startTime, string $endTime, ?int $excludeBookingId = null): Collection
    {
        $query = Booking::where('service_id', $service->id)
            ->where('booking_date', $date->format('Y-m-d'))
            ->where('status', '!=', 'cancelled')
            ->where(function ($q) use ($startTime, $endTime) {
                $q->whereBetween('start_time', [$startTime, $endTime])
                  ->orWhereBetween('end_time', [$startTime, $endTime])
                  ->orWhere(function ($subQ) use ($startTime, $endTime) {
                      $subQ->where('start_time', '<=', $startTime)
                           ->where('end_time', '>=', $endTime);
                  });
            });

        if ($excludeBookingId) {
            $query->where('id', '!=', $excludeBookingId);
        }

        return $query->get();
    }

    private function applyFilters($query, array $filters): void
    {
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('booking_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('booking_date', '<=', $filters['date_to']);
        }

        if (!empty($filters['service_id'])) {
            $query->where('service_id', $filters['service_id']);
        }

        if (!empty($filters['staff_id'])) {
            $query->where('staff_id', $filters['staff_id']);
        }

        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('booking_ref', 'like', "%{$filters['search']}%")
                  ->orWhereHas('user', function ($uq) use ($filters) {
                      $uq->where('name', 'like', "%{$filters['search']}%")
                        ->orWhere('email', 'like', "%{$filters['search']}%");
                  });
            });
        }
    }
}