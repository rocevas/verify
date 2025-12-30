<?php

namespace App\Jobs;

use App\Models\DmarcMonitor;
use App\Models\MonitorCheckResult;
use App\Notifications\DmarcMonitorNotification;
use App\Services\DmarcCheckService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckDmarcMonitorJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $timeout = 300;

    public function __construct(
        public int $monitorId
    ) {
    }

    public function handle(DmarcCheckService $dmarcService): void
    {
        $monitor = DmarcMonitor::find($this->monitorId);

        if (!$monitor || !$monitor->active) {
            Log::info("DmarcMonitor {$this->monitorId} not found or inactive, skipping check");
            return;
        }

        try {
            // Perform DMARC check
            $result = $dmarcService->checkDomain($monitor->domain);

            // Save check result
            $checkResult = MonitorCheckResult::create([
                'monitor_type' => 'dmarc_monitor',
                'monitor_id' => $monitor->id,
                'has_issue' => $result['has_issue'],
                'check_details' => $result,
                'checked_at' => now(),
            ]);

            // Update monitor status
            $hadIssue = $monitor->has_issue;
            $monitor->update([
                'last_checked_at' => now(),
                'has_issue' => $result['has_issue'],
                'last_check_details' => $result,
                'dmarc_record' => $result['details']['parsed'] ?? null,
            ]);

            // Send notification if issue detected or resolved
            if ($result['has_issue'] && !$hadIssue) {
                try {
                    $monitor->user->notify(new DmarcMonitorNotification($monitor, $result));
                    $checkResult->update(['notification_sent' => true]);
                    
                    Log::info("DMARC issue notification sent for monitor {$monitor->id}", [
                        'monitor' => $monitor->name,
                        'domain' => $monitor->domain,
                        'issue_type' => $result['issue_type'] ?? 'unknown',
                    ]);
                } catch (\Exception $e) {
                    Log::error("Failed to send DMARC notification for monitor {$monitor->id}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error("Failed to check DMARC monitor {$monitor->id}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            MonitorCheckResult::create([
                'monitor_type' => 'dmarc_monitor',
                'monitor_id' => $monitor->id,
                'has_issue' => true,
                'check_details' => ['error' => $e->getMessage()],
                'checked_at' => now(),
            ]);

            $monitor->update([
                'last_checked_at' => now(),
            ]);
        }
    }
}
