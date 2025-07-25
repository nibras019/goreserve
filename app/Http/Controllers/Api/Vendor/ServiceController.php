<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Http\Resources\ServiceResource;
use App\Http\Requests\Service\CreateServiceRequest;
use App\Http\Requests\Service\UpdateServiceRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServiceController extends Controller
{
    /**
     * Get all services for vendor's business
     */
    public function index(Request $request)
    {
        $business = $request->user()->business;

        if (!$business) {
            return $this->error('No business found', 404);
        }

        $validated = $request->validate([
            'category' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'search' => 'nullable|string',
            'sort_by' => 'nullable|string|in:name,price,bookings,created',
            'sort_order' => 'nullable|string|in:asc,desc'
        ]);

        $query = $business->services()
            ->with(['media', 'staff'])
            ->withCount('bookings');

        // Filters
        if (isset($validated['is_active'])) {
            $query->where('is_active', $validated['is_active']);
        }

        if (!empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        if (!empty($validated['search'])) {
            $query->where('name', 'like', "%{$validated['search']}%");
        }

        // Sorting
        $sortBy = $validated['sort_by'] ?? 'name';
        $sortOrder = $validated['sort_order'] ?? 'asc';

        switch ($sortBy) {
            case 'price':
                $query->orderBy('price', $sortOrder);
                break;
            case 'bookings':
                $query->orderBy('bookings_count', $sortOrder);
                break;
            case 'created':
                $query->orderBy('created_at', $sortOrder);
                break;
            default:
                $query->orderBy('name', $sortOrder);
        }

        $services = $query->get()->groupBy('category');

        return $this->success([
            'services' => $services->map(function ($services, $category) {
                return [
                    'category' => $category,
                    'count' => $services->count(),
                    'items' => ServiceResource::collection($services)
                ];
            }),
            'total_services' => $business->services()->count(),
            'active_services' => $business->services()->where('is_active', true)->count(),
            'categories' => $business->services()->distinct('category')->pluck('category')
        ], 'Services retrieved successfully');
    }

    /**
     * Create a new service
     */
    public function store(CreateServiceRequest $request)
    {
        $business = $request->user()->business;

        if (!$business) {
            return $this->error('No business found', 404);
        }

        if ($business->status !== 'approved') {
            return $this->error('Business must be approved before adding services', 403);
        }

        $validated = $request->validated();

        DB::beginTransaction();

        try {
            $service = $business->services()->create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'category' => $validated['category'],
                'price' => $validated['price'],
                'duration' => $validated['duration'],
                'is_active' => $validated['is_active'] ?? true,
                'max_bookings_per_slot' => $validated['max_bookings_per_slot'] ?? 1,
                'advance_booking_days' => $validated['advance_booking_days'] ?? 30,
                'min_advance_hours' => $validated['min_advance_hours'] ?? 2,
                'cancellation_hours' => $validated['cancellation_hours'] ?? 24,
                'settings' => $validated['settings'] ?? []
            ]);

            // Assign staff
            if (!empty($validated['staff_ids'])) {
                $service->staff()->attach($validated['staff_ids']);
            }

            // Handle images
            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $image) {
                    $service->addMedia($image)
                        ->toMediaCollection('images');
                }
            }

            DB::commit();

            return $this->success(
                new ServiceResource($service->load(['media', 'staff'])),
                'Service created successfully',
                201
            );

        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->error(
                'Failed to create service',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Get service details
     */
    public function show(Request $request, Service $service)
    {
        // Verify ownership
        if ($service->business_id !== $request->user()->business->id) {
            return $this->error('Service not found', 404);
        }

        $service->load(['media', 'staff', 'bookings' => function ($query) {
            $query->latest()->limit(10);
        }]);

        // Get service statistics
        $stats = [
            'total_bookings' => $service->bookings()->count(),
            'upcoming_bookings' => $service->bookings()->upcoming()->count(),
            'revenue_mtd' => $service->bookings()
                ->whereMonth('booking_date', now()->month)
                ->where('payment_status', 'paid')
                ->sum('amount'),
            'average_rating' => $service->bookings()
                ->whereHas('review')
                ->with('review')
                ->get()
                ->avg('review.rating'),
            'popular_times' => $this->getPopularTimes($service),
        ];

        return $this->success([
            'service' => new ServiceResource($service),
            'stats' => $stats
        ], 'Service details retrieved successfully');
    }

    /**
     * Update service
     */
    public function update(UpdateServiceRequest $request, Service $service)
    {
        // Verify ownership
        if ($service->business_id !== $request->user()->business->id) {
            return $this->error('Service not found', 404);
        }

        $validated = $request->validated();

        DB::beginTransaction();

        try {
            $service->update($validated);

            // Update staff assignments
            if (isset($validated['staff_ids'])) {
                $service->staff()->sync($validated['staff_ids']);
            }

            // Handle image updates
            if ($request->has('remove_image_ids')) {
                $service->media()
                    ->whereIn('id', $request->remove_image_ids)
                    ->each(fn($media) => $media->delete());
            }

            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $image) {
                    $service->addMedia($image)
                        ->toMediaCollection('images');
                }
            }

            DB::commit();

            return $this->success(
                new ServiceResource($service->fresh(['media', 'staff'])),
                'Service updated successfully'
            );

        } catch (\Exception $e) {
            DB::rollBack();
            
            return $this->error(
                'Failed to update service',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Delete service
     */
    public function destroy(Request $request, Service $service)
    {
        // Verify ownership
        if ($service->business_id !== $request->user()->business->id) {
            return $this->error('Service not found', 404);
        }

        // Check for future bookings
        $hasBookings = $service->bookings()
            ->where('booking_date', '>=', today())
            ->where('status', '!=', 'cancelled')
            ->exists();

        if ($hasBookings) {
            return $this->error(
                'Cannot delete service with active bookings',
                422,
                ['active_bookings' => true]
            );
        }

        $service->delete();

        return $this->success(null, 'Service deleted successfully');
    }

    /**
     * Toggle service status
     */
    public function toggleStatus(Request $request, Service $service)
    {
        // Verify ownership
        if ($service->business_id !== $request->user()->business->id) {
            return $this->error('Service not found', 404);
        }

        $service->update(['is_active' => !$service->is_active]);

        return $this->success([
            'is_active' => $service->is_active,
            'message' => $service->is_active ? 'Service activated' : 'Service deactivated'
        ], 'Service status updated');
    }

    /**
     * Get popular booking times for a service
     */
    private function getPopularTimes($service)
    {
        return $service->bookings()
            ->selectRaw('HOUR(start_time) as hour, COUNT(*) as count')
            ->groupBy('hour')
            ->orderBy('count', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                return [
                    'time' => sprintf('%02d:00', $item->hour),
                    'bookings' => $item->count
                ];
            });
    }
}