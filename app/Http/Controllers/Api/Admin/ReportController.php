<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    protected $reportService;

    public function __construct(ReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    /**
     * Get available reports
     */
    public function index()
    {
        $reports = [
            [
                'type' => 'platform_overview',
                'name' => 'Platform Overview Report',
                'description' => 'Comprehensive platform metrics and KPIs',
                'frequency' => ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'],
                'formats' => ['pdf', 'csv', 'json']
            ],
            [
                'type' => 'financial',
                'name' => 'Financial Report',
                'description' => 'Revenue, commissions, and financial metrics',
                'frequency' => ['monthly', 'quarterly', 'yearly'],
                'formats' => ['pdf', 'excel']
            ],
            [
                'type' => 'user_analytics',
                'name' => 'User Analytics Report',
                'description' => 'User acquisition, retention, and behavior analysis',
                'frequency' => ['weekly', 'monthly'],
                'formats' => ['pdf', 'csv']
            ],
            [
                'type' => 'business_performance',
                'name' => 'Business Performance Report',
                'description' => 'Performance metrics for all businesses',
                'frequency' => ['monthly', 'quarterly'],
                'formats' => ['pdf', 'excel']
            ],
            [
                'type' => 'compliance',
                'name' => 'Compliance Report',
                'description' => 'Policy violations, suspensions, and compliance metrics',
                'frequency' => ['monthly'],
                'formats' => ['pdf']
            ]
        ];

        // Get scheduled reports
        $scheduledReports = DB::table('scheduled_reports')
            ->where('is_active', true)
            ->get();

        // Get recent generated reports
        $recentReports = DB::table('report_logs')
            ->whereNull('business_id')
            ->latest()
            ->limit(20)
            ->get();

        return $this->success([
            'available_reports' => $reports,
            'scheduled_reports' => $scheduledReports,
            'recent_reports' => $recentReports
        ], 'Reports retrieved successfully');
    }

    /**
     * Generate platform report
     */
    public function generate(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:platform_overview,financial,user_analytics,business_performance,compliance',
            'period' => 'required|in:daily,weekly,monthly,quarterly,yearly,custom',
            'date_from' => 'required_if:period,custom|date',
            'date_to' => 'required_if:period,custom|date|after_or_equal:date_from',
            'format' => 'required|in:pdf,csv,excel,json',
            'email' => 'nullable|boolean',
            'recipients' => 'nullable|array',
            'recipients.*' => 'email'
        ]);

        // Determine date range
        $dateRange = $this->getDateRange($validated['period'], 
            $validated['date_from'] ?? null, 
            $validated['date_to'] ?? null
        );

        try {
            // Generate report data
            $reportData = match($validated['type']) {
                'platform_overview' => $this->generatePlatformOverview($dateRange['start'], $dateRange['end']),
                'financial' => $this->generateFinancialReport($dateRange['start'], $dateRange['end']),
                'user_analytics' => $this->generateUserAnalytics($dateRange['start'], $dateRange['end']),
                'business_performance' => $this->generateBusinessPerformance($dateRange['start'], $dateRange['end']),
                'compliance' => $this->generateComplianceReport($dateRange['start'], $dateRange['end']),
            };

            // Add metadata
            $reportData['metadata'] = [
                'report_type' => $validated['type'],
                'period' => $validated['period'],
                'date_range' => [
                    'from' => $dateRange['start']->format('Y-m-d'),
                    'to' => $dateRange['end']->format('Y-m-d')
                ],
                'generated_at' => now()->toDateTimeString(),
                'generated_by' => $request->user()->name
            ];

            // Generate output
            $output = $this->generateOutput($reportData, $validated['type'], $validated['format']);

            // Save report if PDF/Excel
            if (in_array($validated['format'], ['pdf', 'excel'])) {
                $filename = $this->saveReport($output, $validated['type'], $validated['format']);
                
                // Email if requested
                if ($request->boolean('email')) {
                    $recipients = $validated['recipients'] ?? [$request->user()->email];
                    
                    foreach ($recipients as $recipient) {
                        \Mail::to($recipient)->queue(new \App\Mail\AdminReportGenerated(
                            $validated['type'],
                            $filename,
                            $reportData
                        ));
                    }
                }

                return $this->success([
                    'filename' => $filename,
                    'download_url' => route('admin.reports.download', ['filename' => basename($filename)])
                ], 'Report generated successfully');
            }

            // Return data for JSON/CSV
            return response()->json([
                'success' => true,
                'data' => $output
            ]);

        } catch (\Exception $e) {
            return $this->error(
                'Failed to generate report',
                500,
                config('app.debug') ? ['error' => $e->getMessage()] : null
            );
        }
    }

    /**
     * Schedule report
     */
    public function schedule(Request $request)
    {
        $validated = $request->validate([
            'report_type' => 'required|string',
            'frequency' => 'required|in:daily,weekly,monthly,quarterly,yearly',
            'day_of_week' => 'required_if:frequency,weekly|nullable|integer|min:0|max:6',
            'day_of_month' => 'required_if:frequency,monthly|nullable|integer|min:1|max:28',
            'time' => 'required|date_format:H:i',
            'format' => 'required|in:pdf,excel',
            'recipients' => 'required|array|min:1',
            'recipients.*' => 'email',
            'is_active' => 'nullable|boolean'
        ]);

        DB::table('scheduled_reports')->insert([
            'report_type' => $validated['report_type'],
            'frequency' => $validated['frequency'],
            'schedule_config' => json_encode([
                'day_of_week' => $validated['day_of_week'] ?? null,
                'day_of_month' => $validated['day_of_month'] ?? null,
                'time' => $validated['time']
            ]),
            'format' => $validated['format'],
            'recipients' => json_encode($validated['recipients']),
            'is_active' => $validated['is_active'] ?? true,
            'created_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        return $this->success(null, 'Report scheduled successfully');
    }

    /**
     * Download report
     */
    public function download($filename)
    {
        $path = "reports/admin/{$filename}";

        if (!Storage::disk('local')->exists($path)) {
            return $this->error('Report not found', 404);
        }

        return Storage::disk('local')->download($path);
    }

    /**
     * Get analytics data
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
    private function getDateRange($period, $customStart = null, $customEnd = null)
    {
        if ($period === 'custom') {
            return [
                'start' => Carbon::parse($customStart),
                'end' => Carbon::parse($customEnd)
            ];
        }

        $now = Carbon::now();
        
        return match($period) {
            'daily' => [
                'start' => $now->copy()->startOfDay(),
                'end' => $now->copy()->endOfDay()
            ],
            'weekly' => [
                'start' => $now->copy()->startOfWeek(),
                'end' => $now->copy()->endOfWeek()
            ],
            'monthly' => [
                'start' => $now->copy()->startOfMonth(),
                'end' => $now->copy()->endOfMonth()
            ],
            'quarterly' => [
                'start' => $now->copy()->startOfQuarter(),
                'end' => $now->copy()->endOfQuarter()
            ],
            'yearly' => [
                'start' => $now->copy()->startOfYear(),
                'end' => $now->copy()->endOfYear()
            ],
            default => [
                'start' => $now->copy()->startOfMonth(),
                'end' => $now->copy()->endOfMonth()
            ]
        };
    }

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