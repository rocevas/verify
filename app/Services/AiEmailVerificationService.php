<?php

namespace App\Services;

use App\Models\EmailVerification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class AiEmailVerificationService
{
    private EmailVerificationService $traditionalService;
    private string $apiKey;
    private string $model;
    private string $provider; // 'openai' or 'ollama'
    private string $baseUrl;
    private bool $enabled;

    public function __construct(EmailVerificationService $traditionalService)
    {
        $this->traditionalService = $traditionalService;
        $this->provider = config('services.ai.provider', 'ollama'); // Default to Ollama
        $this->model = config('services.ai.model', 'llama3.2');
        $this->baseUrl = config('services.ai.base_url', 'http://localhost:11434');

        if ($this->provider === 'openai') {
            $this->apiKey = config('services.openai.key', '');
            $this->baseUrl = config('services.openai.base_url', 'https://api.openai.com/v1');
            $this->model = config('services.openai.model', 'gpt-4o-mini');
            $this->enabled = !empty($this->apiKey) && config('services.openai.enabled', true);
        } else {
            // Ollama doesn't need API key
            $this->apiKey = '';
            $this->enabled = config('services.ai.enabled', true);
        }

        // Log if AI is disabled (for debugging)
        if (!$this->enabled && config('app.debug')) {
            Log::info('AI Email Verification is disabled', [
                'provider' => $this->provider,
                'has_api_key' => !empty($this->apiKey),
                'enabled_config' => config('services.ai.enabled', true),
            ]);
        }
    }

    /**
     * Verify email using AI + traditional methods
     * Returns array with streaming-friendly structure
     */
    public function verifyWithAi(
        string $email,
        ?int $userId = null,
        ?int $teamId = null,
        ?int $tokenId = null,
        ?int $bulkJobId = null,
        ?string $source = null,
        ?callable $streamCallback = null
    ): array {
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
                'ai_analysis' => false,
            ],
            'score' => 0,
            'error' => null,
            'ai_insights' => null,
            'ai_confidence' => null,
            'free' => false,
            'mailbox_full' => false,
        ];

        // Stream: Starting verification
        if ($streamCallback) {
            $streamCallback([
                'type' => 'step',
                'message' => "Starting verification for {$email}...",
                'step' => 'init',
            ]);
        }

        // First, run traditional checks with detailed streaming
        if ($streamCallback) {
            $streamCallback([
                'type' => 'step',
                'message' => '🔍 Running traditional verification checks...',
                'step' => 'traditional_start',
            ]);
        }

        // Parse email first
        $parts = $this->parseEmailForStreaming($email);
        if ($streamCallback && $parts) {
            $streamCallback([
                'type' => 'step',
                'message' => "<div class='shrink-0 size-2 bg-current animate-ping rounded-full inline-block -mt-0.5 mr-1'></div> Parsing email: {$parts['account']}@{$parts['domain']}",
                'step' => 'parsing',
            ]);
        }

        // Run traditional checks with streaming support
        // Set timeout for traditional verification
        set_time_limit(90); // 90 seconds for traditional checks

        $traditionalResult = $this->traditionalService->verify(
            $email,
            $userId,
            $teamId,
            $tokenId,
            $bulkJobId,
            $source
        );

        // Stream individual check results
        if ($streamCallback) {
            $streamCallback([
                'type' => 'step',
                'message' => ($traditionalResult['syntax'] ?? false) ? '✅ Syntax check: Valid format' : '❌ Syntax check: Invalid format',
                'step' => 'syntax_check',
            ]);

            if ($parts) {
                $streamCallback([
                    'type' => 'step',
                    'message' => ($traditionalResult['disposable'] ?? false) ? '⚠️ Disposable email detected' : '✅ Not a disposable email',
                    'step' => 'disposable_check',
                ]);

                $streamCallback([
                    'type' => 'step',
                    'message' => ($traditionalResult['role'] ?? false) ? '⚠️ Role-based email (info@, support@, etc.)' : '✅ Not a role-based email',
                    'step' => 'role_check',
                ]);

                $streamCallback([
                    'type' => 'step',
                    'message' => ($traditionalResult['mx_record'] ?? false) ? '✅ MX records found' : '❌ No MX records found',
                    'step' => 'mx_check',
                ]);

                if ($traditionalResult['mx_record'] ?? false) {
                    $streamCallback([
                        'type' => 'step',
                        'message' => ($traditionalResult['smtp'] ?? false) ? '✅ SMTP check: Email exists' : '⏳ SMTP check: Could not verify (may be catch-all)',
                        'step' => 'smtp_check',
                    ]);
                }
            }
        }

        // Merge traditional results
        $result = array_merge($result, $traditionalResult);

        // email_score is traditional email verification score (MX, blacklist, SMTP, etc.)
        $result['email_score'] = $traditionalResult['score'] ?? 0;

        // Ensure checks array has all keys from traditional result
        if (isset($traditionalResult['checks'])) {
            $result['checks'] = array_merge($result['checks'] ?? [], $traditionalResult['checks']);
        }

        // Ensure mx_record key is synced
        if (isset($result['mx_record'])) {
            $result['checks']['mx_record'] = $result['mx_record'];
        }
        if (isset($result['syntax'])) {
            $result['checks']['syntax'] = $result['syntax'];
        }
        if (isset($result['smtp'])) {
            $result['checks']['smtp'] = $result['smtp'];
        }
        if (isset($result['disposable'])) {
            $result['checks']['disposable'] = $result['disposable'];
        }
        if (isset($result['role'])) {
            $result['checks']['role'] = $result['role'];
        }
        if (isset($result['blacklist'])) {
            $result['checks']['blacklist'] = $result['blacklist'];
        }
        if (isset($result['domain_validity'])) {
            $result['checks']['domain_validity'] = $result['domain_validity'];
        }
        if (isset($result['no_reply'])) {
            $result['checks']['no_reply'] = $result['no_reply'];
        }
        if (isset($result['typo_domain'])) {
            $result['checks']['typo_domain'] = $result['typo_domain'];
        }
        if (isset($result['isp_esp'])) {
            $result['checks']['isp_esp'] = $result['isp_esp'];
        }
        if (isset($result['government_tld'])) {
            $result['checks']['government_tld'] = $result['government_tld'];
        }

        $result['ai_analysis'] = false;

        // Stream: Traditional checks complete
        if ($streamCallback) {
            $streamCallback([
                'type' => 'step',
                'message' => 'Traditional checks completed',
                'step' => 'traditional_complete',
                'data' => [
                    'syntax' => $result['syntax'] ?? false,
                    'mx_record' => $result['mx_record'] ?? false,
                    'smtp' => $result['smtp'] ?? false,
                    'score' => $result['score'],
                ],
            ]);
        }

        // If AI is enabled, enhance with AI analysis
        if ($this->enabled) {
            if ($streamCallback) {
                $streamCallback([
                    'type' => 'step',
                    'message' => '🤖 Connecting to AI model...',
                    'step' => 'ai_connecting',
                ]);

                $streamCallback([
                    'type' => 'step',
                    'message' => '🧠 Analyzing email with AI (this may take a few seconds)...',
                    'step' => 'ai_analysis',
                ]);
            }

            try {
                $aiResult = $this->analyzeWithAi($email, $traditionalResult);

                if ($aiResult) {
                    $result['ai_analysis'] = true; // Mark that AI analysis was performed
                    $result['ai_insights'] = $aiResult['insights'] ?? null;
                    $result['ai_confidence'] = $aiResult['confidence'] ?? null;
                    $result['ai_risk_factors'] = $aiResult['risk_factors'] ?? [];
                    $result['checks']['ai_analysis'] = true;

                    // Use AI risk factors to enhance checks
                    $riskFactors = $aiResult['risk_factors'] ?? [];
                    if (!empty($riskFactors)) {
                        // Mark specific risk factors in checks
                        if (in_array('typo_domain', $riskFactors) && !($result['typo_domain'] ?? false)) {
                            $result['checks']['ai_detected_typo'] = true;

                            // If AI detected typo and provided correction, use it
                            if (isset($aiResult['did_you_mean']) && !isset($result['did_you_mean'])) {
                                $result['did_you_mean'] = $aiResult['did_you_mean'];
                            } else {
                                // Try to get correction from traditional service
                                $parts = explode('@', $email, 2);
                                $correctedDomain = $this->getTypoCorrectionForDomain($parts[1] ?? '');
                                if ($correctedDomain) {
                                    $result['did_you_mean'] = $parts[0] . '@' . $correctedDomain;
                                }
                            }
                        }
                        if (in_array('suspicious_pattern', $riskFactors)) {
                            $result['checks']['ai_suspicious_pattern'] = true;
                        }
                        if (in_array('low_reputation', $riskFactors)) {
                            $result['checks']['ai_low_reputation'] = true;
                        }
                    }

                    // If traditional service already detected typo and has correction, use it
                    if ($result['typo_domain'] ?? false && !isset($result['did_you_mean'])) {
                        // Correction should already be in result from traditional service
                        // But if not, try to get it
                        $parts = explode('@', $email, 2);
                        $correctedDomain = $this->getTypoCorrectionForDomain($parts[1] ?? '');
                        if ($correctedDomain) {
                            $result['did_you_mean'] = $parts[0] . '@' . $correctedDomain;
                        }
                    }

                    // Adjust score based on AI confidence
                    if ($aiResult['confidence'] !== null) {
                        // Check if this is a public provider email
                        $parts = explode('@', $email, 2);
                        $domain = strtolower($parts[1] ?? '');
                        $publicProviders = ['gmail.com', 'googlemail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'mail.ru', 'yandex.com', 'aol.com', 'icloud.com'];
                        $isPublicProvider = in_array($domain, $publicProviders, true);

                        // Check if this is a test domain
                        $testDomains = ['test.com', 'example.com', 'example.org', 'example.net', 'test.org', 'test.net'];
                        $isTestDomain = in_array($domain, $testDomains, true);

                        // Check if domain is an IP address
                        $isIpAddress = preg_match('/^\[?(\d{1,3}\.){3}\d{1,3}\]?$/', $domain) === 1;

                        // Check if domain has invalid TLD
                        $isInvalidTld = str_ends_with($domain, '.invalid');

                        // Check if disposable email
                        $isDisposable = $result['disposable'] ?? false;
                        $disposableServiceDomains = ['guerrillamail.com', '10minutemail.com', 'mailinator.com', 'tempmail.com', 'throwaway.email', 'getnada.com', 'mohmal.com', 'trashmail.com'];
                        $isDisposableService = in_array($domain, $disposableServiceDomains, true);

                        // Calculate final score by blending email_score and AI confidence
                        $emailScore = $result['email_score'] ?? 0;
                        $aiConfidence = $aiResult['confidence'];
                        
                        // For public providers with MX records, ensure high final score
                        if ($isPublicProvider && ($result['mx_record'] ?? false)) {
                            // Public provider with MX records should have high final score (90-100)
                            $result['score'] = max(90, max($emailScore, $aiConfidence));
                        } elseif ($isTestDomain || $isIpAddress || $isInvalidTld) {
                            // Test domains, IP addresses, and invalid TLD should have low final scores (0-30)
                            $result['score'] = min(30, min($emailScore, $aiConfidence));
                        } elseif ($isDisposable || $isDisposableService) {
                            // Disposable emails should have low final scores (0-40)
                            $result['score'] = min(40, min($emailScore, $aiConfidence));
                        } else {
                            // AI confidence is 0-100, blend with email_score
                            $aiWeight = 0.3; // 30% weight for AI
                            $emailWeight = 0.7; // 70% weight for email_score
                            $result['score'] = (int) round(
                                ($emailScore * $emailWeight) +
                                ($aiConfidence * $aiWeight)
                            );
                        }
                    } else {
                        // No AI confidence, score equals email_score
                        $result['score'] = $result['email_score'] ?? 0;
                    }

                    // Use AI suggested status more aggressively if AI is confident
                    if ($aiResult['suggested_status'] && $aiResult['confidence'] !== null) {
                        $aiConfidence = $aiResult['confidence'];
                        $currentStatus = $result['status'];

                        // Check if this is a public provider email
                        $parts = explode('@', $email, 2);
                        $domain = strtolower($parts[1] ?? '');
                        $publicProviders = ['gmail.com', 'googlemail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'mail.ru', 'yandex.com', 'aol.com', 'icloud.com'];
                        $isPublicProvider = in_array($domain, $publicProviders, true);

                        // Check if this is a test domain
                        $testDomains = ['test.com', 'example.com', 'example.org', 'example.net', 'test.org', 'test.net'];
                        $isTestDomain = in_array($domain, $testDomains, true);

                        // Check if domain is an IP address
                        $isIpAddress = preg_match('/^\[?(\d{1,3}\.){3}\d{1,3}\]?$/', $domain) === 1;

                        // Check if domain has invalid TLD
                        $isInvalidTld = str_ends_with($domain, '.invalid');

                        // Check if disposable email
                        $isDisposable = $result['disposable'] ?? false;
                        $disposableServiceDomains = ['guerrillamail.com', '10minutemail.com', 'mailinator.com', 'tempmail.com', 'throwaway.email', 'getnada.com', 'mohmal.com', 'trashmail.com'];
                        $isDisposableService = in_array($domain, $disposableServiceDomains, true);

                        // Protect public provider emails: never override 'valid' status for public providers
                        if ($isPublicProvider && $currentStatus === 'valid') {
                            // Public provider emails that are already marked as valid should stay valid
                            // AI should not override valid status for public providers, even if AI suggests do_not_mail
                            Log::debug('AI status override blocked for public provider', [
                                'email' => $email,
                                'current_status' => $currentStatus,
                                'ai_suggested' => $aiResult['suggested_status'],
                                'reason' => 'Public provider email already marked as valid',
                            ]);
                        } elseif ($isPublicProvider && $aiResult['suggested_status'] === 'valid' && ($result['mx_record'] ?? false)) {
                            // If public provider has MX records and AI suggests valid, always use valid
                            $result['status'] = 'valid';
                            Log::info('AI status override for public provider with MX', [
                                'email' => $email,
                                'original_status' => $currentStatus,
                                'ai_suggested' => $aiResult['suggested_status'],
                            ]);
                        } elseif (($isTestDomain || $isIpAddress || $isInvalidTld) && in_array($aiResult['suggested_status'], ['invalid', 'spamtrap'])) {
                            // Always trust AI for test domains, IP addresses, and invalid TLD - they should be invalid
                            $result['status'] = $aiResult['suggested_status'];
                            Log::info('AI status override for invalid domain type', [
                                'email' => $email,
                                'original_status' => $currentStatus,
                                'ai_suggested' => $aiResult['suggested_status'],
                                'domain_type' => $isTestDomain ? 'test' : ($isIpAddress ? 'ip' : 'invalid_tld'),
                            ]);
                        } elseif (($isDisposable || $isDisposableService) && in_array($aiResult['suggested_status'], ['do_not_mail', 'invalid'])) {
                            // Always trust AI for disposable emails - they should be do_not_mail or invalid
                            $result['status'] = $aiResult['suggested_status'];
                            Log::info('AI status override for disposable email', [
                                'email' => $email,
                                'original_status' => $currentStatus,
                                'ai_suggested' => $aiResult['suggested_status'],
                            ]);
                        } elseif ($aiConfidence >= 80 && $aiResult['suggested_status'] !== $currentStatus) {
                            // If AI is very confident (>= 80%) and suggests different status, trust it
                            // Only override if current status is uncertain or AI suggests more severe status
                            $statusSeverity = [
                                'valid' => 1,
                                'risky' => 2,
                                'catch_all' => 3,
                                'do_not_mail' => 4,
                                'invalid' => 5,
                                'spamtrap' => 6,
                            ];

                            $currentSeverity = $statusSeverity[$currentStatus] ?? 0;
                            $aiSeverity = $statusSeverity[$aiResult['suggested_status']] ?? 0;

                            // Override if AI suggests more severe status (e.g., invalid vs risky)
                            // or if current status is uncertain (unknown, risky, catch_all)
                            // BUT: Never override 'valid' to 'do_not_mail' or 'invalid' for public providers
                            // BUT: Never override invalid status for test domains, IP addresses, invalid TLD
                            $shouldBlockOverride = false;

                            if ($isPublicProvider && $currentStatus === 'valid' && in_array($aiResult['suggested_status'], ['do_not_mail', 'invalid'])) {
                                $shouldBlockOverride = true;
                                Log::debug('AI status override blocked - public provider should stay valid', [
                                    'email' => $email,
                                    'ai_suggested' => $aiResult['suggested_status'],
                                ]);
                            }

                            if (($isTestDomain || $isIpAddress || $isInvalidTld) && $currentStatus === 'invalid' && $aiResult['suggested_status'] !== 'invalid') {
                                $shouldBlockOverride = true;
                                Log::debug('AI status override blocked - invalid domain type should stay invalid', [
                                    'email' => $email,
                                    'ai_suggested' => $aiResult['suggested_status'],
                                ]);
                            }

                            if (!$shouldBlockOverride && ($aiSeverity > $currentSeverity || in_array($currentStatus, ['unknown', 'risky', 'catch_all']))) {
                                $result['status'] = $aiResult['suggested_status'];
                                Log::info('AI status override', [
                                    'email' => $email,
                                    'original_status' => $currentStatus,
                                    'ai_suggested' => $aiResult['suggested_status'],
                                    'ai_confidence' => $aiConfidence,
                                ]);
                            }
                        } elseif ($currentStatus === 'unknown') {
                            // Always use AI suggestion if status is unknown (unless it's a public provider that should be valid)
                            if ($isPublicProvider && ($result['mx_record'] ?? false) && $aiResult['suggested_status'] !== 'valid') {
                                // Public provider with MX records should be valid, not what AI suggests
                                $result['status'] = 'valid';
                                Log::info('AI status override blocked - public provider with MX should be valid', [
                                    'email' => $email,
                                    'ai_suggested' => $aiResult['suggested_status'],
                                ]);
                            } elseif (($isTestDomain || $isIpAddress || $isInvalidTld) && $aiResult['suggested_status'] !== 'invalid') {
                                // Test domains, IP addresses, and invalid TLD should be invalid
                                $result['status'] = 'invalid';
                                Log::info('AI status override - invalid domain type should be invalid', [
                                    'email' => $email,
                                    'ai_suggested' => $aiResult['suggested_status'],
                                ]);
                            } elseif (($isDisposable || $isDisposableService) && !in_array($aiResult['suggested_status'], ['do_not_mail', 'invalid'])) {
                                // Disposable emails should be do_not_mail or invalid
                                $result['status'] = 'do_not_mail';
                                Log::info('AI status override - disposable email should be do_not_mail', [
                                    'email' => $email,
                                    'ai_suggested' => $aiResult['suggested_status'],
                                ]);
                            } else {
                                $result['status'] = $aiResult['suggested_status'];
                            }
                        }
                    }

                    if ($streamCallback) {
                        $streamCallback([
                            'type' => 'step',
                            'message' => 'AI analysis completed',
                            'step' => 'ai_complete',
                            'data' => [
                                'insights' => $aiResult['insights'],
                                'confidence' => $aiResult['confidence'],
                            ],
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::warning('AI analysis failed, using traditional results only', [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);

                if ($streamCallback) {
                    $streamCallback([
                        'type' => 'step',
                        'message' => 'AI analysis unavailable, using traditional verification',
                        'step' => 'ai_fallback',
                    ]);
                }
            }
        }

        // If score is not set (AI not used or failed), set it to email_score
        // score = email_score when AI is not used
        if (!isset($result['score'])) {
            $result['score'] = $result['email_score'] ?? 0;
        }

        // Calculate duration
        $endTime = microtime(true);
        $result['duration'] = round($endTime - $startTime, 2); // Duration in seconds (rounded to 2 decimal places)

        // Determine state and result (traditional service should have set it, but ensure it's set)
        if (!isset($result['state']) || !isset($result['result'])) {
            // Use reflection to call traditional service's method, or implement same logic
            $stateAndResult = $this->determineStateAndResult($result);
            $result['state'] = $stateAndResult['state'];
            $result['result'] = $stateAndResult['result'];
        }

        // Save verification with AI data (traditional service already saved it, so we update)
        $this->updateVerificationWithAi($email, $result, $userId, $teamId);

        // Stream: Final result
        if ($streamCallback) {
            $streamCallback([
                'type' => 'result',
                'data' => $result,
            ]);
        }

        return $result;
    }

    /**
     * Analyze email with AI (supports both OpenAI and Ollama)
     */
    private function analyzeWithAi(string $email, array $traditionalResult): ?array
    {
        $cacheKey = "ai_analysis_" . md5($email . json_encode($traditionalResult) . $this->provider);

        return Cache::remember($cacheKey, 3600, function () use ($email, $traditionalResult) {
            try {
                $prompt = $this->buildPrompt($email, $traditionalResult);

                if ($this->provider === 'ollama') {
                    return $this->analyzeWithOllama($prompt);
                } else {
                    return $this->analyzeWithOpenAI($prompt);
                }
            } catch (\Exception $e) {
                Log::error('AI analysis exception', [
                    'email' => $email,
                    'provider' => $this->provider,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        });
    }

    /**
     * Analyze with Ollama (local AI)
     */
    private function analyzeWithOllama(string $prompt): ?array
    {
        $systemPrompt = 'You are an expert email verification assistant. Analyze email addresses and provide insights about their validity, deliverability, and risk factors. Always respond in valid JSON format with the following structure: {"insights": "your analysis", "confidence": 0-100, "suggested_status": "valid|invalid|risky|catch_all|do_not_mail", "did_you_mean": "corrected_email_or_null", "risk_factors": []}.';

        $response = Http::timeout(30) // Reduced from 60 to 30 seconds for better UX
            ->post("{$this->baseUrl}/api/chat", [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $systemPrompt,
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'stream' => false,
                'options' => [
                    'temperature' => 0.3,
                    'num_predict' => 500,
                ],
            ]);

        if (!$response->successful()) {
            Log::error('Ollama API error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'base_url' => $this->baseUrl,
            ]);
            return null;
        }

        $data = $response->json();
        $content = $data['message']['content'] ?? null;

        if (!$content) {
            return null;
        }

        // Try to extract JSON from response (Ollama sometimes adds extra text)
        $jsonMatch = [];
        if (preg_match('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', $content, $jsonMatch)) {
            $content = $jsonMatch[0];
        }

        $analysis = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('Failed to parse Ollama JSON response', [
                'content' => $content,
                'error' => json_last_error_msg(),
            ]);
            return null;
        }

        return [
            'insights' => $analysis['insights'] ?? null,
            'confidence' => isset($analysis['confidence']) ? (int) $analysis['confidence'] : null,
            'suggested_status' => $analysis['suggested_status'] ?? null,
            'did_you_mean' => $analysis['did_you_mean'] ?? null,
            'risk_factors' => $analysis['risk_factors'] ?? [],
        ];
    }

    /**
     * Analyze with OpenAI
     */
    private function analyzeWithOpenAI(string $prompt): ?array
    {
        $response = Http::timeout(30)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])
            ->post("{$this->baseUrl}/chat/completions", [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are an expert email verification assistant. Analyze email addresses and provide insights about their validity, deliverability, and risk factors. Respond in JSON format.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => 0.3,
                'max_tokens' => 500,
                'response_format' => ['type' => 'json_object'],
            ]);

        if (!$response->successful()) {
            Log::error('OpenAI API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        }

        $data = $response->json();
        $content = $data['choices'][0]['message']['content'] ?? null;

        if (!$content) {
            return null;
        }

        $analysis = json_decode($content, true);

        return [
            'insights' => $analysis['insights'] ?? null,
            'confidence' => isset($analysis['confidence']) ? (int) $analysis['confidence'] : null,
            'suggested_status' => $analysis['suggested_status'] ?? null,
            'did_you_mean' => $analysis['did_you_mean'] ?? null,
            'risk_factors' => $analysis['risk_factors'] ?? [],
        ];
    }

    /**
     * Parse email for streaming updates
     */
    private function parseEmailForStreaming(string $email): ?array
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return null;
        }

        return [
            'account' => strtolower($parts[0]),
            'domain' => strtolower($parts[1]),
        ];
    }

    /**
     * Build prompt for AI analysis
     */
    private function buildPrompt(string $email, array $traditionalResult): string
    {
        $parts = explode('@', $email, 2);
        $localPart = $parts[0] ?? '';
        $domain = $parts[1] ?? '';

        // Check if email contains '+' character
        $hasPlus = str_contains($localPart, '+');

        // Determine if domain is a public provider
        $publicProviders = ['gmail.com', 'googlemail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'mail.ru', 'yandex.com', 'aol.com', 'icloud.com'];
        $isPublicProvider = in_array(strtolower($domain), $publicProviders, true);

        // Check if domain is a typo domain (common misspellings of public providers)
        $typoDomains = config('email-verification.typo_domains', []);
        $isTypoDomain = in_array(strtolower($domain), $typoDomains, true);

        // Check if domain is a test domain (should be marked as invalid)
        $testDomains = ['test.com', 'example.com', 'example.org', 'example.net', 'test.org', 'test.net'];
        $isTestDomain = in_array(strtolower($domain), $testDomains, true);

        // Check if domain is an IP address (e.g., [192.168.1.1])
        $isIpAddress = preg_match('/^\[?(\d{1,3}\.){3}\d{1,3}\]?$/', $domain) === 1;

        // Check if domain has invalid TLD (.invalid is reserved)
        $isInvalidTld = str_ends_with(strtolower($domain), '.invalid');

        // Check if disposable email
        $isDisposable = $traditionalResult['disposable'] ?? false;

        // Check for known disposable email service domains
        $disposableServiceDomains = ['guerrillamail.com', '10minutemail.com', 'mailinator.com', 'tempmail.com', 'throwaway.email', 'getnada.com', 'mohmal.com', 'trashmail.com'];
        $isDisposableService = in_array(strtolower($domain), $disposableServiceDomains, true);

        $smtpStatus = $traditionalResult['smtp'] ?? false
            ? 'PASS'
            : ($traditionalResult['status'] === 'valid' && $traditionalResult['score'] >= 90
                ? 'SKIPPED (public provider - known valid)'
                : 'FAIL');

        // Build context notes only for relevant cases
        $contextNotes = [];
        if ($isPublicProvider) {
            $mxStatus = ($traditionalResult['mx_record'] ?? false) ? 'FOUND' : 'NOT FOUND';
            $contextNotes[] = "🚨 CRITICAL: This domain ({$domain}) is a PUBLIC EMAIL PROVIDER (Gmail, Yahoo, Outlook, etc.). These are MAJOR email providers that are ALWAYS VALID when MX records exist. Public providers ALWAYS have MX records - if MX records are FOUND, this email is VALID with 90-100% confidence. Public providers BLOCK SMTP verification for security, but this does NOT mean the email is invalid. If MX records are FOUND for a public provider, you MUST use 90-100% confidence and 'valid' status. DO NOT mark public provider emails as 'do_not_mail' or give low confidence - they are highly deliverable.";
        }
        if ($isTypoDomain) {
            $contextNotes[] = "⚠️ CRITICAL: This domain ({$domain}) is a TYPO DOMAIN (common misspelling of a public email provider like gmail.com, yahoo.com, etc.). Typo domains are often purchased by spammers or ESPs as spam traps. Even if MX records exist, this should be marked as SPAMTRAP or INVALID with LOW confidence (0-20%). Do NOT trust MX records for typo domains - they are likely spam traps.";
        }
        if ($isTestDomain) {
            $contextNotes[] = "⚠️ CRITICAL: This domain ({$domain}) is a TEST DOMAIN (test.com, example.com, etc.). These are RESERVED for documentation and testing purposes. They should be marked as INVALID with LOW confidence (0-30%) regardless of MX records. These domains are NOT for real email delivery. DO NOT give high confidence (80%+) to test domains - they are always invalid.";
        }
        if ($isIpAddress) {
            $contextNotes[] = "⚠️ CRITICAL: This domain ({$domain}) is an IP ADDRESS, not a domain name. IP addresses in email addresses are INVALID according to RFC standards. Mark as INVALID with LOW confidence (0-20%).";
        }
        if ($isInvalidTld) {
            $contextNotes[] = "⚠️ CRITICAL: This domain ({$domain}) uses the .invalid TLD, which is RESERVED and INVALID. Mark as INVALID with LOW confidence (0-20%).";
        }
        if ($isDisposable || $isDisposableService) {
            $contextNotes[] = "⚠️ CRITICAL: This domain ({$domain}) is a DISPOSABLE EMAIL SERVICE. These services provide temporary email addresses that should NOT be used for real communication. Mark as 'do_not_mail' or 'invalid' with LOW confidence (0-40%). DO NOT mark disposable emails as 'valid' even if MX records exist.";
        }
        // Only mention '+' if it's actually in the email
        if ($hasPlus) {
            $contextNotes[] = "This email contains a '+' character in the local part, which is VALID according to RFC 5322 and commonly used for email aliasing. This is NOT a problem.";
        }

        $contextSection = !empty($contextNotes)
            ? "\n\n🚨 CRITICAL CONTEXT - READ CAREFULLY:\n" . implode("\n", array_map(fn($note) => "- " . $note, $contextNotes))
            : '';

        return "Analyze this email address: {$email}

Email structure:
- Local part (before @): {$localPart}
- Domain (after @): {$domain}{$contextSection}

Traditional verification results:
- Syntax check: " . (($traditionalResult['syntax'] ?? false) ? 'PASS (email format is valid)' : 'FAIL (email format is invalid)') . "
- MX records: " . (($traditionalResult['mx_record'] ?? false) ? 'FOUND' : 'NOT FOUND') . "
- SMTP check: {$smtpStatus}
- Disposable email: " . (($traditionalResult['disposable'] ?? false) ? 'YES' : 'NO') . "
- Role-based email: " . (($traditionalResult['role'] ?? false) ? 'YES' : 'NO') . "
- Current status: {$traditionalResult['status']}
- Current score: {$traditionalResult['score']}/100

🎯 STRICT ANALYSIS RULES - FOLLOW THESE EXACTLY:

" . ($isPublicProvider ? "1. PUBLIC PROVIDER RULE (HIGHEST PRIORITY):
   - Domain: {$domain} is a PUBLIC EMAIL PROVIDER (Gmail, Yahoo, Outlook, etc.)
   - If MX records are FOUND: Email is VALID with 90-100% confidence, status MUST be 'valid'
   - If MX records are NOT FOUND: This is unusual for a public provider, but still likely valid (80-90% confidence)
   - Public providers BLOCK SMTP verification - this is NORMAL and NOT a problem
   - DO NOT mark public provider emails as 'do_not_mail' or give low confidence
   - DO NOT penalize for SMTP check failures - these providers intentionally block SMTP
   - Public provider emails are HIGHLY DELIVERABLE regardless of SMTP check results
   - Confidence: 90-100% if MX found, 80-90% if MX not found (still valid)
   - Status: MUST be 'valid' (never 'do_not_mail' or 'invalid' for public providers)

" : '') . "
" . ($isTestDomain ? "1. TEST DOMAIN RULE (HIGHEST PRIORITY):
   - Domain: {$domain} is a TEST DOMAIN (test.com, example.com, etc.)
   - These domains are RESERVED for documentation/testing and NOT for real email
   - Mark as INVALID with LOW confidence (0-30%)
   - Status: MUST be 'invalid'
   - DO NOT trust MX records for test domains - they are not for real use
   - DO NOT give 80%+ confidence to test domains - they are ALWAYS invalid

" : '') . "
" . ($isIpAddress ? "1. IP ADDRESS RULE (HIGHEST PRIORITY):
   - Domain: {$domain} is an IP ADDRESS, not a domain name
   - IP addresses in email are INVALID according to RFC standards
   - Mark as INVALID with LOW confidence (0-20%)
   - Status: MUST be 'invalid'

" : '') . "
" . ($isInvalidTld ? "1. INVALID TLD RULE (HIGHEST PRIORITY):
   - Domain: {$domain} uses .invalid TLD which is RESERVED
   - Mark as INVALID with LOW confidence (0-20%)
   - Status: MUST be 'invalid'

" : '') . "
" . (($isDisposable || $isDisposableService) ? "1. DISPOSABLE EMAIL RULE (HIGHEST PRIORITY):
   - Domain: {$domain} is a DISPOSABLE EMAIL SERVICE
   - These services provide temporary emails that should NOT be used
   - Mark as 'do_not_mail' or 'invalid' with LOW confidence (0-40%)
   - Status: MUST be 'do_not_mail' or 'invalid'
   - DO NOT mark disposable emails as 'valid' even if MX records exist

" : '') . "
" . ($isTypoDomain ? "2. TYPO DOMAIN RULE:
   - Domain: {$domain} is a TYPO DOMAIN (misspelling of a public provider)
   - Typo domains are often SPAM TRAPS
   - Mark as SPAMTRAP or INVALID with LOW confidence (0-20%)
   - DO NOT trust MX records for typo domains - they are likely spam traps
   - Status: MUST be 'spamtrap' or 'invalid'

" : '') . "
" . (!$isPublicProvider && !$isTestDomain && !$isTypoDomain && !$isIpAddress && !$isInvalidTld && !$isDisposable && !$isDisposableService ? "GENERAL RULES:
" : '') . "
- If syntax check PASSED, the email format is valid
- Low confidence should be due to REAL deliverability issues (no MX records, domain doesn't exist, test domains, typo domains, disposable emails, IP addresses, invalid TLD), NOT due to format validity
- Focus on actual deliverability risks: missing MX records, disposable domains, role-based addresses, typo domains, test domains, IP addresses, invalid TLD
- DO NOT penalize valid emails for SMTP check failures if MX records exist
- Test domains (test.com, example.com) are ALWAYS invalid regardless of MX records
- IP addresses in email addresses are ALWAYS invalid
- .invalid TLD is ALWAYS invalid
- Disposable email services should be marked as 'do_not_mail' or 'invalid'

" . ($hasPlus ? "NOTE: This email contains '+'. If mentioned, note it's valid for aliasing.\n\n" : "🚫 CRITICAL PROHIBITION - READ CAREFULLY:\nThe '+' character is NOT present in this email address ({$email}).\n\nDO NOT write ANY of these phrases:\n- 'it's worth noting that the + character'\n- 'the + character is allowed'\n- 'however, the + character'\n- 'the + character in the local part'\n- 'RFC 5322' in relation to '+'\n- Any mention of 'aliasing' related to '+'\n\nWrite your analysis as if '+' does not exist. Focus ONLY on what is actually in the email: domain type, MX records, deliverability.\n\n") . "
Provide a JSON response with:
1. 'insights': A brief analysis focusing on deliverability. " . ($isPublicProvider ? "For public providers, emphasize that they are known valid providers with high deliverability.\n" : '') . " " . ($isTestDomain ? "For test domains, mention they are reserved for testing and not for real email. DO NOT give high confidence.\n" : '') . " " . ($isTypoDomain ? "For typo domains, mention they are likely spam traps.\n" : '') . " " . ($isIpAddress ? "For IP addresses, mention they are invalid according to RFC standards.\n" : '') . " " . ($isInvalidTld ? "For .invalid TLD, mention it is reserved and invalid.\n" : '') . " " . (($isDisposable || $isDisposableService) ? "For disposable emails, mention they are temporary services and should not be used.\n" : '') . " " . ($hasPlus ? "You may mention '+' if relevant." : "DO NOT mention '+' character - it's not in this email.") . "
2. 'confidence': A score from 0-100. " . ($isPublicProvider ? "For public providers: 90-100% if MX found, 80-90% if MX not found.\n" : '') . " " . ($isTestDomain ? "For test domains: 0-30% (NEVER give 80%+ to test domains).\n" : '') . " " . ($isTypoDomain ? "For typo domains: 0-20%.\n" : '') . " " . ($isIpAddress ? "For IP addresses: 0-20%.\n" : '') . " " . ($isInvalidTld ? "For .invalid TLD: 0-20%.\n" : '') . " " . (($isDisposable || $isDisposableService) ? "For disposable emails: 0-40%.\n" : '') . "
3. 'suggested_status': One of: valid, invalid, risky, catch_all, do_not_mail, spamtrap. " . ($isPublicProvider ? "For public providers: MUST be 'valid' (NEVER 'do_not_mail' or 'invalid').\n" : '') . " " . ($isTestDomain ? "For test domains: MUST be 'invalid'.\n" : '') . " " . ($isTypoDomain ? "For typo domains: MUST be 'spamtrap' or 'invalid'.\n" : '') . " " . ($isIpAddress ? "For IP addresses: MUST be 'invalid'.\n" : '') . " " . ($isInvalidTld ? "For .invalid TLD: MUST be 'invalid'.\n" : '') . " " . (($isDisposable || $isDisposableService) ? "For disposable emails: MUST be 'do_not_mail' or 'invalid'.\n" : '') . "
4. 'did_you_mean': " . ($isTypoDomain ? "If this is a typo domain, provide the corrected email address (e.g., if domain is 'gmai.com' and local part is 'user', return 'user@gmail.com'). If not a typo, return null.\n" : "If you detect this might be a typo domain (similar to gmail.com, yahoo.com, outlook.com, etc.), provide the corrected email address with the same local part. Otherwise, return null.\n") . "
5. 'risk_factors': Array of specific risk factors. Use these exact values when applicable:
   - 'missing_mx_records' - No MX records found
   - 'domain_not_resolvable' - Domain doesn't resolve to IP
   - 'typo_domain' - Domain is a typo of a known provider
   - 'test_domain' - Domain is a test domain (test.com, example.com)
   - 'ip_address' - Domain is an IP address (invalid)
   - 'invalid_tld' - Domain uses invalid TLD (.invalid)
   - 'disposable_service' - Domain is a disposable email service
   - 'suspicious_pattern' - Email pattern suggests spam/fake (e.g., random characters, suspicious combinations)
   - 'low_reputation' - Domain has low reputation indicators
   - 'disposable_like' - Domain looks like disposable email (even if not in list)
   - 'role_based' - Role-based email (info@, support@, etc.)
   - 'catch_all_domain' - Domain accepts all emails (catch-all)
   - 'public_provider_block' - Public provider that blocks SMTP checks (not a risk, just info)

Focus on:
- Domain deliverability (MX records, domain existence)
- Domain reputation and patterns
- Common spam/abuse indicators
- Deliverability best practices
" . ($isPublicProvider ? "- ⚠️ CRITICAL: Public providers are ALWAYS valid when MX records exist - use 90-100% confidence\n" : '') . "
" . ($isTestDomain ? "- ⚠️ CRITICAL: Test domains are ALWAYS invalid - use 0-30% confidence (NEVER 80%+)\n" : '') . "
" . ($isTypoDomain ? "- ⚠️ CRITICAL: Typo domains are spam traps - do NOT trust MX records for typo domains\n" : '') . "
" . ($isIpAddress ? "- ⚠️ CRITICAL: IP addresses in email are ALWAYS invalid - use 0-20% confidence\n" : '') . "
" . ($isInvalidTld ? "- ⚠️ CRITICAL: .invalid TLD is ALWAYS invalid - use 0-20% confidence\n" : '') . "
" . (($isDisposable || $isDisposableService) ? "- ⚠️ CRITICAL: Disposable emails should be 'do_not_mail' or 'invalid' - use 0-40% confidence\n" : '') . "
" . ($hasPlus ? "- If '+' is present, you may note it's valid for aliasing\n" : "- DO NOT mention '+' character - it's not in this email\n") . "
- ONLY discuss features that are ACTUALLY present in the email address";
    }

    /**
     * Determine state and result based on checks and current status
     * Same logic as EmailVerificationService
     */
    private function determineStateAndResult(array $result): array
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

        // Check for catch-all
        if ($currentStatus === 'catch_all') {
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
                'risky' => 'catch_all',
                'catch_all' => 'catch_all',
                default => null,
            },
        ];
    }

    /**
     * Get typo correction for a domain
     * Uses the traditional service's typo correction logic
     */
    private function getTypoCorrectionForDomain(string $domain): ?string
    {
        // Use reflection to access private method, or create a public wrapper
        // For now, let's implement a simple version here
        $domainLower = strtolower($domain);

        // Check known typo corrections from config
        $typoCorrections = config('email-verification.typo_corrections', []);
        if (isset($typoCorrections[$domainLower])) {
            return $typoCorrections[$domainLower];
        }

        // Try to find correction using fuzzy matching
        $publicProviders = ['gmail.com', 'googlemail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'mail.ru', 'yandex.com', 'aol.com', 'icloud.com'];
        $maxDistance = 2;
        $minSimilarity = 0.85;

        $bestMatch = null;
        $bestSimilarity = 0;

        foreach ($publicProviders as $providerDomain) {
            if ($domainLower === $providerDomain) {
                continue;
            }

            $distance = levenshtein($domainLower, $providerDomain);
            $maxLen = max(strlen($domainLower), strlen($providerDomain));

            if ($maxLen === 0) {
                continue;
            }

            $similarity = 1 - ($distance / $maxLen);

            if ($distance <= $maxDistance && $similarity >= $minSimilarity) {
                if ($similarity > $bestSimilarity) {
                    $bestSimilarity = $similarity;
                    $bestMatch = $providerDomain;
                }
            }
        }

        return $bestMatch;
    }

    /**
     * Update existing verification with AI data
     */
    private function updateVerificationWithAi(
        string $email,
        array $result,
        ?int $userId,
        ?int $teamId
    ): void {
        try {
            $verification = EmailVerification::where('email', $email)
                ->where('user_id', $userId)
                ->where('team_id', $teamId)
                ->latest()
                ->first();

            if ($verification) {
                $updateData = [
                    'email_score' => $result['email_score'] ?? null, // Traditional email verification score (MX, blacklist, SMTP, etc.)
                    'score' => $result['score'] ?? null, // Final score (email_score + ai_confidence if AI is used, otherwise email_score)
                    'state' => $result['state'] ?? 'unknown',
                    'result' => $result['result'] ?? null,
                    'ai_analysis' => $result['ai_analysis'] ?? false,
                    'ai_insights' => $result['ai_insights'] ?? null,
                    'ai_confidence' => $result['ai_confidence'] ?? null,
                    'ai_risk_factors' => $result['ai_risk_factors'] ?? null,
                    'did_you_mean' => $result['did_you_mean'] ?? null,
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
                    'duration' => $result['duration'] ?? null, // Verification duration in seconds (rounded to 2 decimal places)
                ];

                $verification->update($updateData);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to update verification with AI data', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

