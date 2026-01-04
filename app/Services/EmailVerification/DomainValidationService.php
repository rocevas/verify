<?php

namespace App\Services\EmailVerification;

use App\Models\MxSkipList;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DomainValidationService
{
    /**
     * Get MX records for domain (with caching)
     * 
     * @param string $domain
     * @return array Array of ['host' => string, 'pri' => int] sorted by priority
     */
    public function getMxRecords(string $domain): array
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

    /**
     * Check if domain has MX records
     * 
     * @param string $domain
     * @return bool
     */
    public function checkMx(string $domain): bool
    {
        $cacheKey = "mx_check_{$domain}";
        $ttl = $this->getMxCacheTtl();
        
        return Cache::remember($cacheKey, $ttl, function () use ($domain) {
            // Set DNS timeout to prevent hanging
            $originalTimeout = ini_get('default_socket_timeout');
            ini_set('default_socket_timeout', 3); // 3 seconds max for DNS
            
            try {
                $mxRecords = [];
                $result = @getmxrr($domain, $mxRecords);
                
                if (!$result && function_exists('dns_get_record')) {
                    $dnsRecords = @dns_get_record($domain, DNS_MX);
                    $result = !empty($dnsRecords);
                }
                
                return $result;
            } finally {
                // Restore original timeout
                ini_set('default_socket_timeout', $originalTimeout);
            }
        });
    }

    /**
     * Check domain validity: DNS resolution, redirect detection, availability
     * 
     * @param string $domain
     * @return array ['valid' => bool, 'status' => string?, 'error' => string?, 'reason' => string?]
     */
    public function checkDomainValidity(string $domain): array
    {
        if (!config('email-verification.enable_domain_validity_check', true)) {
            return ['valid' => true];
        }
        
        $cacheKey = "domain_validity_{$domain}";
        $ttl = config('email-verification.domain_validity_cache_ttl', 3600);
        
        return Cache::remember($cacheKey, $ttl, function () use ($domain) {
            // Set DNS timeout to prevent hanging
            $originalTimeout = ini_get('default_socket_timeout');
            ini_set('default_socket_timeout', 3); // 3 seconds max for DNS
            
            try {
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
            } finally {
                // Restore original timeout
                ini_set('default_socket_timeout', $originalTimeout);
            }
        });
    }
    
    /**
     * Check if domain redirects (HTTP redirect detection)
     * 
     * @param string $domain
     * @return array ['is_redirect' => bool, 'redirect_to' => string?, 'status_code' => int?]
     */
    public function checkDomainRedirect(string $domain): array
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
     * 
     * @param string $domain
     * @return array ['available' => bool]
     */
    public function checkDomainAvailability(string $domain): array
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

    /**
     * Check if domain is a public email provider (Gmail, Yahoo, etc.)
     * 
     * @param string $domain
     * @param array $mxRecords
     * @return array|null Provider config or null
     */
    public function isPublicProvider(string $domain, array $mxRecords): ?array
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
     * Check if MX server should be skipped
     * 
     * @param string $mxHost
     * @return bool
     */
    public function shouldSkipMxServer(string $mxHost): bool
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
     * 
     * @param string $mxHost
     * @param string $reason
     * @param string|null $response
     * @return void
     */
    public function addMxToSkipList(string $mxHost, string $reason = 'SMTP connection failed', ?string $response = null): void
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
     * 
     * @param string $domain
     * @return bool
     */
    public function checkUnsupportedDomain(string $domain): bool
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
     * 
     * @param string $domain
     * @return bool
     */
    public function shouldSkipCatchAllCheck(string $domain): bool
    {
        $skipDomains = config('email-verification.catch_all_skip_domains', []);
        $domainLower = strtolower($domain);
        
        return in_array($domainLower, $skipDomains, true);
    }

    /**
     * Generate random email address for catch-all testing (AfterShip method)
     * Uses 32 alphanumeric characters like AfterShip does
     * 
     * @param string $domain
     * @return string
     */
    public function generateRandomEmail(string $domain): string
    {
        $characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $randomString = '';
        $length = 32;
        
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, strlen($characters) - 1)];
        }
        
        return $randomString . '@' . $domain;
    }

    /**
     * Get MX cache TTL
     * 
     * @return int
     */
    private function getMxCacheTtl(): int
    {
        return config('email-verification.mx_cache_ttl', 3600);
    }
}

