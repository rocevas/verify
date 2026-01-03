<?php

namespace App\Jobs;

use App\Models\BulkVerificationJob;
use App\Services\AiEmailVerificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAiBulkVerificationJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $timeout = 3600; // 1 hour

    /**
     * Create a new job instance.
     */
    public function __construct(
        public BulkVerificationJob $bulkJob,
        public array $emails
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(AiEmailVerificationService $aiService): void
    {
        // Refresh the model from database after serialization
        $this->bulkJob->refresh();
        
        $this->bulkJob->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);

        $validCount = 0;
        $invalidCount = 0;
        $riskyCount = 0;
        $processed = 0;

        try {
            foreach ($this->emails as $email) {
                try {
                    // Verify with AI (no streaming callback for background jobs)
                    $result = $aiService->verifyWithAi(
                        $email,
                        $this->bulkJob->user_id,
                        $this->bulkJob->team_id,
                        $this->bulkJob->api_key_id,
                        $this->bulkJob->id,
                        $this->bulkJob->source ?? 'ui',
                        null // No streaming callback for background jobs
                    );

                    $processed++;

                    // Update counts
                    if ($result['status'] === 'valid') {
                        $validCount++;
                    } elseif ($result['status'] === 'invalid') {
                        $invalidCount++;
                    } elseif (in_array($result['status'], ['catch_all', 'risky', 'do_not_mail'])) {
                        $riskyCount++;
                    }

                    // Update bulk job progress periodically (every 10 emails or last email)
                    if ($processed % 10 === 0 || $processed === count($this->emails)) {
                        $this->bulkJob->update([
                            'processed_emails' => $processed,
                            'valid_count' => $validCount,
                            'invalid_count' => $invalidCount,
                            'risky_count' => $riskyCount,
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Error processing email in AI bulk verification', [
                        'email' => $email,
                        'bulk_job_id' => $this->bulkJob->id,
                        'error' => $e->getMessage(),
                    ]);
                    // Continue with next email
                    $processed++;
                }
            }

            // Final update
            $this->bulkJob->update([
                'status' => 'completed',
                'processed_emails' => $processed,
                'valid_count' => $validCount,
                'invalid_count' => $invalidCount,
                'risky_count' => $riskyCount,
                'completed_at' => now(),
            ]);

        } catch (\Exception $e) {
            $this->bulkJob->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }
}

