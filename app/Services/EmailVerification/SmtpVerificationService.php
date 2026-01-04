<?php

namespace App\Services\EmailVerification;

use App\Exceptions\SmtpRateLimitExceededException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class SmtpVerificationService
{
    public function __construct(
        private DomainValidationService $domainValidationService
    ) {
    }

    /**
     * Check if SMTP verification is enabled in config
     * 
     * @return bool
     */
    public function isSmtpCheckEnabled(): bool
    {
        return config('email-verification.enable_smtp_check', true);
    }

    /**
     * Check SMTP with detailed response information (AfterShip method)
     * Returns array with 'valid', 'mailbox_full', and 'catch_all' flags
     * 
     * AfterShip logic:
     * 1. First check catch-all with random email (if enabled)
     * 2. If catch-all = true, don't check specific email (return early)
     * 3. If catch-all = false, check specific email
     */
    public function checkSmtpWithDetails(string $email, string $domain): array
    {
        // Check rate limit first
        if (!$this->checkSmtpRateLimit($domain)) {
            return ['valid' => false, 'mailbox_full' => false, 'catch_all' => false];
        }

        // Get MX records (with caching)
        $mxRecords = $this->domainValidationService->getMxRecords($domain);
        
        if (empty($mxRecords)) {
            return ['valid' => false, 'mailbox_full' => false, 'catch_all' => false];
        }

        // Filter out skipped MX servers
        $mxRecordsToTry = [];
        foreach ($mxRecords as $mx) {
            $host = $mx['host'];
            
            // Skip if MX server is in skip list
            if ($this->domainValidationService->shouldSkipMxServer($host)) {
                Log::debug('Skipping MX server (in skip list)', ['host' => $host]);
                continue;
            }
            
            $mxRecordsToTry[] = $mx;
        }
        
        if (empty($mxRecordsToTry)) {
            Log::info('All MX servers are in skip list', ['domain' => $domain]);
            return ['valid' => false, 'mailbox_full' => false, 'catch_all' => false];
        }

        // Try MX records concurrently (up to 3 to avoid long delays)
        $maxMxToTry = 3;
        $mxRecordsToTry = array_slice($mxRecordsToTry, 0, $maxMxToTry);
        
        // Get first available MX server connection (concurrent)
        $mxConnection = $this->getConcurrentMxConnection($mxRecordsToTry);
        if ($mxConnection === null) {
            // Fallback to sequential if concurrent failed
            return $this->performSequentialSmtpCheck($mxRecordsToTry, $email);
        }
        
        // Use the connected MX server for SMTP check
        $host = $mxConnection['host'];
        $socket = $mxConnection['socket'];
        
        try {
            // AfterShip method: First check catch-all (if enabled)
            $catchAllEnabled = config('email-verification.enable_catch_all_detection', false);
            $shouldSkipCatchAll = $this->domainValidationService->shouldSkipCatchAllCheck($domain);
            
            // Default: assume catch-all = true (like AfterShip)
            $isCatchAll = true;
            
            if ($catchAllEnabled && !$shouldSkipCatchAll) {
                // Check if domain is public provider (they are always catch-all, skip test)
                $publicProvider = $this->domainValidationService->isPublicProvider($domain, $mxRecords);
                
                if (!$publicProvider) {
                    // Test catch-all with random email (AfterShip method)
                    // First, do SMTP handshake (greeting, EHLO, MAIL FROM)
                    $handshakeResult = $this->performSmtpHandshake($socket, $host);
                    if (!$handshakeResult['success']) {
                        // Handshake failed, add to skip list if auto-add enabled, then close socket and return
                        $this->domainValidationService->addMxToSkipList($host, 'SMTP handshake failed');
                        @fwrite($socket, "QUIT\r\n");
                        @fclose($socket);
                        return ['valid' => false, 'mailbox_full' => false, 'catch_all' => false];
                    }
                    
                    // Try VRFY/EXPN commands first (if enabled)
                    // These can verify email existence even on catch-all servers
                    $vrfyResult = null;
                    if (config('email-verification.enable_vrfy_check', true)) {
                        // Try VRFY first (more commonly supported)
                        $vrfyResult = $this->tryVrfyCommand($socket, $host, $email);
                        
                        // If VRFY worked, we have definitive answer - no need for catch-all check
                        if ($vrfyResult !== null) {
                            @fwrite($socket, "QUIT\r\n");
                            @fclose($socket);
                            
                            Log::info('Email verified using VRFY command', [
                                'email' => $email,
                                'domain' => $domain,
                                'valid' => $vrfyResult['valid'],
                                'confidence' => $vrfyResult['confidence'] ?? 95,
                            ]);
                            
                            return [
                                'valid' => $vrfyResult['valid'],
                                'mailbox_full' => false,
                                'catch_all' => false, // VRFY bypasses catch-all
                                'verification_method' => $vrfyResult['method'] ?? 'vrfy',
                                'confidence' => $vrfyResult['confidence'] ?? 95,
                            ];
                        }
                        
                        // Try EXPN if VRFY didn't work
                        $expnResult = $this->tryExpnCommand($socket, $host, $email);
                        if ($expnResult !== null) {
                            @fwrite($socket, "QUIT\r\n");
                            @fclose($socket);
                            
                            Log::info('Email verified using EXPN command', [
                                'email' => $email,
                                'domain' => $domain,
                                'valid' => $expnResult['valid'],
                                'confidence' => $expnResult['confidence'] ?? 90,
                            ]);
                            
                            return [
                                'valid' => $expnResult['valid'],
                                'mailbox_full' => false,
                                'catch_all' => false, // EXPN bypasses catch-all
                                'verification_method' => $expnResult['method'] ?? 'expn',
                                'confidence' => $expnResult['confidence'] ?? 90,
                            ];
                        }
                    }
                    
                    // VRFY/EXPN didn't work, proceed with catch-all test (AfterShip method)
                    // Now test catch-all with random email
                    $randomEmail = $this->domainValidationService->generateRandomEmail($domain);
                    $catchAllResult = $this->performRcptToOnly($socket, $host, $randomEmail);
                    
                    // If random email is rejected (550 5.1.1), it's NOT catch-all
                    // If random email is accepted, it IS catch-all
                    if ($catchAllResult['rejected'] && $catchAllResult['code'] === 550) {
                        $isCatchAll = false; // Server rejected random email = not catch-all
                    } else {
                        $isCatchAll = true; // Server accepted random email = catch-all
                    }
                    
                    Log::debug('Catch-all check result', [
                        'domain' => $domain,
                        'random_email' => $randomEmail,
                        'is_catch_all' => $isCatchAll,
                        'response_code' => $catchAllResult['code'] ?? null,
                        'vrfy_supported' => $vrfyResult === null ? 'not_supported' : 'supported',
                    ]);
                } else {
                    // Public provider = always catch-all, but we skip the test
                    $isCatchAll = true;
                }
            }
            
            // If catch-all = true, don't check specific email (AfterShip optimization)
            if ($isCatchAll) {
                @fwrite($socket, "QUIT\r\n");
                @fclose($socket);
                
                return [
                    'valid' => false, // Can't verify if specific email exists
                    'mailbox_full' => false,
                    'catch_all' => true,
                ];
            }
            
            // Catch-all = false, so check specific email
            // Socket already has handshake done, just do RCPT TO
            $result = $this->performRcptToOnly($socket, $host, $email);
            
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);
            
            return [
                'valid' => $result['valid'] ?? false,
                'mailbox_full' => $result['mailbox_full'] ?? false,
                'catch_all' => false,
            ];
            
        } catch (\Exception $e) {
            @fclose($socket);
            Log::warning('SMTP check failed', [
                'email' => $email,
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
            return ['valid' => false, 'mailbox_full' => false, 'catch_all' => false];
        }
    }

    /**
     * Simple SMTP check (returns bool)
     */
    public function checkSmtp(string $email, string $domain): bool
    {
        return $this->checkSmtpWithDetails($email, $domain)['valid'];
    }

    /**
     * Get concurrent MX connection (AfterShip method)
     * Attempts to connect to all MX servers simultaneously and returns first successful connection
     */
    private function getConcurrentMxConnection(array $mxRecords): ?array
    {
        if (empty($mxRecords)) {
            return null;
        }
        
        $connectTimeout = $this->getSmtpConnectTimeout();
        
        // Try to connect to all MX servers with quick timeout, use first that succeeds
        foreach ($mxRecords as $mx) {
            $host = $mx['host'];
            
            // Quick connection attempt
            $socket = @fsockopen($host, 25, $errno, $errstr, $connectTimeout);
            
            if ($socket !== false) {
                // Successfully connected - return this connection
                return [
                    'host' => $host,
                    'socket' => $socket,
                ];
            }
        }
        
        // All attempts failed - return null to use sequential fallback
        return null;
    }

    /**
     * Try VRFY command to verify email existence
     */
    private function tryVrfyCommand($socket, string $host, string $email): ?array
    {
        if (!config('email-verification.enable_vrfy_check', true)) {
            return null;
        }
        
        $operationTimeout = $this->getSmtpOperationTimeout();
        stream_set_timeout($socket, $operationTimeout, 0);
        
        $isTimeout = function() use ($socket) {
            $info = stream_get_meta_data($socket);
            return $info['timed_out'] ?? false;
        };
        
        try {
            // VRFY command
            @fwrite($socket, "VRFY {$email}\r\n");
            $response = @fgets($socket, 515);
            
            if (!$response || $isTimeout()) {
                return null;
            }
            
            $code = (int)substr($response, 0, 3);
            
            // 250/251 = Email exists
            if ($code === 250 || $code === 251) {
                return [
                    'valid' => true,
                    'method' => 'vrfy',
                    'confidence' => 95,
                ];
            }
            
            // 550 = Email doesn't exist
            if ($code === 550) {
                return [
                    'valid' => false,
                    'method' => 'vrfy',
                    'confidence' => 95,
                ];
            }
            
            // 502 = Command not implemented (most common)
            // 252 = Cannot verify (catch-all or other reason)
            // 500 = Syntax error
            // 501 = Parameter syntax error
            if (in_array($code, [502, 252, 500, 501], true)) {
                return null; // Command not supported, try other methods
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::warning('VRFY command failed', [
                'host' => $host,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Try EXPN command to verify email existence
     */
    private function tryExpnCommand($socket, string $host, string $email): ?array
    {
        if (!config('email-verification.enable_vrfy_check', true)) {
            return null; // Use same config as VRFY
        }
        
        $operationTimeout = $this->getSmtpOperationTimeout();
        stream_set_timeout($socket, $operationTimeout, 0);
        
        $isTimeout = function() use ($socket) {
            $info = stream_get_meta_data($socket);
            return $info['timed_out'] ?? false;
        };
        
        try {
            // EXPN command
            @fwrite($socket, "EXPN {$email}\r\n");
            $response = @fgets($socket, 515);
            
            if (!$response || $isTimeout()) {
                return null;
            }
            
            $code = (int)substr($response, 0, 3);
            
            // 250 = Email/mailing list exists
            if ($code === 250) {
                return [
                    'valid' => true,
                    'method' => 'expn',
                    'confidence' => 90,
                ];
            }
            
            // 550 = Email/mailing list doesn't exist
            if ($code === 550) {
                return [
                    'valid' => false,
                    'method' => 'expn',
                    'confidence' => 90,
                ];
            }
            
            // 502 = Command not implemented (most common)
            if (in_array($code, [502, 500, 501], true)) {
                return null; // Command not supported
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::warning('EXPN command failed', [
                'host' => $host,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Perform SMTP handshake (greeting, EHLO, MAIL FROM)
     */
    private function performSmtpHandshake($socket, string $host): array
    {
        $operationTimeout = $this->getSmtpOperationTimeout();
        stream_set_timeout($socket, $operationTimeout, 0);
        
        $isTimeout = function() use ($socket) {
            $info = stream_get_meta_data($socket);
            return $info['timed_out'] ?? false;
        };
        
        try {
            // Read greeting with timeout check
            $response = @fgets($socket, 515);
            if ($isTimeout() || !$response || !str_starts_with($response, '220')) {
                return ['success' => false];
            }
            
            // EHLO with configurable hostname
            $heloHostname = $this->getSmtpHeloHostname();
            @fwrite($socket, "EHLO {$heloHostname}\r\n");
            $response = @fgets($socket, 515);
            if ($isTimeout() || !$response || !str_starts_with($response, '250')) {
                return ['success' => false];
            }
            
            // Read all EHLO responses (multi-line) with timeout protection
            $maxLines = 10;
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
                return ['success' => false];
            }
            
            return ['success' => true];
            
        } catch (\Exception $e) {
            Log::warning('SMTP handshake failed', [
                'host' => $host,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false];
        }
    }

    /**
     * Perform RCPT TO only (assumes handshake already done)
     */
    private function performRcptToOnly($socket, string $host, string $email): array
    {
        $operationTimeout = $this->getSmtpOperationTimeout();
        stream_set_timeout($socket, $operationTimeout, 0);
        
        $isTimeout = function() use ($socket) {
            $info = stream_get_meta_data($socket);
            return $info['timed_out'] ?? false;
        };
        
        try {
            // RCPT TO
            @fwrite($socket, "RCPT TO: <{$email}>\r\n");
            $response = @fgets($socket, 515);
            
            if (!$response || $isTimeout()) {
                return ['valid' => false, 'mailbox_full' => false, 'rejected' => true, 'code' => null];
            }
            
            // Analyze SMTP response
            $responseAnalysis = $this->analyzeSmtpResponse($response);
            
            // Check for greylisting (4xx responses) - retry after delay
            if ($responseAnalysis['is_greylisting'] && config('email-verification.enable_greylisting_retry', false)) {
                $retryDelay = config('email-verification.greylisting_retry_delay', 2);
                sleep($retryDelay);
                
                // Retry RCPT TO
                @fwrite($socket, "RCPT TO: <{$email}>\r\n");
                $retryResponse = @fgets($socket, 515);
                
                if ($retryResponse && !$isTimeout()) {
                    $responseAnalysis = $this->analyzeSmtpResponse($retryResponse);
                }
            }
            
            // Check if rejected (550 = mailbox not found)
            $isRejected = $responseAnalysis['is_invalid'] && ($responseAnalysis['code'] === 550);
            
            return [
                'valid' => $responseAnalysis['is_valid'],
                'mailbox_full' => $responseAnalysis['is_mailbox_full'] ?? false,
                'rejected' => $isRejected,
                'code' => $responseAnalysis['code'] ?? null,
            ];
            
        } catch (\Exception $e) {
            Log::warning('RCPT TO check failed', [
                'host' => $host,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            return ['valid' => false, 'mailbox_full' => false, 'rejected' => true, 'code' => null];
        }
    }

    /**
     * Fallback: Perform sequential SMTP check (backward compatibility)
     */
    private function performSequentialSmtpCheck(array $mxRecordsToTry, string $email): array
    {
        $mailboxFull = false;
        
        foreach ($mxRecordsToTry as $mx) {
            $host = $mx['host'];
            $retries = $this->getSmtpRetries();
            
            for ($attempt = 0; $attempt < $retries; $attempt++) {
                $result = $this->performSmtpCheckWithDetails($host, $email);
                
                if ($result['valid']) {
                    return ['valid' => true, 'mailbox_full' => $result['mailbox_full'] ?? false, 'catch_all' => false];
                }
                
                // Track mailbox full status
                if ($result['mailbox_full'] ?? false) {
                    $mailboxFull = true;
                }
                
                // If connection failed, add to skip list (if auto-add enabled)
                if ($attempt === $retries - 1) {
                    $this->domainValidationService->addMxToSkipList($host, 'SMTP connection failed');
                }
                
                // Skip retry delay on last attempt to save time
                if ($attempt < $retries - 1) {
                    usleep(300000); // 0.3 second delay between retries
                }
            }
        }

        return ['valid' => false, 'mailbox_full' => $mailboxFull, 'catch_all' => false];
    }

    /**
     * Perform SMTP check with details (sequential fallback)
     */
    private function performSmtpCheckWithDetails(string $host, string $email): array
    {
        $connectTimeout = $this->getSmtpConnectTimeout();
        $operationTimeout = $this->getSmtpOperationTimeout();
        
        $socket = @fsockopen($host, 25, $errno, $errstr, $connectTimeout);
        
        if (!$socket) {
            $this->domainValidationService->addMxToSkipList($host, 'SMTP connection failed');
            return ['valid' => false, 'mailbox_full' => false, 'catch_all' => false];
        }

        try {
            stream_set_timeout($socket, $operationTimeout, 0);
            
            $isTimeout = function() use ($socket) {
                $info = stream_get_meta_data($socket);
                return $info['timed_out'] ?? false;
            };
            
            // Read greeting
            $response = @fgets($socket, 515);
            if ($isTimeout() || !$response || !str_starts_with($response, '220')) {
                @fclose($socket);
                return ['valid' => false, 'mailbox_full' => false, 'catch_all' => false];
            }

            // EHLO
            $heloHostname = $this->getSmtpHeloHostname();
            @fwrite($socket, "EHLO {$heloHostname}\r\n");
            $response = @fgets($socket, 515);
            if ($isTimeout() || !$response || !str_starts_with($response, '250')) {
                @fclose($socket);
                return ['valid' => false, 'mailbox_full' => false, 'catch_all' => false];
            }

            // Read all EHLO responses
            $maxLines = 10;
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
                return ['valid' => false, 'mailbox_full' => false, 'catch_all' => false];
            }

            // RCPT TO
            @fwrite($socket, "RCPT TO: <{$email}>\r\n");
            $response = @fgets($socket, 515);
            
            if (!$response || $isTimeout()) {
                @fwrite($socket, "QUIT\r\n");
                @fclose($socket);
                return ['valid' => false, 'mailbox_full' => false];
            }
            
            // Analyze SMTP response
            $responseAnalysis = $this->analyzeSmtpResponse($response);
            
            // Check for greylisting
            if ($responseAnalysis['is_greylisting'] && config('email-verification.enable_greylisting_retry', false)) {
                $retryDelay = config('email-verification.greylisting_retry_delay', 5);
                sleep($retryDelay);
                
                @fwrite($socket, "RCPT TO: <{$email}>\r\n");
                $retryResponse = @fgets($socket, 515);
                
                if ($retryResponse && !$isTimeout()) {
                    $responseAnalysis = $this->analyzeSmtpResponse($retryResponse);
                }
            }
            
            // Check for error patterns
            if (!$responseAnalysis['is_valid'] && !$responseAnalysis['is_greylisting']) {
                $errorPatterns = config('email-verification.smtp_error_patterns', []);
                $responseLower = strtolower($response);
                
                foreach ($errorPatterns as $pattern) {
                    if (str_contains($responseLower, strtolower($pattern))) {
                        $this->domainValidationService->addMxToSkipList($host, "SMTP error: {$pattern}", trim($response));
                        @fwrite($socket, "QUIT\r\n");
                        @fclose($socket);
                        return ['valid' => false, 'mailbox_full' => false, 'catch_all' => false];
                    }
                }
            }
            
            // QUIT
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);

            return [
                'valid' => $responseAnalysis['is_valid'],
                'mailbox_full' => $responseAnalysis['is_mailbox_full'] ?? false,
                'catch_all' => false,
            ];

        } catch (\Exception $e) {
            @fclose($socket);
            return ['valid' => false, 'mailbox_full' => false, 'catch_all' => false];
        }
    }

    /**
     * Analyze SMTP response code and message
     */
    private function analyzeSmtpResponse(string $response): array
    {
        $code = (int)substr($response, 0, 3);
        $message = trim(substr($response, 4));
        $messageLower = strtolower($message);
        
        // Check for mailbox full indicators
        $mailboxFullIndicators = [
            'mailbox full',
            'quota exceeded',
            'quota full',
            'storage quota',
            'mailbox quota',
            'over quota',
            'exceeded storage',
            'mailbox is full',
            'inbox full',
        ];
        
        $isMailboxFull = false;
        foreach ($mailboxFullIndicators as $indicator) {
            if (str_contains($messageLower, $indicator)) {
                $isMailboxFull = true;
                break;
            }
        }
        
        // Also check for specific error codes that indicate mailbox full
        if ($code === 552) {
            $isMailboxFull = true;
        }
        
        return [
            'code' => $code,
            'message' => $message,
            'is_greylisting' => in_array($code, [450, 451, 452], true),
            'is_catch_all' => in_array($code, [251, 252], true),
            'is_valid' => in_array($code, [250, 251, 252], true),
            'is_invalid' => in_array($code, [550, 551, 552, 553, 554], true),
            'is_temporary' => $code >= 400 && $code < 500,
            'is_permanent' => $code >= 500 && $code < 600,
            'is_mailbox_full' => $isMailboxFull,
        ];
    }

    /**
     * Check SMTP rate limit
     */
    public function checkSmtpRateLimit(string $domain): bool
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
            RateLimiter::hit($globalKey, 60);
        }

        // Per-domain rate limit (most important - prevents ban from specific servers)
        $domainKey = 'smtp_check_domain_' . md5($domain);
        $domainLimit = RateLimiter::tooManyAttempts($domainKey, $rateLimit['max_checks_per_domain_per_minute']);
        if ($domainLimit) {
            $retryAfter = 60;
            
            // If we're in a queue job context, throw exception to trigger retry
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
            foreach ($backtrace as $trace) {
                if (isset($trace['class']) && str_contains($trace['class'], 'VerifyEmailJob')) {
                    throw new SmtpRateLimitExceededException($domain, $retryAfter);
                }
            }
            
            Log::info('SMTP rate limit exceeded (domain) - skipping check', [
                'domain' => $domain,
                'limit' => $rateLimit['max_checks_per_domain_per_minute'],
            ]);
            return false;
        }

        RateLimiter::hit($domainKey, 60);

        // Add small delay between checks to same domain
        $delay = $rateLimit['delay_between_checks'] ?? 0.5;
        if ($delay > 0) {
            usleep((int)($delay * 1000000));
        }

        return true;
    }

    // Configuration getters
    private function getSmtpConnectTimeout(): int
    {
        return config('email-verification.smtp_connect_timeout', config('email-verification.smtp_timeout', 2));
    }

    private function getSmtpOperationTimeout(): int
    {
        return config('email-verification.smtp_operation_timeout', config('email-verification.smtp_timeout', 5));
    }

    private function getSmtpRetries(): int
    {
        return config('email-verification.smtp_retries', 1);
    }

    private function getSmtpHeloHostname(): string
    {
        return config('email-verification.smtp_helo_hostname') ?? gethostname();
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
}

