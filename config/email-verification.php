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
    | Note: For backward compatibility, this is used as default for both
    | connect and operation timeouts if they are not specified separately.
    |
    */

    'smtp_timeout' => env('EMAIL_VERIFICATION_SMTP_TIMEOUT', 3), // Reduced from 5 to 3 seconds for better UX

    /*
    |--------------------------------------------------------------------------
    | SMTP Connect Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum time in seconds to wait when establishing TCP connection to SMTP server.
    | This is separate from operation timeout to allow faster failure detection.
    |
    */

    'smtp_connect_timeout' => env('EMAIL_VERIFICATION_SMTP_CONNECT_TIMEOUT', 3), // Connection timeout (faster failure)

    /*
    |--------------------------------------------------------------------------
    | SMTP Operation Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum time in seconds to wait for SMTP operations (EHLO, MAIL FROM, RCPT TO, etc.).
    | This allows more time for operations while keeping connection timeout short.
    |
    */

    'smtp_operation_timeout' => env('EMAIL_VERIFICATION_SMTP_OPERATION_TIMEOUT', 6), // Operation timeout (can be longer)

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
        // Global high-risk role-based addresses (no-reply)
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
    | Score Calculation Multipliers (Multiplicative System)
    |--------------------------------------------------------------------------
    |
    | Multiplicative scoring system matching Emailable:
    | - Base score: 100
    | - Each factor multiplies the score (e.g., 0.95x = 95%)
    | - Final score = 100 * factor1 * factor2 * factor3...
    | - Risky emails: score 1-80, Good emails: score 80-100
    |
    | Multipliers are applied in order, so order matters.
    |
    */

    'score_multipliers' => [
        // Base score for calculation (starting point)
        'base_score' => 100.0, // Base score before multipliers
        
        // Free email provider multiplier
        'free' => 0.95, // 95% - Free emails get 5% penalty
        
        // Disposable email multiplier (very severe)
        'disposable' => 0.05, // 5% - Disposable emails get 95% penalty (score ~5)
        
        // Typo domain multiplier (very severe, similar to disposable)
        'typo_domain' => 0.05, // 5% - Typo domains get 95% penalty
        
        // Role-based email multiplier (oranžinis minusinis)
        'role' => 0.7, // 70% - Role-based emails get 30% penalty
        
        // Catch-all / Accept-All multiplier (oranžinis minusinis)
        'catch_all' => 0.6, // 60% - Catch-all servers get 40% penalty
        
        // Mailbox full multiplier (oranžinis/raudonas minusinis)
        'mailbox_full' => 0.5, // 50% - Mailbox full gets 50% penalty
        
        // Tag/Alias multiplier
        'alias' => 0.95, // 95% - Tags/aliases get 5% penalty
        
        // Numerical characters penalty per character
        'numerical_char_per_penalty' => 0.02, // -2% per numerical character (1 char = 0.98x, 3 chars = 0.94x)
        'numerical_char_min_multiplier' => 0.85, // Minimum multiplier for numerical characters
        
        // Alphabetical characters penalty (for low counts)
        'alphabetical_char_per_penalty' => 0.005, // -0.5% per char under threshold
        'alphabetical_char_min_multiplier' => 0.95, // Minimum multiplier for alphabetical characters
        
        // Other/Unknown factors multiplier (oranžinis minusinis)
        'other' => 0.8, // 80% - Other issues get 20% penalty
        
        // Free domain multiplier (yahoo.com, hotmail.com, etc.)
        'free_domain' => 0.9, // 90% - Specific free domains get 10% penalty
        
        // Implicit MX score (when domain has A record but no MX records)
        'implicit_mx_score' => 10, // Very low score for implicit MX only (10 out of 100)
        
        // Score bounds
        'min_score' => 0, // Minimum score
        'max_score' => 100, // Maximum score
        
        // Secure Email Gateway handling
        // When SMTP check fails due to secure gateway (e.g., Cloudflare) but domain is valid
        'secure_gateway_score_override' => 100, // Override score to 100 if secure gateway blocks SMTP
        
        // Secure Email Gateway providers that block SMTP checks
        'secure_gateway_providers' => [
            'Cloudflare',
            // Add more providers that block SMTP checks
        ],
        
        // Domain-specific multipliers
        'domains' => [
            'yahoo.com' => 0.9,
            'hotmail.com' => 0.9,
            'outlook.com' => 0.9,
            'aol.com' => 0.9,
            // Add more domain-specific multipliers as needed
        ],
        
        // Legacy weights for backward compatibility (if needed)
        'legacy_weights' => [
            'syntax' => 20,
            'domain_validity' => 20,
            'mx_record' => 25,
            'smtp' => 20,
            'gravatar_bonus' => 5,
            'dmarc_reject_bonus' => 10,
            'dmarc_quarantine_bonus' => 5,
            'government_tld_penalty' => 10,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Score Calculation Weights (Legacy - Deprecated)
    |--------------------------------------------------------------------------
    |
    | Legacy additive scoring system. Now using multiplicative system above.
    | Kept for backward compatibility.
    |
    */

    'score_weights' => [
        // Round numbers for cleaner scoring (no decimals)
        'syntax' => 20, // Base syntax check
        'domain_validity' => 20, // Domain exists and is valid (DNS resolution)
        'mx_record' => 25, // MX records check (increased to make base score 85 without SMTP)
        'smtp' => 20, // SMTP check (optional, often unavailable for public providers)
        'disposable' => 10, // Added if not disposable
        'role_bonus' => 10, // Added if NOT role-based
        'mailbox_full_penalty' => 30, // Penalty if mailbox is full (email cannot receive mail)
        'free_email_penalty' => 0, // Small penalty for free email providers (disabled - no penalty)
        'gravatar_bonus' => 5, // Bonus if email has Gravatar (for catch-all emails)
        'dmarc_reject_bonus' => 10, // Bonus if DMARC policy = "reject" (for catch-all emails)
        'dmarc_quarantine_bonus' => 5, // Bonus if DMARC policy = "quarantine" (for catch-all emails)
        'government_tld_penalty' => 10, // Penalty for government TLDs (reduces score but doesn't zero it)
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
        'min_score_for_catch_all' => 70, // Increased from 50 to match new scoring system
        'min_score_for_valid' => 85, // Minimum score for valid status (without SMTP)
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
    | No-Reply Keywords
    |--------------------------------------------------------------------------
    |
    | Local-part keywords that indicate synthetic addresses or list poisoning.
    | These are commonly used as pristine traps by ESPs.
    |
    */

    'no_reply_keywords' => [
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
        // Gmail typos (common misspellings)
        'gmial.com', 'gmai.com', 'gnail.com', 'gmal.com', 'gmailc.com', 'gmaill.com',
        'gmaol.com', 'gmaio.com', 'gmaill.com', 'gmailll.com', 'gmail.co', 'gmail.cm',
        // Hotmail/Outlook typos
        'hotnail.com', 'hotmai.com', 'hotmal.com', 'hotmial.com', 'hotmali.com',
        // Yahoo typos
        'yahho.com', 'yaho.com', 'yhaoo.com', 'yhoo.com',
        // Outlook typos
        'outlok.com', 'outllok.com', 'outlokc.com',
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
    | - no_reply_status: Status for no-reply keywords
    | - typo_domain_status: Status for typo domains
    | - isp_esp_status: Status for ISP/ESP infrastructure domains
    | - government_tld_status: Status for government/registry TLDs
    | - enable_typo_check: Enable typo domain checking
    | - enable_isp_esp_check: Enable ISP/ESP domain checking
    | - enable_government_check: Enable government TLD checking
    |
    */

    'risk_checks' => [
        'no_reply_status' => 'do_not_mail',
        'typo_domain_status' => 'spamtrap',
        'isp_esp_status' => 'do_not_mail',
        'government_tld_status' => 'risky',
        'enable_typo_check' => env('EMAIL_VERIFICATION_ENABLE_TYPO_CHECK', true),
        'enable_automatic_typo_detection' => env('EMAIL_VERIFICATION_AUTO_TYPO_DETECTION', true),
        'typo_detection_max_distance' => env('EMAIL_VERIFICATION_TYPO_MAX_DISTANCE', 2), // Max Levenshtein distance
        'typo_detection_min_similarity' => env('EMAIL_VERIFICATION_TYPO_MIN_SIMILARITY', 0.85), // 85% similarity threshold
        'enable_isp_esp_check' => env('EMAIL_VERIFICATION_ENABLE_ISP_ESP_CHECK', true),
        'enable_government_check' => env('EMAIL_VERIFICATION_ENABLE_GOVERNMENT_CHECK', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | MX Skip List
    |--------------------------------------------------------------------------
    |
    | List of MX server hostnames that should be skipped during SMTP checks.
    | These servers are known to block SMTP verification attempts or return
    | errors that prevent proper verification.
    |
    | MX servers can be automatically added to this list if they fail
    | SMTP connection or return specific error patterns (see mx_skip_auto_add).
    |
    */

    'mx_skip_list' => [
        'securence.com',
        'mailanyone.net',
        'mimecast.com',
        // Manual entries (never expire)
        // Automatically added servers are stored in database (mx_skip_list table)
    ],

    /*
    |--------------------------------------------------------------------------
    | MX Skip Auto-Add
    |--------------------------------------------------------------------------
    |
    | Automatically add MX servers to skip list when they fail SMTP connection
    | or return specific error patterns. This helps avoid repeated failures
    | on problematic servers.
    |
    | Auto-added servers are stored in database and expire after the specified
    | number of days. Manual entries (from config) never expire.
    |
    */

    'mx_skip_auto_add' => env('EMAIL_VERIFICATION_MX_SKIP_AUTO_ADD', true),
    'mx_skip_auto_add_expires_days' => env('EMAIL_VERIFICATION_MX_SKIP_EXPIRES_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Unsupported Domains
    |--------------------------------------------------------------------------
    |
    | Domains that do not support SMTP verification. These domains will be
    | marked as 'skipped' status instead of attempting SMTP check.
    |
    | Examples: mimecast.com, yahoo.co (not yahoo.com)
    |
    */

    'unsupported_domains' => [
        'mimecast.com',
        'yahoo.co', // Note: yahoo.com is supported, but yahoo.co is not
    ],

    'unsupported_domain_status' => 'skipped',

    /*
    |--------------------------------------------------------------------------
    | Catch-All Skip Domains
    |--------------------------------------------------------------------------
    |
    | Domains that should skip catch-all detection because they are known
    | to be catch-all servers (typically public email providers).
    |
    | These domains will not be tested for catch-all behavior to save time.
    |
    */

    'catch_all_skip_domains' => [
        // Google/Gmail
        'gmail.com',
        'google.com',
        'googlemail.com',

        // Yahoo
        'yahoo.com',
        'yahoo.co.uk',
        'yahoo.fr',
        'yahoo.de',
        'yahoo.es',
        'yahoo.it',
        'yahoo.co.jp',
        'yahoo.co.in',
        'yahoo.com.au',
        'yahoo.com.br',
        'yahoo.ca',
        'yahoo.com.mx',
        'yahoo.com.sg',
        'yahoo.com.tw',
        'yahoo.com.hk',
        'yahoodns.net',
        'yahoo.net',
        'yahoo.org',
        'ymail.com',
        'rocketmail.com',

        // Microsoft/Outlook
        'outlook.com',
        'outlook.fr',
        'outlook.de',
        'outlook.es',
        'outlook.it',
        'outlook.co.uk',
        'outlook.jp',
        'outlook.in',
        'outlook.com.au',
        'hotmail.com',
        'hotmail.fr',
        'hotmail.de',
        'hotmail.es',
        'hotmail.it',
        'hotmail.co.uk',
        'hotmail.jp',
        'hotmail.in',
        'hotmail.com.au',
        'live.com',
        'live.fr',
        'live.de',
        'live.co.uk',
        'live.jp',
        'msn.com',
        'passport.com',
        'passport.net',

        // Mail.ru
        'mail.ru',
        'inbox.ru',
        'list.ru',
        'bk.ru',
        'internet.ru',

        // Yandex
        'yandex.com',
        'yandex.ru',
        'yandex.ua',
        'yandex.by',
        'yandex.kz',
        'ya.ru',

        // AOL
        'aol.com',
        'aol.fr',
        'aol.de',
        'aol.co.uk',

        // Apple/iCloud
        'icloud.com',
        'icloud.com.cn',
        'me.com',
        'mac.com',

        // ProtonMail
        'protonmail.com',
        'proton.me',
        'pm.me',

        // Zoho
        'zoho.com',
        'zoho.eu',
        'zoho.in',
        'zoho.com.cn',

        // GMX
        'gmx.com',
        'gmx.de',
        'gmx.fr',
        'gmx.co.uk',
        'gmx.net',

        // Mail.com
        'mail.com',
        'email.com',
        'usa.com',
        'myself.com',
        'consultant.com',

        // FastMail
        'fastmail.com',
        'fastmail.fm',

        // Tutanota
        'tutanota.com',
        'tutanota.de',
        'tutamail.com',

        // European providers
        'web.de',
        't-online.de',
        'orange.fr',
        'orange.com',
        'wanadoo.fr',
        'laposte.net',
        'libero.it',
        'virgilio.it',
        'alice.it',
        'aliceadsl.fr',
        'sfr.fr',
        'free.fr',
        'neuf.fr',
        'posteo.de',
        'posteo.net',
        'mailbox.org',

        // Latin America providers
        'terra.com',
        'terra.com.br',
        'terra.es',
        'terra.cl',
        'terra.com.mx',
        'uol.com.br',
        'bol.com.br',

        // Asian providers
        'qq.com',
        '163.com',
        '126.com',
        'yeah.net',
        'vip.163.com',
        'vip.126.com',
        'sina.com',
        'sina.cn',
        'sohu.com',
        'naver.com',
        'daum.net',

        // Russian/CIS providers
        'rambler.ru',
        'mail.ua',

        // Other providers
        'comcast.net',
        'rediffmail.com',
        'rediff.com',
        'hushmail.com',
        'hush.com',
        'runbox.com',
        'startmail.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | SMTP Error Patterns
    |--------------------------------------------------------------------------
    |
    | Error message patterns that indicate an MX server is blocking SMTP
    | verification attempts. When these patterns are detected, the MX server
    | will be automatically added to the skip list (if auto-add is enabled).
    |
    */

    'smtp_error_patterns' => [
        'blocked by prs',
        'unsolicited mail',
        '550 5.7.1',
        '550 5.1.1',
        '550 5.2.1',
        '550 5.7.133',
        '554 5.4.14',
        'administrative prohibition',
        'not allowed',
        'policy violation',
        'relay access denied',
    ],

    /*
    |--------------------------------------------------------------------------
    | Public Email Providers
    |--------------------------------------------------------------------------
    |
    | Configuration for public email providers (Gmail, Yahoo, Outlook, etc.).
    | These providers often block SMTP verification, so we use special handling:
    | - Skip SMTP check if configured
    | - Mark as valid if MX records exist (for known providers)
    | - Use specific status based on provider configuration
    |
    */

    'public_providers' => [
        'gmail' => [
            'domains' => ['gmail.com', 'googlemail.com'],
            'mx_patterns' => ['google.com', 'gmail-smtp-in.l.google.com', 'aspmx.l.google.com'],
            'skip_smtp' => true,
            'status' => 'valid', // If MX records exist, consider valid
        ],
        'yahoo' => [
            'domains' => [
                'yahoo.com', 'yahoo.co.uk', 'yahoo.fr', 'yahoo.de', 'yahoo.es', 'yahoo.it',
                'yahoo.co.jp', 'yahoo.co.in', 'yahoo.com.au', 'yahoo.com.br', 'yahoo.ca',
                'yahoo.com.mx', 'yahoo.com.sg', 'yahoo.com.tw', 'yahoo.com.hk',
                'ymail.com', 'rocketmail.com', 'yahoo.net', 'yahoo.org',
            ],
            'mx_patterns' => ['yahoodns.net', 'yahoo.net', 'mta.am0.yahoodns.net'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'outlook' => [
            'domains' => [
                'outlook.com', 'outlook.fr', 'outlook.de', 'outlook.es', 'outlook.it',
                'outlook.co.uk', 'outlook.jp', 'outlook.in', 'outlook.com.au',
                'hotmail.com', 'hotmail.fr', 'hotmail.de', 'hotmail.es', 'hotmail.it',
                'hotmail.co.uk', 'hotmail.jp', 'hotmail.in', 'hotmail.com.au',
                'live.com', 'live.fr', 'live.de', 'live.co.uk', 'live.jp',
                'msn.com', 'passport.com', 'passport.net',
            ],
            'mx_patterns' => ['outlook.com', 'hotmail.com', 'mail.protection.outlook.com'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'mailru' => [
            'domains' => ['mail.ru', 'inbox.ru', 'list.ru', 'bk.ru', 'internet.ru'],
            'mx_patterns' => ['mail.ru', 'mxs.mail.ru'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'yandex' => [
            'domains' => ['yandex.com', 'yandex.ru', 'ya.ru', 'yandex.ua', 'yandex.by', 'yandex.kz'],
            'mx_patterns' => ['yandex.com', 'yandex.ru', 'mx.yandex.ru'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'aol' => [
            'domains' => ['aol.com', 'aol.fr', 'aol.de', 'aol.co.uk'],
            'mx_patterns' => ['mx-aol.mail', 'yahoodns.net', 'aol.com'], // AOL uses Yahoo MX
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'icloud' => [
            'domains' => ['icloud.com', 'me.com', 'mac.com', 'icloud.com.cn'],
            'mx_patterns' => ['icloud.com', 'apple.com', 'mx01.mail.icloud.com'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'protonmail' => [
            'domains' => ['protonmail.com', 'proton.me', 'pm.me'],
            'mx_patterns' => ['protonmail.com', 'protonmail.ch', 'mail.protonmail.com'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'zoho' => [
            'domains' => ['zoho.com', 'zoho.eu', 'zoho.in', 'zoho.com.cn'],
            'mx_patterns' => ['zoho.com', 'mx.zoho.com'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'gmx' => [
            'domains' => ['gmx.com', 'gmx.de', 'gmx.fr', 'gmx.co.uk', 'gmx.net'],
            'mx_patterns' => ['gmx.com', 'gmx.net', 'mail.gmx.com'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'mail' => [
            'domains' => ['mail.com', 'email.com', 'usa.com', 'myself.com', 'consultant.com'],
            'mx_patterns' => ['mail.com', 'mx.mail.com'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'fastmail' => [
            'domains' => ['fastmail.com', 'fastmail.fm'],
            'mx_patterns' => ['fastmail.com', 'in1.smtp.messagingengine.com'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'tutanota' => [
            'domains' => ['tutanota.com', 'tutanota.de', 'tutamail.com'],
            'mx_patterns' => ['tutanota.com', 'mail.tutanota.com'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'webde' => [
            'domains' => ['web.de', 'gmx.de'],
            'mx_patterns' => ['web.de', 'gmx.net', 'mail.gmx.net'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        't-online' => [
            'domains' => ['t-online.de'],
            'mx_patterns' => ['t-online.de', 'mail.t-online.de'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'orange' => [
            'domains' => ['orange.fr', 'orange.com', 'wanadoo.fr'],
            'mx_patterns' => ['orange.fr', 'orange.com', 'mail.orange.fr'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'laposte' => [
            'domains' => ['laposte.net'],
            'mx_patterns' => ['laposte.net', 'mail.laposte.net'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'libero' => [
            'domains' => ['libero.it'],
            'mx_patterns' => ['libero.it', 'mail.libero.it'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'virgilio' => [
            'domains' => ['virgilio.it'],
            'mx_patterns' => ['virgilio.it', 'mail.virgilio.it'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'alice' => [
            'domains' => ['alice.it', 'aliceadsl.fr'],
            'mx_patterns' => ['alice.it', 'mail.alice.it'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'terra' => [
            'domains' => ['terra.com', 'terra.com.br', 'terra.es', 'terra.cl', 'terra.com.mx'],
            'mx_patterns' => ['terra.com', 'mail.terra.com'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'uol' => [
            'domains' => ['uol.com.br'],
            'mx_patterns' => ['uol.com.br', 'mail.uol.com.br'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'bol' => [
            'domains' => ['bol.com.br'],
            'mx_patterns' => ['bol.com.br', 'mail.bol.com.br'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'netease' => [
            'domains' => ['163.com', '126.com', 'yeah.net', 'vip.163.com', 'vip.126.com'],
            'mx_patterns' => ['163.com', '126.com', 'yeah.net', 'mail.163.com'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'qq' => [
            'domains' => ['qq.com'],
            'mx_patterns' => ['qq.com', 'mx.qq.com'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'sina' => [
            'domains' => ['sina.com', 'sina.cn'],
            'mx_patterns' => ['sina.com', 'mail.sina.com'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'sohu' => [
            'domains' => ['sohu.com'],
            'mx_patterns' => ['sohu.com', 'mail.sohu.com'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'rediffmail' => [
            'domains' => ['rediffmail.com', 'rediff.com'],
            'mx_patterns' => ['rediffmail.com', 'mail.rediffmail.com'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'mailru' => [
            'domains' => ['mail.ru', 'inbox.ru', 'list.ru', 'bk.ru', 'internet.ru'],
            'mx_patterns' => ['mail.ru', 'mxs.mail.ru'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'rambler' => [
            'domains' => ['rambler.ru'],
            'mx_patterns' => ['rambler.ru', 'mail.rambler.ru'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'naver' => [
            'domains' => ['naver.com'],
            'mx_patterns' => ['naver.com', 'mail.naver.com'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'daum' => [
            'domains' => ['daum.net'],
            'mx_patterns' => ['daum.net', 'mail.daum.net'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'hushmail' => [
            'domains' => ['hushmail.com', 'hush.com'],
            'mx_patterns' => ['hushmail.com', 'mail.hushmail.com'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'runbox' => [
            'domains' => ['runbox.com'],
            'mx_patterns' => ['runbox.com', 'mail.runbox.com'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'startmail' => [
            'domains' => ['startmail.com'],
            'mx_patterns' => ['startmail.com', 'mail.startmail.com'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'posteo' => [
            'domains' => ['posteo.de', 'posteo.net'],
            'mx_patterns' => ['posteo.de', 'posteo.net'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
        'mailbox' => [
            'domains' => ['mailbox.org'],
            'mx_patterns' => ['mailbox.org', 'mail.mailbox.org'],
            'skip_smtp' => true,
            'status' => 'valid',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Catch-All Detection
    |--------------------------------------------------------------------------
    |
    | Enable catch-all server detection. When enabled, the system will test
    | if a domain accepts any email address by sending a random email to
    | the domain's MX server.
    |
    | Note: This is disabled by default as it adds significant overhead
    | and most public providers are catch-all anyway.
    |
    */

    'enable_catch_all_detection' => env('EMAIL_VERIFICATION_CATCH_ALL', true),
    'catch_all_status' => 'catch_all',

    /*
    |--------------------------------------------------------------------------
    | VRFY/EXPN Commands
    |--------------------------------------------------------------------------
    |
    | Enable VRFY and EXPN SMTP commands to verify email existence.
    | These commands can verify if an email exists even on catch-all servers.
    |
    | VRFY (Verify) - checks if an email address exists
    | EXPN (Expand) - checks if an email address or mailing list exists
    |
    | Note: Most modern SMTP servers disable these commands for security
    | reasons (502 Command not implemented), but some servers still support them.
    | When enabled, the system will try VRFY first, then EXPN if VRFY fails.
    |
    | If VRFY/EXPN work, they provide definitive answer (95% confidence)
    | and bypass the need for catch-all detection.
    |
    */

    'enable_vrfy_check' => env('EMAIL_VERIFICATION_VRFY_CHECK', true),

    /*
    |--------------------------------------------------------------------------
    | Gravatar Check for Catch-All Emails
    |--------------------------------------------------------------------------
    |
    | Enable Gravatar check for catch-all email addresses to determine
    | if email likely exists. If email has Gravatar, it's more likely
    | that the email address exists and is active.
    |
    | This adds a small score bonus (+5 points) for catch-all emails
    | that have Gravatar, indicating the email is more likely to exist.
    |
    */

    'enable_gravatar_check' => env('EMAIL_VERIFICATION_GRAVATAR_CHECK', true),
    // Note: gravatar_score_bonus moved to score_weights['gravatar_bonus'] for consistency

    /*
    |--------------------------------------------------------------------------
    | DMARC Check for Catch-All Emails
    |--------------------------------------------------------------------------
    |
    | Enable DMARC check for catch-all email addresses to determine
    | email confidence based on DMARC policy.
    |
    | If DMARC policy = "reject" → higher confidence (email more likely real)
    | If DMARC policy = "quarantine" → moderate confidence
    | If DMARC policy = "none" → no confidence boost
    |
    | This adds score bonus based on DMARC policy:
    | - reject: +10 points (default)
    | - quarantine: +5 points (default)
    | - none: +0 points
    |
    */

    'enable_dmarc_check' => env('EMAIL_VERIFICATION_DMARC_CHECK', true),

    /*
    |--------------------------------------------------------------------------
    | Hunter.io Style Confidence Score
    |--------------------------------------------------------------------------
    |
    | Enable Hunter.io style confidence score (0-100%) for catch-all emails.
    | Hunter.io assigns confidence % based on publicly available data.
    |
    | Recommended filter: ~85-90% confidence for catch-all emails.
    |
    | Base confidence:
    | - Syntax: +10%
    | - Domain validity: +15%
    | - MX record: +20%
    | - SMTP: +30% (highest weight)
    |
    | Catch-all specific:
    | - Reduce confidence by 30% if catch-all
    | - Add bonuses:
    |   - Gravatar: +15%
    |   - DMARC reject: +10%
    |   - DMARC quarantine: +5%
    |   - VRFY/EXPN: +10%
    |
    | Penalties:
    | - Disposable: 0%
    | - Role: -20%
    | - Typo: 0%
    |
    */

    'enable_hunter_confidence' => env('EMAIL_VERIFICATION_HUNTER_CONFIDENCE', true),

    /*
    |--------------------------------------------------------------------------
    | Domain Validity Check
    |--------------------------------------------------------------------------
    |
    | Check domain validity before MX check:
    | - DNS resolution (A record)
    | - Redirect detection (HTTP redirect)
    | - Domain availability (HTTP response)
    |
    | This helps identify problematic domains early and skip unnecessary checks.
    |
    */

    'enable_domain_validity_check' => env('EMAIL_VERIFICATION_DOMAIN_VALIDITY_CHECK', true),
    'check_domain_redirect' => env('EMAIL_VERIFICATION_CHECK_REDIRECT', false), // Disabled by default (adds HTTP overhead)
    'check_domain_availability' => env('EMAIL_VERIFICATION_CHECK_AVAILABILITY', false), // Disabled by default (adds HTTP overhead)
    'domain_check_timeout' => env('EMAIL_VERIFICATION_DOMAIN_CHECK_TIMEOUT', 3), // seconds
    'domain_validity_cache_ttl' => env('EMAIL_VERIFICATION_DOMAIN_VALIDITY_CACHE_TTL', 3600), // 1 hour

    /*
    |--------------------------------------------------------------------------
    | Greylisting Detection
    |--------------------------------------------------------------------------
    |
    | Enable automatic retry for greylisting servers (4xx responses).
    | Some servers temporarily reject emails and accept them on retry.
    |
    | When enabled, the system will wait and retry RCPT TO command if it
    | receives a 4xx response (temporary failure).
    |
    */

    'enable_greylisting_retry' => env('EMAIL_VERIFICATION_GREYLISTING_RETRY', true),
    'greylisting_retry_delay' => env('EMAIL_VERIFICATION_GREYLISTING_DELAY', 2), // seconds

    /*
    |--------------------------------------------------------------------------
    | SMTP Error Messages
    |--------------------------------------------------------------------------
    |
    | Human-readable error messages for different SMTP response codes.
    | Used to provide better error information to users.
    |
    */

    'smtp_error_messages' => [
        450 => 'Mailbox temporarily unavailable (greylisting)',
        451 => 'Requested action aborted: local error',
        452 => 'Insufficient system storage',
        550 => 'Mailbox unavailable',
        551 => 'User not local',
        552 => 'Exceeded storage allocation',
        553 => 'Mailbox name not allowed',
        554 => 'Transaction failed',
    ],
];

