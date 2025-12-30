<?php

namespace App\Services;

use App\Models\EmailCampaign;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class SpamAssassinCheckService
{
    private string $spamAssassinHost;
    private int $spamAssassinPort;
    private float $spamThreshold;

    public function __construct()
    {
        $this->spamAssassinHost = config('services.spamassassin.host', 'spamassassin');
        $this->spamAssassinPort = config('services.spamassassin.port', 783);
        $this->spamThreshold = config('services.spamassassin.threshold', 5.0);
    }

    /**
     * Check email campaign with SpamAssassin
     */
    public function checkCampaign(EmailCampaign $campaign): array
    {
        try {
            // Build email message
            $emailMessage = $this->buildEmailMessage($campaign);
            
            // Send to SpamAssassin
            $result = $this->checkWithSpamAssassin($emailMessage);
            
            // Parse result - pass campaign for context
            $parsed = $this->parseSpamAssassinResult($result, $campaign, $emailMessage);
            
            // Generate recommendations
            $recommendations = $this->generateRecommendations($campaign, $parsed);
            
            return [
                'spam_score' => $parsed['score'],
                'spam_threshold' => $this->spamThreshold,
                'is_spam' => $parsed['score'] >= $this->spamThreshold,
                'spam_rules' => $parsed['rules'],
                'check_details' => $parsed,
                'deliverability_score' => $this->calculateDeliverabilityScore($parsed),
                'recommendations' => $recommendations,
            ];
        } catch (\Exception $e) {
            Log::error('SpamAssassin check failed', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Build email message from campaign
     */
    private function buildEmailMessage(EmailCampaign $campaign): string
    {
        $message = [];
        
        // Headers
        $message[] = "From: " . ($campaign->from_name ? "{$campaign->from_name} <{$campaign->from_email}>" : $campaign->from_email);
        $message[] = "To: " . (is_array($campaign->to_emails) ? implode(', ', $campaign->to_emails) : ($campaign->to_emails ?? 'test@example.com'));
        $message[] = "Subject: {$campaign->subject}";
        
        if ($campaign->reply_to) {
            $message[] = "Reply-To: {$campaign->reply_to}";
        }
        
        // Custom headers
        if ($campaign->headers && is_array($campaign->headers)) {
            foreach ($campaign->headers as $key => $value) {
                $message[] = "{$key}: {$value}";
            }
        }
        
        $message[] = "Date: " . date('r');
        $message[] = "Message-ID: <" . uniqid() . "@" . parse_url($campaign->from_email, PHP_URL_HOST) . ">";
        $message[] = "MIME-Version: 1.0";
        
        // Body
        if ($campaign->html_content && $campaign->text_content) {
            // Multipart message
            $boundary = uniqid('boundary_');
            $message[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
            $message[] = "";
            $message[] = "--{$boundary}";
            $message[] = "Content-Type: text/plain; charset=UTF-8";
            $message[] = "Content-Transfer-Encoding: 8bit";
            $message[] = "";
            $message[] = $campaign->text_content;
            $message[] = "";
            $message[] = "--{$boundary}";
            $message[] = "Content-Type: text/html; charset=UTF-8";
            $message[] = "Content-Transfer-Encoding: 8bit";
            $message[] = "";
            $message[] = $campaign->html_content;
            $message[] = "";
            $message[] = "--{$boundary}--";
        } elseif ($campaign->html_content) {
            $message[] = "Content-Type: text/html; charset=UTF-8";
            $message[] = "Content-Transfer-Encoding: 8bit";
            $message[] = "";
            $message[] = $campaign->html_content;
        } else {
            $message[] = "Content-Type: text/plain; charset=UTF-8";
            $message[] = "Content-Transfer-Encoding: 8bit";
            $message[] = "";
            $message[] = $campaign->text_content ?? '';
        }
        
        return implode("\n", $message);
    }

    /**
     * Check email with SpamAssassin using spamc or socket connection
     */
    private function checkWithSpamAssassin(string $emailMessage): string
    {
        // Try using spamc command first
        $spamcPath = $this->findSpamcPath();
        
        if ($spamcPath) {
            return $this->checkWithSpamc($spamcPath, $emailMessage);
        }
        
        // Fallback to socket connection
        return $this->checkWithSocket($emailMessage);
    }

    /**
     * Find spamc executable path
     */
    private function findSpamcPath(): ?string
    {
        $paths = ['/usr/bin/spamc', '/usr/local/bin/spamc', 'spamc'];
        
        foreach ($paths as $path) {
            if ($path === 'spamc') {
                // Check if spamc is in PATH
                $result = Process::run('which spamc');
                if ($result->successful() && !empty(trim($result->output()))) {
                    return 'spamc';
                }
            } elseif (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }
        
        return null;
    }

    /**
     * Check using spamc command
     */
    private function checkWithSpamc(string $spamcPath, string $emailMessage): string
    {
        $command = sprintf(
            '%s -c -H %s -p %d',
            escapeshellarg($spamcPath),
            escapeshellarg($this->spamAssassinHost),
            escapeshellarg($this->spamAssassinPort)
        );
        
        $process = Process::run($command)
            ->input($emailMessage);
        
        if (!$process->successful()) {
            throw new \RuntimeException('SpamAssassin check failed: ' . $process->errorOutput());
        }
        
        return $process->output();
    }

    /**
     * Check using socket connection (fallback) - uses SpamAssassin protocol
     */
    private function checkWithSocket(string $emailMessage): string
    {
        $socket = @fsockopen($this->spamAssassinHost, $this->spamAssassinPort, $errno, $errstr, 5);
        
        if (!$socket) {
            throw new \RuntimeException("Failed to connect to SpamAssassin: {$errstr} ({$errno}). Make sure SpamAssassin container is running.");
        }
        
        // SpamAssassin protocol: send REPORT command to get detailed results
        // Format: SPAMC/1.5\r\nContent-length: <length>\r\n\r\n<email>
        $contentLength = strlen($emailMessage);
        $request = "REPORT SPAMC/1.5\r\n";
        $request .= "Content-length: {$contentLength}\r\n";
        $request .= "\r\n";
        $request .= $emailMessage;
        
        fwrite($socket, $request);
        
        // Read response headers
        $response = '';
        $headers = true;
        $contentLength = 0;
        
        while (!feof($socket)) {
            $line = fgets($socket, 4096);
            
            if ($headers) {
                $response .= $line;
                
                // Check for empty line (end of headers)
                if (trim($line) === '') {
                    $headers = false;
                    continue;
                }
                
                // Extract content length
                if (stripos($line, 'Content-length:') === 0) {
                    $contentLength = (int) trim(substr($line, 15));
                }
            } else {
                // Read body
                $response .= $line;
                
                // Stop if we've read all content
                if ($contentLength > 0) {
                    $bodyLength = strlen($response) - strlen(explode("\r\n\r\n", $response, 2)[0] ?? '') - 4;
                    if ($bodyLength >= $contentLength) {
                        break;
                    }
                }
            }
        }
        
        fclose($socket);
        
        return $response;
    }

    /**
     * Parse SpamAssassin result with detailed information
     */
    private function parseSpamAssassinResult(string $result, EmailCampaign $campaign = null, string $originalEmail = ''): array
    {
        $parsed = [
            'score' => 0.0,
            'threshold' => $this->spamThreshold,
            'rules' => [],
            'raw' => $result,
            'headers' => [],
            'body_analysis' => [],
            'content_analysis' => [],
        ];
        
        // Extract headers and body - SpamAssassin returns headers and body separately
        // First try to split by double newline
        $parts = explode("\r\n\r\n", $result, 2);
        if (count($parts) < 2) {
            // Try single newline
            $parts = explode("\n\n", $result, 2);
        }
        $spamHeaders = $parts[0] ?? '';
        $spamBody = $parts[1] ?? '';
        
        // Parse SpamAssassin response headers (X-Spam-*)
        $headerLines = explode("\r\n", $spamHeaders);
        if (count($headerLines) === 1 && strpos($spamHeaders, "\n") !== false) {
            $headerLines = explode("\n", $spamHeaders);
        }
        
        foreach ($headerLines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            if (preg_match('/^([^:]+):\s*(.+)$/', $line, $matches)) {
                $parsed['headers'][trim($matches[1])] = trim($matches[2]);
            }
        }
        
        // Extract original email headers and body for analysis
        $originalHeaders = [];
        $originalBody = '';
        
        if (!empty($originalEmail)) {
            $emailParts = explode("\r\n\r\n", $originalEmail, 2);
            if (count($emailParts) < 2) {
                $emailParts = explode("\n\n", $originalEmail, 2);
            }
            $emailHeaderLines = explode("\r\n", $emailParts[0] ?? '');
            if (count($emailHeaderLines) === 1 && strpos($emailParts[0] ?? '', "\n") !== false) {
                $emailHeaderLines = explode("\n", $emailParts[0] ?? '');
            }
            
            foreach ($emailHeaderLines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                if (preg_match('/^([^:]+):\s*(.+)$/', $line, $matches)) {
                    $headerName = trim($matches[1]);
                    $headerValue = trim($matches[2]);
                    $originalHeaders[strtolower($headerName)] = $headerValue;
                    // Also store with original case
                    $originalHeaders[$headerName] = $headerValue;
                }
            }
            
            $originalBody = $emailParts[1] ?? '';
        }
        
        // Merge original headers into parsed headers for analysis
        foreach ($originalHeaders as $key => $value) {
            if (!isset($parsed['headers'][$key])) {
                $parsed['headers'][$key] = $value;
            }
        }
        
        // Parse X-Spam-Status header for detailed info
        if (isset($parsed['headers']['X-Spam-Status'])) {
            $statusLine = $parsed['headers']['X-Spam-Status'];
            
            // Parse score
            if (preg_match('/score=([\d.]+)\/([\d.]+)/', $statusLine, $matches)) {
                $parsed['score'] = (float) $matches[1];
                $parsed['threshold'] = (float) $matches[2];
            }
            
            // Parse required score
            if (preg_match('/required=([\d.]+)/', $statusLine, $matches)) {
                $parsed['required'] = (float) $matches[1];
            }
            
            // Parse tests (rules)
            if (preg_match('/tests=([^\s,]+(?:,[^\s,]+)*)/', $statusLine, $matches)) {
                $rulesString = $matches[1];
                $rules = explode(',', $rulesString);
                
                foreach ($rules as $rule) {
                    $rule = trim($rule);
                    if (empty($rule)) continue;
                    
                    // Parse rule with score: RULE_NAME=score or RULE_NAME
                    if (strpos($rule, '=') !== false) {
                        [$name, $score] = explode('=', $rule, 2);
                        $parsed['rules'][] = [
                            'name' => trim($name),
                            'score' => (float) trim($score),
                            'description' => $this->getRuleDescription(trim($name)),
                            'category' => $this->getRuleCategory(trim($name)),
                            'severity' => $this->getRuleSeverity((float) trim($score)),
                        ];
                    } else {
                        $parsed['rules'][] = [
                            'name' => $rule,
                            'score' => 0.0,
                            'description' => $this->getRuleDescription($rule),
                            'category' => $this->getRuleCategory($rule),
                            'severity' => 'info',
                        ];
                    }
                }
            }
        }
        
        // Parse X-Spam-Level header
        if (isset($parsed['headers']['X-Spam-Level'])) {
            $parsed['spam_level'] = $parsed['headers']['X-Spam-Level'];
        }
        
        // Parse X-Spam-Flag header
        if (isset($parsed['headers']['X-Spam-Flag'])) {
            $parsed['is_spam_flag'] = strtoupper(trim($parsed['headers']['X-Spam-Flag'])) === 'YES';
        }
        
        // Analyze body content - use original body or campaign content
        $bodyToAnalyze = !empty($originalBody) ? $originalBody : ($campaign ? ($campaign->html_content ?? $campaign->text_content ?? '') : '');
        $parsed['body_analysis'] = $this->analyzeEmailBody($bodyToAnalyze, $campaign);
        
        // Analyze content
        $parsed['content_analysis'] = $this->analyzeContent($parsed);
        
        return $parsed;
    }
    
    /**
     * Get rule description
     */
    private function getRuleDescription(string $ruleName): string
    {
        $descriptions = [
            'BAYES_99' => 'Very high spam probability based on Bayesian analysis',
            'BAYES_80' => 'High spam probability based on Bayesian analysis',
            'BAYES_60' => 'Moderate spam probability based on Bayesian analysis',
            'URIBL_BLOCKED' => 'URL found in URIBL blacklist',
            'BLACKLISTED' => 'Sender or domain is blacklisted',
            'SUSPICIOUS_LINKS' => 'Email contains suspicious links',
            'HTML_MESSAGE' => 'Email contains HTML content',
            'MIME_HTML_ONLY' => 'Email contains only HTML (no plain text)',
            'HTML_FONT_LOW_CONTRAST' => 'HTML uses low contrast fonts (spam technique)',
            'HTML_MIME_NO_HTML_TAG' => 'HTML MIME type but no HTML tag found',
            'MISSING_MIMEOLE' => 'Missing MIME-OLE header',
            'SUBJ_ALL_CAPS' => 'Subject line is all caps',
            'SUBJ_EXCESSIVE_QUESTION' => 'Subject has excessive question marks',
            'SUBJ_EXCESSIVE_EXCLAIM' => 'Subject has excessive exclamation marks',
            'FROM_EXCESSIVE_EXCLAIM' => 'From field has excessive exclamation marks',
            'FROM_MISSPACED' => 'From field has mis-spaced characters',
            'MISSING_DATE' => 'Missing Date header',
            'MISSING_MID' => 'Missing Message-ID header',
            'MISSING_SUBJECT' => 'Missing Subject header',
            'NO_RECEIVED' => 'No Received headers found',
            'RCVD_IN_DNSWL_NONE' => 'Not in DNS whitelist',
            'RCVD_IN_MSPIKE_H2' => 'Received from medium reputation IP',
            'RCVD_IN_MSPIKE_L3' => 'Received from low reputation IP',
            'RCVD_IN_PBL' => 'Received from PBL (Policy Block List)',
            'RCVD_IN_SORBS_DUL' => 'Received from SORBS dynamic IP list',
            'RCVD_IN_XBL' => 'Received from XBL (Exploits Block List)',
            'SPF_FAIL' => 'SPF check failed',
            'SPF_HELO_FAIL' => 'SPF HELO check failed',
            'DKIM_SIGNED' => 'Email is DKIM signed',
            'DKIM_VALID' => 'DKIM signature is valid',
            'DKIM_INVALID' => 'DKIM signature is invalid',
        ];
        
        return $descriptions[$ruleName] ?? 'Spam rule triggered: ' . $ruleName;
    }
    
    /**
     * Get rule category
     */
    private function getRuleCategory(string $ruleName): string
    {
        if (strpos($ruleName, 'BAYES') === 0) return 'content_analysis';
        if (strpos($ruleName, 'URIBL') === 0 || strpos($ruleName, 'BLACKLIST') === 0) return 'reputation';
        if (strpos($ruleName, 'RCVD_IN') === 0) return 'network';
        if (strpos($ruleName, 'SPF') === 0 || strpos($ruleName, 'DKIM') === 0) return 'authentication';
        if (strpos($ruleName, 'HTML') === 0 || strpos($ruleName, 'MIME') === 0) return 'format';
        if (strpos($ruleName, 'SUBJ') === 0 || strpos($ruleName, 'FROM') === 0) return 'headers';
        if (strpos($ruleName, 'MISSING') === 0 || strpos($ruleName, 'NO_') === 0) return 'structure';
        
        return 'other';
    }
    
    /**
     * Get rule severity
     */
    private function getRuleSeverity(float $score): string
    {
        if ($score >= 2.0) return 'critical';
        if ($score >= 1.0) return 'high';
        if ($score >= 0.5) return 'medium';
        return 'low';
    }
    
    /**
     * Analyze email body
     */
    private function analyzeEmailBody(string $body): array
    {
        $analysis = [
            'length' => strlen($body),
            'has_html' => strpos($body, '<html') !== false || strpos($body, '<HTML') !== false,
            'has_images' => preg_match('/<img[^>]+>/i', $body) > 0,
            'has_links' => preg_match('/<a[^>]+href/i', $body) > 0,
            'link_count' => preg_match_all('/<a[^>]+href=["\']([^"\']+)["\']/i', $body, $matches),
            'image_count' => preg_match_all('/<img[^>]+>/i', $body, $matches),
            'has_scripts' => preg_match('/<script/i', $body) > 0,
            'has_iframes' => preg_match('/<iframe/i', $body) > 0,
        ];
        
        return $analysis;
    }
    
    /**
     * Analyze content for issues and positive results
     */
    private function analyzeContent(array $parsed): array
    {
        $issues = [];
        $warnings = [];
        $suggestions = [];
        $positiveResults = [];
        $checksPerformed = [];
        
        // Overall spam score check
        $checksPerformed[] = [
            'name' => 'Spam Score Check',
            'status' => $parsed['score'] < $parsed['threshold'] ? 'pass' : 'fail',
            'message' => "Score: {$parsed['score']} / Threshold: {$parsed['threshold']}",
            'icon' => $parsed['score'] < $parsed['threshold'] ? '✅' : '❌',
        ];
        
        // Check spam score
        if ($parsed['score'] >= $parsed['threshold']) {
            $issues[] = [
                'type' => 'critical',
                'title' => 'Email marked as SPAM',
                'message' => "Spam score ({$parsed['score']}) exceeds threshold ({$parsed['threshold']})",
                'icon' => '⚠️',
            ];
        } elseif ($parsed['score'] >= $parsed['threshold'] * 0.7) {
            $warnings[] = [
                'type' => 'warning',
                'title' => 'High spam score',
                'message' => "Spam score ({$parsed['score']}) is close to threshold ({$parsed['threshold']})",
                'icon' => '⚠️',
            ];
        } else {
            $positiveResults[] = [
                'type' => 'success',
                'title' => 'Spam score is acceptable',
                'message' => "Score ({$parsed['score']}) is well below threshold ({$parsed['threshold']})",
                'icon' => '✅',
            ];
        }
        
        // Analyze rules by category
        $rulesByCategory = [];
        foreach ($parsed['rules'] as $rule) {
            $category = $rule['category'] ?? 'other';
            if (!isset($rulesByCategory[$category])) {
                $rulesByCategory[$category] = [];
            }
            $rulesByCategory[$category][] = $rule;
        }
        
        // Check for critical rules
        foreach ($parsed['rules'] as $rule) {
            if (($rule['severity'] ?? '') === 'critical') {
                $issues[] = [
                    'type' => 'critical',
                    'title' => 'Critical rule triggered: ' . $rule['name'],
                    'message' => $rule['description'] ?? '',
                    'score' => $rule['score'],
                    'icon' => '🔴',
                ];
            }
        }
        
        // Authentication checks
        $hasSpf = false;
        $hasDkim = false;
        $spfValid = false;
        $dkimValid = false;
        
        foreach ($parsed['rules'] as $rule) {
            if (strpos($rule['name'], 'SPF') !== false) {
                $hasSpf = true;
                if (strpos($rule['name'], 'FAIL') !== false || strpos($rule['name'], 'SOFTFAIL') !== false) {
                    $issues[] = [
                        'type' => 'high',
                        'title' => 'SPF authentication failed',
                        'message' => 'SPF check failed - email may be rejected',
                        'icon' => '🔴',
                    ];
                    $checksPerformed[] = [
                        'name' => 'SPF Authentication',
                        'status' => 'fail',
                        'message' => 'SPF check failed',
                        'icon' => '❌',
                    ];
                } elseif (strpos($rule['name'], 'PASS') !== false || strpos($rule['name'], 'NEUTRAL') !== false) {
                    $spfValid = true;
                }
            }
            if (strpos($rule['name'], 'DKIM') !== false) {
                $hasDkim = true;
                if (strpos($rule['name'], 'INVALID') !== false) {
                    $issues[] = [
                        'type' => 'high',
                        'title' => 'DKIM signature invalid',
                        'message' => 'DKIM signature validation failed',
                        'icon' => '🔴',
                    ];
                    $checksPerformed[] = [
                        'name' => 'DKIM Authentication',
                        'status' => 'fail',
                        'message' => 'DKIM signature invalid',
                        'icon' => '❌',
                    ];
                } elseif (strpos($rule['name'], 'VALID') !== false || strpos($rule['name'], 'SIGNED') !== false) {
                    $dkimValid = true;
                }
            }
        }
        
        // Positive authentication results
        if ($spfValid) {
            $positiveResults[] = [
                'type' => 'success',
                'title' => 'SPF authentication passed',
                'message' => 'SPF record is properly configured and validated',
                'icon' => '✅',
            ];
            $checksPerformed[] = [
                'name' => 'SPF Authentication',
                'status' => 'pass',
                'message' => 'SPF check passed',
                'icon' => '✅',
            ];
        }
        
        if ($dkimValid) {
            $positiveResults[] = [
                'type' => 'success',
                'title' => 'DKIM signature valid',
                'message' => 'DKIM signature is properly signed and validated',
                'icon' => '✅',
            ];
            $checksPerformed[] = [
                'name' => 'DKIM Authentication',
                'status' => 'pass',
                'message' => 'DKIM signature valid',
                'icon' => '✅',
            ];
        }
        
        if (!$hasSpf && !$hasDkim) {
            $suggestions[] = [
                'type' => 'info',
                'title' => 'No authentication found',
                'message' => 'Consider implementing SPF and DKIM for better deliverability',
                'icon' => '💡',
            ];
            $checksPerformed[] = [
                'name' => 'Email Authentication',
                'status' => 'warning',
                'message' => 'No SPF or DKIM found',
                'icon' => '⚠️',
            ];
        }
        
        // Email structure checks - check both parsed headers and original email
        $hasDate = isset($parsed['headers']['Date']) || isset($parsed['headers']['date']);
        $hasMessageId = isset($parsed['headers']['Message-ID']) || isset($parsed['headers']['message-id']);
        $hasSubject = isset($parsed['headers']['Subject']) || isset($parsed['headers']['subject']);
        $hasFrom = isset($parsed['headers']['From']) || isset($parsed['headers']['from']);
        
        $checksPerformed[] = [
            'name' => 'Date Header',
            'status' => $hasDate ? 'pass' : 'fail',
            'message' => $hasDate ? 'Date header present' : 'Missing Date header',
            'icon' => $hasDate ? '✅' : '❌',
        ];
        
        $checksPerformed[] = [
            'name' => 'Message-ID Header',
            'status' => $hasMessageId ? 'pass' : 'fail',
            'message' => $hasMessageId ? 'Message-ID header present' : 'Missing Message-ID header',
            'icon' => $hasMessageId ? '✅' : '❌',
        ];
        
        $checksPerformed[] = [
            'name' => 'Subject Header',
            'status' => $hasSubject ? 'pass' : 'fail',
            'message' => $hasSubject ? 'Subject header present' : 'Missing Subject header',
            'icon' => $hasSubject ? '✅' : '❌',
        ];
        
        $checksPerformed[] = [
            'name' => 'From Header',
            'status' => $hasFrom ? 'pass' : 'fail',
            'message' => $hasFrom ? 'From header present' : 'Missing From header',
            'icon' => $hasFrom ? '✅' : '❌',
        ];
        
        // Positive structure results
        if ($hasDate && $hasMessageId && $hasSubject && $hasFrom) {
            $positiveResults[] = [
                'type' => 'success',
                'title' => 'All required headers present',
                'message' => 'Email contains all essential headers (Date, Message-ID, Subject, From)',
                'icon' => '✅',
            ];
        }
        
        // Body analysis checks
        if (isset($parsed['body_analysis'])) {
            $bodyAnalysis = $parsed['body_analysis'];
            
            $checksPerformed[] = [
                'name' => 'Email Format',
                'status' => $bodyAnalysis['has_html'] ? 'pass' : 'info',
                'message' => $bodyAnalysis['has_html'] ? 'HTML email detected' : 'Plain text email',
                'icon' => $bodyAnalysis['has_html'] ? '✅' : 'ℹ️',
            ];
            
            if (!$bodyAnalysis['has_scripts'] && !$bodyAnalysis['has_iframes']) {
                $positiveResults[] = [
                    'type' => 'success',
                    'title' => 'No suspicious content',
                    'message' => 'Email does not contain scripts or iframes',
                    'icon' => '✅',
                ];
            }
        }
        
        // Count total rules checked
        $totalRulesChecked = count($parsed['rules']);
        $checksPerformed[] = [
            'name' => 'Spam Rules Checked',
            'status' => 'info',
            'message' => "{$totalRulesChecked} spam rules evaluated",
            'icon' => 'ℹ️',
        ];
        
        return [
            'issues' => $issues,
            'warnings' => $warnings,
            'suggestions' => $suggestions,
            'positive_results' => $positiveResults,
            'checks_performed' => $checksPerformed,
            'rules_by_category' => $rulesByCategory,
            'summary' => [
                'total_checks' => count($checksPerformed),
                'passed' => count(array_filter($checksPerformed, fn($c) => $c['status'] === 'pass')),
                'failed' => count(array_filter($checksPerformed, fn($c) => $c['status'] === 'fail')),
                'warnings' => count(array_filter($checksPerformed, fn($c) => $c['status'] === 'warning')),
                'total_rules' => $totalRulesChecked,
            ],
        ];
    }

    /**
     * Calculate deliverability score
     */
    private function calculateDeliverabilityScore(array $parsed): array
    {
        $score = 100;
        $details = [];
        
        // Reduce score based on spam score
        if ($parsed['score'] > 0) {
            $spamPenalty = min($parsed['score'] * 10, 50); // Max 50 points penalty
            $score -= $spamPenalty;
            $details['spam_score_penalty'] = $spamPenalty;
        }
        
        // Check for common spam indicators
        $spamRules = array_column($parsed['rules'] ?? [], 'name');
        $highRiskRules = ['URIBL_BLOCKED', 'BLACKLISTED', 'SUSPICIOUS_LINKS'];
        
        foreach ($highRiskRules as $rule) {
            if (in_array($rule, $spamRules)) {
                $score -= 10;
                $details['high_risk_rule'] = $rule;
            }
        }
        
        $score = max(0, min(100, $score));
        
        return [
            'overall' => round($score, 1),
            'details' => $details,
            'grade' => $this->getGrade($score),
        ];
    }

    /**
     * Get grade from score
     */
    private function getGrade(float $score): string
    {
        if ($score >= 90) return 'A';
        if ($score >= 80) return 'B';
        if ($score >= 70) return 'C';
        if ($score >= 60) return 'D';
        return 'F';
    }

    /**
     * Generate recommendations
     */
    private function generateRecommendations(EmailCampaign $campaign, array $parsed): string
    {
        $recommendations = [];
        
        // Check spam score
        if ($parsed['score'] >= $this->spamThreshold) {
            $recommendations[] = "Spam score ({$parsed['score']}) exceeds threshold ({$this->spamThreshold}). Review content and structure.";
        } elseif ($parsed['score'] > $this->spamThreshold * 0.7) {
            $recommendations[] = "Spam score ({$parsed['score']}) is close to threshold. Consider improving content.";
        }
        
        // Check for missing text version
        if ($campaign->html_content && !$campaign->text_content) {
            $recommendations[] = "Add plain text version of email for better deliverability.";
        }
        
        // Check subject
        if (strlen($campaign->subject) > 50) {
            $recommendations[] = "Subject line is too long. Keep it under 50 characters.";
        }
        
        // Check for spam trigger words in subject
        $spamWords = ['free', 'click here', 'limited time', 'act now', 'urgent'];
        $subjectLower = strtolower($campaign->subject);
        foreach ($spamWords as $word) {
            if (strpos($subjectLower, $word) !== false) {
                $recommendations[] = "Subject contains potential spam trigger word: '{$word}'";
                break;
            }
        }
        
        // Check HTML content
        if ($campaign->html_content) {
            // Check for too many links
            $linkCount = substr_count($campaign->html_content, '<a href');
            if ($linkCount > 5) {
                $recommendations[] = "Email contains many links ({$linkCount}). Reduce to improve deliverability.";
            }
            
            // Check for images without alt text
            if (preg_match_all('/<img[^>]+>/i', $campaign->html_content, $images)) {
                $imagesWithoutAlt = 0;
                foreach ($images[0] as $img) {
                    if (!preg_match('/alt=["\']/i', $img)) {
                        $imagesWithoutAlt++;
                    }
                }
                if ($imagesWithoutAlt > 0) {
                    $recommendations[] = "Add alt text to {$imagesWithoutAlt} image(s) for better accessibility and deliverability.";
                }
            }
        }
        
        if (empty($recommendations)) {
            return "Email looks good! No major issues detected.";
        }
        
        return implode("\n", $recommendations);
    }
}

