<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class DmarcCheckService
{
    /**
     * Check DMARC record for a domain
     */
    public function checkDomain(string $domain): array
    {
        $cacheKey = "dmarc_check_{$domain}";
        
        return Cache::remember($cacheKey, 300, function () use ($domain) {
            try {
                $dmarcRecord = $this->getDmarcRecord($domain);
                
                if (!$dmarcRecord) {
                    return [
                        'has_issue' => true,
                        'issue_type' => 'no_record',
                        'message' => 'DMARC record not found',
                        'details' => [
                            'domain' => $domain,
                            'record' => null,
                            'parsed' => null,
                        ],
                    ];
                }

                $parsed = $this->parseDmarcRecord($dmarcRecord);
                
                // Check for common issues
                $issues = [];
                
                // Check if policy is too weak
                if (isset($parsed['p']) && in_array($parsed['p'], ['none'])) {
                    $issues[] = 'weak_policy';
                }
                
                // Check if subdomain policy is missing or weak
                if (!isset($parsed['sp']) || $parsed['sp'] === 'none') {
                    $issues[] = 'weak_subdomain_policy';
                }
                
                // Check if percentage is less than 100
                if (isset($parsed['pct']) && $parsed['pct'] < 100) {
                    $issues[] = 'partial_coverage';
                }
                
                // Check if rua/ruf are missing
                if (!isset($parsed['rua']) && !isset($parsed['ruf'])) {
                    $issues[] = 'no_reporting';
                }

                return [
                    'has_issue' => !empty($issues),
                    'issue_type' => !empty($issues) ? implode(', ', $issues) : null,
                    'message' => !empty($issues) 
                        ? 'DMARC issues detected: ' . implode(', ', $issues)
                        : 'DMARC record is properly configured',
                    'details' => [
                        'domain' => $domain,
                        'record' => $dmarcRecord,
                        'parsed' => $parsed,
                        'issues' => $issues,
                    ],
                ];
            } catch (\Exception $e) {
                Log::error("Failed to check DMARC for domain {$domain}", [
                    'error' => $e->getMessage(),
                ]);
                
                return [
                    'has_issue' => true,
                    'issue_type' => 'check_failed',
                    'message' => 'Failed to check DMARC record: ' . $e->getMessage(),
                    'details' => [
                        'domain' => $domain,
                        'error' => $e->getMessage(),
                    ],
                ];
            }
        });
    }

    /**
     * Get DMARC TXT record from DNS
     */
    private function getDmarcRecord(string $domain): ?string
    {
        $dmarcDomain = "_dmarc.{$domain}";
        
        if (!function_exists('dns_get_record')) {
            // Fallback to shell command
            $result = @shell_exec("dig +short TXT {$dmarcDomain} 2>&1");
            if ($result && strpos($result, 'v=DMARC1') !== false) {
                return trim($result);
            }
            return null;
        }

        $records = @dns_get_record($dmarcDomain, DNS_TXT);
        
        if (empty($records)) {
            return null;
        }

        // Find DMARC record (starts with v=DMARC1)
        foreach ($records as $record) {
            if (isset($record['txt']) && strpos($record['txt'], 'v=DMARC1') === 0) {
                return $record['txt'];
            }
        }

        return null;
    }

    /**
     * Parse DMARC record into array
     */
    private function parseDmarcRecord(string $record): array
    {
        $parsed = [];
        
        // Remove quotes if present
        $record = trim($record, '"');
        
        // Split by semicolon
        $parts = explode(';', $record);
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }
            
            // Split key=value
            if (strpos($part, '=') !== false) {
                [$key, $value] = explode('=', $part, 2);
                $key = strtolower(trim($key));
                $value = trim($value);
                
                // Handle tags that can have multiple values (like rua, ruf)
                if (in_array($key, ['rua', 'ruf'])) {
                    if (!isset($parsed[$key])) {
                        $parsed[$key] = [];
                    }
                    $parsed[$key][] = $value;
                } else {
                    // Convert numeric values
                    if (is_numeric($value)) {
                        $parsed[$key] = (int) $value;
                    } else {
                        $parsed[$key] = $value;
                    }
                }
            }
        }
        
        return $parsed;
    }
}

