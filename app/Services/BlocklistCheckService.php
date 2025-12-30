<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class BlocklistCheckService
{
    /**
     * Popular DNS-based blocklists for domains and IPs
     */
    private const DOMAIN_BLOCKLISTS = [
        'zen.spamhaus.org' => 'Spamhaus ZEN',
        'bl.spamcop.net' => 'SpamCop',
        'dnsbl.sorbs.net' => 'SORBS',
        'multi.surbl.org' => 'SURBL',
        'bl.mailspike.net' => 'Mailspike',
    ];

    private const IP_BLOCKLISTS = [
        'zen.spamhaus.org' => 'Spamhaus ZEN',
        'bl.spamcop.net' => 'SpamCop',
        'dnsbl.sorbs.net' => 'SORBS',
        'bl.mailspike.net' => 'Mailspike',
        'b.barracudacentral.org' => 'Barracuda',
    ];

    /**
     * Check if a domain is listed in any blocklist
     */
    public function checkDomain(string $domain): array
    {
        $foundIn = [];
        $details = [];

        foreach (self::DOMAIN_BLOCKLISTS as $blocklist => $name) {
            try {
                $isListed = $this->checkDnsBlocklist($domain, $blocklist);
                
                if ($isListed) {
                    $foundIn[] = $name;
                    $details[$name] = [
                        'blocklist' => $blocklist,
                        'listed' => true,
                    ];
                } else {
                    $details[$name] = [
                        'blocklist' => $blocklist,
                        'listed' => false,
                    ];
                }
            } catch (\Exception $e) {
                Log::warning("Failed to check domain {$domain} against {$blocklist}", [
                    'error' => $e->getMessage(),
                ]);
                $details[$name] = [
                    'blocklist' => $blocklist,
                    'listed' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'is_blocklisted' => !empty($foundIn),
            'blocklists' => $foundIn,
            'details' => $details,
        ];
    }

    /**
     * Check if an IP address is listed in any blocklist
     */
    public function checkIp(string $ip): array
    {
        // Validate IP address
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException("Invalid IP address: {$ip}");
        }

        $foundIn = [];
        $details = [];

        // Reverse IP for DNSBL lookup (e.g., 192.168.1.1 -> 1.1.168.192)
        $reversedIp = $this->reverseIp($ip);

        foreach (self::IP_BLOCKLISTS as $blocklist => $name) {
            try {
                $isListed = $this->checkDnsBlocklist($reversedIp, $blocklist);
                
                if ($isListed) {
                    $foundIn[] = $name;
                    $details[$name] = [
                        'blocklist' => $blocklist,
                        'listed' => true,
                    ];
                } else {
                    $details[$name] = [
                        'blocklist' => $blocklist,
                        'listed' => false,
                    ];
                }
            } catch (\Exception $e) {
                Log::warning("Failed to check IP {$ip} against {$blocklist}", [
                    'error' => $e->getMessage(),
                ]);
                $details[$name] = [
                    'blocklist' => $blocklist,
                    'listed' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'is_blocklisted' => !empty($foundIn),
            'blocklists' => $foundIn,
            'details' => $details,
        ];
    }

    /**
     * Check DNS blocklist using DNS lookup
     */
    private function checkDnsBlocklist(string $query, string $blocklist): bool
    {
        $cacheKey = "blocklist_check_{$query}_{$blocklist}";
        
        // Cache for 5 minutes to avoid excessive DNS queries
        return Cache::remember($cacheKey, 300, function () use ($query, $blocklist) {
            $lookup = "{$query}.{$blocklist}";
            
            // Use gethostbyname which returns the IP if found, or the hostname if not found
            $result = gethostbyname($lookup);
            
            // If result is different from input, it means we got an IP (listed)
            // If result is same as input, it means DNS lookup failed (not listed)
            return $result !== $lookup && filter_var($result, FILTER_VALIDATE_IP);
        });
    }

    /**
     * Reverse IP address for DNSBL lookup
     */
    private function reverseIp(string $ip): string
    {
        return implode('.', array_reverse(explode('.', $ip)));
    }
}

