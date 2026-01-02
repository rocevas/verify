<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enable SMTP Check
    |--------------------------------------------------------------------------
    |
    | SMTP check is the most reliable way to verify if an email address
    | actually exists and can receive emails. However, it can be slow
    | (5-10 seconds per check) and some servers may block SMTP checks.
    |
    | Set to false to skip SMTP checks and rely only on:
    | - Syntax validation
    | - MX record check
    | - Disposable email detection
    | - Role-based email detection
    |
    */

    'enable_smtp_check' => env('EMAIL_VERIFICATION_SMTP_CHECK', true),

    /*
    |--------------------------------------------------------------------------
    | SMTP Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum time in seconds to wait for SMTP server response.
    | Lower values = faster but may miss valid emails on slow servers.
    |
    */

    'smtp_timeout' => env('EMAIL_VERIFICATION_SMTP_TIMEOUT', 3), // Reduced from 5 to 3 seconds for better UX

    /*
    |--------------------------------------------------------------------------
    | SMTP Retries
    |--------------------------------------------------------------------------
    |
    | Number of retry attempts for SMTP check.
    | More retries = more reliable but slower.
    |
    */

    'smtp_retries' => env('EMAIL_VERIFICATION_SMTP_RETRIES', 1), // Keep at 1 for faster response

    /*
    |--------------------------------------------------------------------------
    | SMTP Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Rate limiting to prevent getting banned by SMTP servers.
    | 
    | Real-world limits:
    | - Gmail: ~100 per IP per hour (conservative: 20-30 per minute)
    | - Outlook/Hotmail: ~50-100 per IP per hour (conservative: 10-20 per minute)
    | - Average servers: ~200-500 per IP per hour (conservative: 30-50 per minute)
    | - Smaller servers: Can handle 1000+ per hour
    |
    | Note: When using queue workers, parallel processing allows checking
    | different domains simultaneously, so per-domain limits are more important
    | than global limits.
    |
    | - max_checks_per_minute: Maximum SMTP checks per minute globally (0 = disabled)
    | - max_checks_per_domain_per_minute: Maximum checks per domain per minute
    | - delay_between_checks: Delay in seconds between SMTP checks (0 = no delay)
    | - enable_global_limit: Enable global rate limiting (false recommended for queue workers)
    |
    */

    'smtp_rate_limit' => [
        'enable_global_limit' => env('EMAIL_VERIFICATION_SMTP_ENABLE_GLOBAL_LIMIT', false),
        'max_checks_per_minute' => env('EMAIL_VERIFICATION_SMTP_MAX_PER_MINUTE', 100),
        'max_checks_per_domain_per_minute' => env('EMAIL_VERIFICATION_SMTP_MAX_PER_DOMAIN_PER_MINUTE', 20),
        'delay_between_checks' => env('EMAIL_VERIFICATION_SMTP_DELAY', 0.5), // seconds (0.5 = 500ms)
    ],

    /*
    |--------------------------------------------------------------------------
    | SMTP HELO String
    |--------------------------------------------------------------------------
    |
    | HELO/EHLO string to use when connecting to SMTP servers.
    | Some servers may block generic hostnames.
    |
    */

    'smtp_helo_hostname' => env('EMAIL_VERIFICATION_SMTP_HELO', null), // null = use gethostname()

    /*
    |--------------------------------------------------------------------------
    | Role-Based Email Accounts
    |--------------------------------------------------------------------------
    |
    | List of role-based email account names (e.g., admin, support, noreply).
    | These emails are considered risky as they are generic and often
    | monitored by multiple people or automated systems.
    |
    */

    'role_emails' => [
        // Global high-risk role-based addresses (never opt-in)
        'abuse', 'admin', 'billing', 'compliance', 'hostmaster', 'legal',
        'noc', 'postmaster', 'privacy', 'registrar', 'root', 'security',
        'webmaster', 'support', // Support is often treated as high-risk by ESPs
        
        // RFC 2142 technical addresses (not for marketing)
        'mailer-daemon', 'maildaemon', 'daemon', 'ftp', 'usenet', 'news', 'uucp',
        
        // Common role-based addresses
        'dns', 'inoc', 'ispfeedback', 'ispsupport', 'list-request', 'list',
        'sysadmin', 'tech', 'undisclosed-recipients', 'unsubscribe',
        'www', 'info', 'contact', 'sales', 'marketing', 'help',
        
        // Security/phishing related
        'phish', 'phishing', 'spam',
        
        // Legacy/null addresses
        'devnull', 'null',
    ],

    /*
    |--------------------------------------------------------------------------
    | Score Calculation Weights
    |--------------------------------------------------------------------------
    |
    | Points assigned to each verification check. Total score is calculated
    | by summing up points for passed checks and applying penalties.
    |
    | - syntax: Points for valid email syntax
    | - mx: Points for valid MX records
    | - smtp: Points for successful SMTP check
    | - disposable: Points for non-disposable email (or penalty if disposable)
    | - role_penalty: Penalty points for role-based emails (subtracted from score)
    |
    | Final score is clamped between 0 and 100.
    |
    */

    'score_weights' => [
        'syntax' => 10,
        'mx' => 30,
        'smtp' => 50,
        'disposable' => 10, // Added if not disposable
        'role_penalty' => 20, // Subtracted if role-based
    ],

    /*
    |--------------------------------------------------------------------------
    | Blacklist Status Mapping
    |--------------------------------------------------------------------------
    |
    | Maps blacklist reasons to verification statuses.
    | This determines how different blacklist types are categorized.
    |
    */

    'blacklist_status_map' => [
        'spamtrap' => 'spamtrap',
        'abuse' => 'abuse',
        'do_not_mail' => 'do_not_mail',
        'bounce' => 'invalid',
        'complaint' => 'abuse',
        'other' => 'do_not_mail',
    ],

    /*
    |--------------------------------------------------------------------------
    | Status Determination Rules
    |--------------------------------------------------------------------------
    |
    | Rules for determining final verification status based on checks and score.
    | These rules are evaluated in order, first matching rule wins.
    |
    | - smtp_valid: If SMTP check passes, status is 'valid'
    | - min_score_for_catch_all: Minimum score to consider as 'catch_all' or 'risky'
    | - role_emails_status: Status for role-based emails when score is sufficient
    | - default_invalid: Default status when score is too low
    |
    */

    'status_rules' => [
        'smtp_valid' => 'valid',
        'min_score_for_catch_all' => 50,
        'role_emails_status' => 'risky',
        'non_role_emails_status' => 'catch_all',
        'default_invalid' => 'invalid',
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Messages
    |--------------------------------------------------------------------------
    |
    | Customizable error messages for different verification failures.
    |
    */

    'error_messages' => [
        'invalid_format' => 'Invalid email format',
        'invalid_syntax' => 'Invalid email syntax',
        'no_mx_records' => 'No MX records found',
        'blacklisted' => 'Blacklisted: :reason:notes',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | Cache duration for MX record checks (in seconds).
    | MX records rarely change, so caching improves performance.
    |
    */

    'mx_cache_ttl' => env('EMAIL_VERIFICATION_MX_CACHE_TTL', 3600), // 1 hour

    /*
    |--------------------------------------------------------------------------
    | Never-Opt-In Keywords
    |--------------------------------------------------------------------------
    |
    | Local-part keywords that indicate synthetic addresses or list poisoning.
    | These are commonly used as pristine traps by ESPs.
    |
    */

    'never_opt_in_keywords' => [
        'no-reply', 'noreply', 'do-not-reply', 'donotreply',
        'noemail', 'fake', 'test', 'example', 'sample',
        'invalid', 'null', 'void', 'devnull',
    ],

    /*
    |--------------------------------------------------------------------------
    | Typo Domain Traps
    |--------------------------------------------------------------------------
    |
    | Common typo domains for major email providers that are actively used
    | as spam traps. These domains are often purchased by ESPs or redirected
    | to spam trap MX records.
    |
    */

    'typo_domains' => [
        // Gmail typos
        'gmial.com', 'gmai.com', 'gnail.com', 'gmal.com',
        // Hotmail/Outlook typos
        'hotnail.com', 'hotmai.com', 'hotmal.com',
        // Yahoo typos
        'yahho.com', 'yaho.com',
        // Outlook typos
        'outlok.com', 'outllok.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | ISP/ESP Infrastructure Domains
    |--------------------------------------------------------------------------
    |
    | Domains used by ISPs and ESPs for infrastructure, monitoring, and
    | abuse detection. These should never be in marketing lists.
    |
    */

    'isp_esp_domains' => [
        'amazonaws.com',
        'google.com',
        'cloudflare.com',
        'sendgrid.net',
        'mailgun.org',
        'sparkpostmail.com',
        'mandrillapp.com',
        'postmarkapp.com',
        'mailchimp.com',
        'constantcontact.com',
        'aweber.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | Government and Registry TLDs
    |--------------------------------------------------------------------------
    |
    | Top-level domains that are commonly used for policy traps and should
    | be treated with extra caution, especially if they lack active websites.
    |
    */

    'government_tlds' => [
        'gov', 'mil', 'int',
        // Some .edu domains without active websites can be traps
    ],

    /*
    |--------------------------------------------------------------------------
    | Risk Check Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for various risk checks and their severity levels.
    |
    | - never_opt_in_status: Status for never-opt-in keywords
    | - typo_domain_status: Status for typo domains
    | - isp_esp_status: Status for ISP/ESP infrastructure domains
    | - government_tld_status: Status for government/registry TLDs
    | - enable_typo_check: Enable typo domain checking
    | - enable_isp_esp_check: Enable ISP/ESP domain checking
    | - enable_government_check: Enable government TLD checking
    |
    */

    'risk_checks' => [
        'never_opt_in_status' => 'do_not_mail',
        'typo_domain_status' => 'spamtrap',
        'isp_esp_status' => 'do_not_mail',
        'government_tld_status' => 'risky',
        'enable_typo_check' => env('EMAIL_VERIFICATION_ENABLE_TYPO_CHECK', true),
        'enable_isp_esp_check' => env('EMAIL_VERIFICATION_ENABLE_ISP_ESP_CHECK', true),
        'enable_government_check' => env('EMAIL_VERIFICATION_ENABLE_GOVERNMENT_CHECK', true),
    ],
];

