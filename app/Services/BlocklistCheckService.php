<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class BlocklistCheckService
{
    /**
     * Get blocklists for a specific plan
     *
     * @param string $plan
     * @return array
     */
    private function getBlocklistsForPlan(string $plan = 'free'): array
    {
        $config = config('blocklists.plans', []);
        $defaultPlan = config('blocklists.default_plan', 'free');
        
        // Get blocklists for the plan, fallback to default plan if plan doesn't exist
        $blocklists = $config[$plan] ?? $config[$defaultPlan] ?? [];
        
        return $blocklists;
    }

    /**
     * Check if a domain is listed in any blocklist
     * Resolves domain to IP and checks the IP in DNS blocklists
     *
     * @param string $domain
     * @param string $plan The user's plan (free or paid)
     * @return array
     */
    public function checkDomain(string $domain, string $plan = 'free'): array
    {
        $foundIn = [];
        $details = [];
        $resolvedIp = null;

        // Resolve domain to IP address
        try {
            $resolvedIp = gethostbyname($domain);
            
            // If gethostbyname returns the same string, DNS resolution failed
            if ($resolvedIp === $domain) {
                throw new \Exception("Failed to resolve domain {$domain} to IP address");
            }
            
            if (!filter_var($resolvedIp, FILTER_VALIDATE_IP)) {
                throw new \Exception("Invalid IP address resolved for domain {$domain}: {$resolvedIp}");
            }
            
            $details['resolved_ip'] = $resolvedIp;
        } catch (\Exception $e) {
            Log::warning("Failed to resolve domain {$domain} to IP", [
                'error' => $e->getMessage(),
            ]);
            
            return [
                'is_blocklisted' => false,
                'blocklists' => [],
                'details' => [
                    'error' => $e->getMessage(),
                    'resolved_ip' => null,
                ],
            ];
        }

        // Check the resolved IP in IP blocklists
        $reversedIp = $this->reverseIp($resolvedIp);
        $blocklists = $this->getBlocklistsForPlan($plan);
        
        foreach ($blocklists as $blocklist => $name) {
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
                Log::warning("Failed to check domain {$domain} (IP: {$resolvedIp}) against {$blocklist}", [
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
     * Check if an IP address is listed in any DNS blocklist
     *
     * @param string $ip
     * @param string $plan The user's plan (free or paid)
     * @return array
     */
    public function checkIp(string $ip, string $plan = 'free'): array
    {
        // Validate IP address
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException("Invalid IP address: {$ip}");
        }

        $foundIn = [];
        $details = [];

        // Reverse IP for DNSBL lookup (e.g., 192.168.1.1 -> 1.1.168.192)
        $reversedIp = $this->reverseIp($ip);
        $blocklists = $this->getBlocklistsForPlan($plan);

        foreach ($blocklists as $blocklist => $name) {
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

