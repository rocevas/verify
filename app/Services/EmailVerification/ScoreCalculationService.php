<?php

namespace App\Services\EmailVerification;

class ScoreCalculationService
{
    /**
     * Calculate verification score based on checks
     * Multiplicative scoring system matching Emailable's approach:
     * - Base score: 100
     * - Each factor is a multiplier (0.95x, 0.94x, 0.5x, etc.)
     * - Final score = 100 * factor1 * factor2 * factor3...
     * - Risky emails: score 1-80, Good emails: score 80-100
     * 
     * @param array $checks
     * @param array $context Optional context data (email, domain, etc.) for dynamic adjustments
     * @return int Score from 0 to 100
     */
    public function calculateScore(array $checks, array $context = []): int
    {
        $multipliers = $this->getScoreMultipliers();

        // If any high-risk check fails, score is 0
        if ($checks['no_reply'] ?? false) {
            return 0; // No-reply keywords = 0
        }
        // Typo domains: Only return 0 if domain is invalid (doesn't exist)
        if (($checks['typo_domain'] ?? false) && !($checks['domain_validity'] ?? false)) {
            return 0; // Typo domain that doesn't exist = 0
        }
        if ($checks['isp_esp'] ?? false) {
            return 0; // ISP/ESP domains = 0
        }
        if ($checks['blacklist'] ?? false) {
            return 0; // Blacklisted emails = 0
        }

        // Base checks (required for any score)
        if (!($checks['syntax'] ?? false)) {
            return 0; // No syntax = no score
        }

        // Domain validity check (DNS resolution)
        if (!($checks['domain_validity'] ?? false)) {
            return 0; // Domain doesn't exist = 0 score
        }

        // MX records check - CRITICAL: Without MX records, email cannot be delivered
        // Even if domain exists and has implicit MX (A record), explicit MX is preferred
        // For now, if no MX records at all, score should be 0
        // If implicit MX exists (A record), we might give very low score (10-20), but for now keep 0
        if (!($checks['mx_record'] ?? false)) {
            // Check if implicit MX exists (domain has A record but no MX records)
            // Implicit MX means domain will accept mail, but it's less reliable
            $hasImplicitMx = $context['implicit_mx_record'] ?? false;
            if ($hasImplicitMx) {
                // Domain has A record but no MX records - very low score
                // Some domains use A record as fallback, but it's less reliable
                $multipliers = $this->getScoreMultipliers();
                return $multipliers['implicit_mx_score'] ?? 10; // Very low score for implicit MX only
            }
            // No MX records and no implicit MX = cannot receive mail = 0 score
            return 0;
        }

        // Start with base score from config
        $baseScore = $multipliers['base_score'] ?? 100.0;
        $score = (float) $baseScore;

        // Apply multipliers in order of priority

        // 1. Domain-specific multipliers
        $domain = $context['domain'] ?? null;
        if ($domain) {
            // Check for specific domain multipliers (e.g., yahoo.com, hotmail.com = 0.9x)
            $domainMultiplier = $this->getDomainMultiplier($domain, $multipliers);
            if ($domainMultiplier !== null) {
                $score *= $domainMultiplier;
            }
        }

        // 2. Free email provider (0.95x)
        if ($checks['free'] ?? $checks['is_free'] ?? false) {
            $score *= $multipliers['free'] ?? 0.95;
        }

        // 3. Disposable email (0.05x - very low score ~5)
        if (($checks['disposable'] ?? false) && ($checks['domain_validity'] ?? false)) {
            $score *= $multipliers['disposable'] ?? 0.05;
        }

        // 4. Typo domain (very low score ~4-5)
        // Emailable: typo@hotmial.com = 4 (0.9x domain * 0.05x disposable equivalent)
        if (($checks['typo_domain'] ?? false) && ($checks['domain_validity'] ?? false)) {
            // If domain multiplier already applied, typo gets additional penalty
            // Formula: base * domain_multiplier * typo_penalty ≈ 4-5
            $typoMultiplier = $multipliers['typo_domain'] ?? 0.05;
            // If disposable also true, use disposable multiplier (more severe)
            if ($checks['disposable'] ?? false) {
                $typoMultiplier = $multipliers['disposable'] ?? 0.05;
            }
            $score *= $typoMultiplier;
        }

        // 5. Role-based email (0.7x - oranžinis minusinis)
        if ($checks['role'] ?? false) {
            $score *= $multipliers['role'] ?? 0.7;
        }

        // 6. Catch-all / Accept-All (0.6x - oranžinis minusinis)
        // BUT: Free providers with catch-all are treated differently (no penalty)
        // Emailable: Free providers (Gmail, Yahoo, etc.) with catch-all get 85-93 score (no catch-all penalty)
        if ($checks['catch_all'] ?? false) {
            // Only apply catch-all penalty if NOT a free provider
            // Free providers with catch-all are acceptable (they're designed to be catch-all)
            if (!($checks['free'] ?? $checks['is_free'] ?? false)) {
                $score *= $multipliers['catch_all'] ?? 0.6;
            }
        }

        // 7. Mailbox Full (0.5x - oranžinis/raudonas minusinis)
        if ($checks['mailbox_full'] ?? false) {
            $score *= $multipliers['mailbox_full'] ?? 0.5;
        }

        // 8. Tag/Alias (0.95x)
        if (!empty($context['alias_of'])) {
            $score *= $multipliers['alias'] ?? 0.95;
        }

        // 9. Numerical characters multiplier
        // Formula: 0.98x per character (1 char = 0.98, 3 chars = 0.94)
        // Emailable: 1 char = 0.98x, 3 chars = 0.94x
        $numericalChars = $context['numerical_characters'] ?? 0;
        if ($numericalChars > 0) {
            // Calculate multiplier: base 1.0, subtract penalty per character
            // 1 char: 1.0 - 0.02*1 = 0.98
            // 3 chars: 1.0 - 0.02*3 = 0.94
            $minMultiplier = $multipliers['numerical_char_min_multiplier'] ?? 0.85;
            $penaltyPerChar = $multipliers['numerical_char_per_penalty'] ?? 0.02;
            $numericalMultiplier = max($minMultiplier, 1.0 - ($numericalChars * $penaltyPerChar));
            $score *= $numericalMultiplier;
        }

        // 10. Alphabetical characters multiplier (0.98x for 4 chars)
        // Emailable: 4 alphabetical chars = 0.98x
        $alphabeticalChars = $context['alphabetical_characters'] ?? 0;
            if ($alphabeticalChars > 0 && $alphabeticalChars <= 10) {
                // Small penalty for low alphabetical character count
                // 4 chars = 0.98x, meaning ~0.005 per char under threshold
                if ($alphabeticalChars <= 4) {
                    $minMultiplier = $multipliers['alphabetical_char_min_multiplier'] ?? 0.95;
                    $penaltyPerChar = $multipliers['alphabetical_char_per_penalty'] ?? 0.005;
                    $alphabeticalMultiplier = 1.0 - ((5 - $alphabeticalChars) * $penaltyPerChar);
                    $score *= max($minMultiplier, $alphabeticalMultiplier);
                }
            }

        // 11. Other/Unknown factors (0.8x - oranžinis minusinis)
        // This might be applied for certain domain patterns or unknown issues
        // Emailable shows "Other: 0.8x" for some emails
        if ($checks['other'] ?? false) {
            $score *= $multipliers['other'] ?? 0.8;
        }

        // Convert to integer and clamp between min and max scores from config
        $minScore = $multipliers['min_score'] ?? 0;
        $maxScore = $multipliers['max_score'] ?? 100;
        return max($minScore, min($maxScore, (int)round($score)));
    }

    /**
     * Get domain-specific multiplier
     * Some domains have specific multipliers (e.g., yahoo.com, hotmail.com = 0.9x)
     * 
     * @param string $domain
     * @param array $multipliers
     * @return float|null
     */
    private function getDomainMultiplier(string $domain, array $multipliers): ?float
    {
        $domainMultipliers = $multipliers['domains'] ?? [];
        
        // Check exact domain match
        if (isset($domainMultipliers[$domain])) {
            return $domainMultipliers[$domain];
        }
        
        // Check for common free providers
        $commonFreeDomains = ['yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com'];
        if (in_array($domain, $commonFreeDomains)) {
            return $multipliers['free_domain'] ?? 0.9;
        }
        
        return null;
    }

    /**
     * Get score multipliers from config
     * Multiplicative scoring system matching Emailable:
     * - Base score: 100
     * - Each factor multiplies the score
     * - Risky: 1-80, Good: 80-100
     * 
     * @return array
     */
    public function getScoreMultipliers(): array
    {
        return config('email-verification.score_multipliers', [
            // Free email provider multiplier
            'free' => 0.95, // 95% - Free emails get 5% penalty
            
            // Disposable email multiplier (very severe)
            'disposable' => 0.05, // 5% - Disposable emails get 95% penalty (score ~5)
            
            // Typo domain multiplier (very severe, similar to disposable)
            'typo_domain' => 0.05, // 5% - Typo domains get 95% penalty
            
            // Role-based email multiplier (oranžinis minusinis)
            'role' => 0.7, // 70% - Role-based emails get 30% penalty
            
            // Catch-all / Accept-All multiplier (oranžinis minusinis)
            'catch_all' => 0.6, // 60% - Catch-all servers get 40% penalty
            
            // Mailbox full multiplier (oranžinis/raudonas minusinis)
            'mailbox_full' => 0.5, // 50% - Mailbox full gets 50% penalty
            
            // Tag/Alias multiplier
            'alias' => 0.95, // 95% - Tags/aliases get 5% penalty
            
            // Numerical characters penalty per character
            'numerical_char_per_penalty' => 0.02, // -2% per numerical character (1 char = 0.98x, 3 chars = 0.94x)
            
            // Alphabetical characters penalty (for low counts)
            'alphabetical_char_per_penalty' => 0.005, // -0.5% per char under threshold
            
            // Other/Unknown factors multiplier (oranžinis minusinis)
            'other' => 0.8, // 80% - Other issues get 20% penalty
            
            // Free domain multiplier (yahoo.com, hotmail.com, etc.)
            'free_domain' => 0.9, // 90% - Specific free domains get 10% penalty
            
            // Domain-specific multipliers
            'domains' => [
                'yahoo.com' => 0.9,
                'hotmail.com' => 0.9,
                'outlook.com' => 0.9,
                'aol.com' => 0.9,
                // Add more domain-specific multipliers as needed
            ],
            
            // Legacy weights for backward compatibility (if needed)
            'legacy_weights' => [
                'syntax' => 20,
                'domain_validity' => 20,
                'mx_record' => 25,
                'smtp' => 20,
                'gravatar_bonus' => 5,
                'dmarc_reject_bonus' => 10,
                'dmarc_quarantine_bonus' => 5,
                'government_tld_penalty' => 10,
            ],
        ]);
    }

    /**
     * Get score weights from config (legacy method for backward compatibility)
     * 
     * @return array
     */
    public function getScoreWeights(): array
    {
        $multipliers = $this->getScoreMultipliers();
        return $multipliers['legacy_weights'] ?? [];
    }
}

