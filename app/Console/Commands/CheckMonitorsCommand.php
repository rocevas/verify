<?php

namespace App\Console\Commands;

use App\Jobs\CheckBlocklistMonitorJob;
use App\Jobs\CheckDmarcMonitorJob;
use App\Models\BlocklistMonitor;
use App\Models\DmarcMonitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckMonitorsCommand extends Command
{
    protected $signature = 'monitors:check';

    protected $description = 'Check all active monitors that are due for checking';

    public function handle(): int
    {
        $this->info('Checking monitors...');

        // Check blocklist monitors
        $blocklistMonitors = BlocklistMonitor::where('active', true)
            ->where(function ($query) {
                $query->whereNull('last_checked_at')
                    ->orWhereRaw('last_checked_at + INTERVAL check_interval_minutes MINUTE <= NOW()');
            })
            ->get();

        $blocklistCount = 0;
        foreach ($blocklistMonitors as $monitor) {
            try {
                CheckBlocklistMonitorJob::dispatch($monitor->id);
                $blocklistCount++;
                $this->line("Queued blocklist check for: {$monitor->target} ({$monitor->type})");
            } catch (\Exception $e) {
                $this->error("Failed to queue blocklist monitor {$monitor->id}: {$e->getMessage()}");
                Log::error("Failed to queue blocklist monitor check", [
                    'monitor_id' => $monitor->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Check DMARC monitors
        $dmarcMonitors = DmarcMonitor::where('active', true)
            ->where(function ($query) {
                $query->whereNull('last_checked_at')
                    ->orWhereRaw('last_checked_at + INTERVAL check_interval_minutes MINUTE <= NOW()');
            })
            ->get();

        $dmarcCount = 0;
        foreach ($dmarcMonitors as $monitor) {
            try {
                CheckDmarcMonitorJob::dispatch($monitor->id);
                $dmarcCount++;
                $this->line("Queued DMARC check for: {$monitor->name} ({$monitor->domain})");
            } catch (\Exception $e) {
                $this->error("Failed to queue DMARC monitor {$monitor->id}: {$e->getMessage()}");
                Log::error("Failed to queue DMARC monitor check", [
                    'monitor_id' => $monitor->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $total = $blocklistCount + $dmarcCount;
        if ($total === 0) {
            $this->info('No monitors need checking at this time.');
            return Command::SUCCESS;
        }

        $this->info("Successfully queued {$total} monitor check(s) ({$blocklistCount} blocklist, {$dmarcCount} DMARC).");
        return Command::SUCCESS;
    }
}
