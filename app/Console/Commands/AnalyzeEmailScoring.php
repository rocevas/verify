<?php

namespace App\Console\Commands;

use App\Services\EmailVerificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AnalyzeEmailScoring extends Command
{
    protected $signature = 'email:analyze-scoring {file?} {--output=csv}';
    protected $description = 'Analyze email scoring patterns from CSV file';

    public function handle(EmailVerificationService $verificationService)
    {
        $file = $this->argument('file') ?? 'test-email-scoring-analysis.csv';
        
        if (!file_exists($file)) {
            $this->error("File not found: {$file}");
            return 1;
        }

        $this->info("Reading emails from: {$file}");
        $emails = $this->readCsv($file);
        
        $this->info("Found " . count($emails) . " emails to analyze");
        $this->newLine();

        $results = [];
        $patterns = [];

        foreach ($emails as $index => $emailData) {
            $email = $emailData['email'];
            $category = $emailData['category'] ?? 'unknown';
            
            $this->info("Processing [{$index}/" . count($emails) . "]: {$email} ({$category})");
            
            try {
                $result = $verificationService->verify($email);
                
                // Extract key factors
                $factors = $this->extractFactors($result);
                $factors['email'] = $email;
                $factors['category'] = $category;
                $factors['score'] = $result['score'] ?? 0;
                $factors['state'] = $result['state'] ?? 'unknown';
                $factors['reason'] = $result['reason'] ?? null;
                
                $results[] = $factors;
                
                // Track patterns
                $this->trackPatterns($patterns, $factors);
                
            } catch (\Exception $e) {
                $this->error("  Error: " . $e->getMessage());
                $results[] = [
                    'email' => $email,
                    'category' => $category,
                    'score' => 0,
                    'error' => $e->getMessage(),
                ];
            }
            
            // Small delay to avoid rate limiting
            usleep(100000); // 0.1 second
        }

        $this->newLine();
        $this->info("=== Analysis Results ===");
        $this->newLine();

        // Generate analysis report
        $this->generateReport($results, $patterns);

        // Save to CSV if requested
        if ($this->option('output') === 'csv') {
            $outputFile = 'scoring-analysis-results-' . date('Y-m-d-His') . '.csv';
            $this->saveToCsv($results, $outputFile);
            $this->info("Results saved to: {$outputFile}");
        }

        return 0;
    }

    private function readCsv(string $file): array
    {
        $emails = [];
        $handle = fopen($file, 'r');
        
        // Skip header
        $header = fgetcsv($handle);
        
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) >= 1) {
                $emails[] = [
                    'email' => $row[0],
                    'category' => $row[1] ?? 'unknown',
                    'expected_score_range' => $row[2] ?? null,
                    'notes' => $row[3] ?? null,
                ];
            }
        }
        
        fclose($handle);
        return $emails;
    }

    private function extractFactors(array $result): array
    {
        $checks = $result['checks'] ?? [];
        
        return [
            'syntax' => $checks['syntax'] ?? false,
            'domain_validity' => $checks['domain_validity'] ?? false,
            'mx_record' => $checks['mx_record'] ?? false,
            'smtp' => $checks['smtp'] ?? false,
            'disposable' => $checks['disposable'] ?? false,
            'role' => $checks['role'] ?? false,
            'catch_all' => $checks['catch_all'] ?? false,
            'free' => $checks['free'] ?? $checks['is_free'] ?? false,
            'mailbox_full' => $checks['mailbox_full'] ?? false,
            'numerical_characters' => $result['numerical_characters'] ?? 0,
            'alphabetical_characters' => $result['alphabetical_characters'] ?? 0,
            'alias_of' => !empty($result['alias_of']),
            'domain' => $result['domain'] ?? null,
            'smtp_provider' => $result['smtp_provider'] ?? null,
        ];
    }

    private function trackPatterns(array &$patterns, array $factors): void
    {
        // Group by category
        $category = $factors['category'];
        if (!isset($patterns[$category])) {
            $patterns[$category] = [
                'count' => 0,
                'scores' => [],
                'factors' => [],
            ];
        }
        
        $patterns[$category]['count']++;
        $patterns[$category]['scores'][] = $factors['score'];
        
        // Track factor combinations
        $factorKey = $this->getFactorKey($factors);
        if (!isset($patterns[$category]['factors'][$factorKey])) {
            $patterns[$category]['factors'][$factorKey] = [
                'count' => 0,
                'scores' => [],
            ];
        }
        
        $patterns[$category]['factors'][$factorKey]['count']++;
        $patterns[$category]['factors'][$factorKey]['scores'][] = $factors['score'];
    }

    private function getFactorKey(array $factors): string
    {
        $parts = [];
        
        if ($factors['free']) $parts[] = 'free';
        if ($factors['role']) $parts[] = 'role';
        if ($factors['catch_all']) $parts[] = 'catch_all';
        if ($factors['disposable']) $parts[] = 'disposable';
        if ($factors['mailbox_full']) $parts[] = 'mailbox_full';
        if ($factors['numerical_characters'] > 0) $parts[] = 'num:' . $factors['numerical_characters'];
        if ($factors['alias_of']) $parts[] = 'alias';
        
        return implode('+', $parts ?: ['none']);
    }

    private function generateReport(array $results, array $patterns): void
    {
        // Category summary
        $this->info("=== Category Summary ===");
        foreach ($patterns as $category => $data) {
            $avgScore = count($data['scores']) > 0 
                ? round(array_sum($data['scores']) / count($data['scores']), 2)
                : 0;
            $minScore = count($data['scores']) > 0 ? min($data['scores']) : 0;
            $maxScore = count($data['scores']) > 0 ? max($data['scores']) : 0;
            
            $this->line("{$category}:");
            $this->line("  Count: {$data['count']}");
            $this->line("  Score: avg={$avgScore}, min={$minScore}, max={$maxScore}");
            
            // Factor combinations
            if (!empty($data['factors'])) {
                $this->line("  Factor combinations:");
                foreach ($data['factors'] as $factorKey => $factorData) {
                    $factorAvg = count($factorData['scores']) > 0
                        ? round(array_sum($factorData['scores']) / count($factorData['scores']), 2)
                        : 0;
                    $this->line("    {$factorKey}: avg={$factorAvg} (count={$factorData['count']})");
                }
            }
            $this->newLine();
        }

        // Multiplier analysis
        $this->info("=== Multiplier Analysis ===");
        $this->analyzeMultipliers($results);
        
        // Score distribution
        $this->info("=== Score Distribution ===");
        $this->analyzeScoreDistribution($results);
    }

    private function analyzeMultipliers(array $results): void
    {
        // Analyze free multiplier
        $freeScores = [];
        $nonFreeScores = [];
        
        foreach ($results as $result) {
            if ($result['free']) {
                $freeScores[] = $result['score'];
            } else {
                $nonFreeScores[] = $result['score'];
            }
        }
        
        if (count($freeScores) > 0 && count($nonFreeScores) > 0) {
            $freeAvg = array_sum($freeScores) / count($freeScores);
            $nonFreeAvg = array_sum($nonFreeScores) / count($nonFreeScores);
            $multiplier = $freeAvg / $nonFreeAvg;
            $this->line("Free multiplier: ~" . round($multiplier, 3) . "x (free avg: {$freeAvg}, non-free avg: {$nonFreeAvg})");
        }

        // Analyze role multiplier
        $roleScores = [];
        $nonRoleScores = [];
        
        foreach ($results as $result) {
            if ($result['role']) {
                $roleScores[] = $result['score'];
            } else {
                $nonRoleScores[] = $result['score'];
            }
        }
        
        if (count($roleScores) > 0 && count($nonRoleScores) > 0) {
            $roleAvg = array_sum($roleScores) / count($roleScores);
            $nonRoleAvg = array_sum($nonRoleScores) / count($nonRoleScores);
            $multiplier = $roleAvg / $nonRoleAvg;
            $this->line("Role multiplier: ~" . round($multiplier, 3) . "x (role avg: {$roleAvg}, non-role avg: {$nonRoleAvg})");
        }

        // Analyze catch-all multiplier
        $catchAllScores = [];
        $nonCatchAllScores = [];
        
        foreach ($results as $result) {
            if ($result['catch_all']) {
                $catchAllScores[] = $result['score'];
            } else {
                $nonCatchAllScores[] = $result['score'];
            }
        }
        
        if (count($catchAllScores) > 0 && count($nonCatchAllScores) > 0) {
            $catchAllAvg = array_sum($catchAllScores) / count($catchAllScores);
            $nonCatchAllAvg = array_sum($nonCatchAllScores) / count($nonCatchAllScores);
            $multiplier = $catchAllAvg / $nonCatchAllAvg;
            $this->line("Catch-all multiplier: ~" . round($multiplier, 3) . "x (catch-all avg: {$catchAllAvg}, non-catch-all avg: {$nonCatchAllAvg})");
        }

        // Analyze numerical characters impact
        $this->analyzeNumericalImpact($results);
    }

    private function analyzeNumericalImpact(array $results): void
    {
        $byNumbers = [];
        
        foreach ($results as $result) {
            $num = $result['numerical_characters'];
            if (!isset($byNumbers[$num])) {
                $byNumbers[$num] = [];
            }
            $byNumbers[$num][] = $result['score'];
        }
        
        ksort($byNumbers);
        
        $this->line("Numerical characters impact:");
        foreach ($byNumbers as $num => $scores) {
            $avg = array_sum($scores) / count($scores);
            $this->line("  {$num} numbers: avg score = " . round($avg, 2) . " (count: " . count($scores) . ")");
        }
    }

    private function analyzeScoreDistribution(array $results): void
    {
        $scores = array_column($results, 'score');
        
        $ranges = [
            '0' => 0,
            '1-20' => 0,
            '21-40' => 0,
            '41-60' => 0,
            '61-80' => 0,
            '81-100' => 0,
        ];
        
        foreach ($scores as $score) {
            if ($score == 0) {
                $ranges['0']++;
            } elseif ($score <= 20) {
                $ranges['1-20']++;
            } elseif ($score <= 40) {
                $ranges['21-40']++;
            } elseif ($score <= 60) {
                $ranges['41-60']++;
            } elseif ($score <= 80) {
                $ranges['61-80']++;
            } else {
                $ranges['81-100']++;
            }
        }
        
        foreach ($ranges as $range => $count) {
            $percentage = count($scores) > 0 ? round(($count / count($scores)) * 100, 1) : 0;
            $this->line("Score {$range}: {$count} ({$percentage}%)");
        }
    }

    private function saveToCsv(array $results, string $filename): void
    {
        $handle = fopen($filename, 'w');
        
        // Header
        $headers = [
            'email', 'category', 'score', 'state', 'reason',
            'syntax', 'domain_validity', 'mx_record', 'smtp',
            'disposable', 'role', 'catch_all', 'free',
            'mailbox_full', 'numerical_characters', 'alphabetical_characters',
            'alias_of', 'domain', 'smtp_provider',
        ];
        fputcsv($handle, $headers);
        
        // Data
        foreach ($results as $result) {
            $row = [
                $result['email'] ?? '',
                $result['category'] ?? '',
                $result['score'] ?? 0,
                $result['state'] ?? '',
                $result['reason'] ?? '',
                $result['syntax'] ? '1' : '0',
                $result['domain_validity'] ? '1' : '0',
                $result['mx_record'] ? '1' : '0',
                $result['smtp'] ? '1' : '0',
                $result['disposable'] ? '1' : '0',
                $result['role'] ? '1' : '0',
                $result['catch_all'] ? '1' : '0',
                $result['free'] ? '1' : '0',
                $result['mailbox_full'] ? '1' : '0',
                $result['numerical_characters'] ?? 0,
                $result['alphabetical_characters'] ?? 0,
                $result['alias_of'] ? '1' : '0',
                $result['domain'] ?? '',
                $result['smtp_provider'] ?? '',
            ];
            fputcsv($handle, $row);
        }
        
        fclose($handle);
    }
}

