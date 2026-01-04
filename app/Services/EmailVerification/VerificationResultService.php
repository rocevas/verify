<?php

namespace App\Services\EmailVerification;

use App\Models\EmailVerification;
use App\Services\MetricsService;
use Illuminate\Support\Facades\Log;

class VerificationResultService
{
    public function __construct(
        private MetricsService $metricsService
    ) {
    }

    public function getErrorMessages(): array
    {
        return config('email-verification.error_messages', [
            'invalid_format' => 'Invalid email format',
            'invalid_syntax' => 'Invalid email syntax',
            'no_mx_records' => 'No MX records found',
            'blacklisted' => 'Blacklisted: :reason:notes',
        ]);
    }

    public function getBlacklistStatusMap(): array
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

    public function getStatusRules(): array
    {
        return config('email-verification.status_rules', [
            'smtp_valid' => 'valid',
            'min_score_for_valid' => 85,
            'min_score_for_catch_all' => 50,
            'role_emails_status' => 'risky',
            'non_role_emails_status' => 'catch_all',
            'default_invalid' => 'invalid',
        ]);
    }

    /**
     * Format verification result for API response
     * 
     * @param array $result
     * @return array
     */
    public function formatResponse(array $result): array
    {
        $checks = $result['checks'] ?? [];
        
        // Flatten checks into main response
        $formatted = [
            'email' => $result['email'] ?? '',
            'state' => $result['state'] ?? 'unknown',
            'result' => $result['result'] ?? null,
            'account' => $result['account'] ?? null,
            'domain' => $result['domain'] ?? null,
            'score' => $result['score'] ?? 0,
            'email_score' => $result['score'] ?? 0, // Alias for backward compatibility
            'duration' => $result['duration'] ?? null,
            
            // Checks flattened into main response
            'syntax' => $checks['syntax'] ?? false,
            'domain_validity' => $checks['domain_validity'] ?? false,
            'mx_record' => $checks['mx_record'] ?? false,
            'smtp' => $checks['smtp'] ?? false,
            'disposable' => $checks['disposable'] ?? false,
            'role' => $checks['role'] ?? false,
            'no_reply' => $checks['no_reply'] ?? false,
            'typo_domain' => $checks['typo_domain'] ?? false,
            
            // Alias and typo suggestions
            'alias' => $result['aliasOf'] ?? $result['alias_of'] ?? null,
            'did_you_mean' => $result['did_you_mean'] ?? $result['typoSuggestion'] ?? $result['typo_suggestion'] ?? null,
            'free' => $result['free'] ?? $result['is_free'] ?? false,
            'mailbox_full' => $result['mailbox_full'] ?? false,
            'catch_all' => $result['catch_all'] ?? false,
        ];
        
        // Add Gravatar fields if present
        if (isset($result['gravatar'])) {
            $formatted['gravatar'] = $result['gravatar'];
            if (isset($result['gravatar_url'])) {
                $formatted['gravatar_url'] = $result['gravatar_url'];
            }
        }
        
        // Add DMARC fields if present
        if (isset($result['dmarc'])) {
            $formatted['dmarc'] = $result['dmarc'];
            if (isset($result['dmarc_confidence_boost'])) {
                $formatted['dmarc_confidence_boost'] = $result['dmarc_confidence_boost'];
            }
        }
        
        // Add VRFY/EXPN verification method if present
        if (isset($result['verification_method'])) {
            $formatted['verification_method'] = $result['verification_method'];
        }
        if (isset($result['smtp_confidence'])) {
            $formatted['smtp_confidence'] = $result['smtp_confidence'];
        }
        
        
        // Add checks array for compatibility
        if (isset($result['checks'])) {
            $formatted['checks'] = $result['checks'];
        }

        // Only include error if present
        if (!empty($result['error'])) {
            $formatted['error'] = $result['error'];
        }

        // Add optional AI fields if present
        if (isset($result['ai_confidence'])) {
            $formatted['ai_confidence'] = $result['ai_confidence'];
        }
        if (isset($result['ai_insights'])) {
            $formatted['ai_insights'] = $result['ai_insights'];
        }

        return $formatted;
    }

    /**
     * Record metrics for verification
     * 
     * @param array $result
     * @param float $startTime
     * @return void
     */
    public function recordMetrics(array $result, float $startTime): void
    {
        try {
            $duration = $result['duration'] ?? (microtime(true) - $startTime);
            $status = $result['status'] ?? 'unknown';
            
            $this->metricsService->recordVerification($status, $duration);
            
            if (isset($result['score'])) {
                $this->metricsService->recordScore($result['score'], $status);
            }
            
            if (isset($result['smtp']) && $result['smtp'] !== null) {
                $smtpDuration = $result['smtp_duration'] ?? null;
                $this->metricsService->recordSmtpCheck($result['smtp'], $smtpDuration);
            }
        } catch (\Exception $e) {
            // Don't fail verification if metrics recording fails
            Log::debug('Failed to record metrics', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Save verification result to database
     * 
     * @param array $result
     * @param int|null $userId
     * @param int|null $teamId
     * @param int|null $tokenId
     * @param array $parts
     * @param int|null $bulkJobId
     * @param string|null $source
     * @return void
     */
    public function saveVerification(array $result, ?int $userId, ?int $teamId, ?int $tokenId, array $parts, ?int $bulkJobId = null, ?string $source = null): void
    {
        try {
            $verification = EmailVerification::create([
                'user_id' => $userId,
                'team_id' => $teamId,
                'api_key_id' => $tokenId,
                'bulk_verification_job_id' => $bulkJobId,
                'source' => $source,
                'email' => $result['email'],
                'account' => $parts['account'] ?? null,
                'domain' => $parts['domain'] ?? null,
                'state' => $result['state'] ?? 'unknown',
                'result' => $result['result'] ?? null,
                'syntax' => $result['syntax'] ?? false,
                'mx_record' => $result['mx_record'] ?? false,
                'smtp' => $result['smtp'] ?? false,
                'disposable' => $result['disposable'] ?? false,
                'role' => $result['role'] ?? false,
                'no_reply' => $result['no_reply'] ?? false,
                'typo_domain' => $result['typo_domain'] ?? false,
                'mailbox_full' => $result['mailbox_full'] ?? false,
                'is_free' => $result['is_free'] ?? $result['free'] ?? false,
                'blacklist' => $result['blacklist'] ?? false,
                'domain_validity' => $result['domain_validity'] ?? false,
                'isp_esp' => $result['isp_esp'] ?? false,
                'government_tld' => $result['government_tld'] ?? false,
                'gravatar' => $result['gravatar'] ?? false,
                'ai_analysis' => $result['ai_analysis'] ?? false,
                'ai_insights' => $result['ai_insights'] ?? null,
                'ai_confidence' => $result['ai_confidence'] ?? null,
                'ai_risk_factors' => $result['ai_risk_factors'] ?? null,
                'did_you_mean' => $result['did_you_mean'] ?? $result['typo_suggestion'] ?? $result['typoSuggestion'] ?? null,
                'alias_of' => $result['alias_of'] ?? $result['aliasOf'] ?? null,
                'email_score' => $result['score'] ?? null,
                'score' => null, // Will be calculated by AI service if AI is used
                'duration' => $result['duration'] ?? null,
                'verified_at' => now(),
            ]);
            
            // Only log in debug mode to reduce log verbosity
            if (config('app.debug')) {
                Log::debug('Email verification saved', [
                    'id' => $verification->id,
                    'email' => $result['email'],
                    'state' => $result['state'] ?? 'unknown',
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to save email verification', [
                'email' => $result['email'] ?? 'unknown',
                'user_id' => $userId,
                'team_id' => $teamId,
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 500),
            ]);
            // Don't throw - we still want to return the result
        }
    }

    /**
     * Calculate and add duration to result
     * 
     * @param array $result
     * @param float $startTime
     * @return void
     */
    public function addDuration(array &$result, float $startTime): void
    {
        $endTime = microtime(true);
        $result['duration'] = round($endTime - $startTime, 2); // Duration in seconds (rounded to 2 decimal places)
    }
    
    /**
     * Determine state and result based on checks and current status
     * Following Emailable API format: https://help.emailable.com/en-us/article/verification-results-all-possible-states-and-reasons-fjsjn2/
     * 
     * @param array $result
     * @return array ['state' => string, 'result' => string|null]
     */
    public function determineStateAndResult(array $result): array
    {
        $currentStatus = $result['status'] ?? 'unknown';
        $error = $result['error'] ?? null;
        
        // Check for syntax error first (highest priority)
        if (!($result['syntax'] ?? false)) {
            return [
                'state' => 'undeliverable',
                'result' => 'syntax_error',
            ];
        }
        
        // Check for typo domain
        if ($result['typo_domain'] ?? false) {
            return [
                'state' => 'undeliverable',
                'result' => 'typo',
            ];
        }
        
        // Check for disposable email
        if ($result['disposable'] ?? false) {
            return [
                'state' => 'undeliverable',
                'result' => 'disposable',
            ];
        }
        
        // Check for blacklist/blocked
        if ($result['blacklist'] ?? false || $currentStatus === 'spamtrap' || $currentStatus === 'abuse' || $currentStatus === 'do_not_mail') {
            return [
                'state' => 'undeliverable',
                'result' => 'blocked',
            ];
        }
        
        // Check for mailbox full
        if ($result['mailbox_full'] ?? false) {
            return [
                'state' => 'risky',
                'result' => 'mailbox_full',
            ];
        }
        
        // Check for role-based email
        if ($result['role'] ?? false) {
            return [
                'state' => 'risky',
                'result' => 'role',
            ];
        }
        
        // Check for catch-all (only if actually detected, not just based on score or status)
        // Only return catch_all if the catch_all flag is actually true
        if ($result['catch_all'] ?? false) {
            return [
                'state' => 'risky',
                'result' => 'catch_all',
            ];
        }
        
        // Check for valid email (SMTP verified or high confidence)
        if ($result['smtp'] ?? false) {
            return [
                'state' => 'deliverable',
                'result' => 'valid',
            ];
        }
        
        // If MX records exist and domain is valid, but SMTP not checked (public providers)
        if (($result['mx_record'] ?? false) && ($result['domain_validity'] ?? false) && $currentStatus === 'valid') {
            return [
                'state' => 'deliverable',
                'result' => 'valid',
            ];
        }
        
        // Check for invalid domain or no MX records
        if (!($result['mx_record'] ?? false) || !($result['domain_validity'] ?? false)) {
            if ($currentStatus === 'invalid') {
                return [
                    'state' => 'undeliverable',
                    'result' => 'mailbox_not_found',
                ];
            }
        }
        
        // Check for connection/timeout errors
        if ($error && (
            str_contains(strtolower($error), 'timeout') ||
            str_contains(strtolower($error), 'connection') ||
            str_contains(strtolower($error), 'unavailable') ||
            str_contains(strtolower($error), 'could not connect')
        )) {
            return [
                'state' => 'unknown',
                'result' => null,
            ];
        }
        
        // Check for unexpected errors
        if ($error && $currentStatus === 'unknown') {
            return [
                'state' => 'error',
                'result' => 'error',
            ];
        }
        
        // Default: unknown
        if ($currentStatus === 'unknown') {
            return [
                'state' => 'unknown',
                'result' => null,
            ];
        }
        
        // Fallback: map old status to new format
        // Only map 'catch_all' status to catch_all result if catch_all flag is true
        $isCatchAll = $result['catch_all'] ?? false;
        return [
            'state' => match($currentStatus) {
                'valid' => 'deliverable',
                'invalid', 'spamtrap', 'abuse', 'do_not_mail' => 'undeliverable',
                'risky', 'catch_all' => 'risky',
                default => 'unknown',
            },
            'result' => match($currentStatus) {
                'valid' => 'valid',
                'invalid' => 'mailbox_not_found',
                'spamtrap', 'abuse', 'do_not_mail' => 'blocked',
                'risky' => 'risky', // Changed: risky status should map to risky result, not catch_all
                'catch_all' => $isCatchAll ? 'catch_all' : 'risky', // Only catch_all if flag is true
                default => null,
            },
        ];
    }
}

