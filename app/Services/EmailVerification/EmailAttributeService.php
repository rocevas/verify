<?php

namespace App\Services\EmailVerification;

class EmailAttributeService
{
    /**
     * Analyze email attributes similar to Emailable
     * Returns numerical, alphabetical, and unicode character counts
     * 
     * @param string $email
     * @return array
     */
    public function analyzeEmailAttributes(string $email): array
    {
        $localPart = explode('@', $email)[0] ?? '';
        
        return [
            'numerical_characters' => $this->countNumericalCharacters($localPart),
            'alphabetical_characters' => $this->countAlphabeticalCharacters($localPart),
            'unicode_symbols' => $this->countUnicodeSymbols($localPart),
        ];
    }

    /**
     * Count numerical characters in email local part
     * Values greater than 0 indicate a higher chance of a bad address
     * 
     * @param string $localPart
     * @return int
     */
    private function countNumericalCharacters(string $localPart): int
    {
        return preg_match_all('/[0-9]/', $localPart);
    }

    /**
     * Count alphabetical characters in email local part
     * Values closer to 0 indicate a higher chance of a bad address
     * 
     * @param string $localPart
     * @return int
     */
    private function countAlphabeticalCharacters(string $localPart): int
    {
        return preg_match_all('/[a-zA-Z]/', $localPart);
    }

    /**
     * Count Unicode symbols in email local part
     * A value higher than 0 could indicate an international or less deliverable address
     * 
     * @param string $localPart
     * @return int
     */
    private function countUnicodeSymbols(string $localPart): int
    {
        // Count non-ASCII characters (Unicode symbols)
        $count = 0;
        $length = mb_strlen($localPart, 'UTF-8');
        
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($localPart, $i, 1, 'UTF-8');
            $code = mb_ord($char, 'UTF-8');
            
            // Check if character is outside ASCII range (0-127)
            // Exclude common allowed characters: dots, plus, dash, underscore
            if ($code > 127 && !in_array($char, ['.', '+', '-', '_'])) {
                $count++;
            }
        }
        
        return $count;
    }

    /**
     * Detect SMTP provider from MX records or SMTP server
     * Similar to Emailable's SMTP Provider field
     * 
     * @param array $mxRecords
     * @param string|null $smtpHost
     * @return string|null Provider name (e.g., "Cloudflare", "Google", "Microsoft")
     */
    public function detectSmtpProvider(array $mxRecords = [], ?string $smtpHost = null): ?string
    {
        $providerMap = config('email-verification.smtp_providers', [
            // Google
            'google' => ['gmail-smtp-in', 'aspmx', 'google', 'googlemail'],
            // Microsoft
            'Microsoft' => ['outlook', 'hotmail', 'live', 'msn', 'exchange', 'office365', 'olc.protection'],
            // Cloudflare
            'Cloudflare' => ['cloudflare', 'mx.cloudflare'],
            // Yahoo
            'Yahoo' => ['yahoo', 'yahoo-smtp'],
            // Amazon SES
            'Amazon SES' => ['amazonses', 'aws'],
            // SendGrid
            'SendGrid' => ['sendgrid'],
            // Mailgun
            'Mailgun' => ['mailgun'],
            // Zoho
            'Zoho' => ['zoho'],
            // FastMail
            'FastMail' => ['fastmail'],
            // ProtonMail
            'ProtonMail' => ['protonmail', 'proton'],
        ]);

        $checkHosts = [];
        
        // Add MX record hosts
        foreach ($mxRecords as $mx) {
            if (isset($mx['host'])) {
                $checkHosts[] = strtolower($mx['host']);
            }
        }
        
        // Add SMTP host if provided
        if ($smtpHost) {
            $checkHosts[] = strtolower($smtpHost);
        }

        // Check each host against provider patterns
        foreach ($checkHosts as $host) {
            foreach ($providerMap as $providerName => $patterns) {
                foreach ($patterns as $pattern) {
                    if (str_contains($host, strtolower($pattern))) {
                        return $providerName;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Check if domain uses implicit MX record
     * Implicit MX means domain accepts mail even without explicit MX record (falls back to A record)
     * 
     * @param string $domain
     * @return bool
     */
    public function checkImplicitMx(string $domain): bool
    {
        // If no MX records exist, check if domain has A record
        // This indicates implicit MX (mail server on same domain)
        $domainValidationService = app(DomainValidationService::class);
        $hasMx = $domainValidationService->checkMx($domain);
        
        if (!$hasMx) {
            // No MX records, check for A record
            $resolvedIp = @gethostbyname($domain);
            if ($resolvedIp !== $domain && filter_var($resolvedIp, FILTER_VALIDATE_IP)) {
                return true; // Has A record but no MX = implicit MX
            }
        }
        
        return false;
    }

    /**
     * Check if email uses Secure Email Gateway (SEG)
     * SEGs are email security services that scan emails before delivery
     * Examples: Proofpoint, Mimecast, Barracuda, etc.
     * 
     * @param array $mxRecords
     * @return bool
     */
    public function checkSecureEmailGateway(array $mxRecords = []): bool
    {
        $segPatterns = config('email-verification.seg_patterns', [
            'proofpoint',
            'mimecast',
            'barracuda',
            'symantec',
            'messaging',
            'ironport',
            'cisco',
            'forcepoint',
            'trendmicro',
            'sophos',
            'fortimail',
            'checkpoint',
        ]);

        foreach ($mxRecords as $mx) {
            $host = strtolower($mx['host'] ?? '');
            foreach ($segPatterns as $pattern) {
                if (str_contains($host, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }
}

