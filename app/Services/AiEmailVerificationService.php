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
                'ai_analysis' => false,
            ],
            'score' => 0,
            'error' => null,
            'ai_insights' => null,
            'ai_confidence' => null,
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
                'message' => "📧 Parsing email: {$parts['account']}@{$parts['domain']}",
                'step' => 'parsing',
            ]);
        }

        // Run traditional checks with streaming support
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
            $checks = $traditionalResult['checks'] ?? [];
            
            $streamCallback([
                'type' => 'step',
                'message' => $checks['syntax'] ? '✅ Syntax check: Valid format' : '❌ Syntax check: Invalid format',
                'step' => 'syntax_check',
            ]);
            
            if ($parts) {
                $streamCallback([
                    'type' => 'step',
                    'message' => $checks['disposable'] ? '⚠️ Disposable email detected' : '✅ Not a disposable email',
                    'step' => 'disposable_check',
                ]);
                
                $streamCallback([
                    'type' => 'step',
                    'message' => $checks['role'] ? '⚠️ Role-based email (info@, support@, etc.)' : '✅ Not a role-based email',
                    'step' => 'role_check',
                ]);
                
                $streamCallback([
                    'type' => 'step',
                    'message' => $checks['mx'] ? '✅ MX records found' : '❌ No MX records found',
                    'step' => 'mx_check',
                ]);
                
                if ($checks['mx']) {
                    $streamCallback([
                        'type' => 'step',
                        'message' => $checks['smtp'] ? '✅ SMTP check: Email exists' : '⏳ SMTP check: Could not verify (may be catch-all)',
                        'step' => 'smtp_check',
                    ]);
                }
            }
        }

        // Merge traditional results
        $result = array_merge($result, $traditionalResult);
        $result['checks']['ai_analysis'] = false;

        // Stream: Traditional checks complete
        if ($streamCallback) {
            $streamCallback([
                'type' => 'step',
                'message' => 'Traditional checks completed',
                'step' => 'traditional_complete',
                'data' => [
                    'syntax' => $result['checks']['syntax'],
                    'mx' => $result['checks']['mx'],
                    'smtp' => $result['checks']['smtp'],
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
                    $result['ai_insights'] = $aiResult['insights'] ?? null;
                    $result['ai_confidence'] = $aiResult['confidence'] ?? null;
                    $result['checks']['ai_analysis'] = true;

                    // Adjust score based on AI confidence
                    if ($aiResult['confidence'] !== null) {
                        // AI confidence is 0-100, blend with traditional score
                        $aiWeight = 0.3; // 30% weight for AI
                        $traditionalWeight = 0.7; // 70% weight for traditional
                        $result['score'] = (int) round(
                            ($result['score'] * $traditionalWeight) + 
                            ($aiResult['confidence'] * $aiWeight)
                        );
                    }

                    // Update status if AI suggests different
                    if ($aiResult['suggested_status'] && $result['status'] === 'unknown') {
                        $result['status'] = $aiResult['suggested_status'];
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
        $systemPrompt = 'You are an expert email verification assistant. Analyze email addresses and provide insights about their validity, deliverability, and risk factors. Always respond in valid JSON format with the following structure: {"insights": "your analysis", "confidence": 0-100, "suggested_status": "valid|invalid|risky|catch_all|do_not_mail", "risk_factors": []}.';

        $response = Http::timeout(60) // Ollama can be slower
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

        $smtpStatus = $traditionalResult['checks']['smtp'] 
            ? 'PASS' 
            : ($traditionalResult['status'] === 'valid' && $traditionalResult['score'] >= 90 
                ? 'SKIPPED (public provider - known valid)' 
                : 'FAIL');

        // Build context notes only for relevant cases
        $contextNotes = [];
        if ($isPublicProvider) {
            $contextNotes[] = "This domain ({$domain}) is a PUBLIC EMAIL PROVIDER. These providers are KNOWN VALID and have high deliverability. They often BLOCK SMTP verification attempts for security, but this does NOT mean the email is invalid. If MX records are FOUND, confidence should be 90-100%.";
        }
        if ($isTypoDomain) {
            $contextNotes[] = "⚠️ CRITICAL: This domain ({$domain}) is a TYPO DOMAIN (common misspelling of a public email provider like gmail.com, yahoo.com, etc.). Typo domains are often purchased by spammers or ESPs as spam traps. Even if MX records exist, this should be marked as SPAMTRAP or INVALID with LOW confidence (0-20%). Do NOT trust MX records for typo domains - they are likely spam traps.";
        }
        // Only mention '+' if it's actually in the email
        if ($hasPlus) {
            $contextNotes[] = "This email contains a '+' character in the local part, which is VALID according to RFC 5322 and commonly used for email aliasing. This is NOT a problem.";
        }
        
        $contextSection = !empty($contextNotes) 
            ? "\n\nIMPORTANT CONTEXT:\n" . implode("\n", array_map(fn($note) => "- " . $note, $contextNotes))
            : '';

        // Build strict instructions - completely remove '+' references if not present
        $plusInstruction = $hasPlus 
            ? "If you mention the '+' character, note it's valid for aliasing."
            : "ABSOLUTE PROHIBITION: The '+' character is NOT in this email. DO NOT mention it, discuss it, reference it, or write ANY phrases containing '+', 'plus character', 'aliasing', or 'RFC 5322' in relation to '+'. Common forbidden phrases: 'it's worth noting that the + character', 'the + character is allowed', 'however, the + character', 'the + character in the local part'. Write as if '+' does not exist in email addresses.";

        return "Analyze this email address: {$email}

Email structure:
- Local part (before @): {$localPart}
- Domain (after @): {$domain}{$contextSection}

Traditional verification results:
- Syntax check: " . ($traditionalResult['checks']['syntax'] ? 'PASS (email format is valid)' : 'FAIL (email format is invalid)') . "
- MX records: " . ($traditionalResult['checks']['mx'] ? 'FOUND' : 'NOT FOUND') . "
- SMTP check: {$smtpStatus}
- Disposable email: " . ($traditionalResult['checks']['disposable'] ? 'YES' : 'NO') . "
- Role-based email: " . ($traditionalResult['checks']['role'] ? 'YES' : 'NO') . "
- Current status: {$traditionalResult['status']}
- Current score: {$traditionalResult['score']}/100

Analysis guidelines:
" . ($isPublicProvider ? "- This is a PUBLIC PROVIDER email. If MX records are FOUND, confidence should be 90-100% (these are known valid providers).\n" : '') . "
" . ($isTypoDomain ? "⚠️ CRITICAL: This is a TYPO DOMAIN. Even if MX records exist, this is likely a SPAM TRAP. Mark as SPAMTRAP or INVALID with LOW confidence (0-20%). Do NOT trust MX records for typo domains.\n" : '') . "
- If syntax check PASSED, the email format is valid
- If SMTP check is SKIPPED but status is 'valid' and score is 90-100, this is likely a public provider email - treat as HIGHLY VALID
- Low confidence should be due to deliverability issues (no MX records, domain doesn't exist), NOT due to format validity or public provider domains
- Focus on actual deliverability risks: missing MX records, disposable domains, role-based addresses, typo domains, etc.
- DO NOT penalize public provider emails for SMTP check failures - these providers intentionally block SMTP verification
- DO NOT trust MX records for typo domains - they are likely spam traps

" . ($hasPlus ? "NOTE: This email contains '+'. If mentioned, note it's valid for aliasing.\n\n" : "🚫 CRITICAL PROHIBITION - READ CAREFULLY:\nThe '+' character is NOT present in this email address ({$email}).\n\nDO NOT write ANY of these phrases:\n- 'it's worth noting that the + character'\n- 'the + character is allowed'\n- 'however, the + character'\n- 'the + character in the local part'\n- 'RFC 5322' in relation to '+'\n- Any mention of 'aliasing' related to '+'\n\nWrite your analysis as if '+' does not exist. Focus ONLY on what is actually in the email: domain type, MX records, deliverability.\n\n") . "
Provide a JSON response with:
1. 'insights': A brief analysis focusing on deliverability issues (MX records, domain validity, etc.), NOT format validity (since syntax check already passed). " . ($isTypoDomain ? "⚠️ CRITICAL: If this is a typo domain, mention it's a spam trap and mark as SPAMTRAP/INVALID even if MX records exist.\n" : '') . " " . ($hasPlus ? "You may mention '+' if relevant." : "DO NOT mention '+' character - it's not in this email.") . "
2. 'confidence': A score from 0-100 based on deliverability likelihood (not format validity). " . ($isTypoDomain ? "If typo domain, use 0-20% confidence.\n" : '') . "
3. 'suggested_status': One of: valid, invalid, risky, catch_all, do_not_mail, spamtrap. " . ($isTypoDomain ? "If typo domain, use 'spamtrap' or 'invalid'.\n" : '') . "
4. 'risk_factors': Array of potential risk factors (e.g., ['missing_mx_records', 'domain_not_resolvable'" . ($isTypoDomain ? ", 'typo_domain'" : '') . "])

Focus on:
- Domain deliverability (MX records, domain existence)
- Domain reputation and patterns
- Common spam/abuse indicators
- Deliverability best practices
" . ($isTypoDomain ? "- ⚠️ CRITICAL: Typo domains are spam traps - do NOT trust MX records for typo domains\n" : '') . "
" . ($hasPlus ? "- If '+' is present, you may note it's valid for aliasing\n" : "- DO NOT mention '+' character - it's not in this email\n") . "
- ONLY discuss features that are ACTUALLY present in the email address";
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
                $checks = $verification->checks ?? [];
                $checks['ai_analysis'] = $result['checks']['ai_analysis'] ?? false;

                // Store AI insights in checks JSON (we can add a separate column later if needed)
                $updateData = [
                    'checks' => $checks,
                    'score' => $result['score'],
                    'status' => $result['status'],
                ];

                // Store AI insights in checks for now (can be extracted later)
                if ($result['ai_insights'] || $result['ai_confidence'] !== null) {
                    $checks['ai_insights'] = $result['ai_insights'];
                    $checks['ai_confidence'] = $result['ai_confidence'];
                    $updateData['checks'] = $checks;
                }

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

