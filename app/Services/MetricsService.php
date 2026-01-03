<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MetricsService
{
    private const METRICS_PREFIX = 'email_verification_metrics:';
    private const METRICS_TTL = 86400; // 24 hours

    /**
     * Record a verification attempt
     */
    public function recordVerification(string $status, float $duration = null): void
    {
        $this->incrementCounter('verifications_total', ['status' => $status]);
        
        if ($duration !== null) {
            $this->recordHistogram('verification_duration_seconds', $duration, ['status' => $status]);
        }
    }

    /**
     * Record validation score
     */
    public function recordScore(int $score, string $status): void
    {
        $this->recordHistogram('verification_score', $score, ['status' => $status]);
    }

    /**
     * Record cache operation
     */
    public function recordCacheOperation(string $operation, string $result): void
    {
        $this->incrementCounter('cache_operations_total', [
            'operation' => $operation,
            'result' => $result,
        ]);
    }

    /**
     * Record DNS lookup
     */
    public function recordDnsLookup(string $type, float $duration): void
    {
        $this->recordHistogram('dns_lookup_duration_seconds', $duration, ['type' => $type]);
        $this->incrementCounter('dns_lookups_total', ['type' => $type]);
    }

    /**
     * Record SMTP check
     */
    public function recordSmtpCheck(bool $success, float $duration = null): void
    {
        $this->incrementCounter('smtp_checks_total', ['success' => $success ? 'true' : 'false']);
        
        if ($duration !== null) {
            $this->recordHistogram('smtp_check_duration_seconds', $duration, ['success' => $success ? 'true' : 'false']);
        }
    }

    /**
     * Record batch processing metrics
     */
    public function recordBatchProcessing(int $size, float $duration): void
    {
        $this->recordHistogram('batch_size', $size);
        $this->recordHistogram('batch_processing_duration_seconds', $duration);
        $this->incrementCounter('batches_total');
    }

    /**
     * Increment a counter
     */
    private function incrementCounter(string $name, array $labels = []): void
    {
        $key = $this->buildKey($name, $labels);
        Cache::increment($key, 1);
        Cache::put($key, Cache::get($key, 0), self::METRICS_TTL);
    }

    /**
     * Record a histogram value
     */
    private function recordHistogram(string $name, float $value, array $labels = []): void
    {
        $key = $this->buildKey($name, $labels);
        $values = Cache::get($key, []);
        $values[] = [
            'value' => $value,
            'timestamp' => now()->timestamp,
        ];
        
        // Keep only last 1000 values to prevent memory issues
        if (count($values) > 1000) {
            $values = array_slice($values, -1000);
        }
        
        Cache::put($key, $values, self::METRICS_TTL);
    }

    /**
     * Build cache key from name and labels
     */
    private function buildKey(string $name, array $labels = []): string
    {
        $key = self::METRICS_PREFIX . $name;
        
        if (!empty($labels)) {
            ksort($labels);
            $key .= ':' . md5(json_encode($labels));
        }
        
        return $key;
    }

    /**
     * Get counter value
     */
    public function getCounter(string $name, array $labels = []): int
    {
        $key = $this->buildKey($name, $labels);
        return Cache::get($key, 0);
    }

    /**
     * Get histogram statistics
     */
    public function getHistogramStats(string $name, array $labels = []): array
    {
        $key = $this->buildKey($name, $labels);
        $values = Cache::get($key, []);
        
        if (empty($values)) {
            return [
                'count' => 0,
                'sum' => 0,
                'avg' => 0,
                'min' => 0,
                'max' => 0,
            ];
        }
        
        $numericValues = array_column($values, 'value');
        
        return [
            'count' => count($numericValues),
            'sum' => array_sum($numericValues),
            'avg' => count($numericValues) > 0 ? array_sum($numericValues) / count($numericValues) : 0,
            'min' => min($numericValues),
            'max' => max($numericValues),
        ];
    }

    /**
     * Get all metrics summary
     */
    public function getSummary(): array
    {
        return [
            'verifications' => [
                'total' => $this->getCounter('verifications_total'),
                'by_status' => [
                    'valid' => $this->getCounter('verifications_total', ['status' => 'valid']),
                    'invalid' => $this->getCounter('verifications_total', ['status' => 'invalid']),
                    'risky' => $this->getCounter('verifications_total', ['status' => 'risky']),
                ],
                'duration' => $this->getHistogramStats('verification_duration_seconds'),
            ],
            'smtp_checks' => [
                'total' => $this->getCounter('smtp_checks_total'),
                'success' => $this->getCounter('smtp_checks_total', ['success' => 'true']),
                'failed' => $this->getCounter('smtp_checks_total', ['success' => 'false']),
                'duration' => $this->getHistogramStats('smtp_check_duration_seconds'),
            ],
            'dns_lookups' => [
                'total' => $this->getCounter('dns_lookups_total'),
                'duration' => $this->getHistogramStats('dns_lookup_duration_seconds'),
            ],
            'cache' => [
                'operations' => [
                    'total' => $this->getCounter('cache_operations_total'),
                    'hits' => $this->getCounter('cache_operations_total', ['result' => 'hit']),
                    'misses' => $this->getCounter('cache_operations_total', ['result' => 'miss']),
                ],
            ],
            'batches' => [
                'total' => $this->getCounter('batches_total'),
                'size' => $this->getHistogramStats('batch_size'),
                'duration' => $this->getHistogramStats('batch_processing_duration_seconds'),
            ],
        ];
    }
}

