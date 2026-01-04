<?php

namespace App\Services\EmailVerification;

class ScoreCalculationService
{
    /**
     * Calculate verification score based on checks
     * Improved scoring system inspired by Go email-verifier:
     * - More balanced weights (not too dependent on SMTP)
     * - Domain validity included in score
     * - Better handling of public providers (SMTP often unavailable)
     * - Dynamic scoring with fractional weights for more nuanced results
     * 
     * @param array $checks
     * @param array $context Optional context data (email, domain, etc.) for dynamic adjustments
     * @return int Score from 0 to 100
     */
    public function calculateScore(array $checks, array $context = []): int
    {
        $weights = $this->getScoreWeights();
        $score = 0; // Use integer for cleaner scoring

        // If any high-risk check fails, score is 0
        if ($checks['no_reply'] ?? false) {
            return 0; // No-reply keywords = 0
        }
        if ($checks['typo_domain'] ?? false) {
            return 0; // Typo domains = 0
        }
        if ($checks['isp_esp'] ?? false) {
            return 0; // ISP/ESP domains = 0
        }
        if ($checks['blacklist'] ?? false) {
            return 0; // Blacklisted emails = 0
        }

        // Base checks (required for any score)
        if ($checks['syntax'] ?? false) {
            $score += $weights['syntax'] ?? 20;
        } else {
            // No syntax = no score
            return 0;
        }

        // Domain validity check (DNS resolution)
        if ($checks['domain_validity'] ?? false) {
            $score += $weights['domain_validity'] ?? 20;
        } else {
            // Domain doesn't exist = very low score
            return max(0, $score);
        }

        // MX records check
        if ($checks['mx_record'] ?? false) {
            $score += $weights['mx_record'] ?? 25;
        }

        // SMTP check (optional, often unavailable for public providers)
        if ($checks['smtp'] ?? false) {
            $score += $weights['smtp'] ?? 20;
        }
        // Note: If SMTP is not checked (public providers), score can still be high
        // if domain_validity and mx_record pass (base score: 20 + 20 + 25 = 65, plus disposable + role = 85)

        // Disposable email check (negative check - add points if NOT disposable)
        if (!($checks['disposable'] ?? false)) {
            $score += $weights['disposable'] ?? 10;
        } else {
            $score = 0; // Disposable emails get 0
            return 0;
        }

        // Role-based email bonus (adds points if NOT role-based, matches Go behavior)
        if (!($checks['role'] ?? false)) {
            $score += $weights['role_bonus'] ?? 10;
        }

        // Mailbox full penalty (significant penalty - email cannot receive mail)
        if ($checks['mailbox_full'] ?? false) {
            $score -= $weights['mailbox_full_penalty'] ?? 30;
        }

        // Free email provider penalty (small penalty - free emails can be less reliable)
        if ($checks['free'] ?? $checks['is_free'] ?? false) {
            $freePenalty = $weights['free_email_penalty'] ?? 0;
            if ($freePenalty > 0) {
                $score -= $freePenalty;
            }
        }

        // Government TLD penalty (reduces score but doesn't zero it)
        if ($checks['government_tld'] ?? false) {
            $score -= $weights['government_tld_penalty'] ?? 10;
        }

        // Clamp score between 0 and 100
        return max(0, min(100, $score));
    }

/**
     * Get score weights from config
     * Uses round numbers for cleaner scoring
     * 
     * @return array
     */
    public function getScoreWeights(): array
    {
        return config('email-verification.score_weights', [
            'syntax' => 20,
            'domain_validity' => 20,
            'mx_record' => 25, // Increased to make base score 85 without SMTP
            'smtp' => 20,
            'disposable' => 10,
            'role_bonus' => 10,
            'mailbox_full_penalty' => 30,
            'free_email_penalty' => 0,
            'gravatar_bonus' => 5,
            'dmarc_reject_bonus' => 10,
            'dmarc_quarantine_bonus' => 5,
            'government_tld_penalty' => 10,
        ]);
    }
}

