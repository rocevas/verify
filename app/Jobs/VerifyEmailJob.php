<?php

namespace App\Jobs;

use App\Exceptions\SmtpRateLimitExceededException;
use App\Services\EmailVerificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class VerifyEmailJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 5; // More retries for rate limit cases
    public int $timeout = 60;
    public int $backoff = 60; // Initial backoff in seconds (1 minute)

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $email,
        public ?int $userId = null,
        public ?int $teamId = null,
        public ?int $tokenId = null,
        public ?int $bulkJobId = null,
        public ?string $source = null
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(EmailVerificationService $service): void
    {
        try {
            $service->verify($this->email, $this->userId, $this->teamId, $this->tokenId, $this->bulkJobId, $this->source);
        } catch (SmtpRateLimitExceededException $e) {
            // Rate limit exceeded - will retry with backoff
            Log::info('SMTP rate limit exceeded, job will retry', [
                'email' => $this->email,
                'domain' => $e->domain,
                'retry_after' => $e->retryAfter,
                'attempt' => $this->attempts(),
            ]);
            
            // Re-throw to trigger retry with backoff
            throw $e;
        }
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     * Exponential backoff: 60s, 120s, 240s, 480s, 960s
     */
    public function backoff(): array
    {
        return [
            60,   // 1 minute
            120,  // 2 minutes
            240,  // 4 minutes
            480,  // 8 minutes
            960,  // 16 minutes
        ];
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): \DateTime
    {
        // Retry for up to 1 hour
        return now()->addHour();
    }
}
