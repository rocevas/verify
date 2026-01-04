<?php

namespace App\Services;

use App\Models\EmailVerification;
use App\Services\EmailVerification\DomainValidationService;
use App\Services\EmailVerification\EmailAttributeService;
use App\Services\EmailVerification\EmailParserService;
use App\Services\EmailVerification\GravatarService;
use App\Services\EmailVerification\RiskAssessmentService;
use App\Services\EmailVerification\ScoreCalculationService;
use App\Services\EmailVerification\SmtpVerificationService;
use App\Services\EmailVerification\VerificationResultService;
use Illuminate\Support\Facades\Log;

class EmailVerificationService
{
    public function __construct(
        private EmailParserService $emailParserService,
        private DomainValidationService $domainValidationService,
        private RiskAssessmentService $riskAssessmentService,
        private SmtpVerificationService $smtpVerificationService,
        private ScoreCalculationService $scoreCalculationService,
        private VerificationResultService $verificationResultService,
        private EmailAttributeService $emailAttributeService,
        private MetricsService $metricsService
    ) {
    }




    public function verify(string $email, ?int $userId = null, ?int $teamId = null, ?int $tokenId = null, ?int $bulkJobId = null, ?string $source = null): array
    {
        // Start timing
        $startTime = microtime(true);

        $result = [
            'email' => $email,
            'status' => 'unknown',
            'account' => null,
            'domain' => null,
            'checks' => [
                'syntax' => false,
                'blacklist' => false,
                'domain_validity' => false,
                'mx_record' => false,
                'smtp' => false,
                'disposable' => false,
                'role' => false,
                'no_reply' => false,
                'typo_domain' => false,
                'isp_esp' => false,
                'government_tld' => false,
                'mailbox_full' => false,
                'free' => false,
                'is_free' => false,
            ],
            'score' => 0,
            'error' => null,
            'free' => false,
            'mailbox_full' => false,
        ];

        $parts = null;

        try {
            // Parse email
            $parts = $this->emailParserService->parseEmail($email);
            if (!$parts) {
                $result['status'] = 'invalid';
                $result['error'] = $this->verificationResultService->getErrorMessages()['invalid_format'];
                $result['score'] = 0;
                // Save even invalid emails for tracking
                $this->verificationResultService->addDuration($result, $startTime);
                // Determine state and result before saving
                $stateAndResult = $this->verificationResultService->determineStateAndResult($result);
                $result['state'] = $stateAndResult['state'];
                $result['result'] = $stateAndResult['result'];
                $result['reason'] = $stateAndResult['reason'] ?? $stateAndResult['result'];
                $this->verificationResultService->saveVerification($result, $userId, $teamId, $tokenId, ['account' => null, 'domain' => null], $bulkJobId, $source);
                return $this->verificationResultService->formatResponse($result);
            }

            $result['account'] = $parts['account'];
            $result['domain'] = $parts['domain'];

            // 0.4. Email attributes analysis (Emailable format: numerical, alphabetical, unicode characters)
            $emailAttributes = $this->emailAttributeService->analyzeEmailAttributes($email);
            $result['numerical_characters'] = $emailAttributes['numerical_characters'];
            $result['alphabetical_characters'] = $emailAttributes['alphabetical_characters'];
            $result['unicode_symbols'] = $emailAttributes['unicode_symbols'];

            // 0.5. Email alias detection (before other checks)
            $aliasOf = $this->emailParserService->detectAlias($email);
            if ($aliasOf && $aliasOf !== $email) {
                $result['alias_of'] = $aliasOf;
            }

            // 0.6. Typo suggestions (check for typos even if syntax is valid)
            $typoSuggestion = $this->riskAssessmentService->getTypoSuggestions($email);
            if ($typoSuggestion && $typoSuggestion !== $email) {
                $result['typo_suggestion'] = $typoSuggestion;
                $result['typoSuggestion'] = $typoSuggestion; // Keep for backward compatibility
                // Also set did_you_mean for backward compatibility
                if (empty($result['did_you_mean'])) {
                    $result['did_you_mean'] = $typoSuggestion;
                }
            }

            // 1. Syntax check
            $syntaxCheck = $this->emailParserService->checkSyntax($email);
            $result['syntax'] = $syntaxCheck;
            $result['checks']['syntax'] = $syntaxCheck; // Update checks array
            if (!$syntaxCheck) {
                // Set free flag early (by domain name only)
                $result['is_free'] = $this->riskAssessmentService->isFreeEmailProviderByDomain($parts['domain']);
                $result['free'] = $result['is_free']; // Keep for backward compatibility
                $result['checks']['free'] = $result['free']; // Add to checks for score calculation
                $result['checks']['is_free'] = $result['is_free']; // Also add is_free for compatibility

                $result['status'] = 'invalid';
                $result['error'] = $this->verificationResultService->getErrorMessages()['invalid_syntax'];
                $result['score'] = 0;
                $this->verificationResultService->addDuration($result, $startTime);
                // Determine state and result before saving
                $stateAndResult = $this->verificationResultService->determineStateAndResult($result);
                $result['state'] = $stateAndResult['state'];
                $result['result'] = $stateAndResult['result'];
                $result['reason'] = $stateAndResult['reason'] ?? $stateAndResult['result'];
                $this->verificationResultService->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);
                return $this->verificationResultService->formatResponse($result);
            }

            // 2. Blacklist check
            $blacklist = \App\Models\Blacklist::isBlacklisted($email);
            if ($blacklist) {
                $statusMap = $this->verificationResultService->getBlacklistStatusMap();
                $result['status'] = $statusMap[$blacklist->reason] ?? 'do_not_mail';
                $errorTemplate = $this->verificationResultService->getErrorMessages()['blacklisted'];
                $notes = $blacklist->notes ? " - {$blacklist->notes}" : '';
                $result['error'] = str_replace([':reason', ':notes'], [$blacklist->reason, $notes], $errorTemplate);
                $result['score'] = 0;
                $result['blacklist'] = true;
                $result['checks']['blacklist'] = true; // Update checks array
                $this->verificationResultService->addDuration($result, $startTime);
                $this->verificationResultService->addDuration($result, $startTime);
                // Determine state and result before saving
                $stateAndResult = $this->verificationResultService->determineStateAndResult($result);
                $result['state'] = $stateAndResult['state'];
                $result['result'] = $stateAndResult['result'];
                $result['reason'] = $stateAndResult['reason'] ?? $stateAndResult['result'];
                $this->verificationResultService->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);
                return $this->verificationResultService->formatResponse($result);
            }
            $result['blacklist'] = false;
            $result['checks']['blacklist'] = false; // Update checks array

            // 2.5. No-reply keywords check (synthetic addresses / list poisoning)
            $noReplyCheck = $this->riskAssessmentService->checkNoReply($parts['account']);
            $result['no_reply'] = $noReplyCheck;
            $result['checks']['no_reply'] = $noReplyCheck; // Update checks array
            if ($noReplyCheck) {
                $riskChecks = $this->riskAssessmentService->getRiskChecks();
                $result['status'] = $riskChecks['no_reply_status'] ?? 'do_not_mail';
                $result['score'] = 0;
                $this->verificationResultService->addDuration($result, $startTime);
                // Determine state and result before saving
                $stateAndResult = $this->verificationResultService->determineStateAndResult($result);
                $result['state'] = $stateAndResult['state'];
                $result['result'] = $stateAndResult['result'];
                $result['reason'] = $stateAndResult['reason'] ?? $stateAndResult['result'];
                $this->verificationResultService->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);
                return $this->verificationResultService->formatResponse($result);
            }

            // 2.6. Typo domain check (spam trap domains)
            // Emailable behavior:
            // - If typo domain doesn't exist: invalid_domain (0 score) - return early
            // - If typo domain exists: low_deliverability (4-5 score) - continue processing
            $riskChecks = $this->riskAssessmentService->getRiskChecks();
            if ($riskChecks['enable_typo_check'] ?? true) {
                $typoCheck = $this->riskAssessmentService->checkTypoDomain($parts['domain']);
                $result['typo_domain'] = $typoCheck;
                $result['checks']['typo_domain'] = $typoCheck; // Update checks array

                if ($typoCheck) {
                    // Get the corrected domain
                    $correctedDomain = $this->riskAssessmentService->getTypoCorrection($parts['domain']);
                    if ($correctedDomain) {
                        // Build the corrected email address
                        $result['did_you_mean'] = $parts['account'] . '@' . $correctedDomain;
                    }

                    // Check if domain is valid (exists) - if not, return early with 0 score
                    // Domain validity check happens later, so we'll check it here first
                    $domainValidity = $this->domainValidationService->checkDomainValidity($parts['domain']);
                    if (!$domainValidity['valid']) {
                        // Typo domain that doesn't exist = invalid_domain (0 score)
                        $result['status'] = $riskChecks['typo_domain_status'] ?? 'spamtrap';
                        $result['score'] = 0;
                        $result['error'] = 'Typo domain detected (domain does not exist)';
                        $result['domain_validity'] = false;
                        $this->verificationResultService->addDuration($result, $startTime);
                        // Determine state and result before saving
                        $stateAndResult = $this->verificationResultService->determineStateAndResult($result);
                        $result['state'] = $stateAndResult['state'];
                        $result['result'] = $stateAndResult['result'];
                        $result['reason'] = $stateAndResult['reason'] ?? $stateAndResult['result'];
                        $this->verificationResultService->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);
                        return $this->verificationResultService->formatResponse($result);
                    }
                    // If typo domain exists, continue processing (will get low_deliverability with 4-5 score)
                }
            }

            // 2.7. ISP/ESP infrastructure domain check
            if ($riskChecks['enable_isp_esp_check'] ?? true) {
                $result['isp_esp'] = $this->riskAssessmentService->checkIspEspDomain($parts['domain']);
                $result['checks']['isp_esp'] = $result['isp_esp']; // Update checks array
                if ($result['isp_esp']) {
                    $result['status'] = $riskChecks['isp_esp_status'] ?? 'do_not_mail';
                    $result['score'] = 0;
                    $result['error'] = 'ISP/ESP infrastructure domain (not for marketing)';
                    $this->verificationResultService->addDuration($result, $startTime);
                    // Determine state and result before saving
                    $stateAndResult = $this->verificationResultService->determineStateAndResult($result);
                    $result['state'] = $stateAndResult['state'];
                    $result['result'] = $stateAndResult['result'];
                    $result['reason'] = $stateAndResult['reason'] ?? $stateAndResult['result'];
                    $this->verificationResultService->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);
                    return $this->verificationResultService->formatResponse($result);
                }
            }

            // 2.8. Government/registry TLD check
            if ($riskChecks['enable_government_check'] ?? true) {
                $result['government_tld'] = $this->riskAssessmentService->checkGovernmentTld($parts['domain']);
                $result['checks']['government_tld'] = $result['government_tld']; // Update checks array
                if ($result['government_tld']) {
                    $result['status'] = $riskChecks['government_tld_status'] ?? 'risky';
                    // Don't return early - just mark as risky and continue
                }
            }

            // 3. Disposable email check
            // Emailable behavior:
            // - If disposable domain doesn't exist: invalid_domain (0 score) - return early
            // - If disposable domain exists: low_deliverability (5 score) - continue processing
            $disposableCheck = $this->emailParserService->checkDisposable($parts['domain']);
            $result['disposable'] = $disposableCheck;
            $result['checks']['disposable'] = $disposableCheck; // Update checks array
            if ($disposableCheck) {
                // Set free flag early (by domain name only)
                $result['free'] = $this->riskAssessmentService->isFreeEmailProviderByDomain($parts['domain']);
                $result['is_free'] = $result['free']; // Keep for backward compatibility
                $result['checks']['free'] = $result['free']; // Add to checks for score calculation
                $result['checks']['is_free'] = $result['is_free']; // Also add is_free for compatibility

                // Check if domain is valid (exists) - if not, return early with 0 score
                // Domain validity check happens later, so we'll check it here first
                $domainValidity = $this->domainValidationService->checkDomainValidity($parts['domain']);
                if (!$domainValidity['valid']) {
                    // Disposable domain that doesn't exist = invalid_domain (0 score)
                    $result['status'] = 'do_not_mail';
                    $result['score'] = 0;
                    $result['domain_validity'] = false;
                    $this->verificationResultService->addDuration($result, $startTime);
                    // Determine state and result before saving
                    $stateAndResult = $this->verificationResultService->determineStateAndResult($result);
                    $result['state'] = $stateAndResult['state'];
                    $result['result'] = $stateAndResult['result'];
                    $result['reason'] = $stateAndResult['reason'] ?? $stateAndResult['result'];
                    $this->verificationResultService->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);
                    return $this->verificationResultService->formatResponse($result);
                }
                // If disposable domain exists, continue processing (will get low_deliverability with 5 score)
            }

            // 3.5. Unsupported domain check
            if ($this->domainValidationService->checkUnsupportedDomain($parts['domain'])) {
                $result['status'] = config('email-verification.unsupported_domain_status', 'skipped');
                $result['score'] = 0;
                $result['error'] = 'Domain does not support SMTP verification';
                $this->verificationResultService->addDuration($result, $startTime);
                // Determine state and result before saving
                $stateAndResult = $this->verificationResultService->determineStateAndResult($result);
                $result['state'] = $stateAndResult['state'];
                $result['result'] = $stateAndResult['result'];
                $result['reason'] = $stateAndResult['reason'] ?? $stateAndResult['result'];
                $this->verificationResultService->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);
                return $this->verificationResultService->formatResponse($result);
            }

            // 3. Role-based email check
            $roleCheck = $this->emailParserService->checkRoleBased($parts['account']);
            $result['role'] = $roleCheck;
            $result['checks']['role'] = $roleCheck; // Update checks array
            if ($roleCheck) {
                $result['status'] = 'risky';
            }

            // 3.9. Domain validity check (DNS resolution, redirect detection, availability)
            $domainValidity = $this->domainValidationService->checkDomainValidity($parts['domain']);
            if (!$domainValidity['valid']) {
                $result['status'] = $domainValidity['status'] ?? 'invalid';
                $result['error'] = $domainValidity['error'] ?? 'Domain does not exist or is not accessible';
                $result['score'] = 0;
                $result['domain_validity'] = false;
                $this->verificationResultService->addDuration($result, $startTime);
                // Determine state and result before saving
                $stateAndResult = $this->verificationResultService->determineStateAndResult($result);
                $result['state'] = $stateAndResult['state'];
                $result['result'] = $stateAndResult['result'];
                $result['reason'] = $stateAndResult['reason'] ?? $stateAndResult['result'];
                $this->verificationResultService->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);
                return $this->verificationResultService->formatResponse($result);
            }
            $result['domain_validity'] = true;
            $result['checks']['domain_validity'] = true; // Update checks array

            // 4. MX check
            $mxCheck = $this->domainValidationService->checkMx($parts['domain']);
            $mxRecords = $this->domainValidationService->getMxRecords($parts['domain']);
            $result['mx_record'] = $mxCheck;
            $result['checks']['mx_record'] = $mxCheck; // Update checks array

            // 4.1. MX Record string (Emailable format - first MX record)
            $result['mx_record_string'] = !empty($mxRecords) ? $mxRecords[0]['host'] : null;

            // 4.2. Implicit MX check
            $result['implicit_mx_record'] = $this->emailAttributeService->checkImplicitMx($parts['domain']);

            // 4.3. Secure Email Gateway check
            $result['secure_email_gateway'] = $this->emailAttributeService->checkSecureEmailGateway($mxRecords);

            // 4.4. SMTP Provider detection (Emailable format - initial detection from MX)
            $result['smtp_provider'] = $this->emailAttributeService->detectSmtpProvider($mxRecords);

            if (!$mxCheck) {
                $result['status'] = 'invalid';
                $result['error'] = $this->verificationResultService->getErrorMessages()['no_mx_records'];
                // Don't add disposable and role_bonus if no MX records (matches Go behavior)
                // Go skriptas: syntax (20) + domain_exists (20) = 40 (ne 60)
                $checksForScore = $result['checks'];
                unset($checksForScore['disposable'], $checksForScore['role']); // Remove disposable and role from score calculation
                $result['score'] = $this->scoreCalculationService->calculateScore($checksForScore, [
                    'email' => $email,
                    'domain' => $parts['domain'],
                    'mx_records' => [],
                    'numerical_characters' => $result['numerical_characters'] ?? 0,
                    'alphabetical_characters' => $result['alphabetical_characters'] ?? 0,
                    'alias_of' => $result['alias_of'] ?? null,
                    'implicit_mx_record' => $result['implicit_mx_record'] ?? false, // Pass implicit MX info
                ]);
                $this->verificationResultService->addDuration($result, $startTime);
                // Determine state and result before saving
                $stateAndResult = $this->verificationResultService->determineStateAndResult($result);
                $result['state'] = $stateAndResult['state'];
                $result['result'] = $stateAndResult['result'];
                $result['reason'] = $stateAndResult['reason'] ?? $stateAndResult['result'];
                $this->verificationResultService->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);
                return $this->verificationResultService->formatResponse($result);
            }

            // 4.5. Public provider check (before SMTP check)
            // Note: mxRecords already fetched above at line 305
            $publicProvider = $this->domainValidationService->isPublicProvider($parts['domain'], $mxRecords);

            // Set free flag if domain is a public provider (free email provider)
            $result['is_free'] = $publicProvider !== null;
            $result['free'] = $result['is_free']; // Keep for backward compatibility
            $result['checks']['free'] = $result['free']; // Add to checks for score calculation
            $result['checks']['is_free'] = $result['is_free']; // Also add is_free for compatibility

            if ($publicProvider && ($publicProvider['skip_smtp'] ?? false)) {
                // Skip SMTP check for public providers
                // Public providers (Gmail, Yahoo, etc.) are catch-all servers
                // Emailable: Free providers with catch-all are treated as accepted_email (deliverable) with score 85-93
                if ($result['mx_record']) {
                    // Public providers are catch-all, but for free providers this is acceptable
                    // Emailable treats these as accepted_email (deliverable) with score 85-93
                    $result['status'] = 'valid'; // Valid status for free catch-all providers
                    $result['catch_all'] = true; // Set catch-all flag
                    $result['smtp'] = false; // Not checked (public providers block SMTP checks)
                    $result['checks']['smtp'] = false; // Update checks array
                    $result['mailbox_full'] = false; // Not checked (public providers are assumed to have space)
                    $result['checks']['mailbox_full'] = false; // Add to checks for score calculation

                    // Calculate score with multiplicative system
                    // Free providers with catch-all: 100 * 0.95 (free) * 0.98-1.0 (numbers) = 85-95
                    // But Emailable shows 85-93, so catch-all penalty should not apply for free providers
                    $result['score'] = $this->scoreCalculationService->calculateScore($result['checks'], [
                        'email' => $email,
                        'domain' => $parts['domain'],
                        'mx_records' => $mxRecords,
                        'numerical_characters' => $result['numerical_characters'] ?? 0,
                        'alphabetical_characters' => $result['alphabetical_characters'] ?? 0,
                        'alias_of' => $result['alias_of'] ?? null,
                    ]);

                    // Check Gravatar for catch-all emails (helps determine if email likely exists)
                    if (config('email-verification.enable_gravatar_check', true)) {
                        try {
                            $gravatarService = app(GravatarService::class);
                            $gravatar = $gravatarService->checkGravatar($email);

                            if ($gravatar['has_gravatar'] ?? false) {
                                // Email has Gravatar - more likely to exist (active user)
                                $multipliers = $this->scoreCalculationService->getScoreMultipliers();
                                $weights = $multipliers['legacy_weights'] ?? [];
                                $gravatarBonus = $weights['gravatar_bonus'] ?? 5;
                                $minScore = $multipliers['min_score'] ?? 0;
                                $maxScore = $multipliers['max_score'] ?? 100;
                                $result['score'] = min($maxScore, max($minScore, $result['score'] + $gravatarBonus));
                                $result['gravatar'] = true;
                                $result['gravatar_url'] = $gravatar['gravatar_url'] ?? null;
                            } else {
                                $result['gravatar'] = false;
                            }
                        } catch (\Exception $e) {
                            // Fail gracefully - if Gravatar check fails, just continue without it
                            Log::debug('Gravatar check failed for catch-all email', [
                                'email' => $email,
                                'error' => $e->getMessage(),
                            ]);
                            $result['gravatar'] = false;
                        }
                    }

                    // Check DMARC for catch-all emails (helps determine email confidence)
                    // If DMARC policy = "reject" → more likely email is real
                    // If DMARC policy = "quarantine" → somewhat likely email is real
                    if (config('email-verification.enable_dmarc_check', true)) {
                        try {
                            $dmarcService = app(DmarcCheckService::class);
                            $dmarcResult = $dmarcService->checkDomain($parts['domain']);

                            if (!$dmarcResult['has_issue'] && isset($dmarcResult['details']['parsed'])) {
                                $parsed = $dmarcResult['details']['parsed'];
                                $dmarcPolicy = $parsed['p'] ?? null;

                                // Store DMARC info
                                $result['dmarc'] = [
                                    'policy' => $dmarcPolicy,
                                    'record' => $dmarcResult['details']['record'] ?? null,
                                    'parsed' => $parsed,
                                ];

                                // Add confidence boost based on DMARC policy
                                $multipliers = $this->scoreCalculationService->getScoreMultipliers();
                                $weights = $multipliers['legacy_weights'] ?? [];
                                $dmarcBonus = 0;

                                if ($dmarcPolicy === 'reject') {
                                    // DMARC reject policy = more likely email is real (strict security)
                                    $dmarcBonus = $weights['dmarc_reject_bonus'] ?? 10;
                                    Log::debug('DMARC reject policy detected - higher confidence', [
                                        'email' => $email,
                                        'domain' => $parts['domain'],
                                    ]);
                                } elseif ($dmarcPolicy === 'quarantine') {
                                    // DMARC quarantine policy = somewhat likely email is real
                                    $dmarcBonus = $weights['dmarc_quarantine_bonus'] ?? 5;
                                    Log::debug('DMARC quarantine policy detected - moderate confidence', [
                                        'email' => $email,
                                        'domain' => $parts['domain'],
                                    ]);
                                } elseif ($dmarcPolicy === 'none') {
                                    // DMARC none policy = no additional confidence
                                    $dmarcBonus = 0;
                                    Log::debug('DMARC none policy detected - no confidence boost', [
                                        'email' => $email,
                                        'domain' => $parts['domain'],
                                    ]);
                                }

                                if ($dmarcBonus > 0) {
                                    $minScore = $multipliers['min_score'] ?? 0;
                                    $maxScore = $multipliers['max_score'] ?? 100;
                                    $result['score'] = min($maxScore, max($minScore, $result['score'] + $dmarcBonus));
                                    $result['dmarc_confidence_boost'] = $dmarcBonus;
                                }
                            } else {
                                // DMARC check failed or no record - no confidence boost
                                $result['dmarc'] = [
                                    'policy' => null,
                                    'error' => $dmarcResult['message'] ?? 'DMARC check failed',
                                ];
                                Log::debug('DMARC check failed or no record', [
                                    'email' => $email,
                                    'domain' => $parts['domain'],
                                    'error' => $dmarcResult['message'] ?? 'Unknown error',
                                ]);
                            }
                        } catch (\Exception $e) {
                            // Fail gracefully - if DMARC check fails, just continue without it
                            Log::debug('DMARC check failed for catch-all email', [
                                'email' => $email,
                                'domain' => $parts['domain'],
                                'error' => $e->getMessage(),
                            ]);
                            $result['dmarc'] = [
                                'policy' => null,
                                'error' => $e->getMessage(),
                            ];
                        }
                    }

                    $result['error'] = null; // Clear any errors
                    $this->verificationResultService->addDuration($result, $startTime);
                    // Determine state and result before saving
                    $stateAndResult = $this->verificationResultService->determineStateAndResult($result);
                    $result['state'] = $stateAndResult['state'];
                    $result['result'] = $stateAndResult['result'];
                    $result['reason'] = $stateAndResult['reason'] ?? $stateAndResult['result'];
                    $this->verificationResultService->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);
                    return $this->verificationResultService->formatResponse($result);
                }
            }

            // Calculate score before SMTP check (for faster response if SMTP fails)
            $result['score'] = $this->scoreCalculationService->calculateScore($result['checks'], [
                'email' => $email,
                'domain' => $parts['domain'],
                'mx_records' => $mxRecords,
                'numerical_characters' => $result['numerical_characters'] ?? 0,
                'alphabetical_characters' => $result['alphabetical_characters'] ?? 0,
                'alias_of' => $result['alias_of'] ?? null,
            ]);

            // 5. SMTP check (AfterShip method: catch-all check first, then specific email if not catch-all)
            // Only perform if enabled in config and rate limit allows
            if ($this->smtpVerificationService->isSmtpCheckEnabled()) {
                try {
                    // Check rate limit before performing SMTP check
                    if ($this->smtpVerificationService->checkSmtpRateLimit($parts['domain'])) {
                        $smtpResult = $this->smtpVerificationService->checkSmtpWithDetails($email, $parts['domain']);
                        $smtpCheck = $smtpResult['valid'];
                        $isCatchAll = $smtpResult['catch_all'] ?? false;

                        $result['smtp'] = $smtpCheck;
                        $result['checks']['smtp'] = $smtpCheck; // Update checks array
                        $result['mailbox_full'] = $smtpResult['mailbox_full'] ?? false;
                        $result['checks']['mailbox_full'] = $result['mailbox_full']; // Add to checks for score calculation
                        $result['catch_all'] = $isCatchAll; // Store catch-all status
                        
                        // Store error message if SMTP check failed (for unavailable/timeout/no_connect detection)
                        // Note: Don't set error if SMTP failed due to mailbox rejection (550)
                        // That should be detected as rejected_email, not unavailable_smtp
                        if (!$smtpCheck) {
                            // Only set error if it's a connection/timeout issue, not mailbox rejection
                            // If error is set in smtpResult, it's likely connection/timeout issue
                            if (isset($smtpResult['error'])) {
                                $result['error'] = $smtpResult['error'];
                            }
                            // If no error, SMTP check might have failed due to mailbox rejection (550)
                            // Don't set error - let determineStateAndResult detect it as rejected_email
                        }

                        // Store VRFY/EXPN verification method and confidence if available
                        if (isset($smtpResult['verification_method'])) {
                            $result['verification_method'] = $smtpResult['verification_method'];
                        }
                        if (isset($smtpResult['confidence'])) {
                            $result['smtp_confidence'] = $smtpResult['confidence'];
                        }

                        // Update SMTP Provider detection with actual SMTP host
                        if (isset($smtpResult['smtp_host'])) {
                            $result['smtp_provider'] = $this->emailAttributeService->detectSmtpProvider($mxRecords, $smtpResult['smtp_host']);
                        }

                        // If catch-all detected, handle it (AfterShip method)
                        if ($isCatchAll) {
                            $result['status'] = config('email-verification.catch_all_status', 'catch_all');

                            // Check Gravatar for catch-all emails (helps determine if email likely exists)
                            if (config('email-verification.enable_gravatar_check', true)) {
                                try {
                                    $gravatarService = app(GravatarService::class);
                                    $gravatar = $gravatarService->checkGravatar($email);

                                    if ($gravatar['has_gravatar'] ?? false) {
                                        // Email has Gravatar - more likely to exist (active user)
                                        $weights = $this->scoreCalculationService->getScoreWeights();
                                        $gravatarBonus = $weights['gravatar_bonus'] ?? 5;
                                        $result['score'] = min(100, max(0, $result['score'] + $gravatarBonus)); // Clamp to 0-100
                                        $result['gravatar'] = true;
                                        $result['gravatar_url'] = $gravatar['gravatar_url'] ?? null;
                                    } else {
                                        $result['gravatar'] = false;
                                    }
                                } catch (\Exception $e) {
                                    // Fail gracefully - if Gravatar check fails, just continue without it
                                    Log::debug('Gravatar check failed for catch-all email', [
                                        'email' => $email,
                                        'error' => $e->getMessage(),
                                    ]);
                                    $result['gravatar'] = false;
                                }
                            }

                            // Check DMARC for catch-all emails (helps determine email confidence)
                            // If DMARC policy = "reject" → more likely email is real
                            // If DMARC policy = "quarantine" → somewhat likely email is real
                            if (config('email-verification.enable_dmarc_check', true)) {
                                try {
                                    $dmarcService = app(DmarcCheckService::class);
                                    $dmarcResult = $dmarcService->checkDomain($parts['domain']);

                                    if (!$dmarcResult['has_issue'] && isset($dmarcResult['details']['parsed'])) {
                                        $parsed = $dmarcResult['details']['parsed'];
                                        $dmarcPolicy = $parsed['p'] ?? null;

                                        // Store DMARC info
                                        $result['dmarc'] = [
                                            'policy' => $dmarcPolicy,
                                            'record' => $dmarcResult['details']['record'] ?? null,
                                            'parsed' => $parsed,
                                        ];

                                        // Add confidence boost based on DMARC policy
                                        $weights = $this->scoreCalculationService->getScoreWeights();
                                        $dmarcBonus = 0;

                                        if ($dmarcPolicy === 'reject') {
                                            // DMARC reject policy = more likely email is real (strict security)
                                            $dmarcBonus = $weights['dmarc_reject_bonus'] ?? 10;
                                            Log::debug('DMARC reject policy detected - higher confidence', [
                                                'email' => $email,
                                                'domain' => $parts['domain'],
                                            ]);
                                        } elseif ($dmarcPolicy === 'quarantine') {
                                            // DMARC quarantine policy = somewhat likely email is real
                                            $dmarcBonus = $weights['dmarc_quarantine_bonus'] ?? 5;
                                            Log::debug('DMARC quarantine policy detected - moderate confidence', [
                                                'email' => $email,
                                                'domain' => $parts['domain'],
                                            ]);
                                        } elseif ($dmarcPolicy === 'none') {
                                            // DMARC none policy = no additional confidence
                                            $dmarcBonus = 0;
                                            Log::debug('DMARC none policy detected - no confidence boost', [
                                                'email' => $email,
                                                'domain' => $parts['domain'],
                                            ]);
                                        }

                                        if ($dmarcBonus > 0) {
                                            $result['score'] = min(100, max(0, $result['score'] + $dmarcBonus)); // Clamp to 0-100
                                            $result['dmarc_confidence_boost'] = $dmarcBonus;
                                        }
                                    } else {
                                        // DMARC check failed or no record - no confidence boost
                                        $result['dmarc'] = [
                                            'policy' => null,
                                            'error' => $dmarcResult['message'] ?? 'DMARC check failed',
                                        ];
                                        Log::debug('DMARC check failed or no record', [
                                            'email' => $email,
                                            'domain' => $parts['domain'],
                                            'error' => $dmarcResult['message'] ?? 'Unknown error',
                                        ]);
                                    }
                                } catch (\Exception $e) {
                                    // Fail gracefully - if DMARC check fails, just continue without it
                                    Log::debug('DMARC check failed for catch-all email', [
                                        'email' => $email,
                                        'domain' => $parts['domain'],
                                        'error' => $e->getMessage(),
                                    ]);
                                    $result['dmarc'] = [
                                        'policy' => null,
                                        'error' => $e->getMessage(),
                                    ];
                                }
                            }

                            // Recalculate score after Gravatar and DMARC checks (if applicable)
                            // Catch-all servers get penalty: base 85 - catch_all_penalty 20 = 65
                            // But with mailbox_full or other factors, can be lower (e.g., 60)
                            $result['score'] = $this->scoreCalculationService->calculateScore($result['checks'], [
                                'email' => $email,
                                'domain' => $parts['domain'],
                                'mx_records' => $mxRecords,
                                'verification_method' => $result['verification_method'] ?? null,
                                'numerical_characters' => $result['numerical_characters'] ?? 0,
                                'alphabetical_characters' => $result['alphabetical_characters'] ?? 0,
                                'alias_of' => $result['alias_of'] ?? null,
                            ]);

                            $result['error'] = 'Catch-all server detected';
                            $this->verificationResultService->addDuration($result, $startTime);
                            // Determine state and result before saving
                            $stateAndResult = $this->verificationResultService->determineStateAndResult($result);
                            $result['state'] = $stateAndResult['state'];
                            $result['result'] = $stateAndResult['result'];
                            $result['reason'] = $stateAndResult['reason'] ?? $stateAndResult['result'];
                            $this->verificationResultService->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);
                            return $this->verificationResultService->formatResponse($result);
                        }

                        // Not catch-all, so SMTP check result is valid
                        if ($smtpCheck) {
                            // SMTP check passed - recalculate score with SMTP passed
                            // Multiplicative system: base 100 with multipliers
                            // Free providers: 100 * 0.95 (free) * 0.98-1.0 (numbers) = 85-95
                            // Non-free: 100 * (multipliers) = 98-100
                            $result['score'] = $this->scoreCalculationService->calculateScore($result['checks'], [
                                'email' => $email,
                                'domain' => $parts['domain'],
                                'mx_records' => $mxRecords,
                                'numerical_characters' => $result['numerical_characters'] ?? 0,
                                'alphabetical_characters' => $result['alphabetical_characters'] ?? 0,
                                'alias_of' => $result['alias_of'] ?? null,
                            ]);
                            $result['status'] = 'valid';
                        } else {
                            // SMTP check failed - could be rejected_email or unavailable_smtp
                            // Check if it's due to secure email gateway (Cloudflare, etc.)
                            // If secure email gateway detected and domain is valid, treat as accepted_email
                            $hasSecureGateway = $result['secure_email_gateway'] ?? false;
                            $smtpError = $result['error'] ?? null;
                            $isUnavailableError = $smtpError && (
                                str_contains(strtolower($smtpError), 'unavailable') ||
                                str_contains(strtolower($smtpError), 'timeout') ||
                                str_contains(strtolower($smtpError), 'connection')
                            );
                            
                            // Check if SMTP provider is a secure gateway provider (known to block SMTP checks)
                            $smtpProvider = $result['smtp_provider'] ?? null;
                            $multipliers = $this->scoreCalculationService->getScoreMultipliers();
                            $secureGatewayProviders = $multipliers['secure_gateway_providers'] ?? [];
                            $isSecureGatewayProvider = $smtpProvider && in_array($smtpProvider, $secureGatewayProviders, true);
                            
                            // If secure gateway provider detected, treat SMTP failure as unavailable (gateway blocking)
                            if ($isSecureGatewayProvider && !$smtpCheck) {
                                $isUnavailableError = true;
                            }
                            
                            if (($hasSecureGateway || $isSecureGatewayProvider) && $isUnavailableError && $result['mx_record'] && $result['domain_validity']) {
                                // Secure email gateway blocking SMTP check, but domain is valid
                                // Treat as accepted_email (override score) - can't verify via SMTP but domain exists
                                $result['score'] = $this->scoreCalculationService->calculateScore($result['checks'], [
                                    'email' => $email,
                                    'domain' => $parts['domain'],
                                    'mx_records' => $mxRecords,
                                    'numerical_characters' => $result['numerical_characters'] ?? 0,
                                    'alphabetical_characters' => $result['alphabetical_characters'] ?? 0,
                                    'alias_of' => $result['alias_of'] ?? null,
                                ]);
                                // If score is less than override score, set to override (secure gateway means we can't verify, but domain is valid)
                                $overrideScore = $multipliers['secure_gateway_score_override'] ?? 100;
                                if ($result['score'] < $overrideScore) {
                                    $result['score'] = $overrideScore;
                                }
                                $result['status'] = 'valid';
                                $result['error'] = null; // Clear error - it's not really an error, just gateway blocking
                            } else {
                                // SMTP check failed = mailbox doesn't exist (REJECTED EMAIL = 0 score)
                                // Emailable: rejected_email - The email address was rejected by the mail server
                                $result['score'] = 0;
                                $result['status'] = 'invalid';
                            }
                        }
                    } else {
                        // Rate limit exceeded, skip SMTP check
                        $result['smtp'] = false;
                        $result['checks']['smtp'] = false; // Update checks array
                        $result['mailbox_full'] = false; // Not checked
                        $result['checks']['mailbox_full'] = false; // Add to checks for score calculation
                        $result['catch_all'] = false;
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
                    $result['smtp'] = false;
                    $result['checks']['smtp'] = false; // Update checks array
                    $result['mailbox_full'] = false; // Not checked
                    $result['checks']['mailbox_full'] = false; // Add to checks for score calculation
                    $result['catch_all'] = false;
                    // Set error message so it can be detected as unavailable/timeout/no_connect
                    $result['error'] = $e->getMessage();
                }
            } else {
                $result['smtp'] = false; // Not checked
                $result['checks']['smtp'] = false; // Update checks array
                $result['mailbox_full'] = false; // Not checked
                $result['checks']['mailbox_full'] = false; // Add to checks for score calculation
                $result['catch_all'] = false;
            }

            // Determine final status based on config rules
            $statusRules = $this->verificationResultService->getStatusRules();
            if ($result['smtp']) {
                // SMTP check passed = definitely valid
                $result['status'] = $statusRules['smtp_valid'];
            } elseif ($result['catch_all'] ?? false) {
                // Catch-all server detected = catch_all status
                $result['status'] = config('email-verification.catch_all_status', 'catch_all');
            } elseif ($result['score'] >= ($statusRules['min_score_for_valid'] ?? 85)) {
                // High score without SMTP (likely public provider or known good domain)
                $result['status'] = 'valid';
            } elseif ($result['score'] >= ($statusRules['min_score_for_catch_all'] ?? 70)) {
                // Medium score = risky (not catch-all, just uncertain)
                $result['status'] = $result['role']
                    ? ($statusRules['role_emails_status'] ?? 'risky')
                    : 'risky'; // Changed from 'catch_all' to 'risky' - only use catch_all if actually detected
            } else {
                // Low score = invalid
                $result['status'] = $statusRules['default_invalid'] ?? 'invalid';
            }

            // Determine state and result before saving
            $stateAndResult = $this->verificationResultService->determineStateAndResult($result);
            $result['state'] = $stateAndResult['state'];
            $result['result'] = $stateAndResult['result'];
            $result['reason'] = $stateAndResult['reason'] ?? $stateAndResult['result'];

            // Save to database
            $this->verificationResultService->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);

        } catch (\Exception $e) {
            Log::error('Email verification failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            $result['status'] = 'unknown';
            $result['error'] = $e->getMessage();

            // Determine state and result even on error
            $stateAndResult = $this->verificationResultService->determineStateAndResult($result);
            $result['state'] = $stateAndResult['state'];
            $result['result'] = $stateAndResult['result'];
            $result['reason'] = $stateAndResult['reason'] ?? $stateAndResult['result'];

            // Try to save even on error
            try {
                $parts = $this->emailParserService->parseEmail($email);
                if ($parts) {
                    $this->verificationResultService->saveVerification($result, $userId, $teamId, $tokenId, $parts, $bulkJobId, $source);
                }
            } catch (\Exception $saveException) {
                Log::error('Failed to save verification record', [
                    'email' => $email,
                    'error' => $saveException->getMessage(),
                ]);
            }
        }

        // Calculate duration
        $this->verificationResultService->addDuration($result, $startTime);

        // Record metrics
        $this->verificationResultService->recordMetrics($result, $startTime);

        // Format response before returning
        return $this->verificationResultService->formatResponse($result);
    }

    /**
     * Format verification response to ensure consistent structure
     * Checks are flattened into main response, not in separate object
     *
     * @param array $result
     * @return array
     */




    /**
     * Get typo suggestions for email address
     * Returns corrected email if typo is detected
     * Wrapper method for backward compatibility
     *
     * @param string $email
     * @return string|null Corrected email address or null if no typo detected
     */
    public function getTypoSuggestions(string $email): ?string
    {
        return $this->riskAssessmentService->getTypoSuggestions($email);
    }


    /**
     * Optimized batch verification with domain grouping
     * Groups emails by domain and caches domain validation results
     *
     * @param array $emails
     * @param int|null $userId
     * @param int|null $teamId
     * @param int|null $tokenId
     * @param int|null $bulkJobId
     * @param string|null $source
     * @return array
     */
    public function verifyBatchOptimized(array $emails, ?int $userId = null, ?int $teamId = null, ?int $tokenId = null, ?int $bulkJobId = null, ?string $source = null): array
    {
        if (empty($emails)) {
            return [];
        }

        // 1. Group emails by domain
        $emailsByDomain = [];
        $emailToDomain = [];

        foreach ($emails as $email) {
            $parts = $this->emailParserService->parseEmail($email);
            if ($parts) {
                $domain = $parts['domain'];
                $emailsByDomain[$domain][] = $email;
                $emailToDomain[$email] = $domain;
            }
        }

        // 2. Pre-validate domains concurrently (cache results)
        $domainResults = $this->validateDomainsConcurrently(array_keys($emailsByDomain));

        // 3. Verify emails using cached domain results
        $batchStartTime = microtime(true);
        $results = [];
        foreach ($emails as $email) {
            // Use regular verify method, but domain checks will be cached
            $result = $this->verify($email, $userId, $teamId, $tokenId, $bulkJobId, $source);
            $results[] = $result;
        }

        // Record batch metrics
        $batchDuration = microtime(true) - $batchStartTime;
        try {
            $this->metricsService->recordBatchProcessing(count($emails), $batchDuration);
        } catch (\Exception $e) {
            Log::debug('Failed to record batch metrics', ['error' => $e->getMessage()]);
        }

        return $results;
    }

    /**
     * Validate multiple domains concurrently using optimized caching
     * This method groups domain validations and uses cache to avoid redundant checks
     *
     * @param array $domains
     * @return array Domain validation results keyed by domain
     */
    private function validateDomainsConcurrently(array $domains): array
    {
        if (empty($domains)) {
            return [];
        }

        $domainResults = [];

        // Process domains in parallel using array_map for better performance
        // Note: PHP doesn't have true concurrency, but this optimizes the order
        // and uses caching to minimize redundant DNS lookups
        $results = array_map(function ($domain) {
            // All these methods use internal caching, so redundant calls are fast
            $mxRecords = $this->domainValidationService->getMxRecords($domain);
            $domainValidity = $this->domainValidationService->checkDomainValidity($domain);

            return [
                'domain' => $domain,
                'domain_validity' => $domainValidity['valid'] ?? false,
                'mx_record' => $this->domainValidationService->checkMx($domain),
                'disposable' => $this->emailParserService->checkDisposable($domain),
                'mx_records' => $mxRecords,
                'is_public_provider' => $this->domainValidationService->isPublicProvider($domain, $mxRecords) !== null,
            ];
        }, array_unique($domains));

        // Convert to associative array keyed by domain
        foreach ($results as $result) {
            $domainResults[$result['domain']] = $result;
        }

        return $domainResults;
    }

    /**
     * Get API status and health information
     *
     * @return array
     */
    public function getStatus(): array
    {
        // Try to get Laravel start time from request or use current time
        $startTime = defined('LARAVEL_START') ? LARAVEL_START : (request()->server('REQUEST_TIME_FLOAT') ?? microtime(true));
        $uptime = microtime(true) - $startTime;

        // Get memory usage
        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);

        // Get recent verification stats (last hour)
        $recentVerifications = EmailVerification::where('created_at', '>=', now()->subHour())
            ->count();

        // Get queue stats if Horizon is available
        $queueStats = null;
        if (class_exists(\Laravel\Horizon\Horizon::class)) {
            try {
                $queueStats = [
                    'pending' => \Illuminate\Support\Facades\Queue::size(),
                ];
            } catch (\Exception $e) {
                // Horizon might not be configured
            }
        }

        return [
            'status' => 'healthy',
            'uptime_seconds' => round($uptime, 2),
            'uptime_human' => $this->formatUptime($uptime),
            'memory_usage_mb' => round($memoryUsage / 1024 / 1024, 2),
            'memory_peak_mb' => round($memoryPeak / 1024 / 1024, 2),
            'recent_verifications_1h' => $recentVerifications,
            'queue' => $queueStats,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Format uptime in human-readable format
     *
     * @param float $seconds
     * @return string
     */
    private function formatUptime(float $seconds): string
    {
        if ($seconds < 60) {
            return round($seconds, 1) . 's';
        }

        $minutes = floor($seconds / 60);
        if ($minutes < 60) {
            return $minutes . 'm ' . round($seconds % 60, 0) . 's';
        }

        $hours = floor($minutes / 60);
        $minutes = $minutes % 60;

        if ($hours < 24) {
            return $hours . 'h ' . $minutes . 'm';
        }

        $days = floor($hours / 24);
        $hours = $hours % 24;

        return $days . 'd ' . $hours . 'h';
    }
}



