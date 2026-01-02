<?php

namespace App\Services;

use App\Exceptions\SmtpRateLimitExceededException;
use App\Models\EmailVerification;
use App\Models\MxSkipList;
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
        $domainLower = strtolower($domain);
        
        // 1. Check known typo domains list (fastest)
        $typoDomains = $this->getTypoDomains();
        if (in_array($domainLower, $typoDomains, true)) {
            return true;
        }
        
        // 2. Automatic typo detection using fuzzy matching (if enabled)
        if (config('email-verification.enable_automatic_typo_detection', true)) {
            return $this->detectTypoDomainAutomatically($domainLower);
        }
        
        return false;
    }
    
    /**
     * Automatically detect typo domains using fuzzy matching
     * Compares domain against known public provider domains
     * Smart logic: Only marks as typo if domain is invalid or suspicious
     */
    private function detectTypoDomainAutomatically(string $domain): bool
    {
        // Get all known public provider domains
        $publicProviderDomains = $this->getPublicProviderDomains();
        
        $maxDistance = config('email-verification.typo_detection_max_distance', 2);
        $minSimilarity = config('email-verification.typo_detection_min_similarity', 0.85); // 85% similarity
        
        $bestMatch = null;
        $bestSimilarity = 0;
        
        foreach ($publicProviderDomains as $providerDomain) {
            // Skip if domains are identical
            if ($domain === $providerDomain) {
                continue;
            }
            
            // Check Levenshtein distance (character edits needed)
            $distance = levenshtein($domain, $providerDomain);
            $maxLen = max(strlen($domain), strlen($providerDomain));
            
            if ($maxLen === 0) {
                continue;
            }
            
            // Calculate similarity (0-1, where 1 is identical)
            $similarity = 1 - ($distance / $maxLen);
            
            // Check if within threshold
            if ($distance <= $maxDistance && $similarity >= $minSimilarity) {
                // Track best match
                if ($similarity > $bestSimilarity) {
                    $bestSimilarity = $similarity;
                    $bestMatch = $providerDomain;
                }
            }
        }
        
        // If no similar domain found, not a typo
        if (!$bestMatch) {
            return false;
        }
        
        // Smart check: Only mark as typo if domain is invalid or suspicious
        // If domain is valid (exists, has MX records), it might be a real domain
        return $this->isDomainSuspiciousTypo($domain, $bestMatch, $bestSimilarity);
    }
    
    /**
     * Smart typo detection: Only mark as typo if domain is suspicious
     * Real domains (with valid MX records) are not marked as typo
     */
    private function isDomainSuspiciousTypo(string $domain, string $providerDomain, float $similarity): bool
    {
        // 1. Check if domain has valid MX records
        $mxRecords = $this->getMxRecords($domain);
        $hasMxRecords = !empty($mxRecords);
        
        // 2. Check domain validity (DNS resolution)
        $domainValidity = $this->checkDomainValidity($domain);
        $isValidDomain = $domainValidity['valid'] ?? false;
        
        // 3. If domain is valid and has MX records, it might be a real domain
        // Don't mark as typo if it's a legitimate domain
        if ($isValidDomain && $hasMxRecords) {
            // Additional check: Is it too similar to be a coincidence?
            // If similarity is very high (>= 95%) and it's a common typo pattern, still mark as typo
            if ($similarity >= 0.95 && $this->isLikelyTypo($domain, $providerDomain)) {
                // Very high similarity + common typo pattern = likely typo even with MX records
                // (spammers sometimes register typo domains with MX records)
                Log::info('Automatic typo domain detected (high similarity despite valid MX)', [
                    'domain' => $domain,
                    'provider_domain' => $providerDomain,
                    'similarity' => round($similarity * 100, 2) . '%',
                    'has_mx' => $hasMxRecords,
                ]);
                return true;
            }
            
            // Domain is valid and has MX records - likely a real domain, not a typo
            Log::debug('Similar domain found but not marked as typo (valid domain with MX records)', [
                'domain' => $domain,
                'provider_domain' => $providerDomain,
                'similarity' => round($similarity * 100, 2) . '%',
                'has_mx' => $hasMxRecords,
            ]);
            return false;
        }
        
        // 4. Domain is invalid or has no MX records + similar to provider = typo
        if ($this->isLikelyTypo($domain, $providerDomain)) {
            Log::info('Automatic typo domain detected (invalid domain or no MX records)', [
                'domain' => $domain,
                'provider_domain' => $providerDomain,
                'similarity' => round($similarity * 100, 2) . '%',
                'has_mx' => $hasMxRecords,
                'is_valid' => $isValidDomain,
            ]);
            return true;
        }
        
        return false;
    }
    
    /**
     * Get all public provider domains for typo detection
     */
    private function getPublicProviderDomains(): array
    {
        $cacheKey = 'public_provider_domains_list';
        $ttl = 3600; // 1 hour cache
        
        return Cache::remember($cacheKey, $ttl, function () {
            $domains = [];
            $providers = config('email-verification.public_providers', []);
            
            foreach ($providers as $providerConfig) {
                $providerDomains = $providerConfig['domains'] ?? [];
                $domains = array_merge($domains, $providerDomains);
            }
            
            return array_unique($domains);
        });
    }
    
    /**
     * Check if domain is likely a typo (not just similar by chance)
     * Uses common typo patterns and domain structure analysis
     */
    private function isLikelyTypo(string $domain, string $providerDomain): bool
    {
        // Extract base domain (without TLD)
        $domainBase = $this->getDomainBase($domain);
        $providerBase = $this->getDomainBase($providerDomain);
        
        // If base domains are very similar, it's likely a typo
        $baseDistance = levenshtein($domainBase, $providerBase);
        $baseMaxLen = max(strlen($domainBase), strlen($providerBase));
        
        if ($baseMaxLen === 0) {
            return false;
        }
        
        $baseSimilarity = 1 - ($baseDistance / $baseMaxLen);
        
        // If base similarity is high (>= 80%), it's likely a typo
        if ($baseSimilarity >= 0.80) {
            return true;
        }
        
        // Check for common typo patterns
        $commonTypoPatterns = [
            // Character insertion (gmail.com -> gmailc.com)
            function($d, $p) { return str_contains($d, $p) && strlen($d) === strlen($p) + 1; },
            // Character deletion (gmail.com -> gmai.com)
            function($d, $p) { return str_contains($p, $d) && strlen($d) === strlen($p) - 1; },
            // Character substitution (gmail.com -> gma1l.com)
            function($d, $p) {
                if (strlen($d) !== strlen($p)) return false;
                $diff = 0;
                for ($i = 0; $i < strlen($d); $i++) {
                    if ($d[$i] !== $p[$i]) $diff++;
                }
                return $diff <= 2; // Max 2 character substitutions
            },
        ];
        
        foreach ($commonTypoPatterns as $pattern) {
            if ($pattern($domainBase, $providerBase)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Extract base domain (without TLD)
     * Example: gmail.com -> gmail
     */
    private function getDomainBase(string $domain): string
    {
        $parts = explode('.', $domain);
        if (count($parts) < 2) {
            return $domain;
        }
        
        // Remove TLD (last part)
        array_pop($parts);
        
        return implode('.', $parts);
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

    /**
     * Check if MX server should be skipped
     */
    private function shouldSkipMxServer(string $mxHost): bool
    {
        $skipList = config('email-verification.mx_skip_list', []);
        $mxHostLower = strtolower($mxHost);
        
        // Check exact match in config (manual entries)
        if (in_array($mxHostLower, $skipList, true)) {
            return true;
        }
        
        // Check subdomain match (e.g., mail.securence.com -> securence.com)
        foreach ($skipList as $skipDomain) {
            if (str_ends_with($mxHostLower, '.' . $skipDomain) || $mxHostLower === $skipDomain) {
                return true;
            }
        }
        
        // Check database for auto-added servers (with caching for performance)
        $cacheKey = "mx_skip_db_{$mxHostLower}";
        return Cache::remember($cacheKey, 3600, function () use ($mxHostLower) {
            return MxSkipList::isSkipped($mxHostLower);
        });
    }

    /**
     * Add MX server to skip list (auto-add feature)
     */
    private function addMxToSkipList(string $mxHost, string $reason = 'SMTP connection failed', ?string $response = null): void
    {
        if (!config('email-verification.mx_skip_auto_add', true)) {
            return;
        }
        
        try {
            $expiresInDays = config('email-verification.mx_skip_auto_add_expires_days', 30);
            
            // Store in database (persistent)
            MxSkipList::addOrUpdate(
                $mxHost,
                $reason,
                $response,
                false, // is_manual = false (auto-added)
                $expiresInDays
            );
            
            // Clear cache to force refresh
            $mxHostLower = strtolower($mxHost);
            Cache::forget("mx_skip_db_{$mxHostLower}");
            
            Log::info('MX server added to skip list', [
                'mx_host' => $mxHost,
                'reason' => $reason,
                'expires_in_days' => $expiresInDays,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to add MX server to skip list', [
                'mx_host' => $mxHost,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if domain is unsupported for SMTP verification
     */
    private function checkUnsupportedDomain(string $domain): bool
    {
        $unsupported = config('email-verification.unsupported_domains', []);
        $domainLower = strtolower($domain);
        
        // Exact match
        if (in_array($domainLower, $unsupported, true)) {
            return true;
        }
        
        // Subdomain check
        foreach ($unsupported as $unsupportedDomain) {
            if (str_ends_with($domainLower, '.' . $unsupportedDomain) || $domainLower === $unsupportedDomain) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if catch-all check should be skipped for domain
     */
    private function shouldSkipCatchAllCheck(string $domain): bool
    {
        $skipDomains = config('email-verification.catch_all_skip_domains', []);
        $domainLower = strtolower($domain);
        
        return in_array($domainLower, $skipDomains, true);
    }

    /**
     * Generate random email address for catch-all testing
     */
    private function generateRandomEmail(string $domain): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
        $randomString = '';
        $length = 10;
        
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, strlen($characters) - 1)];
        }
        
        return strtolower($randomString) . '@' . $domain;
    }

    /**
     * Check if domain is a catch-all server
     * Tests by sending a random email address to the domain's MX server
     */
    private function isCatchAllServer(string $domain): bool
    {
        if (!config('email-verification.enable_catch_all_detection', false)) {
            return false;
        }
        
        // Skip if domain is in catch-all skip list
        if ($this->shouldSkipCatchAllCheck($domain)) {
            return false;
        }
        
        // Skip if domain is a public provider (they are always catch-all)
        $mxRecords = $this->getMxRecords($domain);
        $publicProvider = $this->isPublicProvider($domain, $mxRecords);
        if ($publicProvider) {
            return false; // Public providers are catch-all, but we skip detection
        }
        
        // Generate random email
        $randomEmail = $this->generateRandomEmail($domain);
        
        // Check if random email is accepted (if yes, it's catch-all)
        return $this->checkSmtp($randomEmail, $domain);
    }

    /**
     * Check if domain is a public email provider
     */
    private function isPublicProvider(string $domain, array $mxRecords): ?array
    {
        $providers = config('email-verification.public_providers', []);
        $domainLower = strtolower($domain);
        
        foreach ($providers as $providerName => $providerConfig) {
            // Check domain match
            $providerDomains = $providerConfig['domains'] ?? [];
            if (in_array($domainLower, $providerDomains, true)) {
                return $providerConfig;
            }
            
            // Check MX patterns
            $mxPatterns = $providerConfig['mx_patterns'] ?? [];
            foreach ($mxRecords as $mx) {
                $mxHost = strtolower($mx['host'] ?? '');
                foreach ($mxPatterns as $pattern) {
                    if (str_contains($mxHost, strtolower($pattern))) {
                        return $providerConfig;
                    }
                }
            }
        }
        
        return null;
    }

    /**
     * Get MX records for domain (with caching)
     */
    private function getMxRecords(string $domain): array
    {
        $cacheKey = "mx_records_{$domain}";
        $ttl = $this->getMxCacheTtl();
        
        return Cache::remember($cacheKey, $ttl, function () use ($domain) {
            $mxHosts = [];
            $mxWeights = [];
            
            if (!getmxrr($domain, $mxHosts, $mxWeights)) {
                // Try DNS record method
                if (function_exists('dns_get_record')) {
                    $dnsRecords = dns_get_record($domain, DNS_MX);
                    if (empty($dnsRecords)) {
                        return [];
                    }
                    
                    foreach ($dnsRecords as $record) {
                        $mxHosts[] = $record['target'];
                        $mxWeights[] = $record['pri'];
                    }
                } else {
                    return [];
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
            
            // Sort by priority (lower priority number = higher priority)
            usort($mxRecords, function ($a, $b) {
                return $a['pri'] <=> $b['pri'];
            });
            
            return $mxRecords;
        });
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
                'domain_validity' => false,
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

            // 3.5. Unsupported domain check
            if ($this->checkUnsupportedDomain($parts['domain'])) {
                $result['status'] = config('email-verification.unsupported_domain_status', 'skipped');
                $result['score'] = 0;
                $result['error'] = 'Domain does not support SMTP verification';
                $this->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);
                return $result;
            }

            // 3. Role-based email check
            $result['checks']['role'] = $this->checkRoleBased($parts['account']);
            if ($result['checks']['role']) {
                $result['status'] = 'risky';
            }

            // 3.9. Domain validity check (DNS resolution, redirect detection, availability)
            $domainValidity = $this->checkDomainValidity($parts['domain']);
            if (!$domainValidity['valid']) {
                $result['status'] = $domainValidity['status'] ?? 'invalid';
                $result['error'] = $domainValidity['error'] ?? 'Domain does not exist or is not accessible';
                $result['score'] = 0;
                $result['checks']['domain_validity'] = false;
                $this->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);
                return $result;
            }
            $result['checks']['domain_validity'] = true;

            // 4. MX check
            $result['checks']['mx'] = $this->checkMx($parts['domain']);
            if (!$result['checks']['mx']) {
                $result['status'] = 'invalid';
                $result['error'] = $this->getErrorMessages()['no_mx_records'];
                $result['score'] = $this->calculateScore($result['checks']);
                $this->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);
                return $result;
            }

            // 4.5. Public provider check (before SMTP check)
            $mxRecords = $this->getMxRecords($parts['domain']);
            $publicProvider = $this->isPublicProvider($parts['domain'], $mxRecords);
            
            if ($publicProvider && ($publicProvider['skip_smtp'] ?? false)) {
                // Skip SMTP check for public providers, but mark as valid if MX records exist
                if ($result['checks']['mx']) {
                    $result['status'] = $publicProvider['status'] ?? 'valid';
                    $result['checks']['smtp'] = false; // Not checked, but valid (public providers block SMTP checks)
                    // For public providers, give full score since they're known valid providers
                    $result['score'] = 100; // Public providers are always valid if MX records exist
                    $result['error'] = null; // Clear any errors
                    $this->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);
                    return $result;
                }
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

            // Catch-all detection (if enabled and SMTP check didn't pass)
            if (!$result['checks']['smtp'] && config('email-verification.enable_catch_all_detection', false)) {
                try {
                    if ($this->isCatchAllServer($parts['domain'])) {
                        $result['status'] = config('email-verification.catch_all_status', 'catch_all');
                        $result['score'] = max($result['score'], 50); // Minimum score for catch-all
                        $result['error'] = 'Catch-all server detected';
                        $this->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);
                        return $result;
                    }
                } catch (\Exception $e) {
                    Log::warning('Catch-all detection failed', [
                        'email' => $email,
                        'domain' => $parts['domain'],
                        'error' => $e->getMessage(),
                    ]);
                }
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

    /**
     * Check domain validity: DNS resolution, redirect detection, availability
     */
    private function checkDomainValidity(string $domain): array
    {
        if (!config('email-verification.enable_domain_validity_check', true)) {
            return ['valid' => true];
        }
        
        $cacheKey = "domain_validity_{$domain}";
        $ttl = config('email-verification.domain_validity_cache_ttl', 3600);
        
        return Cache::remember($cacheKey, $ttl, function () use ($domain) {
            // 1. DNS Resolution Check (A record)
            $resolvedIp = @gethostbyname($domain);
            if ($resolvedIp === $domain || !filter_var($resolvedIp, FILTER_VALIDATE_IP)) {
                // Domain does not resolve to IP
                return [
                    'valid' => false,
                    'status' => 'invalid',
                    'error' => 'Domain does not exist (DNS resolution failed)',
                    'reason' => 'dns_resolution_failed',
                ];
            }
            
            // 2. Check if domain has A record (not just CNAME)
            if (function_exists('dns_get_record')) {
                $dnsRecords = @dns_get_record($domain, DNS_A | DNS_AAAA);
                if (empty($dnsRecords)) {
                    // No A or AAAA records found
                    return [
                        'valid' => false,
                        'status' => 'invalid',
                        'error' => 'Domain has no A or AAAA records',
                        'reason' => 'no_a_record',
                    ];
                }
            }
            
            // 3. HTTP Redirect Detection (optional, can be disabled for performance)
            if (config('email-verification.check_domain_redirect', false)) {
                $redirectCheck = $this->checkDomainRedirect($domain);
                if ($redirectCheck['is_redirect']) {
                    return [
                        'valid' => false,
                        'status' => 'risky',
                        'error' => 'Domain redirects to another domain (possible spam trap)',
                        'reason' => 'domain_redirect',
                        'redirect_to' => $redirectCheck['redirect_to'] ?? null,
                    ];
                }
            }
            
            // 4. Domain Availability Check (HTTP response)
            if (config('email-verification.check_domain_availability', false)) {
                $availabilityCheck = $this->checkDomainAvailability($domain);
                if (!$availabilityCheck['available']) {
                    return [
                        'valid' => false,
                        'status' => 'invalid',
                        'error' => 'Domain is not accessible or does not respond',
                        'reason' => 'domain_not_available',
                    ];
                }
            }
            
            return ['valid' => true];
        });
    }
    
    /**
     * Check if domain redirects (HTTP redirect detection)
     */
    private function checkDomainRedirect(string $domain): array
    {
        $timeout = config('email-verification.domain_check_timeout', 3);
        
        try {
            $url = "http://{$domain}";
            $context = stream_context_create([
                'http' => [
                    'method' => 'HEAD',
                    'timeout' => $timeout,
                    'follow_location' => false, // Don't follow redirects automatically
                    'max_redirects' => 0,
                    'user_agent' => 'Mozilla/5.0 (compatible; EmailVerifier/1.0)',
                ],
            ]);
            
            $headers = @get_headers($url, 1, $context);
            
            if ($headers === false) {
                return ['is_redirect' => false];
            }
            
            // Check for redirect status codes (3xx)
            $statusLine = $headers[0] ?? '';
            if (preg_match('/HTTP\/\d\.\d\s+3\d{2}/', $statusLine)) {
                // Extract redirect location
                $location = $headers['Location'] ?? $headers['location'] ?? null;
                if (is_array($location)) {
                    $location = $location[0] ?? null;
                }
                
                return [
                    'is_redirect' => true,
                    'redirect_to' => $location,
                    'status_code' => (int)substr($statusLine, 9, 3),
                ];
            }
            
            return ['is_redirect' => false];
        } catch (\Exception $e) {
            // If check fails, don't block - just return no redirect
            Log::debug('Domain redirect check failed', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
            return ['is_redirect' => false];
        }
    }
    
    /**
     * Check if domain is available/accessible (HTTP response check)
     */
    private function checkDomainAvailability(string $domain): array
    {
        $timeout = config('email-verification.domain_check_timeout', 3);
        
        try {
            $url = "http://{$domain}";
            $context = stream_context_create([
                'http' => [
                    'method' => 'HEAD',
                    'timeout' => $timeout,
                    'user_agent' => 'Mozilla/5.0 (compatible; EmailVerifier/1.0)',
                ],
            ]);
            
            $headers = @get_headers($url, 0, $context);
            
            if ($headers === false) {
                // Domain doesn't respond to HTTP - might still be valid for email
                // Don't block based on HTTP availability alone
                return ['available' => true];
            }
            
            // Check for successful response (2xx) or redirect (3xx)
            $statusLine = $headers[0] ?? '';
            if (preg_match('/HTTP\/\d\.\d\s+[23]\d{2}/', $statusLine)) {
                return ['available' => true];
            }
            
            // 4xx or 5xx - domain exists but has issues
            // Still consider available (might be email-only domain)
            return ['available' => true];
        } catch (\Exception $e) {
            // If check fails, don't block - email domains might not have HTTP
            Log::debug('Domain availability check failed', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
            return ['available' => true];
        }
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
        // Get MX records (with caching)
        $mxRecords = $this->getMxRecords($domain);
        
        if (empty($mxRecords)) {
            return false;
        }

        // Filter out skipped MX servers
        $mxRecordsToTry = [];
        foreach ($mxRecords as $mx) {
            $host = $mx['host'];
            
            // Skip if MX server is in skip list
            if ($this->shouldSkipMxServer($host)) {
                Log::debug('Skipping MX server (in skip list)', ['host' => $host]);
                continue;
            }
            
            $mxRecordsToTry[] = $mx;
        }
        
        if (empty($mxRecordsToTry)) {
            Log::info('All MX servers are in skip list', ['domain' => $domain]);
            return false;
        }

        // Try MX records in priority order, but limit to first 3 to avoid long delays
        $maxMxToTry = 3;
        $mxRecordsToTry = array_slice($mxRecordsToTry, 0, $maxMxToTry);
        
        foreach ($mxRecordsToTry as $mx) {
            $host = $mx['host'];
            $retries = $this->getSmtpRetries();
            
            for ($attempt = 0; $attempt < $retries; $attempt++) {
                $result = $this->performSmtpCheck($host, $email);
                
                if ($result) {
                    return true;
                }
                
                // If connection failed, add to skip list (if auto-add enabled)
                if ($attempt === $retries - 1) {
                    // Last attempt failed, add to skip list
                    $this->addMxToSkipList($host, 'SMTP connection failed');
                }
                
                // Skip retry delay on last attempt to save time
                if ($attempt < $retries - 1) {
                    usleep(300000); // 0.3 second delay between retries (reduced from 0.5)
                }
            }
        }

        return false;
    }

    /**
     * Analyze SMTP response code and message
     */
    private function analyzeSmtpResponse(string $response): array
    {
        $code = (int)substr($response, 0, 3);
        $message = trim(substr($response, 4));
        
        return [
            'code' => $code,
            'message' => $message,
            'is_greylisting' => in_array($code, [450, 451, 452], true), // Temporary failures
            'is_catch_all' => in_array($code, [251, 252], true), // Catch-all indicators
            'is_valid' => in_array($code, [250, 251, 252], true), // Valid responses
            'is_invalid' => in_array($code, [550, 551, 552, 553, 554], true), // Permanent failures
            'is_temporary' => $code >= 400 && $code < 500, // 4xx = temporary
            'is_permanent' => $code >= 500 && $code < 600, // 5xx = permanent
        ];
    }

    private function performSmtpCheck(string $host, string $email): bool
    {
        $timeout = $this->getSmtpTimeout();
        $connectionTimeout = min(2, $timeout); // Connection timeout (max 2 seconds)
        $readTimeout = $timeout; // Read timeout
        
        // Use connection timeout for initial connection
        $socket = @fsockopen($host, 25, $errno, $errstr, $connectionTimeout);
        
        if (!$socket) {
            // Connection failed, add to skip list if auto-add enabled
            $this->addMxToSkipList($host, 'SMTP connection failed');
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
            
            if (!$response || $isTimeout()) {
                @fwrite($socket, "QUIT\r\n");
                @fclose($socket);
                return false;
            }
            
            // Analyze SMTP response
            $responseAnalysis = $this->analyzeSmtpResponse($response);
            
            // Check for greylisting (4xx responses) - retry after delay
            if ($responseAnalysis['is_greylisting'] && config('email-verification.enable_greylisting_retry', false)) {
                $retryDelay = config('email-verification.greylisting_retry_delay', 5);
                sleep($retryDelay);
                
                // Retry RCPT TO
                @fwrite($socket, "RCPT TO: <{$email}>\r\n");
                $retryResponse = @fgets($socket, 515);
                
                if ($retryResponse && !$isTimeout()) {
                    $responseAnalysis = $this->analyzeSmtpResponse($retryResponse);
                    $response = $retryResponse; // Update response for final check
                }
            }
            
            // Check for error patterns before closing connection
            if (!$responseAnalysis['is_valid'] && !$responseAnalysis['is_greylisting']) {
                $errorPatterns = config('email-verification.smtp_error_patterns', []);
                $responseLower = strtolower($response);
                
                foreach ($errorPatterns as $pattern) {
                    if (str_contains($responseLower, strtolower($pattern))) {
                        // Add MX to skip list
                        $this->addMxToSkipList($host, "SMTP error: {$pattern}", trim($response));
                        
                        Log::warning('SMTP error pattern detected', [
                            'host' => $host,
                            'email' => $email,
                            'pattern' => $pattern,
                            'response' => trim($response),
                            'code' => $responseAnalysis['code'],
                        ]);
                        
                        @fwrite($socket, "QUIT\r\n");
                        @fclose($socket);
                        return false;
                    }
                }
            }
            
            // QUIT
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);

            // Check RCPT TO response - valid responses
            return $responseAnalysis['is_valid'];

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


