<?php

namespace App\Console\Commands;

use App\Services\EmailVerificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class VerifyEmailCommand extends Command
{
    protected $signature = 'email:verify {email} {--json : Output result as JSON (same format as API)}';

    protected $description = 'Verify an email address and return result in the same format as API';

    public function handle(EmailVerificationService $verificationService): int
    {
        $email = $this->argument('email');
        $outputJson = $this->option('json');
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if ($outputJson) {
                $this->line(json_encode([
                    'error' => "Invalid email format: {$email}",
                ], JSON_PRETTY_PRINT));
            } else {
                $this->error("Invalid email format: {$email}");
            }
            return 1;
        }
        
        try {
            // Try to get user, but if DB is not available, use null (service should handle it)
            $userId = null;
            $teamId = null;
            
            try {
                $user = \App\Models\User::first();
                if ($user) {
                    $userId = $user->id;
                    $team = $user->currentTeam;
                    if ($team) {
                        $teamId = $team->id;
                    }
                }
            } catch (\Exception $e) {
                if (!$outputJson) {
                    $this->warn("Database not available, testing without saving results");
                }
            }
            
            $result = $verificationService->verify(
                $email,
                $userId,
                $teamId,
                null, // tokenId
                null, // bulkJobId
                'cli' // source
            );
            
            if ($outputJson) {
                // Output as JSON (same format as API)
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                // Output as formatted text
                $this->info("=== Email Verification Result ===");
                $this->newLine();
                $this->line("Email: " . ($result['email'] ?? $email));
                $this->line("State: " . ($result['state'] ?? 'unknown'));
                $this->line("Result: " . ($result['result'] ?? 'unknown'));
                $this->line("Score: " . ($result['score'] ?? 0));
                if (isset($result['duration'])) {
                    $this->line("Duration: {$result['duration']}s");
                }
                $this->newLine();
                
                // Checks
                $this->info("Checks:");
                $this->line("  - Syntax: " . ($result['syntax'] ?? false ? '✓' : '✗'));
                $this->line("  - Domain Validity: " . ($result['domain_validity'] ?? false ? '✓' : '✗'));
                $this->line("  - MX Record: " . ($result['mx_record'] ?? false ? '✓' : '✗'));
                $this->line("  - SMTP: " . ($result['smtp'] ?? false ? '✓' : '✗'));
                $this->line("  - Disposable: " . ($result['disposable'] ?? false ? '✓' : '✗'));
                $this->line("  - Role: " . ($result['role'] ?? false ? '✓' : '✗'));
                $this->line("  - Catch-All: " . ($result['catch_all'] ?? false ? 'Yes' : 'No'));
                $this->newLine();
                
                if (isset($result['error']) && $result['error']) {
                    $this->warn("Error: " . $result['error']);
                    $this->newLine();
                }
                
                $this->info("=== End of Result ===");
                $this->newLine();
                $this->info("Tip: Use --json flag to get API-formatted JSON output");
            }
            
            return 0;
            
        } catch (\Exception $e) {
            if ($outputJson) {
                $this->line(json_encode([
                    'error' => $e->getMessage(),
                    'email' => $email,
                ], JSON_PRETTY_PRINT));
            } else {
                $this->error("Verification failed: " . $e->getMessage());
            }
            Log::error('Email verification command failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            return 1;
        }
    }
}

