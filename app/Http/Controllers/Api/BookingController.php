<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\CreateBookingRequest;
use App\Http\Resources\BookingResource;
use App\Repositories\Contracts\BookingRepositoryInterface;
use App\Services\BookingService;
use App\Exceptions\BookingConflictException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    public function __construct(
        private BookingRepositoryInterface $bookingRepository,
        private BookingService $bookingService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|string|in:pending,confirmed,completed,cancelled,no_show',
            'period' => 'nullable|string|in:upcoming,past,today,week,month',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'sort_by' => 'nullable|string|in:date,created,amount',
            'sort_order' => 'nullable|string|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        try {
            $bookings = $this->bookingRepository->getForUser($request->user()->id, $validated);
            
            return $this->paginatedResponse(
                $bookings->through(fn ($booking) => new BookingResource($booking)),
                'Bookings retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve bookings', 500);
        }
    }

    public function store(CreateBookingRequest $request): JsonResponse
    {
        DB::beginTransaction();
        
        try {
            $validated = $request->validated();
            
            // Check availability using repository
            if (!$this->bookingService->validateBookingAvailability($validated)) {
                throw new BookingConflictException('The selected time slot is not available');
            }

            // Create booking
            $booking = $this->bookingRepository->create([
                ...$validated,
                'user_id' => $request->user()->id,
                'status' => 'pending',
                'payment_status' => 'pending'
            ]);

            DB::commit();

            // Clear related cache
            $this->bookingService->clearAvailabilityCache($booking);

            return $this->successResponse(
                new BookingResource($booking->load(['business', 'service', 'staff'])),
                'Booking created successfully',
                201
            );

        } catch (BookingConflictException $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 409, $e->toArray());
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to create booking', 500);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $booking = $this->bookingRepository->findById($id);
            
            if (!$booking) {
                return $this->errorResponse('Booking not found', 404);
            }

            $this->authorize('view', $booking);

            return $this->successResponse(
                new BookingResource($booking),
                'Booking details retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve booking', 500);
        }
    }

    public function availableSlots(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => 'required|exists:services,id',
            'date' => 'required|date|after_or_equal:today',
            'staff_id' => 'nullable|exists:staff,id'
        ]);

        try {
            $service = \App\Models\Service::with('business')->findOrFail($validated['service_id']);
            $date = \Carbon\Carbon::parse($validated['date']);
            
            $slots = $this->bookingRepository->getAvailableSlots(
                $service,
                $date,
                $validated['staff_id'] ?? null
            );

            return $this->successResponse([
                'date' => $validated['date'],
                'service' => [
                    'id' => $service->id,
                    'name' => $service->name,
                    'duration' => $service->duration
                ],
                'staff_id' => $validated['staff_id'],
                'slots' => $slots,
                'timezone' => config('app.timezone')
            ], 'Available slots retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve available slots', 500);
        }
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500'
        ]);

        DB::beginTransaction();

        try {
            $booking = $this->bookingRepository->findById($id);
            
            if (!$booking) {
                return $this->errorResponse('Booking not found', 404);
            }

            $this->authorize('cancel', $booking);

            if (!$booking->canBeCancelled()) {
                return $this->errorResponse('This booking cannot be cancelled', 422);
            }

            // Cancel the booking
            $booking->cancel($validated['reason']);

            // Process refund if needed
            if ($booking->payment_status === 'paid') {
                $this->bookingService->processRefund($booking);
            }

            DB::commit();

            // Clear related cache
            $this->bookingService->clearAvailabilityCache($booking);

            return $this->successResponse(
                new BookingResource($booking),
                'Booking cancelled successfully'
            );

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to cancel booking', 500);
        }
    }
}