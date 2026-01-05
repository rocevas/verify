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
            'reason' => $result['reason'] ?? $result['result'] ?? null, // Emailable API format (primary)
            'result' => $result['result'] ?? $result['reason'] ?? null, // Backward compatibility
            'account' => $result['account'] ?? null,
            'domain' => $result['domain'] ?? null,
            'score' => $result['score'] ?? 0,
            'email_score' => $result['score'] ?? 0, // Alias for backward compatibility
            'duration' => $result['duration'] ?? null,

            // Checks flattened into main response
            'syntax' => $checks['syntax'] ?? false,
            'domain_validity' => $checks['domain_validity'] ?? false,
            'mx_record' => $checks['mx_record'] ?? false, // Boolean check
            'mx_record_string' => $result['mx_record_string'] ?? null, // MX record hostname string
            'smtp' => $checks['smtp'] ?? false,
            'disposable' => $checks['disposable'] ?? false,
            'role' => $checks['role'] ?? false,
            'no_reply' => $checks['no_reply'] ?? false,
            'typo_domain' => $checks['typo_domain'] ?? false,

            // Alias and typo suggestions
            'alias_of' => $result['alias_of'] ?? null,
            'did_you_mean' => $result['did_you_mean'] ?? $result['typoSuggestion'] ?? $result['typo_suggestion'] ?? null,
            'free' => $result['free'] ?? $result['is_free'] ?? false,
            'mailbox_full' => $result['mailbox_full'] ?? false,
            'catch_all' => $result['catch_all'] ?? false,

            // Email attributes
            'numerical_characters' => $result['numerical_characters'] ?? 0,
            'alphabetical_characters' => $result['alphabetical_characters'] ?? 0,
            'unicode_symbols' => $result['unicode_symbols'] ?? 0,

            // MX and SMTP information
            'implicit_mx_record' => $result['implicit_mx_record'] ?? false,
            'smtp_provider' => $result['smtp_provider'] ?? null,
            'secure_email_gateway' => $result['secure_email_gateway'] ?? false,
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
                'result' => $result['result'] ?? null, // Backward compatibility
                'reason' => $result['reason'] ?? $result['result'] ?? null, // Emailable API format
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
                'alias_of' => $result['alias_of'] ?? null,
                'numerical_characters' => $result['numerical_characters'] ?? 0,
                'alphabetical_characters' => $result['alphabetical_characters'] ?? 0,
                'unicode_symbols' => $result['unicode_symbols'] ?? 0,
                'mx_record_string' => $result['mx_record_string'] ?? null,
                'smtp_provider' => $result['smtp_provider'] ?? null,
                'implicit_mx_record' => $result['implicit_mx_record'] ?? false,
                'secure_email_gateway' => $result['secure_email_gateway'] ?? false,
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
     * Determine state and result/reason based on checks and current status
     * Following Emailable API format: https://help.emailable.com/en-us/article/verification-results-all-possible-states-and-reasons-fjsjn2/
     *
     * @param array $result
     * @return array ['state' => string, 'result' => string|null, 'reason' => string|null]
     */
    public function determineStateAndResult(array $result): array
    {
        $currentStatus = $result['status'] ?? 'unknown';
        $error = $result['error'] ?? null;

        // Helper function to return state, result (backward compat), and reason
        $makeResult = function($state, $resultValue, $emailableReason) {
            return [
                'state' => $state,
                'result' => $resultValue, // Kept for backward compatibility (our old format)
                'reason' => $emailableReason, // Emailable API format
            ];
        };

        // Check for syntax error first (highest priority)
        // Emailable: invalid_email - The email address does not pass syntax validations
        if (!($result['syntax'] ?? false)) {
            return $makeResult('undeliverable', 'syntax_error', 'invalid_email');
        }

        // Check for typo domain
        // Emailable behavior:
        // - If typo domain doesn't exist: invalid_domain (0 score)
        // - If typo domain exists: low_deliverability (4-5 score)
        if ($result['typo_domain'] ?? false) {
            if (!($result['domain_validity'] ?? false)) {
                // Typo domain that doesn't exist = invalid_domain
                return $makeResult('undeliverable', 'typo', 'invalid_domain');
            } else {
                // Typo domain that exists = low_deliverability (score 4-5)
                return $makeResult('risky', 'typo', 'low_deliverability');
            }
        }

        // Check for disposable email
        // Emailable behavior:
        // - If disposable domain doesn't exist: invalid_domain (0 score)
        // - If disposable domain exists: low_deliverability (5 score)
        if ($result['disposable'] ?? false) {
            if (!($result['domain_validity'] ?? false)) {
                // Disposable domain that doesn't exist = invalid_domain
                return $makeResult('undeliverable', 'disposable', 'invalid_domain');
            } else {
                // Disposable domain that exists = low_deliverability (score 5)
                // Will be handled by scoring logic below
                // Continue processing to calculate score
            }
        }

        // Check for blacklist/blocked
        // Emailable: invalid_domain - Should not be mailed to
        if ($result['blacklist'] ?? false || $currentStatus === 'spamtrap' || $currentStatus === 'abuse' || $currentStatus === 'do_not_mail') {
            return $makeResult('undeliverable', 'blocked', 'invalid_domain');
        }

        // Check for mailbox full
        // Emailable: low_deliverability - The email address appears to be deliverable, but deliverability cannot be guaranteed (Risky)
        if ($result['mailbox_full'] ?? false) {
            return $makeResult('risky', 'mailbox_full', 'low_deliverability');
        }

        // Check for role-based email (after other checks, don't return early)
        // Emailable: low_quality - The email address has quality issues (Risky)
        // Role-based emails with valid domains get ~64-70 score, not 0
        // Don't return early - let scoring logic handle it, then check at end

        // Check for valid email (SMTP verified or high confidence)
        // Emailable: accepted_email - The email address exists and is deliverable
        if ($result['smtp'] ?? false) {
            return $makeResult('deliverable', 'valid', 'accepted_email');
        }

        // PRIORITY: Free providers with catch-all are treated as accepted_email (deliverable) with score 85-93
        // This must be checked BEFORE general catch-all check
        // Emailable: Free providers (Gmail, Yahoo, Outlook) with catch-all = accepted_email (deliverable)
        if (($result['mx_record'] ?? false) &&
            ($result['domain_validity'] ?? false) &&
            ($result['status'] ?? null) === 'valid' &&
            ($result['free'] ?? $result['is_free'] ?? false) &&
            ($result['catch_all'] ?? false) &&
            ($result['score'] ?? 0) >= 80) { // Only if score >= 80 (Emailable treats >= 80 as deliverable)
            return $makeResult('deliverable', 'valid', 'accepted_email');
        }

        // Check for catch-all (only if NOT free provider or score < 80)
        // Emailable: low_deliverability - The email address appears to be deliverable, but deliverability cannot be guaranteed (Risky)
        // This applies to non-free catch-all domains or free providers with low score (< 80)
        if ($result['catch_all'] ?? false) {
            // For free providers with score < 80, still risky
            // For non-free providers with catch-all, risky
            return $makeResult('risky', 'catch_all', 'low_deliverability');
        }

        // Non-free catch-all with valid domain = low_deliverability (risky)
        if (($result['mx_record'] ?? false) &&
            ($result['domain_validity'] ?? false) &&
            $currentStatus === 'valid' &&
            !($result['free'] ?? false)) {
            return $makeResult('deliverable', 'valid', 'accepted_email');
        }

        // Check for invalid domain or no MX records
        // Emailable: invalid_domain - The email address domain does not exist
        if (!($result['mx_record'] ?? false) || !($result['domain_validity'] ?? false)) {
            if ($currentStatus === 'invalid') {
                return $makeResult('undeliverable', 'mailbox_not_found', 'invalid_domain');
            }
        }

        // Check for connection/timeout errors FIRST (before rejected_email check)
        // Emailable: timeout, no_connect, unavailable_smtp
        // These should take priority over rejected_email because they indicate server issues, not mailbox non-existence
        if ($error) {
            $errorLower = strtolower($error);
            if (str_contains($errorLower, 'timeout')) {
                return $makeResult('unknown', null, 'timeout');
            }
            if (str_contains($errorLower, 'unavailable') || 
                str_contains($errorLower, 'server was unavailable') ||
                str_contains($errorLower, 'smtp unavailable') ||
                str_contains($errorLower, 'unavailable smtp')) {
                return $makeResult('unknown', null, 'unavailable_smtp');
            }
            if (str_contains($errorLower, 'connection') || 
                str_contains($errorLower, 'could not connect') || 
                str_contains($errorLower, 'connect') ||
                str_contains($errorLower, 'connection refused') ||
                str_contains($errorLower, 'connection timed out')) {
                return $makeResult('unknown', null, 'no_connect');
            }
        }

        // Check for SMTP rejection (mailbox doesn't exist) - only if no error was detected above
        // Emailable: rejected_email - The email address was rejected by the mail server because it does not exist (0 score)
        // SMTP check was performed but failed (mailbox doesn't exist), even though domain is valid
        // This should only be used when SMTP check failed without connection/timeout/unavailable errors
        if (($result['smtp'] ?? false) === false &&
            ($result['mx_record'] ?? false) &&
            ($result['domain_validity'] ?? false) &&
            !($result['catch_all'] ?? false) &&
            !($result['typo_domain'] ?? false) &&
            !($result['disposable'] ?? false)) {
            
            // If error exists, it's connection/timeout/unavailable issue (already handled above)
            if ($error) {
                // Already handled by error check above, don't return rejected_email
                // Just continue to fallback
            } else {
                // No error means SMTP was checked but mailbox doesn't exist = rejected_email (0 score)
                return $makeResult('undeliverable', 'mailbox_not_found', 'rejected_email');
            }
        }

        // Check for unexpected errors
        // Emailable: unexpected_error - An unexpected error occurred
        if ($error && $currentStatus === 'unknown') {
            return $makeResult('unknown', 'error', 'unexpected_error');
        }

        // Check for role-based email (after other priority checks)
        // Emailable: low_quality - The email address has quality issues (Risky)
        // Role-based emails with valid domains get ~64-70 score (LOW QUALITY)
        if (($result['role'] ?? false) &&
            ($result['domain_validity'] ?? false) &&
            ($result['mx_record'] ?? false) &&
            !($result['smtp'] ?? false)) {
            // Role-based email with valid domain but SMTP not passed = low_quality (~64-70 score)
            return $makeResult('risky', 'role', 'low_quality');
        }

        // Default: unknown (no specific reason)
        if ($currentStatus === 'unknown') {
            return $makeResult('unknown', null, null);
        }

        // Fallback: map old status to new format
        $isCatchAll = $result['catch_all'] ?? false;
        $state = match($currentStatus) {
            'valid' => 'deliverable',
            'invalid', 'spamtrap', 'abuse', 'do_not_mail' => 'undeliverable',
            'risky', 'catch_all' => 'risky',
            default => 'unknown',
        };
        // Map old result values to Emailable reasons
        $resultValue = match($currentStatus) {
            'valid' => 'valid',
            'invalid' => 'mailbox_not_found',
            'spamtrap', 'abuse', 'do_not_mail' => 'blocked',
            'risky' => 'risky',
            'catch_all' => $isCatchAll ? 'catch_all' : 'risky',
            default => null,
        };
        // Map to Emailable reason format
        $emailableReason = match($currentStatus) {
            'valid' => 'accepted_email',
            'invalid' => 'invalid_domain',
            'spamtrap', 'abuse', 'do_not_mail' => 'invalid_domain',
            'risky' => 'low_quality',
            'catch_all' => $isCatchAll ? 'low_deliverability' : 'low_quality',
            default => null,
        };
        return $makeResult($state, $resultValue, $emailableReason);
    }
}

