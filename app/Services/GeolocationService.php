<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class GeolocationService
{
    protected $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.google.maps_api_key');
    }

    /**
     * Get coordinates from address
     */
    public function geocodeAddress(string $address): array
    {
        $cacheKey = 'geocode_' . md5($address);
        
        return Cache::remember($cacheKey, 3600, function () use ($address) {
            try {
                $response = Http::get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'address' => $address,
                    'key' => $this->apiKey
                ]);

                $data = $response->json();

                if ($data['status'] === 'OK' && !empty($data['results'])) {
                    $location = $data['results'][0]['geometry']['location'];
                    return [
                        'latitude' => $location['lat'],
                        'longitude' => $location['lng'],
                        'formatted_address' => $data['results'][0]['formatted_address']
                    ];
                }

                return ['latitude' => null, 'longitude' => null, 'formatted_address' => $address];
            } catch (\Exception $e) {
                \Log::error('Geocoding failed', ['address' => $address, 'error' => $e->getMessage()]);
                return ['latitude' => null, 'longitude' => null, 'formatted_address' => $address];
            }
        });
    }

    /**
     * Calculate distance between two points
     */
    public function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // Earth's radius in kilometers

        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($lonDelta / 2) * sin($lonDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Get nearby places
     */
    public function getNearbyPlaces(float $latitude, float $longitude, string $type = 'point_of_interest', int $radius = 5000): array
    {
        $cacheKey = "nearby_{$latitude}_{$longitude}_{$type}_{$radius}";
        
        return Cache::remember($cacheKey, 1800, function () use ($latitude, $longitude, $type, $radius) {
            try {
                $response = Http::get('https://maps.googleapis.com/maps/api/place/nearbysearch/json', [
                    'location' => "{$latitude},{$longitude}",
                    'radius' => $radius,
                    'type' => $type,
                    'key' => $this->apiKey
                ]);

                $data = $response->json();

                if ($data['status'] === 'OK') {
                    return collect($data['results'])->map(function ($place) {
                        return [
                            'name' => $place['name'],
                            'rating' => $place['rating'] ?? null,
                            'vicinity' => $place['vicinity'] ?? null,
                            'types' => $place['types'] ?? [],
                            'place_id' => $place['place_id']
                        ];
                    })->toArray();
                }

                return [];
            } catch (\Exception $e) {
                \Log::error('Nearby places search failed', ['error' => $e->getMessage()]);
                return [];
            }
        });
    }
}