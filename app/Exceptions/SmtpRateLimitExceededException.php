<?php

namespace App\Exceptions;

use Exception;

class SmtpRateLimitExceededException extends Exception
{
    public function __construct(
        public string $domain,
        public int $retryAfter = 60, // seconds
        string $message = 'SMTP rate limit exceeded',
        int $code = 429
    ) {
        parent::__construct($message, $code);
    }
}
