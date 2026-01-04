<?php

namespace App\Console\Commands;

use App\Services\EmailVerificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestEmailVerification extends Command
{
    protected $signature = 'email:test {email}';

    protected $description = 'Test email verification with VRFY/EXPN support';

    public function handle(EmailVerificationService $verificationService): int
    {
        $email = $this->argument('email');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email format: {$email}");
            return 1;
        }

        $this->info("Testing email verification for: {$email}");
        $this->newLine();

        $this->info("Configuration:");
        $this->line("  - SMTP Check: " . (config('email-verification.enable_smtp_check') ? 'Enabled' : 'Disabled'));
        $this->line("  - Catch-All Detection: " . (config('email-verification.enable_catch_all_detection') ? 'Enabled' : 'Disabled'));
        $this->line("  - VRFY Check: " . (config('email-verification.enable_vrfy_check') ? 'Enabled' : 'Disabled'));
        $this->line("  - Gravatar Check: " . (config('email-verification.enable_gravatar_check') ? 'Enabled' : 'Disabled'));
        $this->newLine();

        $this->info("Starting verification...");
        $startTime = microtime(true);

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
                $this->warn("Database not available, testing without saving results");
            }

            $result = $verificationService->verify(
                $email,
                $userId,
                $teamId,
                null, // tokenId
                null, // bulkJobId
                'cli' // source
            );

            $duration = round(microtime(true) - $startTime, 2);

            $this->newLine();
            $this->info("=== Verification Results ===");
            $this->newLine();

            // Basic info
            $this->line("Email: " . ($result['email'] ?? $email));
            $this->line("State: " . ($result['state'] ?? 'unknown'));
            $this->line("Reason: " . ($result['reason'] ?? $result['result'] ?? 'unknown'));
            $this->line("Result: " . ($result['result'] ?? 'unknown'));
            $this->line("Score: " . ($result['score'] ?? 0));
            $this->line("Duration: {$duration}s");
            $this->newLine();

            // Checks
            $this->info("Checks:");
            $checks = $result['checks'] ?? [];
            $this->line("  - Syntax: " . ($checks['syntax'] ?? false ? '✓' : '✗'));
            $this->line("  - Domain Validity: " . ($checks['domain_validity'] ?? false ? '✓' : '✗'));
            $this->line("  - MX Record: " . ($checks['mx_record'] ?? false ? '✓' : '✗'));
            if (isset($result['mx_record']) && $result['mx_record']) {
                $this->line("    MX: " . $result['mx_record']);
            }
            $this->line("  - SMTP: " . ($checks['smtp'] ?? false ? '✓' : '✗'));
            if (isset($result['smtp_provider']) && $result['smtp_provider']) {
                $this->line("    SMTP Provider: " . $result['smtp_provider']);
            }
            $this->line("  - Disposable: " . ($checks['disposable'] ?? false ? '✓' : '✗'));
            $this->line("  - Role: " . ($checks['role'] ?? false ? '✓' : '✗'));
            $this->line("  - Catch-All: " . ($result['catch_all'] ?? false ? 'Yes' : 'No'));
            $this->line("  - Secure Email Gateway: " . ($result['secure_email_gateway'] ?? false ? 'Yes' : 'No'));
            $this->line("  - Implicit MX: " . ($result['implicit_mx_record'] ?? false ? 'Yes' : 'No'));
            $this->newLine();

            // Email Attributes
            if (isset($result['numerical_characters']) || isset($result['alphabetical_characters']) || isset($result['unicode_symbols'])) {
                $this->info("Email Attributes:");
                $this->line("  - Numerical Characters: " . ($result['numerical_characters'] ?? 0));
                $this->line("  - Alphabetical Characters: " . ($result['alphabetical_characters'] ?? 0));
                $this->line("  - Unicode Symbols: " . ($result['unicode_symbols'] ?? 0));
                if (isset($result['alias_of']) && $result['alias_of']) {
                    $this->line("  - Tag (Alias): " . $result['alias_of']);
                }
                $this->newLine();
            }

            // VRFY/EXPN info
            if (isset($result['verification_method'])) {
                $this->info("Verification Method: " . $result['verification_method']);
                if (isset($result['smtp_confidence'])) {
                    $this->line("  Confidence: " . $result['smtp_confidence'] . "%");
                }
                $this->newLine();
            }

            // Gravatar info
            if (isset($result['gravatar'])) {
                $this->info("Gravatar: " . ($result['gravatar'] ? 'Found' : 'Not found'));
                if (isset($result['gravatar_url'])) {
                    $this->line("  URL: " . $result['gravatar_url']);
                }
                $this->newLine();
            }

            // DMARC info
            if (isset($result['dmarc'])) {
                $this->info("DMARC:");
                if (isset($result['dmarc']['policy'])) {
                    $this->line("  Policy: " . $result['dmarc']['policy']);
                } else {
                    $this->line("  Policy: Not found or check failed");
                }
                if (isset($result['dmarc_confidence_boost'])) {
                    $this->line("  Confidence Boost: +" . $result['dmarc_confidence_boost']);
                }
                if (isset($result['dmarc']['error'])) {
                    $this->line("  Error: " . $result['dmarc']['error']);
                }
                $this->newLine();
            }


            // AI info
            if (isset($result['ai_analysis']) && $result['ai_analysis']) {
                $this->info("AI Analysis:");
                $this->line("  - AI Confidence: " . ($result['ai_confidence'] ?? 0) . "%");
                if (isset($result['ai_insights'])) {
                    $this->line("  - Insights: " . $result['ai_insights']);
                }
                $this->newLine();
            }

            // Additional info
            if (isset($result['alias_of'])) {
                $this->info("Alias: " . $result['alias_of']);
                $this->newLine();
            }

            if (isset($result['did_you_mean'])) {
                $this->info("Did you mean: " . $result['did_you_mean']);
                $this->newLine();
            }

            // Error info
            if (isset($result['error']) && $result['error']) {
                $this->warn("Error: " . $result['error']);
                $this->newLine();
            }

            $this->info("=== End of Results ===");

            return 0;

        } catch (\Exception $e) {
            $this->error("Verification failed: " . $e->getMessage());
            $this->error("Stack trace: " . $e->getTraceAsString());
            return 1;
        }
    }
}
