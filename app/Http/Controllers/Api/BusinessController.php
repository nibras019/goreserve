<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Http\Resources\BusinessResource;
use App\Http\Resources\ServiceResource;
use App\Services\GeolocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class BusinessController extends Controller
{
    protected $geolocationService;

    public function __construct(GeolocationService $geolocationService)
    {
        $this->geolocationService = $geolocationService;
    }

    /**
     * Get list of businesses
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:255',
            'type' => 'nullable|string|in:salon,spa,hotel,restaurant,other',
            'min_rating' => 'nullable|numeric|min:0|max:5',
            'max_price' => 'nullable|numeric|min:0',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'radius' => 'nullable|numeric|min:1|max:100',
            'services' => 'nullable|array',
            'services.*' => 'string',
            'amenities' => 'nullable|array',
            'amenities.*' => 'string',
            'sort_by' => 'nullable|string|in:rating,distance,price,name',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        $query = Business::query()
            ->with(['media', 'services'])
            ->withCount(['services', 'reviews'])
            ->withAvg('reviews', 'rating')
            ->approved();

        // Search
        if (!empty($validated['search'])) {
            $query->where(function ($q) use ($validated) {
                $q->where('name', 'like', "%{$validated['search']}%")
                  ->orWhere('description', 'like', "%{$validated['search']}%")
                  ->orWhereHas('services', function ($sq) use ($validated) {
                      $sq->where('name', 'like', "%{$validated['search']}%");
                  });
            });
        }

        // Filter by type
        if (!empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        // Filter by rating
        if (!empty($validated['min_rating'])) {
            $query->where('rating', '>=', $validated['min_rating']);
        }

        // Filter by max price
        if (!empty($validated['max_price'])) {
            $query->whereHas('services', function ($q) use ($validated) {
                $q->where('price', '<=', $validated['max_price']);
            });
        }

        // Filter by services
        if (!empty($validated['services'])) {
            $query->whereHas('services', function ($q) use ($validated) {
                $q->whereIn('category', $validated['services']);
            });
        }

        // Location-based search
        if (!empty($validated['latitude']) && !empty($validated['longitude'])) {
            $radius = $validated['radius'] ?? 10;
            $query->nearby($validated['latitude'], $validated['longitude'], $radius);
        }

        // Sorting
        switch ($validated['sort_by'] ?? 'rating') {
            case 'distance':
                if (!empty($validated['latitude']) && !empty($validated['longitude'])) {
                    $query->orderBy('distance');
                }
                break;
            case 'price':
                $query->withMin('services', 'price')->orderBy('services_min_price');
                break;
            case 'name':
                $query->orderBy('name');
                break;
            case 'rating':
            default:
                $query->orderBy('rating', 'desc');
                break;
        }

        $businesses = $query->paginate($validated['per_page'] ?? 20);

        return $this->successWithPagination(
            $businesses->through(fn ($business) => new BusinessResource($business)),
            'Businesses retrieved successfully'
        );
    }

    /**
     * Get business details
     */
    public function show(Business $business)
    {
        // Check if business is approved
        if ($business->status !== 'approved') {
            return $this->error('Business not found', 404);
        }

        // Load relationships
        $business->load([
            'owner',
            'services' => function ($query) {
                $query->active()->with('media');
            },
            'staff' => function ($query) {
                $query->where('is_active', true);
            },
            'reviews' => function ($query) {
                $query->where('is_approved', true)
                      ->with('user')
                      ->latest()
                      ->limit(10);
            },
            'media'
        ]);

        // Increment view count
        $business->increment('views_count');

        // Cache popular businesses
        if ($business->rating >= 4.5) {
            Cache::remember(
                "popular_business_{$business->id}",
                now()->addHours(6),
                fn() => new BusinessResource($business)
            );
        }

        return $this->success(
            new BusinessResource($business),
            'Business details retrieved successfully'
        );
    }

    /**
     * Get business services
     */
    public function services(Business $business)
    {
        if ($business->status !== 'approved') {
            return $this->error('Business not found', 404);
        }

        $services = $business->services()
            ->active()
            ->with(['media', 'staff'])
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->groupBy('category');

        return $this->success([
            'business' => [
                'id' => $business->id,
                'name' => $business->name,
                'type' => $business->type
            ],
            'services' => $services->map(function ($services, $category) {
                return [
                    'category' => $category,
                    'count' => $services->count(),
                    'items' => ServiceResource::collection($services)
                ];
            })
        ], 'Services retrieved successfully');
    }

    /**
     * Get nearby businesses
     */
    public function nearby(Request $request)
    {
        $validated = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'radius' => 'nullable|numeric|min:1|max:50',
            'limit' => 'nullable|integer|min:1|max:50'
        ]);

        $businesses = Business::approved()
            ->with(['media', 'services'])
            ->withCount('services')
            ->nearby(
                $validated['latitude'],
                $validated['longitude'],
                $validated['radius'] ?? 5
            )
            ->limit($validated['limit'] ?? 20)
            ->get();

        return $this->success(
            BusinessResource::collection($businesses),
            'Nearby businesses retrieved successfully'
        );
    }

    /**
     * Get featured businesses
     */
    public function featured()
    {
        $featured = Cache::remember('featured_businesses', now()->addHours(12), function () {
            return Business::approved()
                ->with(['media', 'services'])
                ->withCount(['services', 'reviews'])
                ->where('is_featured', true)
                ->orWhere('rating', '>=', 4.5)
                ->orderBy('rating', 'desc')
                ->limit(10)
                ->get();
        });

        return $this->success(
            BusinessResource::collection($featured),
            'Featured businesses retrieved successfully'
        );
    }

    /**
     * Get business working hours
     */
    public function workingHours(Business $business)
    {
        if ($business->status !== 'approved') {
            return $this->error('Business not found', 404);
        }

        $today = now()->format('l');
        $currentTime = now()->format('H:i');
        
        $workingHours = collect($business->working_hours)->map(function ($hours, $day) use ($today, $currentTime, $business) {
            $isToday = strcasecmp($day, $today) === 0;
            $isOpen = false;
            
            if ($isToday && isset($hours['open']) && isset($hours['close'])) {
                $isOpen = $currentTime >= $hours['open'] && $currentTime <= $hours['close'];
            }

            return [
                'day' => ucfirst($day),
                'is_today' => $isToday,
                'is_open_now' => $isToday && $isOpen,
                'hours' => $hours
            ];
        });

        return $this->success([
            'business_id' => $business->id,
            'is_open_now' => $business->isOpen(),
            'current_time' => $currentTime,
            'timezone' => config('app.timezone'),
            'working_hours' => $workingHours
        ], 'Working hours retrieved successfully');
    }
}