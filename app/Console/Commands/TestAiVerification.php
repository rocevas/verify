<?php

namespace App\Console\Commands;

use App\Services\AiEmailVerificationService;
use Illuminate\Console\Command;

class TestAiVerification extends Command
{
    protected $signature = 'ai:test {email}';
    protected $description = 'Test AI email verification with Ollama';

    public function handle(AiEmailVerificationService $aiService): int
    {
        $email = $this->argument('email');

        $this->info("Testing AI verification for: {$email}");
        $this->newLine();

        // Check if AI is enabled
        $enabled = config('services.ai.enabled', true);
        $provider = config('services.ai.provider', 'ollama');
        $model = config('services.ai.model', 'llama3.2:1b');
        $baseUrl = config('services.ai.base_url', 'http://localhost:11434');

        $this->info("AI Configuration:");
        $this->line("  Provider: {$provider}");
        $this->line("  Model: {$model}");
        $this->line("  Base URL: {$baseUrl}");
        $this->line("  Enabled: " . ($enabled ? 'Yes' : 'No'));
        $this->newLine();

        if (!$enabled) {
            $this->error("AI is disabled! Set AI_ENABLED=true in .env");
            return 1;
        }

        $this->info("Starting verification...");
        $this->newLine();

        $result = $aiService->verifyWithAi(
            $email,
            null, // userId
            null, // teamId
            null, // tokenId
            null, // bulkJobId
            'test', // source
            function ($data) {
                if ($data['type'] === 'step') {
                    $this->line("  → {$data['message']}");
                }
            }
        );

        $this->newLine();
        $this->info("Verification Results:");
        $this->newLine();

        $this->line("Email: {$result['email']}");
        $this->line("Status: {$result['status']}");
        $this->line("Score: {$result['score']}/100");
        $this->newLine();

        $this->info("Traditional Checks:");
        foreach ($result['checks'] as $check => $value) {
            if ($check !== 'ai_analysis') {
                $status = $value ? '✅' : '❌';
                $this->line("  {$status} " . ucfirst($check));
            }
        }
        $this->newLine();

        if ($result['checks']['ai_analysis'] ?? false) {
            $this->info("AI Analysis:");
            $this->line("  AI Confidence: " . ($result['ai_confidence'] ?? 'N/A') . "/100");
            if ($result['ai_insights'] ?? null) {
                $this->line("  AI Insights: " . $result['ai_insights']);
            }
            $this->newLine();
        } else {
            $this->warn("AI Analysis: Not performed (check logs for errors)");
            $this->newLine();
        }

        $this->info("Final Result:");
        $this->line("  Status: {$result['status']}");
        $this->line("  Score: {$result['score']}/100");
        if ($result['error'] ?? null) {
            $this->line("  Error: {$result['error']}");
        }

        return 0;
    }
}

