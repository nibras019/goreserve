<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class CacheService
{
    private const DEFAULT_TTL = 3600; // 1 hour
    private const AVAILABILITY_TTL = 300; // 5 minutes
    private const BUSINESS_TTL = 1800; // 30 minutes

    public function getBusinessAvailability(Business $business, Carbon $date): array
    {
        $cacheKey = $this->getAvailabilityCacheKey($business->id, $date);
        
        return Cache::tags(['availability', "business:{$business->id}"])
            ->remember($cacheKey, self::AVAILABILITY_TTL, function () use ($business, $date) {
                return $this->calculateBusinessAvailability($business, $date);
            });
    }

    public function getServiceSlots(Service $service, Carbon $date, ?int $staffId = null): Collection
    {
        $cacheKey = $this->getSlotsCacheKey($service->id, $date, $staffId);
        
        return Cache::tags(['slots', "service:{$service->id}"])
            ->remember($cacheKey, self::AVAILABILITY_TTL, function () use ($service, $date, $staffId) {
                return app(\App\Repositories\Contracts\BookingRepositoryInterface::class)
                    ->getAvailableSlots($service, $date, $staffId);
            });
    }

    public function getBusinessDetails(int $businessId): ?Business
    {
        $cacheKey = "business_details:{$businessId}";
        
        return Cache::tags(['business', "business:{$businessId}"])
            ->remember($cacheKey, self::BUSINESS_TTL, function () use ($businessId) {
                return Business::with(['services', 'staff', 'media'])
                    ->find($businessId);
            });
    }

    public function invalidateBusinessCache(Business $business): void
    {
        Cache::tags(["business:{$business->id}"])->flush();
    }

    public function invalidateAvailabilityCache(int $businessId, ?Carbon $date = null): void
    {
        if ($date) {
            $cacheKey = $this->getAvailabilityCacheKey($businessId, $date);
            Cache::forget($cacheKey);
        } else {
            Cache::tags(['availability', "business:{$businessId}"])->flush();
        }
    }

    public function invalidateServiceCache(int $serviceId): void
    {
        Cache::tags(["service:{$serviceId}"])->flush();
    }

    public function warmupCache(): void
    {
        // Warm up cache for popular businesses
        $popularBusinesses = Business::where('status', 'approved')
            ->where('rating', '>=', 4.0)
            ->limit(50)
            ->get();

        foreach ($popularBusinesses as $business) {
            // Cache business details
            $this->getBusinessDetails($business->id);
            
            // Cache availability for next 7 days
            for ($i = 0; $i < 7; $i++) {
                $date = now()->addDays($i);
                $this->getBusinessAvailability($business, $date);
            }
        }
    }

    private function getAvailabilityCacheKey(int $businessId, Carbon $date): string
    {
        return "availability:{$businessId}:{$date->format('Y-m-d')}";
    }

    private function getSlotsCacheKey(int $serviceId, Carbon $date, ?int $staffId): string
    {
        return "slots:{$serviceId}:{$date->format('Y-m-d')}:" . ($staffId ?? 'any');
    }

    private function calculateBusinessAvailability(Business $business, Carbon $date): array
    {
        $dayOfWeek = strtolower($date->format('l'));
        $workingHours = $business->working_hours[$dayOfWeek] ?? null;
        
        if (!$workingHours) {
            return ['is_open' => false, 'reason' => 'Closed'];
        }

        $bookingsCount = $business->bookings()
            ->where('booking_date', $date->format('Y-m-d'))
            ->where('status', '!=', 'cancelled')
            ->count();

        $maxCapacity = $business->services->sum('max_bookings_per_slot') * 16; // Assuming 16 slots per day

        return [
            'is_open' => true,
            'working_hours' => $workingHours,
            'bookings_count' => $bookingsCount,
            'capacity_percentage' => $maxCapacity > 0 ? ($bookingsCount / $maxCapacity) * 100 : 0,
            'availability' => $maxCapacity > 0 ? max(0, $maxCapacity - $bookingsCount) : 0
        ];
    }
}