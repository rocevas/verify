<?php

namespace App\Jobs;

use App\Models\BulkVerificationJob;
use App\Services\EmailVerificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ProcessBulkVerificationJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $timeout = 3600; // 1 hour

    /**
     * Create a new job instance.
     */
    public function __construct(
        public BulkVerificationJob $bulkJob
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(EmailVerificationService $service): void
    {
        $this->bulkJob->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);

        // Check if file_path exists (for CSV uploads)
        if (!$this->bulkJob->file_path) {
            // This is a UI batch verification, not a CSV upload
            // Jobs should already be dispatched, just wait for completion
            return;
        }

        $filePath = Storage::disk('local')->path($this->bulkJob->file_path);
        $results = [];
        $validCount = 0;
        $invalidCount = 0;
        $riskyCount = 0;
        $processed = 0;

        try {
            $handle = fopen($filePath, 'r');
            if (!$handle) {
                throw new \Exception('Cannot open file');
            }

            // Read all emails first
            $emails = [];
            while (($line = fgetcsv($handle)) !== false) {
                if (empty($line[0])) {
                    continue;
                }

                $email = trim($line[0]);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }

                $emails[] = $email;
            }

            fclose($handle);

            // Update total count
            $this->bulkJob->update([
                'total_emails' => count($emails),
            ]);

            // Dispatch each email as a separate job for parallel processing
            // Laravel Queue with multiple workers will process these in parallel
            foreach ($emails as $email) {
                VerifyEmailJob::dispatch(
                    $email,
                    $this->bulkJob->user_id,
                    $this->bulkJob->team_id,
                    $this->bulkJob->api_key_id,
                    $this->bulkJob->id // Pass bulk job ID
                )->onQueue('default');
            }

            // Dispatch a job to check completion and generate CSV
            // This job will run after a delay and check if all emails are processed
            CheckBulkVerificationCompletionJob::dispatch($this->bulkJob->id)
                ->delay(now()->addSeconds(10)); // Start checking after 10 seconds

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
