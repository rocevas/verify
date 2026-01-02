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

    private function getRoleEmails(): array
    {
        return config('email-verification.role_emails', []);
    }

    private function getScoreWeights(): array
    {
        return config('email-verification.score_weights', [
            'syntax' => 10,
            'mx' => 30,
            'smtp' => 50,
            'disposable' => 10,
            'role_penalty' => 20,
        ]);
    }

    private function getBlacklistStatusMap(): array
    {
        return config('email-verification.blacklist_status_map', [
            'spamtrap' => 'spamtrap',
            'abuse' => 'abuse',
            'do_not_mail' => 'do_not_mail',
            'bounce' => 'invalid',
            'complaint' => 'abuse',
            'other' => 'do_not_mail',
        ]);
    }

    private function getStatusRules(): array
    {
        return config('email-verification.status_rules', [
            'smtp_valid' => 'valid',
            'min_score_for_catch_all' => 50,
            'role_emails_status' => 'risky',
            'non_role_emails_status' => 'catch_all',
            'default_invalid' => 'invalid',
        ]);
    }

    private function getErrorMessages(): array
    {
        return config('email-verification.error_messages', [
            'invalid_format' => 'Invalid email format',
            'invalid_syntax' => 'Invalid email syntax',
            'no_mx_records' => 'No MX records found',
            'blacklisted' => 'Blacklisted: :reason:notes',
        ]);
    }

    private function getMxCacheTtl(): int
    {
        return config('email-verification.mx_cache_ttl', 3600);
    }

    private function getRiskChecks(): array
    {
        return config('email-verification.risk_checks', [
            'never_opt_in_status' => 'do_not_mail',
            'typo_domain_status' => 'spamtrap',
            'isp_esp_status' => 'do_not_mail',
            'government_tld_status' => 'risky',
            'enable_typo_check' => true,
            'enable_isp_esp_check' => true,
            'enable_government_check' => true,
        ]);
    }

    private function getNeverOptInKeywords(): array
    {
        return config('email-verification.never_opt_in_keywords', []);
    }

    private function getTypoDomains(): array
    {
        return config('email-verification.typo_domains', []);
    }

    private function getIspEspDomains(): array
    {
        return config('email-verification.isp_esp_domains', []);
    }

    private function getGovernmentTlds(): array
    {
        return config('email-verification.government_tlds', []);
    }

    private function checkNeverOptIn(string $account): bool
    {
        $keywords = $this->getNeverOptInKeywords();
        $accountLower = strtolower($account);
        
        // Check exact match
        if (in_array($accountLower, $keywords, true)) {
            return true;
        }
        
        // Check if account contains any keyword (for patterns like "no-reply-123")
        foreach ($keywords as $keyword) {
            if (str_contains($accountLower, $keyword)) {
                return true;
            }
        }
        
        return false;
    }

    private function checkTypoDomain(string $domain): bool
    {
        $typoDomains = $this->getTypoDomains();
        return in_array(strtolower($domain), $typoDomains, true);
    }

    private function checkIspEspDomain(string $domain): bool
    {
        $ispEspDomains = $this->getIspEspDomains();
        $domainLower = strtolower($domain);
        
        // Check exact match
        if (in_array($domainLower, $ispEspDomains, true)) {
            return true;
        }
        
        // Check if domain ends with any ISP/ESP domain (for subdomains)
        foreach ($ispEspDomains as $ispDomain) {
            if (str_ends_with($domainLower, '.' . $ispDomain) || $domainLower === $ispDomain) {
                return true;
            }
        }
        
        return false;
    }

    private function checkGovernmentTld(string $domain): bool
    {
        $governmentTlds = $this->getGovernmentTlds();
        $parts = explode('.', strtolower($domain));
        
        if (empty($parts)) {
            return false;
        }
        
        // Get TLD (last part)
        $tld = end($parts);
        
        return in_array($tld, $governmentTlds, true);
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
                'blacklist' => false,
                'mx' => false,
                'smtp' => false,
                'disposable' => false,
                'role' => false,
                'never_opt_in' => false,
                'typo_domain' => false,
                'isp_esp' => false,
                'government_tld' => false,
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
                $result['error'] = $this->getErrorMessages()['invalid_format'];
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
                $result['error'] = $this->getErrorMessages()['invalid_syntax'];
                $result['score'] = 0;
                $this->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);
                return $result;
            }

            // 2. Blacklist check
            $blacklist = \App\Models\Blacklist::isBlacklisted($email);
            if ($blacklist) {
                $statusMap = $this->getBlacklistStatusMap();
                $result['status'] = $statusMap[$blacklist->reason] ?? 'do_not_mail';
                $errorTemplate = $this->getErrorMessages()['blacklisted'];
                $notes = $blacklist->notes ? " - {$blacklist->notes}" : '';
                $result['error'] = str_replace([':reason', ':notes'], [$blacklist->reason, $notes], $errorTemplate);
                $result['score'] = 0;
                $result['checks']['blacklist'] = true;
                $this->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);
                return $result;
            }
            $result['checks']['blacklist'] = false;

            // 2.5. Never-opt-in keywords check (synthetic addresses / list poisoning)
            $result['checks']['never_opt_in'] = $this->checkNeverOptIn($parts['account']);
            if ($result['checks']['never_opt_in']) {
                $riskChecks = $this->getRiskChecks();
                $result['status'] = $riskChecks['never_opt_in_status'] ?? 'do_not_mail';
                $result['score'] = 0;
                $this->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);
                return $result;
            }

            // 2.6. Typo domain check (spam trap domains)
            $riskChecks = $this->getRiskChecks();
            if ($riskChecks['enable_typo_check'] ?? true) {
                $result['checks']['typo_domain'] = $this->checkTypoDomain($parts['domain']);
                if ($result['checks']['typo_domain']) {
                    $result['status'] = $riskChecks['typo_domain_status'] ?? 'spamtrap';
                    $result['score'] = 0;
                    $result['error'] = 'Typo domain detected (likely spam trap)';
                    $this->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);
                    return $result;
                }
            }

            // 2.7. ISP/ESP infrastructure domain check
            if ($riskChecks['enable_isp_esp_check'] ?? true) {
                $result['checks']['isp_esp'] = $this->checkIspEspDomain($parts['domain']);
                if ($result['checks']['isp_esp']) {
                    $result['status'] = $riskChecks['isp_esp_status'] ?? 'do_not_mail';
                    $result['score'] = 0;
                    $result['error'] = 'ISP/ESP infrastructure domain (not for marketing)';
                    $this->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);
                    return $result;
                }
            }

            // 2.8. Government/registry TLD check
            if ($riskChecks['enable_government_check'] ?? true) {
                $result['checks']['government_tld'] = $this->checkGovernmentTld($parts['domain']);
                if ($result['checks']['government_tld']) {
                    $result['status'] = $riskChecks['government_tld_status'] ?? 'risky';
                    // Don't return early - just mark as risky and continue
                }
            }

            // 3. Disposable email check
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
                $result['error'] = $this->getErrorMessages()['no_mx_records'];
                $result['score'] = $this->calculateScore($result['checks']);
                $this->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);
                return $result;
            }

            // Calculate score before SMTP check (for faster response if SMTP fails)
            $result['score'] = $this->calculateScore($result['checks']);

            // 5. SMTP check (lėčiausias check, daromas paskutinis) - wrapped in try-catch to not fail entire verification
            // Only perform if enabled in config and rate limit allows
            if ($this->isSmtpCheckEnabled()) {
                try {
                    // Check rate limit before performing SMTP check
                    if ($this->checkSmtpRateLimit($parts['domain'])) {
                        $result['checks']['smtp'] = $this->checkSmtp($email, $parts['domain']);
                        
                        // Recalculate score after SMTP check
                        $result['score'] = $this->calculateScore($result['checks']);
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

            // Determine final status based on config rules
            $statusRules = $this->getStatusRules();
            if ($result['checks']['smtp']) {
                $result['status'] = $statusRules['smtp_valid'];
            } elseif ($result['score'] >= ($statusRules['min_score_for_catch_all'] ?? 50)) {
                $result['status'] = $result['checks']['role'] 
                    ? ($statusRules['role_emails_status'] ?? 'risky')
                    : ($statusRules['non_role_emails_status'] ?? 'catch_all');
            } else {
                $result['status'] = $statusRules['default_invalid'] ?? 'invalid';
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
        return in_array(strtolower($account), $this->getRoleEmails(), true);
    }

    private function checkMx(string $domain): bool
    {
        $cacheKey = "mx_check_{$domain}";
        $ttl = $this->getMxCacheTtl();
        
        return Cache::remember($cacheKey, $ttl, function () use ($domain) {
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

        // Try MX records in priority order, but limit to first 3 to avoid long delays
        $maxMxToTry = 3;
        $mxRecordsToTry = array_slice($mxRecords, 0, $maxMxToTry);
        
        foreach ($mxRecordsToTry as $mx) {
            $host = $mx['host'];
            $retries = $this->getSmtpRetries();
            
            for ($attempt = 0; $attempt < $retries; $attempt++) {
                if ($this->performSmtpCheck($host, $email)) {
                    return true;
                }
                
                // Skip retry delay on last attempt to save time
                if ($attempt < $retries - 1) {
                    usleep(300000); // 0.3 second delay between retries (reduced from 0.5)
                }
            }
        }

        return false;
    }

    private function performSmtpCheck(string $host, string $email): bool
    {
        $timeout = $this->getSmtpTimeout();
        $connectionTimeout = min(2, $timeout); // Connection timeout (max 2 seconds)
        $readTimeout = $timeout; // Read timeout
        
        // Use connection timeout for initial connection
        $socket = @fsockopen($host, 25, $errno, $errstr, $connectionTimeout);
        
        if (!$socket) {
            return false;
        }

        try {
            // Set read timeout for all subsequent operations
            stream_set_timeout($socket, $readTimeout, 0); // seconds, microseconds
            
            // Helper to check timeout
            $isTimeout = function() use ($socket) {
                $info = stream_get_meta_data($socket);
                return $info['timed_out'] ?? false;
            };
            
            // Read greeting with timeout check
            $response = @fgets($socket, 515);
            if ($isTimeout() || !$response || !str_starts_with($response, '220')) {
                @fclose($socket);
                return false;
            }

            // EHLO with configurable hostname
            $heloHostname = $this->getSmtpHeloHostname();
            @fwrite($socket, "EHLO {$heloHostname}\r\n");
            $response = @fgets($socket, 515);
            if ($isTimeout() || !$response || !str_starts_with($response, '250')) {
                @fclose($socket);
                return false;
            }

            // Read all EHLO responses (multi-line) with timeout protection
            $maxLines = 10; // Safety limit
            $lineCount = 0;
            while ($lineCount < $maxLines) {
                $line = @fgets($socket, 515);
                if ($isTimeout() || !$line || !str_starts_with($line, '250')) {
                    break;
                }
                $lineCount++;
            }

            // MAIL FROM
            @fwrite($socket, "MAIL FROM: <noreply@".gethostname().">\r\n");
            $response = @fgets($socket, 515);
            if ($isTimeout() || !$response || !str_starts_with($response, '250')) {
                @fclose($socket);
                return false;
            }

            // RCPT TO
            @fwrite($socket, "RCPT TO: <{$email}>\r\n");
            $response = @fgets($socket, 515);
            
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
        $weights = $this->getScoreWeights();
        $score = 0;

        // If any high-risk check fails, score is 0
        if ($checks['never_opt_in'] ?? false) {
            return 0; // Never-opt-in keywords = 0
        }
        if ($checks['typo_domain'] ?? false) {
            return 0; // Typo domains = 0
        }
        if ($checks['isp_esp'] ?? false) {
            return 0; // ISP/ESP domains = 0
        }

        if ($checks['syntax']) {
            $score += $weights['syntax'] ?? 10;
        }

        if ($checks['mx']) {
            $score += $weights['mx'] ?? 30;
        }

        if ($checks['smtp']) {
            $score += $weights['smtp'] ?? 50;
        }

        if (!$checks['disposable']) {
            $score += $weights['disposable'] ?? 10;
        } else {
            $score = 0; // Disposable emails get 0
        }

        if ($checks['role']) {
            $score -= $weights['role_penalty'] ?? 20; // Penalty for role-based emails
        }

        // Government TLD penalty (reduces score but doesn't zero it)
        if ($checks['government_tld'] ?? false) {
            $score -= 10; // Small penalty for government TLDs
        }

        return max(0, min(100, $score));
    }
}


