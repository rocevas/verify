<?php

namespace App\Services\EmailVerification;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GravatarService
{
    private const GRAVATAR_BASE_URL = 'https://www.gravatar.com/avatar/';
    private const DEFAULT_IMAGE_MD5 = 'cdf2e8b267dcc7fe2a49d3a5f2c7b1a8'; // Default Gravatar image MD5 hash

    /**
     * Check if email has Gravatar
     *
     * @param string $email
     * @return array{has_gravatar: bool, gravatar_url: string|null}
     */
    public function checkGravatar(string $email): array
    {
        $emailHash = md5(strtolower(trim($email)));
        $gravatarUrl = self::GRAVATAR_BASE_URL . $emailHash . '?d=404';

        // Cache for 1 hour (Gravatar profiles don't change often)
        $cacheKey = "gravatar_check_{$emailHash}";
        $ttl = 3600; // 1 hour

        return Cache::remember($cacheKey, $ttl, function () use ($emailHash, $gravatarUrl) {
            try {
                // Use HEAD request for better performance (only headers, no body)
                $response = Http::timeout(5)
                    ->head($gravatarUrl);

                $hasGravatar = $response->successful() && $response->status() === 200;

                if ($hasGravatar) {
                    return [
                        'has_gravatar' => true,
                        'gravatar_url' => self::GRAVATAR_BASE_URL . $emailHash,
                    ];
                }

                return [
                    'has_gravatar' => false,
                    'gravatar_url' => null,
                ];
            } catch (\Exception $e) {
                // If request fails, assume no Gravatar (fail gracefully)
                Log::debug('Gravatar check failed', [
                    'email_hash' => $emailHash,
                    'error' => $e->getMessage(),
                ]);

                return [
                    'has_gravatar' => false,
                    'gravatar_url' => null,
                ];
            }
        });
    }

    /**
     * Get Gravatar URL for email (if exists)
     *
     * @param string $email
     * @return string|null
     */
    public function getGravatarUrl(string $email): ?string
    {
        $result = $this->checkGravatar($email);
        return $result['gravatar_url'];
    }
}

