<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Service;
use App\Models\Staff;
use App\Services\BookingService;
use App\Http\Resources\BookingResource;
use App\Http\Requests\Booking\CreateBookingRequest;
use App\Http\Requests\Booking\UpdateBookingRequest;
use App\Events\BookingCreated;
use App\Events\BookingCancelled;
use App\Exceptions\BookingConflictException;
use App\Jobs\SendBookingConfirmation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BookingController extends Controller
{
    protected $bookingService;

    public function __construct(BookingService $bookingService)
    {
        $this->bookingService = $bookingService;
    }

    /**
     * Get user's bookings
     */
    public function index(Request $request)
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

        $query = $request->user()
            ->bookings()
            ->with(['business', 'service', 'staff', 'review']);

        // Filter by status
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        // Filter by period
        switch ($validated['period'] ?? null) {
            case 'upcoming':
                $query->upcoming();
                break;
            case 'past':
                $query->past();
                break;
            case 'today':
                $query->whereDate('booking_date', today());
                break;
            case 'week':
                $query->whereBetween('booking_date', [now()->startOfWeek(), now()->endOfWeek()]);
                break;
            case 'month':
                $query->whereMonth('booking_date', now()->month)
                      ->whereYear('booking_date', now()->year);
                break;
        }

        // Filter by date range
        if (!empty($validated['from_date'])) {
            $query->whereDate('booking_date', '>=', $validated['from_date']);
        }
        if (!empty($validated['to_date'])) {
            $query->whereDate('booking_date', '<=', $validated['to_date']);
        }

        // Sorting
        $sortBy = $validated['sort_by'] ?? 'date';
        $sortOrder = $validated['sort_order'] ?? 'desc';
        
        switch ($sortBy) {
            case 'created':
                $query->orderBy('created_at', $sortOrder);
                break;
            case 'amount':
                $query->orderBy('amount', $sortOrder);
                break;
            case 'date':
            default:
                $query->orderBy('booking_date', $sortOrder)
                      ->orderBy('start_time', $sortOrder);
                break;
        }

        $bookings = $query->paginate($validated['per_page'] ?? 20);

        return $this->successWithPagination(
            $bookings->through(fn ($booking) => new BookingResource($booking)),
            'Bookings retrieved successfully'
        );
    }

    /**
     * Create a new booking
     */
    public function store(CreateBookingRequest $request)
    {
        $validated = $request->validated();
        
        DB::beginTransaction();
        
        try {
            $service = Service::findOrFail($validated['service_id']);
            
            // Calculate times
            $startTime = Carbon::parse($validated['start_time']);
            $endTime = $startTime->copy()->addMinutes($service->duration);
            
            // Check availability
            if (!$this->bookingService->checkAvailability(
                $service,
                $validated['booking_date'],
                $startTime->format('H:i'),
                $endTime->format('H:i'),
                $validated['staff_id'] ?? null
            )) {
                throw BookingConflictException::timeSlotTaken($validated);
            }

            // Check business hours
            $business = $service->business;
            $bookingDateTime = Carbon::parse($validated['booking_date'] . ' ' . $validated['start_time']);
            
            if (!$business->isOpen($bookingDateTime)) {
                throw BookingConflictException::businessClosed(
                    $validated,
                    $business->working_hours
                );
            }

            // Check advance booking limits
            $advanceDays = now()->diffInDays($validated['booking_date'], false);
            $maxAdvanceDays = config('goreserve.booking.advance_days', 30);
            
            if ($advanceDays > $maxAdvanceDays) {
                throw BookingConflictException::tooFarInAdvance($validated, $maxAdvanceDays);
            }

            // Check minimum notice
            $hoursUntilBooking = now()->diffInHours($bookingDateTime, false);
            $minAdvanceHours = config('goreserve.booking.min_advance_hours', 2);
            
            if ($hoursUntilBooking < $minAdvanceHours) {
                throw BookingConflictException::minimumNoticeRequired($validated, $minAdvanceHours);
            }

            // Create booking
            $booking = Booking::create([
                'user_id' => $request->user()->id,
                'business_id' => $service->business_id,
                'service_id' => $service->id,
                'staff_id' => $validated['staff_id'] ?? null,
                'booking_date' => $validated['booking_date'],
                'start_time' => $startTime->format('H:i'),
                'end_time' => $endTime->format('H:i'),
                'amount' => $validated['custom_amount'] ?? $service->price,
                'notes' => $validated['notes'] ?? null,
                'status' => 'pending',
                'payment_status' => 'pending',
                'source' => $validated['source'] ?? 'website',
                'metadata' => $validated['metadata'] ?? []
            ]);

            // Apply promo code if provided
            if (!empty($validated['promo_code'])) {
                $discount = $this->bookingService->applyPromoCode(
                    $booking,
                    $validated['promo_code']
                );
                
                if ($discount > 0) {
                    $booking->update([
                        'discount_amount' => $discount,
                        'amount' => $booking->amount - $discount
                    ]);
                }
            }

            DB::commit();

            // Load relationships
            $booking->load(['business', 'service', 'staff']);

            // Fire events
            event(new BookingCreated($booking, [
                'source' => $validated['source'] ?? 'website',
                'promo_applied' => !empty($validated['promo_code'])
            ]));

            // Queue confirmation email
            SendBookingConfirmation::dispatch($booking)
                ->delay(now()->addSeconds(5));

            // Log activity
            activity()
                ->performedOn($booking)
                ->causedBy($request->user())
                ->withProperties([
                    'booking_ref' => $booking->booking_ref,
                    'service' => $service->name,
                    'amount' => $booking->amount
                ])
                ->log('Booking created');

            return $this->success(
                new BookingResource($booking),
                'Booking created successfully',
                201
            );

        } catch (BookingConflictException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->error(
                'Failed to create booking. Please try again.',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Get booking details
     */
    public function show(Booking $booking)
    {
        $this->authorize('view', $booking);

        $booking->load([
            'business.media',
            'service.media',
            'staff',
            'payments',
            'review'
        ]);

        return $this->success(
            new BookingResource($booking),
            'Booking details retrieved successfully'
        );
    }

    /**
     * Cancel booking
     */
    public function cancel(Request $request, Booking $booking)
    {
        $this->authorize('cancel', $booking);

        if (!$booking->canBeCancelled()) {
            return $this->error(
                'This booking cannot be cancelled',
                422,
                ['status' => $booking->status]
            );
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:500'
        ]);

        DB::beginTransaction();

        try {
            // Cancel the booking
            $booking->cancel($validated['reason']);

            // Process refund if payment was made
            if ($booking->payment_status === 'paid') {
                $refund = $this->bookingService->processRefund($booking);
                
                if ($refund) {
                    $booking->update(['payment_status' => 'refunded']);
                }
            }

            DB::commit();

            // Fire cancellation event
            event(new BookingCancelled(
                $booking,
                $request->user(),
                $validated['reason'],
                $booking->payment_status === 'refunded'
            ));

            // Log activity
            activity()
                ->performedOn($booking)
                ->causedBy($request->user())
                ->withProperties([
                    'reason' => $validated['reason'],
                    'refunded' => $booking->payment_status === 'refunded'
                ])
                ->log('Booking cancelled');

            return $this->success(
                new BookingResource($booking),
                'Booking cancelled successfully'
            );

        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->error(
                'Failed to cancel booking',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Get available time slots
     */
    public function availableSlots(Request $request)
    {
        $validated = $request->validate([
            'service_id' => 'required|exists:services,id',
            'date' => 'required|date|after_or_equal:today',
            'staff_id' => 'nullable|exists:staff,id'
        ]);

        $service = Service::with('business')->findOrFail($validated['service_id']);
        
        // Check if business is open on this date
        $date = Carbon::parse($validated['date']);
        $dayOfWeek = strtolower($date->format('l'));
        
        if (!isset($service->business->working_hours[$dayOfWeek])) {
            return $this->success([
                'date' => $validated['date'],
                'day' => $dayOfWeek,
                'is_open' => false,
                'slots' => []
            ], 'Business is closed on this day');
        }

        $slots = $this->bookingService->getAvailableSlots(
            $service,
            $validated['date'],
            $validated['staff_id'] ?? null
        );

        return $this->success([
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
    }

    /**
     * Reschedule booking
     */
    public function reschedule(UpdateBookingRequest $request, Booking $booking)
    {
        $this->authorize('update', $booking);

        if ($booking->status !== 'confirmed' && $booking->status !== 'pending') {
            return $this->error('Only pending or confirmed bookings can be rescheduled', 422);
        }

        $validated = $request->validated();

        DB::beginTransaction();

        try {
            // Check new slot availability
            $startTime = Carbon::parse($validated['start_time']);
            $endTime = $startTime->copy()->addMinutes($booking->service->duration);
            
            if (!$this->bookingService->checkAvailability(
                $booking->service,
                $validated['booking_date'],
                $startTime->format('H:i'),
                $endTime->format('H:i'),
                $validated['staff_id'] ?? $booking->staff_id
            )) {
                throw BookingConflictException::timeSlotTaken($validated);
            }

            // Store old booking data for notification
            $oldDate = $booking->booking_date;
            $oldTime = $booking->start_time;

            // Update booking
            $booking->update([
                'booking_date' => $validated['booking_date'],
                'start_time' => $startTime->format('H:i'),
                'end_time' => $endTime->format('H:i'),
                'staff_id' => $validated['staff_id'] ?? $booking->staff_id,
                'reschedule_count' => $booking->reschedule_count + 1,
                'last_rescheduled_at' => now()
            ]);

            DB::commit();

            // Send reschedule notifications
            $this->bookingService->sendRescheduleNotification($booking, $oldDate, $oldTime);

            // Log activity
            activity()
                ->performedOn($booking)
                ->causedBy($request->user())
                ->withProperties([
                    'old_date' => $oldDate,
                    'old_time' => $oldTime,
                    'new_date' => $booking->booking_date,
                    'new_time' => $booking->start_time
                ])
                ->log('Booking rescheduled');

            return $this->success(
                new BookingResource($booking->fresh()),
                'Booking rescheduled successfully'
            );

        } catch (BookingConflictException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->error(
                'Failed to reschedule booking',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }
}