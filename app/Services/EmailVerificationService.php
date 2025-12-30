<?php

namespace App\Services;

use App\Exceptions\SmtpRateLimitExceededException;
use App\Models\EmailVerification;
use Illuminate\Support\Facades\Log;
use Propaganistas\LaravelDisposableEmail\DisposableDomains;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class EmailVerificationService
{
    private const ROLE_EMAILS = [
        'abuse', 'admin', 'billing', 'compliance', 'devnull', 'dns',
        'ftp', 'hostmaster', 'inoc', 'ispfeedback', 'ispsupport', 'list-request',
        'list', 'maildaemon', 'noc', 'no-reply', 'noreply', 'null', 'phish',
        'phishing', 'postmaster', 'privacy', 'registrar', 'root', 'security',
        'spam', 'support', 'sysadmin', 'tech', 'undisclosed-recipients', 'unsubscribe',
        'webmaster', 'www', 'info', 'contact', 'sales', 'marketing', 'help',
    ];

    private function getSmtpTimeout(): int
    {
        return config('email-verification.smtp_timeout', 5);
    }
    
    private function getSmtpRetries(): int
    {
        return config('email-verification.smtp_retries', 1);
    }
    
    private function isSmtpCheckEnabled(): bool
    {
        return config('email-verification.enable_smtp_check', true);
    }

    private function getSmtpRateLimit(): array
    {
        return config('email-verification.smtp_rate_limit', [
            'enable_global_limit' => false,
            'max_checks_per_minute' => 100,
            'max_checks_per_domain_per_minute' => 20,
            'delay_between_checks' => 0.5,
        ]);
    }

    private function getSmtpHeloHostname(): string
    {
        return config('email-verification.smtp_helo_hostname') ?? gethostname();
    }

    private function checkSmtpRateLimit(string $domain): bool
    {
        $rateLimit = $this->getSmtpRateLimit();
        
        // Global rate limit (optional - disabled by default for queue workers)
        if ($rateLimit['enable_global_limit'] ?? false) {
            $globalKey = 'smtp_check_global';
            $globalLimit = RateLimiter::tooManyAttempts($globalKey, $rateLimit['max_checks_per_minute']);
            if ($globalLimit) {
                Log::warning('SMTP rate limit exceeded (global)', [
                    'limit' => $rateLimit['max_checks_per_minute'],
                ]);
                return false;
            }
            RateLimiter::hit($globalKey, 60); // 60 seconds window
        }

        // Per-domain rate limit (most important - prevents ban from specific servers)
        $domainKey = 'smtp_check_domain_' . md5($domain);
        $domainLimit = RateLimiter::tooManyAttempts($domainKey, $rateLimit['max_checks_per_domain_per_minute']);
        if ($domainLimit) {
            // Calculate retry after time (60 seconds = 1 minute window)
            $retryAfter = 60;
            
            // If we're in a queue job context, throw exception to trigger retry
            // Optimize: Check if VerifyEmailJob is in call stack (limit to 5 levels for performance)
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
            foreach ($backtrace as $trace) {
                if (isset($trace['class']) && str_contains($trace['class'], 'VerifyEmailJob')) {
                    throw new SmtpRateLimitExceededException($domain, $retryAfter);
                }
            }
            
            // Otherwise, just skip SMTP check (synchronous call)
            Log::info('SMTP rate limit exceeded (domain) - skipping check', [
                'domain' => $domain,
                'limit' => $rateLimit['max_checks_per_domain_per_minute'],
            ]);
            return false;
        }

        // Increment domain counter
        RateLimiter::hit($domainKey, 60);

        // Add small delay between checks to same domain (helps avoid detection)
        $delay = $rateLimit['delay_between_checks'] ?? 0.5;
        if ($delay > 0) {
            usleep((int)($delay * 1000000)); // Convert seconds to microseconds
        }

        return true;
    }

    public function verify(string $email, ?int $userId = null, ?int $teamId = null, ?int $tokenId = null, ?int $bulkJobId = null, ?string $source = null): array
    {
        $result = [
            'email' => $email,
            'status' => 'unknown',
            'account' => null,
            'domain' => null,
            'checks' => [
                'syntax' => false,
                'mx' => false,
                'smtp' => false,
                'disposable' => false,
                'role' => false,
            ],
            'score' => 0,
            'error' => null,
        ];

        $parts = null;
        
        try {
            // Parse email
            $parts = $this->parseEmail($email);
            if (!$parts) {
                $result['status'] = 'invalid';
                $result['error'] = 'Invalid email format';
                $result['score'] = 0;
                // Save even invalid emails for tracking
                $this->saveVerification($result, $userId, $teamId, $tokenId, ['account' => null, 'domain' => null], $bulkJobId, $source);
                return $result;
            }

            $result['account'] = $parts['account'];
            $result['domain'] = $parts['domain'];

            // 1. Syntax check
            $result['checks']['syntax'] = $this->checkSyntax($email);
            if (!$result['checks']['syntax']) {
                $result['status'] = 'invalid';
                $result['error'] = 'Invalid email syntax';
                $result['score'] = 0;
                $this->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);
                return $result;
            }

            // 2. Disposable email check
            $result['checks']['disposable'] = $this->checkDisposable($parts['domain']);
            if ($result['checks']['disposable']) {
                $result['status'] = 'do_not_mail';
                $result['score'] = 0;
                $this->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);
                return $result;
            }

            // 3. Role-based email check
            $result['checks']['role'] = $this->checkRoleBased($parts['account']);
            if ($result['checks']['role']) {
                $result['status'] = 'risky';
            }

            // 4. MX check
            $result['checks']['mx'] = $this->checkMx($parts['domain']);
            if (!$result['checks']['mx']) {
                $result['status'] = 'invalid';
                $result['error'] = 'No MX records found';
                $result['score'] = $this->calculateScore($result['checks']);
                $this->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);
                return $result;
            }

            // 5. SMTP check (with retries) - wrapped in try-catch to not fail entire verification
            // Only perform if enabled in config and rate limit allows
            if ($this->isSmtpCheckEnabled()) {
                try {
                    // Check rate limit before performing SMTP check
                    if ($this->checkSmtpRateLimit($parts['domain'])) {
                        $result['checks']['smtp'] = $this->checkSmtp($email, $parts['domain']);
                    } else {
                        // Rate limit exceeded, skip SMTP check
                        $result['checks']['smtp'] = false;
                        Log::info('SMTP check skipped due to rate limit', [
                            'email' => $email,
                            'domain' => $parts['domain'],
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::warning('SMTP check failed', [
                        'email' => $email,
                        'domain' => $parts['domain'],
                        'error' => $e->getMessage(),
                    ]);
                    $result['checks']['smtp'] = false;
                }
            } else {
                $result['checks']['smtp'] = false; // Not checked
            }

            // Calculate score
            $result['score'] = $this->calculateScore($result['checks']);

            // Determine final status
            // If SMTP check passed, email is definitely valid
            if ($result['checks']['smtp']) {
                $result['status'] = 'valid';
            } elseif ($result['score'] >= 50) {
                // Without SMTP, we can't be 100% sure, so mark as catch_all or risky
                $result['status'] = $result['checks']['role'] ? 'risky' : 'catch_all';
            } else {
                $result['status'] = 'invalid';
            }

            // Save to database
            $this->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);

        } catch (\Exception $e) {
            Log::error('Email verification failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            $result['status'] = 'unknown';
            $result['error'] = $e->getMessage();
            
            // Try to save even on error
            try {
                $parts = $this->parseEmail($email);
                if ($parts) {
                    $this->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);
                }
            } catch (\Exception $saveException) {
                Log::error('Failed to save verification record', [
                    'email' => $email,
                    'error' => $saveException->getMessage(),
                ]);
            }
        }

        return $result;
    }
    
    private function saveVerification(array $result, ?int $userId, ?int $teamId, ?int $tokenId, array $parts, ?int $bulkJobId = null, ?string $source = null): void
    {
        try {
            $verification = EmailVerification::create([
                'user_id' => $userId,
                'team_id' => $teamId,
                'api_key_id' => $tokenId, // Store Sanctum token ID for reference
                'bulk_verification_job_id' => $bulkJobId,
                'source' => $source,
                'email' => $result['email'],
                'account' => $parts['account'] ?? null,
                'domain' => $parts['domain'] ?? null,
                'status' => $result['status'],
                'checks' => $result['checks'],
                'score' => $result['score'],
                'error' => $result['error'],
                'verified_at' => now(),
            ]);
            
            // Only log in debug mode to reduce log verbosity
            if (config('app.debug')) {
                Log::debug('Email verification saved', [
                    'id' => $verification->id,
                    'email' => $result['email'],
                    'status' => $result['status'],
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to save email verification', [
                'email' => $result['email'],
                'user_id' => $userId,
                'team_id' => $teamId,
                'error' => $e->getMessage(),
            ]);
            // Don't throw - we still want to return the result
        }
    }

    private function parseEmail(string $email): ?array
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        // Use limit=2 to handle emails with @ in local part (though filter_var should prevent this)
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return null;
        }

        return [
            'account' => strtolower($parts[0]),
            'domain' => strtolower($parts[1]),
        ];
    }

    private function checkSyntax(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function checkDisposable(string $domain): bool
    {
        try {
            return app(DisposableDomains::class)->isDisposable($domain);
        } catch (\Exception $e) {
            Log::warning('Disposable email check failed', ['domain' => $domain, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private function checkRoleBased(string $account): bool
    {
        return in_array(strtolower($account), self::ROLE_EMAILS, true);
    }

    private function checkMx(string $domain): bool
    {
        $cacheKey = "mx_check_{$domain}";
        
        return Cache::remember($cacheKey, 3600, function () use ($domain) {
            $mxRecords = [];
            $result = getmxrr($domain, $mxRecords);
            
            if (!$result && function_exists('dns_get_record')) {
                $dnsRecords = dns_get_record($domain, DNS_MX);
                $result = !empty($dnsRecords);
            }
            
            return $result;
        });
    }

    private function checkSmtp(string $email, string $domain): bool
    {
        $mxHosts = [];
        $mxWeights = [];
        
        if (!getmxrr($domain, $mxHosts, $mxWeights)) {
            // Try DNS record method
            if (function_exists('dns_get_record')) {
                $dnsRecords = dns_get_record($domain, DNS_MX);
                if (empty($dnsRecords)) {
                    return false;
                }
                
                foreach ($dnsRecords as $record) {
                    $mxHosts[] = $record['target'];
                    $mxWeights[] = $record['pri'];
                }
            } else {
                return false;
            }
        }

        // Combine and sort by priority
        $mxRecords = [];
        foreach ($mxHosts as $index => $host) {
            $mxRecords[] = [
                'host' => $host,
                'pri' => $mxWeights[$index] ?? 10,
            ];
        }

        usort($mxRecords, function ($a, $b) {
            return $a['pri'] <=> $b['pri'];
        });

        foreach ($mxRecords as $mx) {
            $host = $mx['host'];
            $retries = $this->getSmtpRetries();
            
            for ($attempt = 0; $attempt < $retries; $attempt++) {
                if ($this->performSmtpCheck($host, $email)) {
                    return true;
                }
                
                if ($attempt < $retries - 1) {
                    usleep(500000); // 0.5 second delay between retries
                }
            }
        }

        return false;
    }

    private function performSmtpCheck(string $host, string $email): bool
    {
        $timeout = $this->getSmtpTimeout();
        $socket = @fsockopen($host, 25, $errno, $errstr, $timeout);
        
        if (!$socket) {
            return false;
        }

        try {
            stream_set_timeout($socket, $timeout);
            
            // Helper to check timeout
            $isTimeout = function() use ($socket) {
                $info = stream_get_meta_data($socket);
                return $info['timed_out'];
            };
            
            // Read greeting
            $response = fgets($socket, 515);
            if ($isTimeout() || !$response || !str_starts_with($response, '220')) {
                @fclose($socket);
                return false;
            }

            // EHLO with configurable hostname
            $heloHostname = $this->getSmtpHeloHostname();
            fwrite($socket, "EHLO {$heloHostname}\r\n");
            $response = fgets($socket, 515);
            if ($isTimeout() || !$response || !str_starts_with($response, '250')) {
                @fclose($socket);
                return false;
            }

            // Read all EHLO responses (multi-line)
            while (true) {
                $line = fgets($socket, 515);
                if ($isTimeout() || !$line || !str_starts_with($line, '250')) {
                    break;
                }
            }

            // MAIL FROM
            fwrite($socket, "MAIL FROM: <noreply@".gethostname().">\r\n");
            $response = fgets($socket, 515);
            if ($isTimeout() || !$response || !str_starts_with($response, '250')) {
                @fclose($socket);
                return false;
            }

            // RCPT TO
            fwrite($socket, "RCPT TO: <{$email}>\r\n");
            $response = fgets($socket, 515);
            
            // QUIT
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);

            // Check RCPT TO response
            if ($response && str_starts_with($response, '250')) {
                return true;
            }

            // Some servers return 251/252 for catch-all
            if ($response && (str_starts_with($response, '251') || str_starts_with($response, '252'))) {
                return true;
            }

            return false;

        } catch (\Exception $e) {
            @fclose($socket);
            return false;
        }
    }

    private function calculateScore(array $checks): int
    {
        $score = 0;

        if ($checks['syntax']) {
            $score += 10;
        }

        if ($checks['mx']) {
            $score += 30;
        }

        if ($checks['smtp']) {
            $score += 50;
        }

        if (!$checks['disposable']) {
            $score += 10;
        } else {
            $score = 0; // Disposable emails get 0
        }

        if ($checks['role']) {
            $score -= 20; // Penalty for role-based emails
        }

        return max(0, min(100, $score));
    }
}

