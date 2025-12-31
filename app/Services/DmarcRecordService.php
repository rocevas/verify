<?php

namespace App\Services;

use App\Models\DmarcMonitor;
use Illuminate\Support\Facades\Log;

class DmarcRecordService
{
    /**
     * Generate DMARC record for a monitor
     */
    public function generateRecord(DmarcMonitor $monitor, array $options = []): string
    {
        $policy = $options['policy'] ?? 'none';
        $percentage = $options['percentage'] ?? 100;
        $reportEmail = $monitor->report_email ?? $monitor->generateReportEmail();
        
        $parts = [
            'v=DMARC1',
            "p={$policy}",
            "pct={$percentage}",
            "rua=mailto:{$reportEmail}",
        ];

        // Add optional subdomain policy
        if (isset($options['subdomain_policy'])) {
            $parts[] = "sp={$options['subdomain_policy']}";
        }

        // Add optional ruf (forensic reports)
        if (isset($options['ruf'])) {
            $parts[] = "ruf=mailto:{$options['ruf']}";
        }

        // Add optional alignment
        if (isset($options['aspf'])) {
            $parts[] = "aspf={$options['aspf']}";
        }
        if (isset($options['adkim'])) {
            $parts[] = "adkim={$options['adkim']}";
        }

        return implode('; ', $parts);
    }

    /**
     * Parse DMARC record string into array
     */
    public function parseRecord(string $record): array
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
                    // Remove mailto: prefix if present
                    $value = str_replace('mailto:', '', $value);
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

    /**
     * Validate DMARC record
     */
    public function validateRecord(string $record): array
    {
        $errors = [];
        $parsed = $this->parseRecord($record);

        // Check required fields
        if (!isset($parsed['v']) || $parsed['v'] !== 'DMARC1') {
            $errors[] = 'Missing or invalid version (v=DMARC1)';
        }

        if (!isset($parsed['p'])) {
            $errors[] = 'Missing policy (p)';
        } elseif (!in_array($parsed['p'], ['none', 'quarantine', 'reject'])) {
            $errors[] = 'Invalid policy. Must be none, quarantine, or reject';
        }

        // Check percentage
        if (isset($parsed['pct']) && ($parsed['pct'] < 0 || $parsed['pct'] > 100)) {
            $errors[] = 'Percentage must be between 0 and 100';
        }

        // Check rua/ruf format
        if (isset($parsed['rua'])) {
            foreach ($parsed['rua'] as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Invalid email in rua: {$email}";
                }
            }
        }

        if (isset($parsed['ruf'])) {
            foreach ($parsed['ruf'] as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Invalid email in ruf: {$email}";
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'parsed' => $parsed,
        ];
    }
}

