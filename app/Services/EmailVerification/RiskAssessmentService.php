<?php

namespace App\Services\EmailVerification;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RiskAssessmentService
{
    public function __construct(
        private \App\Services\EmailVerification\DomainValidationService $domainValidationService
    ) {
    }

    /**
     * Check if account contains no-reply keywords
     * 
     * @param string $account
     * @return bool
     */
    public function checkNoReply(string $account): bool
    {
        $keywords = $this->getNoReplyKeywords();
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

    /**
     * Check if domain is a typo domain
     * 
     * @param string $domain
     * @return bool
     */
    public function checkTypoDomain(string $domain): bool
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
     * Get the correct domain for a typo domain
     * Returns the corrected domain if typo is detected, null otherwise
     * 
     * @param string $domain
     * @return string|null
     */
    public function getTypoCorrection(string $domain): ?string
    {
        $domainLower = strtolower($domain);
        
        // 1. Check known typo domains list with corrections (if config has mapping)
        $typoCorrections = config('email-verification.typo_corrections', []);
        if (isset($typoCorrections[$domainLower])) {
            return $typoCorrections[$domainLower];
        }
        
        // 2. Automatic typo detection using fuzzy matching (if enabled)
        if (config('email-verification.enable_automatic_typo_detection', true)) {
            return $this->findTypoCorrection($domainLower);
        }
        
        return null;
    }
    
    /**
     * Find the correct domain for a typo using fuzzy matching
     * Returns the corrected domain if typo is detected, null otherwise
     * 
     * @param string $domain
     * @return string|null
     */
    private function findTypoCorrection(string $domain): ?string
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
            return null;
        }
        
        // Smart check: Only return correction if domain is suspicious typo
        // If domain is valid (exists, has MX records), it might be a real domain
        if ($this->isDomainSuspiciousTypo($domain, $bestMatch, $bestSimilarity)) {
            return $bestMatch;
        }
        
        return null;
    }
    
    /**
     * Automatically detect typo domains using fuzzy matching
     * Compares domain against known public provider domains
     * Smart logic: Only marks as typo if domain is invalid or suspicious
     * 
     * @param string $domain
     * @return bool
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
     * 
     * @param string $domain
     * @param string $providerDomain
     * @param float $similarity
     * @return bool
     */
    private function isDomainSuspiciousTypo(string $domain, string $providerDomain, float $similarity): bool
    {
        // 1. Check if domain has valid MX records
        $mxRecords = $this->domainValidationService->getMxRecords($domain);
        $hasMxRecords = !empty($mxRecords);
        
        // 2. Check domain validity (DNS resolution)
        $domainValidity = $this->domainValidationService->checkDomainValidity($domain);
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
     * 
     * @return array
     */
    private function getPublicProviderDomains(): array
    {
        $cacheKey = 'public_provider_domains_list';
        $ttl = 3600; // 1 hour cache
        
        return Cache::remember($cacheKey, $ttl, function () {
            $domains = [];
            
            // Get domains from public_providers config (structured providers with MX patterns)
            $providers = config('email-verification.public_providers', []);
            foreach ($providers as $providerConfig) {
                $providerDomains = $providerConfig['domains'] ?? [];
                $domains = array_merge($domains, $providerDomains);
            }
            
            // Get domains from free-email-providers config (imported from Go email-verifier)
            $freeEmailProviders = config('free-email-providers.domains', []);
            $domains = array_merge($domains, $freeEmailProviders);
            
            return array_unique($domains);
        });
    }
    
    /**
     * Check if domain is a free email provider (by domain name only, no MX check)
     * This is a simpler check used for early free flag setting
     * 
     * @param string $domain
     * @return bool
     */
    public function isFreeEmailProviderByDomain(string $domain): bool
    {
        $domainLower = strtolower($domain);
        $publicProviderDomains = $this->getPublicProviderDomains();
        
        return in_array($domainLower, $publicProviderDomains, true);
    }
    
    /**
     * Check if domain is likely a typo (not just similar by chance)
     * Uses common typo patterns and domain structure analysis
     * 
     * @param string $domain
     * @param string $providerDomain
     * @return bool
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
     * 
     * @param string $domain
     * @return string
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

    /**
     * Check if domain is ISP/ESP domain
     * 
     * @param string $domain
     * @return bool
     */
    public function checkIspEspDomain(string $domain): bool
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

    /**
     * Check if domain has government TLD
     * 
     * @param string $domain
     * @return bool
     */
    public function checkGovernmentTld(string $domain): bool
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
     * Get typo suggestions for email address
     * Returns corrected email if typo is detected
     * 
     * @param string $email
     * @return string|null Corrected email address or null if no typo detected
     */
    public function getTypoSuggestions(string $email): ?string
    {
        $emailParser = app(\App\Services\EmailVerification\EmailParserService::class);
        $parts = $emailParser->parseEmail($email);
        if (!$parts) {
            return null;
        }
        
        $domain = $parts['domain'];
        $localPart = $parts['account'];
        
        // First check if we already have typo correction from existing method
        $typoCorrection = $this->getTypoCorrection($domain);
        if ($typoCorrection) {
            return $localPart . '@' . $typoCorrection;
        }
        
        // Common domain typo corrections (static list for fast lookup)
        $typoCorrections = [
            'gmial.com' => 'gmail.com',
            'gmal.com' => 'gmail.com',
            'gamil.com' => 'gmail.com',
            'gmai.com' => 'gmail.com',
            'gmail.co' => 'gmail.com',
            'gmail.cm' => 'gmail.com',
            'gmail.om' => 'gmail.com',
            'gmail.con' => 'gmail.com',
            'yaho.com' => 'yahoo.com',
            'yahooo.com' => 'yahoo.com',
            'yahoo.co' => 'yahoo.com',
            'yahoo.cm' => 'yahoo.com',
            'hotmai.com' => 'hotmail.com',
            'hotmal.com' => 'hotmail.com',
            'hotmail.co' => 'hotmail.com',
            'hotmail.cm' => 'hotmail.com',
            'otmail.com' => 'hotmail.com',
            'outlook.co' => 'outlook.com',
            'outlook.cm' => 'outlook.com',
            'outlok.com' => 'outlook.com',
        ];
        
        $domainLower = strtolower($domain);
        if (isset($typoCorrections[$domainLower])) {
            return $localPart . '@' . $typoCorrections[$domainLower];
        }
        
        return null;
    }

    /**
     * Get no-reply keywords from config
     * 
     * @return array
     */
    private function getNoReplyKeywords(): array
    {
        return config('email-verification.no_reply_keywords', []);
    }

    /**
     * Get typo domains from config
     * 
     * @return array
     */
    private function getTypoDomains(): array
    {
        return config('email-verification.typo_domains', []);
    }

    /**
     * Get ISP/ESP domains from config
     * 
     * @return array
     */
    private function getIspEspDomains(): array
    {
        return config('email-verification.isp_esp_domains', []);
    }

    /**
     * Get government TLDs from config
     * 
     * @return array
     */
    private function getGovernmentTlds(): array
    {
        return config('email-verification.government_tlds', []);
    }

    public function getRiskChecks(): array
    {
        return config('email-verification.risk_checks', [
            'no_reply_status' => 'do_not_mail',
            'typo_domain_status' => 'spamtrap',
            'isp_esp_status' => 'do_not_mail',
            'government_tld_status' => 'risky',
            'enable_typo_check' => true,
            'enable_isp_esp_check' => true,
            'enable_government_check' => true,
        ]);
    }
}

