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

    'smtp_timeout' => env('EMAIL_VERIFICATION_SMTP_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | SMTP Retries
    |--------------------------------------------------------------------------
    |
    | Number of retry attempts for SMTP check.
    | More retries = more reliable but slower.
    |
    */

    'smtp_retries' => env('EMAIL_VERIFICATION_SMTP_RETRIES', 1),

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
];

