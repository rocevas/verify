<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'spamassassin' => [
        'host' => env('SPAMASSASSIN_HOST', 'spamassassin'),
        'port' => env('SPAMASSASSIN_PORT', 783),
        'threshold' => env('SPAMASSASSIN_THRESHOLD', 5.0),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'enabled' => env('OPENAI_ENABLED', true),
    ],

    'ai' => [
        'provider' => env('AI_PROVIDER', 'ollama'), // 'ollama' or 'openai'
        'model' => env('AI_MODEL', 'llama3.2:1b'), // For Ollama: llama3.2:1b (1B params, low resources), llama3.2, mistral, etc.
        'base_url' => env('AI_BASE_URL', env('APP_ENV') === 'local' && env('LARAVEL_SAIL') ? 'http://ollama:11434' : 'http://localhost:11434'), // Docker: ollama, Local: localhost
        'enabled' => env('AI_ENABLED', true),
    ],

];
