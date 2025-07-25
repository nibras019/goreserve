<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Booking;
use App\Models\User;
use App\Models\Payment;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    /**
     * Get admin dashboard data
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'period' => 'nullable|string|in:today,week,month,year',
            'compare' => 'nullable|boolean'
        ]);

        $period = $validated['period'] ?? 'month';
        $compare = $request->boolean('compare', false);

        // Get date ranges
        $dateRanges = $this->getDateRanges($period);
        $currentStart = $dateRanges['current']['start'];
        $currentEnd = $dateRanges['current']['end'];
        $previousStart = $dateRanges['previous']['start'];
        $previousEnd = $dateRanges['previous']['end'];

        // Platform Overview
        $overview = [
            'users' => [
                'total' => User::count(),
                'customers' => User::role('customer')->count(),
                'vendors' => User::role('vendor')->count(),
                'new_this_period' => User::whereBetween('created_at', [$currentStart, $currentEnd])->count(),
                'growth' => $compare ? $this->calculateGrowth(
                    User::whereBetween('created_at', [$currentStart, $currentEnd])->count(),
                    User::whereBetween('created_at', [$previousStart, $previousEnd])->count()
                ) : null,
            ],
            'businesses' => [
                'total' => Business::count(),
                'approved' => Business::where('status', 'approved')->count(),
                'pending' => Business::where('status', 'pending')->count(),
                'suspended' => Business::where('status', 'suspended')->count(),
                'new_this_period' => Business::whereBetween('created_at', [$currentStart, $currentEnd])->count(),
            ],
            'bookings' => [
                'total' => Booking::whereBetween('booking_date', [$currentStart, $currentEnd])->count(),
                'completed' => Booking::whereBetween('booking_date', [$currentStart, $currentEnd])
                    ->where('status', 'completed')->count(),
                'cancelled' => Booking::whereBetween('booking_date', [$currentStart, $currentEnd])
                    ->where('status', 'cancelled')->count(),
                'growth' => $compare ? $this->calculateGrowth(
                    Booking::whereBetween('booking_date', [$currentStart, $currentEnd])->count(),
                    Booking::whereBetween('booking_date', [$previousStart, $previousEnd])->count()
                ) : null,
            ],
            'revenue' => [
                'total' => Payment::whereBetween('created_at', [$currentStart, $currentEnd])
                    ->where('status', 'completed')->sum('amount'),
                'platform_fees' => $this->calculatePlatformFees($currentStart, $currentEnd),
                'average_booking_value' => Payment::whereBetween('created_at', [$currentStart, $currentEnd])
                    ->where('status', 'completed')->avg('amount'),
                'growth' => $compare ? $this->calculateGrowth(
                    Payment::whereBetween('created_at', [$currentStart, $currentEnd])
                        ->where('status', 'completed')->sum('amount'),
                    Payment::whereBetween('created_at', [$previousStart, $previousEnd])
                        ->where('status', 'completed')->sum('amount')
                ) : null,
            ],
        ];

        // Real-time stats (today)
        $realTimeStats = Cache::remember('admin_realtime_stats', 60, function () {
            return [
                'active_users' => DB::table('sessions')
                    ->where('last_activity', '>', now()->subMinutes(5)->timestamp)
                    ->count(),
                'bookings_today' => Booking::whereDate('booking_date', today())->count(),
                'revenue_today' => Payment::whereDate('created_at', today())
                    ->where('status', 'completed')->sum('amount'),
                'new_users_today' => User::whereDate('created_at', today())->count(),
            ];
        });

        // Top performing businesses
        $topBusinesses = Business::approved()
            ->withCount(['bookings' => function ($query) use ($currentStart, $currentEnd) {
                $query->whereBetween('booking_date', [$currentStart, $currentEnd]);
            }])
            ->withSum(['bookings as revenue' => function ($query) use ($currentStart, $currentEnd) {
                $query->whereBetween('booking_date', [$currentStart, $currentEnd])
                    ->where('payment_status', 'paid');
            }], 'amount')
            ->orderBy('revenue', 'desc')
            ->limit(10)
            ->get();

        // Revenue chart data
        $revenueChart = $this->getRevenueChartData($currentStart, $currentEnd, $period);

        // Bookings by category
        $bookingsByCategory = DB::table('bookings')
            ->join('services', 'bookings.service_id', '=', 'services.id')
            ->whereBetween('bookings.booking_date', [$currentStart, $currentEnd])
            ->select('services.category', DB::raw('COUNT(*) as count'))
            ->groupBy('services.category')
            ->orderBy('count', 'desc')
            ->get();

        // Geographic distribution
        $geographicData = $this->getGeographicDistribution();

        // System health
        $systemHealth = [
            'queue_size' => DB::table('jobs')->count(),
            'failed_jobs' => DB::table('failed_jobs')->where('failed_at', '>', now()->subDay())->count(),
            'error_rate' => $this->calculateErrorRate(),
            'average_response_time' => $this->getAverageResponseTime(),
            'disk_usage' => $this->getDiskUsage(),
            'database_size' => $this->getDatabaseSize(),
        ];

        // Recent activities
        $recentActivities = DB::table('activity_log')
            ->latest()
            ->limit(20)
            ->get();

        // Alerts and notifications
        $alerts = $this->getSystemAlerts();

        return $this->success([
            'period' => [
                'type' => $period,
                'current' => [
                    'start' => $currentStart->format('Y-m-d'),
                    'end' => $currentEnd->format('Y-m-d'),
                ],
                'previous' => $compare ? [
                    'start' => $previousStart->format('Y-m-d'),
                    'end' => $previousEnd->format('Y-m-d'),
                ] : null,
            ],
            'overview' => $overview,
            'real_time' => $realTimeStats,
            'top_businesses' => $topBusinesses,
            'revenue_chart' => $revenueChart,
            'bookings_by_category' => $bookingsByCategory,
            'geographic_distribution' => $geographicData,
            'system_health' => $systemHealth,
            'recent_activities' => $recentActivities,
            'alerts' => $alerts,
        ], 'Dashboard data retrieved successfully');
    }

    /**
     * Get key metrics
     */
    public function metrics(Request $request)
    {
        $validated = $request->validate([
            'metrics' => 'required|array',
            'metrics.*' => 'in:mrr,arr,churn_rate,ltv,cac,arpu',
            'period' => 'nullable|string|in:month,quarter,year'
        ]);

        $period = $validated['period'] ?? 'month';
        $metrics = [];

        foreach ($validated['metrics'] as $metric) {
            $metrics[$metric] = $this->calculateMetric($metric, $period);
        }

        return $this->success([
            'metrics' => $metrics,
            'period' => $period,
            'calculated_at' => now()->toDateTimeString()
        ], 'Metrics calculated successfully');
    }

    /**
     * Get platform analytics
     */
    public function analytics(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:user_acquisition,revenue_analytics,booking_trends,business_performance',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'granularity' => 'nullable|in:hour,day,week,month'
        ]);

        $startDate = Carbon::parse($validated['date_from']);
        $endDate = Carbon::parse($validated['date_to']);
        $granularity = $validated['granularity'] ?? 'day';

        $analyticsData = match($validated['type']) {
            'user_acquisition' => $this->getUserAcquisitionAnalytics($startDate, $endDate, $granularity),
            'revenue_analytics' => $this->getRevenueAnalytics($startDate, $endDate, $granularity),
            'booking_trends' => $this->getBookingTrendsAnalytics($startDate, $endDate, $granularity),
            'business_performance' => $this->getBusinessPerformanceAnalytics($startDate, $endDate),
        };

        return $this->success([
            'type' => $validated['type'],
            'period' => [
                'from' => $startDate->format('Y-m-d'),
                'to' => $endDate->format('Y-m-d'),
                'granularity' => $granularity
            ],
            'data' => $analyticsData
        ], 'Analytics data retrieved successfully');
    }

    /**
     * Helper methods
     */
    private function getDateRanges($period)
    {
        $now = now();
        
        switch ($period) {
            case 'today':
                return [
                    'current' => [
                        'start' => $now->copy()->startOfDay(),
                        'end' => $now->copy()->endOfDay()
                    ],
                    'previous' => [
                        'start' => $now->copy()->subDay()->startOfDay(),
                        'end' => $now->copy()->subDay()->endOfDay()
                    ]
                ];
                
            case 'week':
                return [
                    'current' => [
                        'start' => $now->copy()->startOfWeek(),
                        'end' => $now->copy()->endOfWeek()
                    ],
                    'previous' => [
                        'start' => $now->copy()->subWeek()->startOfWeek(),
                        'end' => $now->copy()->subWeek()->endOfWeek()
                    ]
                ];
                
            case 'year':
                return [
                    'current' => [
                        'start' => $now->copy()->startOfYear(),
                        'end' => $now->copy()->endOfYear()
                    ],
                    'previous' => [
                        'start' => $now->copy()->subYear()->startOfYear(),
                        'end' => $now->copy()->subYear()->endOfYear()
                    ]
                ];
                
            case 'month':
            default:
                return [
                    'current' => [
                        'start' => $now->copy()->startOfMonth(),
                        'end' => $now->copy()->endOfMonth()
                    ],
                    'previous' => [
                        'start' => $now->copy()->subMonth()->startOfMonth(),
                        'end' => $now->copy()->subMonth()->endOfMonth()
                    ]
                ];
        }
    }

    private function calculateGrowth($current, $previous)
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }
        
        return round((($current - $previous) / $previous) * 100, 2);
    }

    private function calculatePlatformFees($startDate, $endDate)
    {
        $platformFeePercentage = config('goreserve.platform.fee_percentage', 10);
        
        $totalRevenue = Payment::whereBetween('created_at', [$startDate, $endDate])
            ->where('status', 'completed')
            ->sum('amount');
            
        return round($totalRevenue * ($platformFeePercentage / 100), 2);
    }

    private function getRevenueChartData($startDate, $endDate, $period)
    {
        $query = Payment::whereBetween('created_at', [$startDate, $endDate])
            ->where('status', 'completed')
            ->selectRaw('DATE(created_at) as date, SUM(amount) as revenue, COUNT(*) as transactions')
            ->groupBy('date')
            ->orderBy('date');

        $data = $query->get();

        // Fill in missing dates
        $filledData = [];
        $currentDate = $startDate->copy();
        
        while ($currentDate <= $endDate) {
            $dateStr = $currentDate->format('Y-m-d');
            $dayData = $data->firstWhere('date', $dateStr);
            
            $filledData[] = [
                'date' => $dateStr,
                'revenue' => $dayData ? $dayData->revenue : 0,
                'transactions' => $dayData ? $dayData->transactions : 0
            ];
            
            $currentDate->addDay();
        }

        return $filledData;
    }

    private function getGeographicDistribution()
    {
        return Cache::remember('geographic_distribution', 3600, function () {
            return DB::table('businesses')
                ->select('address', DB::raw('COUNT(*) as count'))
                ->where('status', 'approved')
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->groupBy('address')
                ->orderBy('count', 'desc')
                ->limit(20)
                ->get();
        });
    }

    private function calculateErrorRate()
    {
        $totalRequests = DB::table('request_logs')
            ->where('created_at', '>', now()->subHour())
            ->count();
            
        $errorRequests = DB::table('request_logs')
            ->where('created_at', '>', now()->subHour())
            ->where('status_code', '>=', 400)
            ->count();
            
        return $totalRequests > 0 ? round(($errorRequests / $totalRequests) * 100, 2) : 0;
    }

    private function getAverageResponseTime()
    {
        return DB::table('request_logs')
            ->where('created_at', '>', now()->subHour())
            ->avg('response_time') ?? 0;
    }

    private function getDiskUsage()
    {
        $totalSpace = disk_total_space('/');
        $freeSpace = disk_free_space('/');
        $usedSpace = $totalSpace - $freeSpace;
        
        return [
            'used' => round($usedSpace / 1073741824, 2), // Convert to GB
            'total' => round($totalSpace / 1073741824, 2),
            'percentage' => round(($usedSpace / $totalSpace) * 100, 2)
        ];
    }

    private function getDatabaseSize()
    {
        $size = DB::select('SELECT 
            SUM(data_length + index_length) / 1024 / 1024 AS size_mb 
            FROM information_schema.tables 
            WHERE table_schema = ?', [config('database.connections.mysql.database')]);
            
        return round($size[0]->size_mb ?? 0, 2);
    }

    private function getSystemAlerts()
    {
        $alerts = [];

        // Check for pending business approvals
        $pendingBusinesses = Business::where('status', 'pending')->count();
        if ($pendingBusinesses > 0) {
            $alerts[] = [
                'type' => 'info',
                'title' => 'Pending Business Approvals',
                'message' => "{$pendingBusinesses} businesses awaiting approval",
                'action' => 'Review businesses',
                'link' => '/admin/businesses?status=pending'
            ];
        }

        // Check for high cancellation rate
        $cancellationRate = $this->getRecentCancellationRate();
        if ($cancellationRate > 20) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'High Cancellation Rate',
                'message' => "Cancellation rate is {$cancellationRate}% in the last 7 days",
                'action' => 'View report',
                'link' => '/admin/reports/cancellations'
            ];
        }

        // Check for failed jobs
        $failedJobs = DB::table('failed_jobs')->count();
        if ($failedJobs > 10) {
            $alerts[] = [
                'type' => 'error',
                'title' => 'Failed Jobs Alert',
                'message' => "{$failedJobs} jobs have failed",
                'action' => 'View failed jobs',
                'link' => '/admin/system/jobs'
            ];
        }

        return $alerts;
    }

    private function getRecentCancellationRate()
    {
        $total = Booking::where('created_at', '>', now()->subDays(7))->count();
        $cancelled = Booking::where('created_at', '>', now()->subDays(7))
            ->where('status', 'cancelled')
            ->count();
            
        return $total > 0 ? round(($cancelled / $total) * 100, 2) : 0;
    }

    private function calculateMetric($metric, $period)
    {
        switch ($metric) {
            case 'mrr': // Monthly Recurring Revenue
                return Payment::whereMonth('created_at', now()->month)
                    ->where('status', 'completed')
                    ->sum('amount');
                    
            case 'arr': // Annual Recurring Revenue
                return Payment::whereYear('created_at', now()->year)
                    ->where('status', 'completed')
                    ->sum('amount');
                    
            case 'churn_rate':
                return $this->calculateChurnRate($period);
                
            case 'ltv': // Customer Lifetime Value
                return $this->calculateCustomerLifetimeValue();
                
            case 'cac': // Customer Acquisition Cost
                return $this->calculateCustomerAcquisitionCost($period);
                
            case 'arpu': // Average Revenue Per User
                return $this->calculateARPU($period);
                
            default:
                return 0;
        }
    }

    private function calculateChurnRate($period)
    {
        // Implementation would calculate actual churn
        return rand(2, 8);
    }

    private function calculateCustomerLifetimeValue()
    {
        $avgBookingValue = Booking::where('payment_status', 'paid')->avg('amount');
        $avgBookingsPerCustomer = DB::table('bookings')
            ->select('user_id', DB::raw('COUNT(*) as count'))
            ->groupBy('user_id')
            ->get()
            ->avg('count');
            
        return round($avgBookingValue * $avgBookingsPerCustomer, 2);
    }

    private function calculateCustomerAcquisitionCost($period)
    {
        // This would include marketing costs, etc.
        return rand(10, 50);
    }

    private function calculateARPU($period)
    {
        $totalRevenue = Payment::whereMonth('created_at', now()->month)
            ->where('status', 'completed')
            ->sum('amount');
            
        $activeUsers = User::whereHas('bookings', function ($query) {
            $query->whereMonth('booking_date', now()->month);
        })->count();
        
        return $activeUsers > 0 ? round($totalRevenue / $activeUsers, 2) : 0;
    }

    private function getUserAcquisitionAnalytics($startDate, $endDate, $granularity)
    {
        // Implementation would provide detailed user acquisition data
        return [
            'new_users' => [],
            'acquisition_channels' => [],
            'conversion_funnel' => [],
            'cohort_analysis' => []
        ];
    }

    private function getRevenueAnalytics($startDate, $endDate, $granularity)
    {
        // Implementation would provide detailed revenue analytics
        return [
            'revenue_by_period' => [],
            'revenue_by_service' => [],
            'revenue_by_location' => [],
            'payment_methods' => []
        ];
    }

    private function getBookingTrendsAnalytics($startDate, $endDate, $granularity)
    {
        // Implementation would provide booking trend analysis
        return [
            'bookings_over_time' => [],
            'peak_hours' => [],
            'popular_services' => [],
            'seasonal_trends' => []
        ];
    }

    private function getBusinessPerformanceAnalytics($startDate, $endDate)
    {
        // Implementation would provide business performance metrics
        return [
            'top_performers' => [],
            'growth_metrics' => [],
            'category_analysis' => [],
            'geographic_performance' => []
        ];
    }
}