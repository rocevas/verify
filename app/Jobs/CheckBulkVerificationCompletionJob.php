<?php

namespace App\Jobs;

use App\Models\BulkVerificationJob;
use App\Models\EmailVerification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class CheckBulkVerificationCompletionJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 100; // Check up to 100 times
    public int $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $bulkJobId
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $bulkJob = BulkVerificationJob::find($this->bulkJobId);
        
        if (!$bulkJob || $bulkJob->status === 'completed' || $bulkJob->status === 'failed') {
            return; // Already completed or failed
        }

        // Count how many emails have been verified for this bulk job
        $processedCount = EmailVerification::where('bulk_verification_job_id', $this->bulkJobId)
            ->count();

        // Count statuses
        $validCount = EmailVerification::where('bulk_verification_job_id', $this->bulkJobId)
            ->where('status', 'valid')
            ->count();
        
        $invalidCount = EmailVerification::where('bulk_verification_job_id', $this->bulkJobId)
            ->where('status', 'invalid')
            ->count();
        
        $riskyCount = EmailVerification::where('bulk_verification_job_id', $this->bulkJobId)
            ->whereIn('status', ['catch_all', 'risky', 'do_not_mail'])
            ->count();

        // Update progress
        $bulkJob->update([
            'processed_emails' => $processedCount,
            'valid_count' => $validCount,
            'invalid_count' => $invalidCount,
            'risky_count' => $riskyCount,
        ]);

        // Check if all emails are processed
        if ($processedCount >= $bulkJob->total_emails) {
            // All emails processed, generate CSV
            $this->generateResultCsv($bulkJob);
            
            $bulkJob->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        } else {
            // Not all emails processed yet, check again in 5 seconds
            self::dispatch($this->bulkJobId)
                ->delay(now()->addSeconds(5));
        }
    }

    private function generateResultCsv(BulkVerificationJob $bulkJob): void
    {
        // Get all verifications for this bulk job
        $verifications = EmailVerification::where('bulk_verification_job_id', $bulkJob->id)
            ->orderBy('created_at')
            ->get();

        // Generate result CSV
        $resultPath = 'bulk-results/result_'.$bulkJob->id.'_'.time().'.csv';
        $resultHandle = fopen(Storage::disk('local')->path($resultPath), 'w');
        
        // Write header
        fputcsv($resultHandle, ['Email', 'Status', 'Score', 'Syntax', 'MX', 'SMTP', 'Disposable', 'Role']);
        
        // Write results
        foreach ($verifications as $verification) {
            $checks = $verification->checks ?? [];
            fputcsv($resultHandle, [
                $verification->email,
                $verification->status,
                $verification->score ?? 0,
                ($checks['syntax'] ?? false) ? 'Yes' : 'No',
                ($checks['mx'] ?? false) ? 'Yes' : 'No',
                ($checks['smtp'] ?? false) ? 'Yes' : 'No',
                ($checks['disposable'] ?? false) ? 'Yes' : 'No',
                ($checks['role'] ?? false) ? 'Yes' : 'No',
            ]);
        }
        
        fclose($resultHandle);

        // Update bulk job with result file path
        $bulkJob->update([
            'result_file_path' => $resultPath,
        ]);
    }
}
