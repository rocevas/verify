<?php

namespace App\Jobs;

use App\Models\DmarcMonitor;
use App\Services\DmarcReportParserService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessDmarcReportJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $timeout = 300;

    public function __construct(
        public string $xmlContent,
        public ?string $reportEmail = null
    ) {
    }

    public function handle(DmarcReportParserService $parser): void
    {
        try {
            // Parse the DMARC report XML
            $reportData = $parser->parse($this->xmlContent);

            if (!$reportData) {
                Log::warning('Failed to parse DMARC report XML');
                return;
            }

            // Find the monitor by report email or domain
            $monitor = null;
            
            // First try to match by report email (most reliable)
            if ($this->reportEmail) {
                // Try exact match first
                $monitor = DmarcMonitor::where('report_email', $this->reportEmail)
                    ->where('active', true)
                    ->first();
                
                // If not found, try to match by extracting domain from email
                if (!$monitor) {
                    // Extract domain from report email (e.g., dmarc-abc123@example.com -> example.com)
                    $emailDomain = substr(strrchr($this->reportEmail, '@'), 1);
                    if ($emailDomain) {
                        // Try to find monitor where report_email contains this domain
                        $monitor = DmarcMonitor::where('report_email', 'like', '%@' . $emailDomain)
                            ->where('active', true)
                            ->first();
                    }
                }
            }

            // If not found by email, try to find by domain from report
            if (!$monitor && isset($reportData['domain'])) {
                $monitor = DmarcMonitor::where('domain', $reportData['domain'])
                    ->where('active', true)
                    ->first();
            }
            
            // Last resort: try to match by domain extracted from report email
            if (!$monitor && $this->reportEmail) {
                $emailDomain = substr(strrchr($this->reportEmail, '@'), 1);
                if ($emailDomain) {
                    $monitor = DmarcMonitor::where('domain', $emailDomain)
                        ->where('active', true)
                        ->first();
                }
            }

            if (!$monitor) {
                Log::warning('No DMARC monitor found for report', [
                    'report_email' => $this->reportEmail,
                    'domain' => $reportData['domain'] ?? null,
                ]);
                return;
            }

            // Store the report data
            $parser->storeReport($monitor, $reportData);

            Log::info('DMARC report processed successfully', [
                'monitor_id' => $monitor->id,
                'domain' => $monitor->domain,
                'report_id' => $reportData['report_id'] ?? null,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to process DMARC report', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw $e;
        }
    }
}

