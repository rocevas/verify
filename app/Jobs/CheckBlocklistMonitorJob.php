<?php

namespace App\Jobs;

use App\Models\BlocklistMonitor;
use App\Models\MonitorCheckResult;
use App\Notifications\BlocklistMonitorNotification;
use App\Services\BlocklistCheckService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckBlocklistMonitorJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $timeout = 300;

    public function __construct(
        public int $monitorId
    ) {
    }

    public function handle(BlocklistCheckService $blocklistService): void
    {
        $monitor = BlocklistMonitor::find($this->monitorId);

        if (!$monitor || !$monitor->active) {
            Log::info("BlocklistMonitor {$this->monitorId} not found or inactive, skipping check");
            return;
        }

        try {
            // Perform blocklist check
            if ($monitor->type === 'domain') {
                $result = $blocklistService->checkDomain($monitor->target);
            } else {
                $result = $blocklistService->checkIp($monitor->target);
            }

            // Save check result
            $checkResult = MonitorCheckResult::create([
                'monitor_type' => 'blocklist_monitor',
                'monitor_id' => $monitor->id,
                'has_issue' => $result['is_blocklisted'],
                'check_details' => $result,
                'checked_at' => now(),
            ]);

            // Update monitor status
            $wasBlocklisted = $monitor->is_blocklisted;
            $monitor->update([
                'last_checked_at' => now(),
                'is_blocklisted' => $result['is_blocklisted'],
                'last_check_details' => $result,
            ]);

            // Send notification if newly blocklisted
            if ($result['is_blocklisted'] && !$wasBlocklisted) {
                try {
                    $monitor->user->notify(new BlocklistMonitorNotification($monitor, $result));
                    $checkResult->update(['notification_sent' => true]);
                    
                    Log::info("Blocklist notification sent for monitor {$monitor->id}", [
                        'monitor' => $monitor->name,
                        'target' => $monitor->target,
                        'blocklists' => $result['blocklists'] ?? [],
                    ]);
                } catch (\Exception $e) {
                    Log::error("Failed to send blocklist notification for monitor {$monitor->id}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error("Failed to check blocklist monitor {$monitor->id}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            MonitorCheckResult::create([
                'monitor_type' => 'blocklist_monitor',
                'monitor_id' => $monitor->id,
                'has_issue' => false,
                'check_details' => ['error' => $e->getMessage()],
                'checked_at' => now(),
            ]);

            $monitor->update([
                'last_checked_at' => now(),
            ]);
        }
    }
}
