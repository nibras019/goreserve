<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\User;
use App\Services\ReportService;
use App\Mail\MonthlyReportMail;
use App\Jobs\GenerateBusinessReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;

class GenerateMonthlyReports extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reports:generate-monthly 
                            {--month= : Month to generate report for (YYYY-MM format)}
                            {--business= : Generate report for specific business ID}
                            {--email= : Email address to send report to}
                            {--type=summary : Report type (summary|detailed|financial)}
                            {--queue : Queue report generation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate monthly reports for businesses and admins';

    /**
     * The report service instance.
     *
     * @var ReportService
     */
    protected $reportService;

    /**
     * Create a new command instance.
     */
    public function __construct(ReportService $reportService)
    {
        parent::__construct();
        $this->reportService = $reportService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("📊 Starting monthly report generation...");

        // Determine the month to generate reports for
        $monthOption = $this->option('month');
        if ($monthOption) {
            $reportDate = Carbon::createFromFormat('Y-m', $monthOption)->startOfMonth();
        } else {
            // Default to previous month
            $reportDate = now()->subMonth()->startOfMonth();
        }

        $this->info("Generating reports for: {$reportDate->format('F Y')}");

        $startDate = $reportDate->copy()->startOfMonth();
        $endDate = $reportDate->copy()->endOfMonth();

        // Check if we're generating for a specific business
        if ($businessId = $this->option('business')) {
            $this->generateBusinessReport($businessId, $startDate, $endDate);
        } else {
            // Generate for all active businesses and admin
            $this->generateAllReports($startDate, $endDate);
        }

        $this->info("✅ Report generation completed!");
        return Command::SUCCESS;
    }

    /**
     * Generate reports for all businesses and admin
     */
    private function generateAllReports(Carbon $startDate, Carbon $endDate)
    {
        $useQueue = $this->option('queue');
        
        // Generate admin report
        $this->info("📈 Generating admin platform report...");
        $this->generateAdminReport($startDate, $endDate);

        // Get all active businesses
        $businesses = Business::where('status', 'approved')
            ->whereHas('bookings', function ($query) use ($startDate, $endDate) {
                $query->whereBetween('booking_date', [$startDate, $endDate]);
            })
            ->get();

        $this->info("Found {$businesses->count()} businesses with activity in {$startDate->format('F Y')}");

        $this->output->progressStart($businesses->count());

        foreach ($businesses as $business) {
            if ($useQueue) {
                GenerateBusinessReport::dispatch($business, $startDate, $endDate)
                    ->onQueue('reports');
                $this->line("  📋 Queued report generation for: {$business->name}");
            } else {
                $this->generateBusinessReport($business->id, $startDate, $endDate);
            }
            
            $this->output->progressAdvance();
        }

        $this->output->progressFinish();
    }

    /**
     * Generate report for a specific business
     */
    private function generateBusinessReport($businessId, Carbon $startDate, Carbon $endDate)
    {
        try {
            $business = Business::with('owner')->findOrFail($businessId);
            $reportType = $this->option('type');
            
            $this->info("  📊 Generating {$reportType} report for: {$business->name}");

            // Generate report data
            $reportData = $this->reportService->generateBusinessReport($business, $startDate, $endDate);
            
            // Add report metadata
            $reportData['metadata'] = [
                'business_name' => $business->name,
                'business_id' => $business->id,
                'report_type' => $reportType,
                'generated_at' => now()->toDateTimeString(),
                'generated_by' => 'system',
                'period' => [
                    'month' => $startDate->format('F Y'),
                    'start' => $startDate->toDateString(),
                    'end' => $endDate->toDateString()
                ]
            ];

            // Generate PDF based on report type
            $pdf = $this->generatePDF($business, $reportData, $reportType);
            
            // Save PDF to storage
            $filename = $this->saveReport($business, $pdf, $startDate);
            
            // Send email if specified or to business owner
            $emailTo = $this->option('email') ?: $business->owner->email;
            if ($emailTo) {
                $this->sendReportEmail($emailTo, $business, $filename, $reportData);
            }

            // Log report generation
            $this->logReportGeneration($business, $filename, $reportData);
            
            $this->info("    ✅ Report generated: {$filename}");
            
        } catch (\Exception $e) {
            $this->error("    ❌ Failed to generate report for business {$businessId}: {$e->getMessage()}");
            
            \Log::error('Monthly report generation failed', [
                'business_id' => $businessId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Generate admin platform report
     */
    private function generateAdminReport(Carbon $startDate, Carbon $endDate)
    {
        try {
            $reportData = $this->reportService->generatePlatformReport($startDate, $endDate);
            
            $reportData['metadata'] = [
                'report_type' => 'platform_admin',
                'generated_at' => now()->toDateTimeString(),
                'period' => [
                    'month' => $startDate->format('F Y'),
                    'start' => $startDate->toDateString(),
                    'end' => $endDate->toDateString()
                ]
            ];

            // Generate admin PDF
            $pdf = PDF::loadView('reports.admin-monthly', $reportData);
            
            // Save report
            $filename = "reports/admin/platform-report-{$startDate->format('Y-m')}.pdf";
            Storage::disk('local')->put($filename, $pdf->output());
            
            // Send to all admin users
            $admins = User::role('admin')->get();
            foreach ($admins as $admin) {
                Mail::to($admin->email)->queue(new MonthlyReportMail(
                    'admin',
                    $filename,
                    $reportData
                ));
            }
            
            $this->info("  ✅ Admin report generated and sent to {$admins->count()} administrators");
            
        } catch (\Exception $e) {
            $this->error("  ❌ Failed to generate admin report: {$e->getMessage()}");
            \Log::error('Admin report generation failed', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Generate PDF based on report type
     */
    private function generatePDF(Business $business, array $reportData, string $type)
    {
        $viewMap = [
            'summary' => 'reports.business-summary',
            'detailed' => 'reports.business-detailed',
            'financial' => 'reports.business-financial'
        ];

        $view = $viewMap[$type] ?? 'reports.business-summary';
        
        return PDF::loadView($view, [
            'business' => $business,
            'report' => $reportData
        ])->setPaper('a4', 'portrait');
    }

    /**
     * Save report to storage
     */
    private function saveReport(Business $business, $pdf, Carbon $date)
    {
        $filename = sprintf(
            'reports/businesses/%s/%s-report-%s.pdf',
            $business->id,
            $business->slug,
            $date->format('Y-m')
        );

        Storage::disk('local')->put($filename, $pdf->output());
        
        return $filename;
    }

    /**
     * Send report via email
     */
    private function sendReportEmail($email, Business $business, $filename, array $reportData)
    {
        try {
            Mail::to($email)
                ->queue(new MonthlyReportMail(
                    'business',
                    $filename,
                    $reportData,
                    $business
                ));
                
            $this->info("    📧 Report emailed to: {$email}");
        } catch (\Exception $e) {
            $this->error("    ❌ Failed to email report: {$e->getMessage()}");
        }
    }

    /**
     * Log report generation in database
     */
    private function logReportGeneration(Business $business, $filename, array $reportData)
    {
        try {
            \DB::table('report_logs')->insert([
                'business_id' => $business->id,
                'report_type' => $this->option('type'),
                'period_start' => $reportData['period']['start'],
                'period_end' => $reportData['period']['end'],
                'filename' => $filename,
                'metadata' => json_encode($reportData['metadata']),
                'created_at' => now(),
                'updated_at' => now()
            ]);
        } catch (\Exception $e) {
            \Log::warning('Failed to log report generation', [
                'business_id' => $business->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}