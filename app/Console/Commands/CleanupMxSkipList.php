<?php

namespace App\Console\Commands;

use App\Models\MxSkipList;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupMxSkipList extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:cleanup-mx-skip-list 
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up expired auto-added MX skip list entries';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        
        $this->info('Cleaning up expired MX skip list entries...');
        
        // Count expired entries
        $expiredCount = MxSkipList::autoAdded()
            ->where('expires_at', '<=', now())
            ->count();
        
        if ($expiredCount === 0) {
            $this->info('No expired entries found.');
            return Command::SUCCESS;
        }
        
        $this->info("Found {$expiredCount} expired entries.");
        
        if ($dryRun) {
            $this->warn('DRY RUN: Would delete the following entries:');
            
            $expired = MxSkipList::autoAdded()
                ->where('expires_at', '<=', now())
                ->get(['mx_host', 'reason', 'expires_at', 'failure_count']);
            
            $this->table(
                ['MX Host', 'Reason', 'Expires At', 'Failure Count'],
                $expired->map(function ($entry) {
                    return [
                        $entry->mx_host,
                        $entry->reason ?? 'N/A',
                        $entry->expires_at?->format('Y-m-d H:i:s') ?? 'N/A',
                        $entry->failure_count,
                    ];
                })->toArray()
            );
            
            $this->warn('Run without --dry-run to actually delete these entries.');
            return Command::SUCCESS;
        }
        
        // Actually delete expired entries
        $deleted = MxSkipList::cleanupExpired();
        
        $this->info("Successfully deleted {$deleted} expired entries.");
        
        Log::info('MX skip list cleanup completed', [
            'deleted_count' => $deleted,
        ]);
        
        return Command::SUCCESS;
    }
}

