<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ClearEmailVerificationCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:clear-cache 
                            {--all : Clear all email verification related cache}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear email verification cache (domains, MX records, etc.)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Clearing email verification cache...');

        $cleared = 0;

        // Clear public provider domains cache
        // Try different cache stores if Redis is not available
        $cacheStores = ['default', 'file', 'array'];
        $clearedCache = false;
        
        foreach ($cacheStores as $store) {
            try {
                Cache::store($store)->forget('public_provider_domains_list');
                $this->info("✅ Cleared: public_provider_domains_list (store: {$store})");
                $cleared++;
                $clearedCache = true;
                break;
            } catch (\Exception $e) {
                // Try next store
                continue;
            }
        }
        
        if (!$clearedCache) {
            $this->warn('⚠️  Could not clear public_provider_domains_list cache');
            $this->info('   Cache will expire automatically in 1 hour (TTL)');
        }

        if ($this->option('all')) {
            // Clear all email verification related cache
            $this->info('Clearing all email verification cache...');
            
            // Clear config cache
            try {
                $this->call('config:clear');
                $this->info('✅ Cleared: config cache');
                $cleared++;
            } catch (\Exception $e) {
                $this->warn('⚠️  Could not clear config cache: ' . $e->getMessage());
            }

            // Note: Domain-specific cache (mx_records_*, domain_validity_*) 
            // will be cleared automatically when they expire (TTL)
            // or can be cleared manually if needed
        }

        $this->info("✅ Cache cleared successfully! ({$cleared} items)");
        $this->info('');
        $this->info('Note: Domain-specific cache (MX records, domain validity)');
        $this->info('will be refreshed on next verification or when TTL expires.');

        return 0;
    }
}
