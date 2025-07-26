<?php

namespace App\Repositories\Contracts;

use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface BookingRepositoryInterface
{
    public function create(array $data): Booking;
    public function findById(int $id): ?Booking;
    public function findByReference(string $bookingRef): ?Booking;
    public function getForUser(int $userId, array $filters = []): LengthAwarePaginator;
    public function getForBusiness(int $businessId, array $filters = []): LengthAwarePaginator;
    public function checkAvailability(Service $service, Carbon $date, string $startTime, string $endTime, ?int $staffId = null): bool;
    public function getAvailableSlots(Service $service, Carbon $date, ?int $staffId = null): Collection;
    public function getUpcoming(int $userId): Collection;
    public function getConflictingBookings(Service $service, Carbon $date, string $startTime, string $endTime, ?int $excludeBookingId = null): Collection;
}