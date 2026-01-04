<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Propaganistas\LaravelDisposableEmail\DisposableDomains;

class EmailParserService
{
    /**
     * Parse email address into account and domain parts
     * 
     * @param string $email
     * @return array|null ['account' => string, 'domain' => string] or null if invalid
     */
    public function parseEmail(string $email): ?array
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        // Use limit=2 to handle emails with @ in local part (though filter_var should prevent this)
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
     * Check if email syntax is valid
     * 
     * @param string $email
     * @return bool
     */
    public function checkSyntax(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Check if domain is disposable email provider
     * 
     * @param string $domain
     * @return bool
     */
    public function checkDisposable(string $domain): bool
    {
        try {
            return app(DisposableDomains::class)->isDisposable($domain);
        } catch (\Exception $e) {
            Log::warning('Disposable email check failed', ['domain' => $domain, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Check if account is role-based email (e.g., info@, support@)
     * 
     * @param string $account
     * @return bool
     */
    public function checkRoleBased(string $account): bool
    {
        $roleEmails = config('email-verification.role_emails', []);
        return in_array(strtolower($account), $roleEmails, true);
    }

    /**
     * Detect email alias and return canonical email address
     * Supports Gmail, Yahoo, Outlook/Hotmail aliases
     * 
     * @param string $email
     * @return string|null Canonical email address or null if not an alias
     */
    public function detectAlias(string $email): ?string
    {
        $parts = $this->parseEmail($email);
        if (!$parts) {
            return null;
        }
        
        $domain = strtolower($parts['domain']);
        $localPart = $parts['account'];
        
        // Gmail/GoogleMail alias detection
        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            // Gmail aliases: dots are ignored, plus addressing is supported
            // user.name+test@gmail.com -> username@gmail.com
            $canonical = $localPart;
            
            // Remove everything after plus sign
            if (($plusPos = strpos($canonical, '+')) !== false) {
                $canonical = substr($canonical, 0, $plusPos);
            }
            
            // Remove all dots
            $canonical = str_replace('.', '', $canonical);
            
            // Always use gmail.com as canonical domain
            return $canonical . '@gmail.com';
        }
        
        // Yahoo alias detection (format: username-alias@yahoo.com)
        if (str_contains($domain, 'yahoo.') || in_array($domain, ['ymail.com', 'rocketmail.com'], true)) {
            // Yahoo uses hyphen for aliases: username-alias@yahoo.com -> username@yahoo.com
            if (preg_match('/^([^-]+)-(.+)$/', $localPart, $matches)) {
                // Extract base email (before hyphen)
                $baseEmail = $matches[1];
                // Use original domain (yahoo.com, yahoo.co.uk, etc.)
                return $baseEmail . '@' . $domain;
            }
        }
        
        // Outlook/Hotmail/Live alias detection
        $outlookDomains = [
            'outlook.com', 'outlook.fr', 'outlook.de', 'outlook.es', 'outlook.it',
            'outlook.co.uk', 'outlook.jp', 'outlook.in', 'outlook.com.au',
            'hotmail.com', 'hotmail.fr', 'hotmail.de', 'hotmail.es', 'hotmail.it',
            'hotmail.co.uk', 'hotmail.jp', 'hotmail.in', 'hotmail.com.au',
            'live.com', 'live.fr', 'live.de', 'live.co.uk', 'live.jp',
            'msn.com', 'passport.com', 'passport.net',
        ];
        
        if (in_array($domain, $outlookDomains, true)) {
            // Outlook uses plus addressing: username+test@outlook.com -> username@outlook.com
            if (($plusPos = strpos($localPart, '+')) !== false) {
                $canonical = substr($localPart, 0, $plusPos);
                return $canonical . '@' . $domain;
            }
        }
        
        return null;
    }
}

