<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Service;
use App\Models\Business;
use App\Models\User;
use App\Models\PromoCode;
use App\Exceptions\BookingConflictException;
use App\Exceptions\BookingException;
use App\Exceptions\SlotUnavailableException;
use App\Exceptions\InvalidPromoCodeException;
use App\Repositories\Contracts\BookingRepositoryInterface;
use App\Events\BookingCreated;
use App\Events\BookingCancelled;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BookingService
{
    public function __construct(
        private BookingRepositoryInterface $bookingRepository,
        private CacheService $cacheService,
        private AvailabilityService $availabilityService,
        private PaymentService $paymentService,
        private NotificationService $notificationService
    ) {}

    public function validateBookingAvailability(array $bookingData): bool
    {
        $service = Service::findOrFail($bookingData['service_id']);
        $date = Carbon::parse($bookingData['booking_date']);
        $startTime = $bookingData['start_time'];
        
        $endTime = Carbon::parse($startTime)
            ->addMinutes($service->duration)
            ->format('H:i');

        return $this->bookingRepository->checkAvailability(
            $service,
            $date,
            $startTime,
            $endTime,
            $bookingData['staff_id'] ?? null
        );
    }

    public function createBookingWithValidation(array $data): Booking
    {
        DB::beginTransaction();

        try {
            // Additional validations
            $this->validateBusinessHours($data);
            $this->validateAdvanceBookingLimits($data);
            $this->validateCustomerBookingLimits($data);

            // Check for conflicts one more time (race condition protection)
            if (!$this->validateBookingAvailability($data)) {
                throw new BookingConflictException('Time slot became unavailable');
            }

            $booking = $this->bookingRepository->create($data);

            // Clear related caches
            $this->clearAvailabilityCache($booking);

            // Send notifications
            $this->notificationService->sendBookingConfirmation($booking);

            DB::commit();

            Log::info('Booking created successfully', [
                'booking_id' => $booking->id,
                'booking_ref' => $booking->booking_ref,
                'user_id' => $booking->user_id
            ]);

            return $booking;

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Booking creation failed', [
                'data' => $data,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    public function createBooking(array $data): Booking
    {
        DB::beginTransaction();

        try {
            // Validate availability
            $this->validateAvailability($data);

            // Apply promo code if provided
            if (!empty($data['promo_code'])) {
                $data = $this->applyPromoCode($data, $data['promo_code']);
            }

            // Create booking
            $booking = Booking::create($data);

            // Calculate end time
            $endTime = Carbon::parse($data['start_time'])
                ->addMinutes($booking->service->duration)
                ->format('H:i');
            
            $booking->update(['end_time' => $endTime]);

            // Send notifications
            $this->notificationService->sendBookingCreated($booking);

            // Clear availability cache
            $this->clearAvailabilityCache($booking);

            DB::commit();

            event(new BookingCreated($booking));

            return $booking;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new BookingException('Failed to create booking: ' . $e->getMessage());
        }
    }

    public function cancelBooking(Booking $booking, string $reason, User $cancelledBy = null): bool
    {
        if (!$booking->canBeCancelled()) {
            throw new BookingException('This booking cannot be cancelled');
        }

        DB::beginTransaction();

        try {
            $refundAmount = $booking->calculateRefundAmount();

            $booking->update([
                'status' => 'cancelled',
                'cancellation_reason' => $reason,
                'cancelled_at' => now(),
                'cancelled_by' => $cancelledBy?->id ?? 'system'
            ]);

            // Process refund if applicable
            if ($refundAmount > 0 && $booking->payment_status === 'paid') {
                $this->paymentService->processRefund($booking, $refundAmount);
            }

            // Send notifications
            $this->notificationService->sendBookingCancelled($booking);

            // Clear cache
            $this->clearAvailabilityCache($booking);

            DB::commit();

            event(new BookingCancelled($booking, $cancelledBy, $reason, $refundAmount > 0));

            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new BookingException('Failed to cancel booking: ' . $e->getMessage());
        }
    }

    public function processRefund(Booking $booking, float $refundPercentage = 1.0): bool
    {
        try {
            $refundAmount = $booking->amount * $refundPercentage;
            
            // Process refund through payment service
            $refundSuccess = $this->paymentService->refund($booking->payments()->latest()->first(), $refundAmount);

            if ($refundSuccess) {
                $booking->update([
                    'payment_status' => 'refunded',
                    'refund_amount' => $refundAmount,
                    'refunded_at' => now()
                ]);

                Log::info('Refund processed successfully', [
                    'booking_id' => $booking->id,
                    'refund_amount' => $refundAmount
                ]);

                return true;
            }

            return false;

        } catch (\Exception $e) {
            Log::error('Refund processing failed', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    public function clearAvailabilityCache(Booking $booking): void
    {
        $this->cacheService->invalidateBusinessCache($booking->business);
        $this->cacheService->invalidateAvailabilityCache(
            $booking->business_id,
            $booking->booking_date
        );
        $this->cacheService->invalidateServiceCache($booking->service_id);
    }

    public function getBookingRecommendations(int $userId, int $serviceId): array
    {
        $service = Service::with('business')->findOrFail($serviceId);
        
        return [
            'optimal_times' => $this->getOptimalBookingTimes($service),
            'alternative_services' => $this->getSimilarServices($service),
            'discounts' => $this->getAvailableDiscounts($userId, $service),
            'bundle_suggestions' => $this->getBundleRecommendations($userId, $service)
        ];
    }

    private function validateAvailability(array $data): void
    {
        $isAvailable = $this->availabilityService->isSlotAvailable(
            $data['service_id'],
            $data['booking_date'],
            $data['start_time'],
            $data['staff_id'] ?? null
        );

        if (!$isAvailable) {
            throw new SlotUnavailableException('Selected time slot is not available');
        }
    }

    private function applyPromoCode(array $data, string $promoCode): array
    {
        $promo = PromoCode::where('code', $promoCode)
            ->active()
            ->first();

        if (!$promo || !$promo->isValid($data['amount'], $data['user_id'], $data['business_id'])) {
            throw new InvalidPromoCodeException('Invalid or expired promo code');
        }

        $discount = $promo->calculateDiscount($data['amount']);
        
        $data['promo_code_id'] = $promo->id;
        $data['discount_amount'] = $discount;
        $data['amount'] -= $discount;

        return $data;
    }

    private function validateBusinessHours(array $data): void
    {
        $service = Service::with('business')->findOrFail($data['service_id']);
        $dateTime = Carbon::parse($data['booking_date'] . ' ' . $data['start_time']);
        
        if (!$service->business->isOpen($dateTime)) {
            throw new BookingConflictException('Business is closed at the selected time');
        }
    }

    private function validateAdvanceBookingLimits(array $data): void
    {
        $service = Service::findOrFail($data['service_id']);
        $bookingDateTime = Carbon::parse($data['booking_date'] . ' ' . $data['start_time']);
        
        // Check minimum advance time
        $minAdvanceHours = $service->min_advance_hours ?? 2;
        if ($bookingDateTime->lessThan(now()->addHours($minAdvanceHours))) {
            throw new BookingConflictException(
                "Bookings must be made at least {$minAdvanceHours} hours in advance"
            );
        }

        // Check maximum advance time
        $maxAdvanceDays = $service->advance_booking_days ?? 30;
        if ($bookingDateTime->greaterThan(now()->addDays($maxAdvanceDays))) {
            throw new BookingConflictException(
                "Bookings cannot be made more than {$maxAdvanceDays} days in advance"
            );
        }
    }

    private function validateCustomerBookingLimits(array $data): void
    {
        // Check daily booking limit for customer
        $dailyLimit = config('goreserve.booking.daily_limit', 5);
        $todayBookings = $this->bookingRepository->getForUser($data['user_id'], [
            'date_from' => today(),
            'date_to' => today()
        ])->total();

        if ($todayBookings >= $dailyLimit) {
            throw new BookingConflictException(
                "You have reached the daily booking limit of {$dailyLimit} bookings"
            );
        }
    }

    private function getOptimalBookingTimes(Service $service): array
    {
        // Return times with lowest booking density
        return DB::table('bookings')
            ->where('service_id', $service->id)
            ->where('created_at', '>', now()->subMonths(3))
            ->selectRaw('HOUR(start_time) as hour, COUNT(*) as count')
            ->groupBy('hour')
            ->orderBy('count', 'asc')
            ->limit(3)
            ->get()
            ->map(fn($item) => sprintf('%02d:00', $item->hour))
            ->toArray();
    }

    private function getSimilarServices(Service $service): array
    {
        return Service::where('business_id', $service->business_id)
            ->where('category', $service->category)
            ->where('id', '!=', $service->id)
            ->where('is_active', true)
            ->limit(3)
            ->get(['id', 'name', 'price', 'duration'])
            ->toArray();
    }

    private function getAvailableDiscounts(int $userId, Service $service): array
    {
        // Implementation for discount logic
        return [];
    }

    private function getBundleRecommendations(int $userId, Service $service): array
    {
        // Implementation for bundle recommendations
        return [];
    }
}