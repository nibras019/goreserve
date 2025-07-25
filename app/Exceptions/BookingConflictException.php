<?php

namespace App\Exceptions;

use Exception;
use App\Models\Booking;
use App\Models\Service;
use App\Models\Staff;
use Carbon\Carbon;

class BookingConflictException extends Exception
{
    /**
     * The conflicting booking(s)
     *
     * @var \Illuminate\Support\Collection|null
     */
    protected $conflictingBookings;

    /**
     * The requested booking data
     *
     * @var array
     */
    protected $bookingData;

    /**
     * The type of conflict
     *
     * @var string
     */
    protected $conflictType;

    /**
     * Additional conflict details
     *
     * @var array
     */
    protected $conflictDetails;

    /**
     * Available alternative slots
     *
     * @var array
     */
    protected $suggestions = [];

    /**
     * Create a new booking conflict exception.
     *
     * @param string $message
     * @param array $bookingData
     * @param string $conflictType
     * @param \Illuminate\Support\Collection|null $conflictingBookings
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct(
        string $message = '',
        array $bookingData = [],
        string $conflictType = 'time_slot',
        $conflictingBookings = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        
        $this->bookingData = $bookingData;
        $this->conflictType = $conflictType;
        $this->conflictingBookings = $conflictingBookings;
        
        $this->buildConflictDetails();
        $this->generateSuggestions();
    }

    /**
     * Create exception for time slot conflict
     */
    public static function timeSlotTaken(
        array $bookingData,
        $conflictingBookings = null
    ): self {
        return new static(
            'The selected time slot is already booked',
            $bookingData,
            'time_slot',
            $conflictingBookings
        );
    }

    /**
     * Create exception for staff unavailability
     */
    public static function staffUnavailable(
        Staff $staff,
        array $bookingData,
        string $reason = 'booked'
    ): self {
        $message = match($reason) {
            'booked' => "Staff member {$staff->name} is already booked for this time",
            'off_duty' => "Staff member {$staff->name} is not working at this time",
            'on_break' => "Staff member {$staff->name} is on break during this time",
            'vacation' => "Staff member {$staff->name} is on vacation",
            default => "Staff member {$staff->name} is unavailable"
        };

        return new static(
            $message,
            $bookingData,
            'staff_unavailable',
            null
        );
    }

    /**
     * Create exception for business closed
     */
    public static function businessClosed(
        array $bookingData,
        array $workingHours
    ): self {
        $exception = new static(
            'The business is closed at the selected time',
            $bookingData,
            'business_closed'
        );

        $exception->conflictDetails['working_hours'] = $workingHours;
        
        return $exception;
    }

    /**
     * Create exception for capacity exceeded
     */
    public static function capacityExceeded(
        array $bookingData,
        int $currentCapacity,
        int $maxCapacity
    ): self {
        $exception = new static(
            'The maximum capacity for this time slot has been reached',
            $bookingData,
            'capacity_exceeded'
        );

        $exception->conflictDetails['current_capacity'] = $currentCapacity;
        $exception->conflictDetails['max_capacity'] = $maxCapacity;
        
        return $exception;
    }

    /**
     * Create exception for booking too far in advance
     */
    public static function tooFarInAdvance(
        array $bookingData,
        int $maxAdvanceDays
    ): self {
        return new static(
            "Bookings cannot be made more than {$maxAdvanceDays} days in advance",
            $bookingData,
            'advance_limit_exceeded'
        );
    }

    /**
     * Create exception for minimum notice not met
     */
    public static function minimumNoticeRequired(
        array $bookingData,
        int $minimumHours
    ): self {
        return new static(
            "Bookings require at least {$minimumHours} hours advance notice",
            $bookingData,
            'minimum_notice_required'
        );
    }

    /**
     * Build detailed conflict information
     */
    protected function buildConflictDetails(): void
    {
        $this->conflictDetails = [
            'conflict_type' => $this->conflictType,
            'requested_date' => $this->bookingData['date'] ?? null,
            'requested_time' => $this->bookingData['start_time'] ?? null,
            'requested_duration' => $this->bookingData['duration'] ?? null,
        ];

        if ($this->conflictingBookings && $this->conflictingBookings->isNotEmpty()) {
            $this->conflictDetails['conflicts'] = $this->conflictingBookings->map(function ($booking) {
                return [
                    'booking_ref' => $booking->booking_ref,
                    'start_time' => $booking->start_time,
                    'end_time' => $booking->end_time,
                    'service' => $booking->service->name ?? null,
                    'staff' => $booking->staff->name ?? null,
                ];
            })->toArray();
        }
    }

    /**
     * Generate alternative slot suggestions
     */
    protected function generateSuggestions(): void
    {
        if (!isset($this->bookingData['service_id']) || !isset($this->bookingData['date'])) {
            return;
        }

        try {
            $service = Service::find($this->bookingData['service_id']);
            if (!$service) {
                return;
            }

            $requestedDate = Carbon::parse($this->bookingData['date']);
            $requestedTime = Carbon::parse($this->bookingData['start_time'] ?? '00:00');

            // Suggest alternative times on the same day
            $this->suggestions['same_day'] = $this->findAlternativeTimesOnDay(
                $service,
                $requestedDate,
                $requestedTime
            );

            // Suggest next available day
            $this->suggestions['next_available'] = $this->findNextAvailableDay(
                $service,
                $requestedDate,
                $requestedTime
            );

            // Suggest alternative staff if applicable
            if (isset($this->bookingData['staff_id'])) {
                $this->suggestions['alternative_staff'] = $this->findAlternativeStaff(
                    $service,
                    $requestedDate,
                    $requestedTime
                );
            }
        } catch (\Exception $e) {
            // Don't let suggestion generation fail the exception
            $this->suggestions = [];
        }
    }

    /**
     * Find alternative times on the same day
     */
    protected function findAlternativeTimesOnDay($service, $date, $requestedTime): array
    {
        // This would integrate with your booking service
        // For now, return example slots
        return [
            ['time' => '09:00', 'available' => true],
            ['time' => '14:30', 'available' => true],
            ['time' => '16:00', 'available' => true],
        ];
    }

    /**
     * Find next available day
     */
    protected function findNextAvailableDay($service, $startDate, $preferredTime): ?array
    {
        // This would integrate with your booking service
        // For now, return example
        return [
            'date' => $startDate->copy()->addDay()->format('Y-m-d'),
            'time' => $preferredTime->format('H:i'),
            'available' => true,
        ];
    }

    /**
     * Find alternative staff members
     */
    protected function findAlternativeStaff($service, $date, $time): array
    {
        // This would check other staff availability
        // For now, return example
        return [
            ['staff_id' => 2, 'staff_name' => 'Jane Doe', 'available' => true],
            ['staff_id' => 3, 'staff_name' => 'Bob Smith', 'available' => true],
        ];
    }

    /**
     * Get the booking data that caused the conflict
     */
    public function getBookingData(): array
    {
        return $this->bookingData;
    }

    /**
     * Get the type of conflict
     */
    public function getConflictType(): string
    {
        return $this->conflictType;
    }

    /**
     * Get detailed conflict information
     */
    public function getConflictDetails(): array
    {
        return $this->conflictDetails;
    }

    /**
     * Get suggested alternatives
     */
    public function getSuggestions(): array
    {
        return $this->suggestions;
    }

    /**
     * Get conflicting bookings
     */
    public function getConflictingBookings()
    {
        return $this->conflictingBookings;
    }

    /**
     * Convert exception to array for API responses
     */
    public function toArray(): array
    {
        return [
            'error' => 'booking_conflict',
            'message' => $this->getMessage(),
            'conflict_type' => $this->conflictType,
            'details' => $this->conflictDetails,
            'suggestions' => $this->suggestions,
        ];
    }
}