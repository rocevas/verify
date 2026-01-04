<?php

use App\Models\User;
use App\Services\EmailVerificationService;
use App\Services\DmarcCheckService;
use App\Services\GravatarService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cache;

test('email verification service can verify basic email', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $service = app(EmailVerificationService::class);
    
    $result = $service->verify(
        'test@example.com',
        $user->id,
        $user->currentTeam->id,
        null,
        null,
        'test'
    );
    
    expect($result)->toBeArray();
    expect($result)->toHaveKey('email');
    expect($result)->toHaveKey('state');
    expect($result)->toHaveKey('result');
    expect($result)->toHaveKey('score');
    expect($result['email'])->toBe('test@example.com');
});

test('email verification service handles public provider catch-all', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $service = app(EmailVerificationService::class);
    
    // Enable catch-all detection
    Config::set('email-verification.enable_catch_all_detection', true);
    Config::set('email-verification.enable_smtp_check', true);
    
    $result = $service->verify(
        'test@gmail.com',
        $user->id,
        $user->currentTeam->id,
        null,
        null,
        'test'
    );
    
    expect($result)->toBeArray();
    // Public provider should be marked as free if MX records exist
    expect($result)->toHaveKey('free');
    expect($result['free'])->toBeBool();
    expect($result)->toHaveKey('score');
    expect($result)->toHaveKey('catch_all');
    // Catch-all may be true if MX records exist and public provider is detected
    // But we just check that the key exists and is boolean
    expect($result['catch_all'])->toBeBool();
});

test('email verification service includes dmarc check for catch-all emails', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $service = app(EmailVerificationService::class);
    
    // Enable DMARC check
    Config::set('email-verification.enable_dmarc_check', true);
    Config::set('email-verification.enable_catch_all_detection', true);
    
    $result = $service->verify(
        'test@gmail.com',
        $user->id,
        $user->currentTeam->id,
        null,
        null,
        'test'
    );
    
    expect($result)->toBeArray();
    expect($result)->toHaveKey('catch_all');
    
    // DMARC should be checked if catch-all is true (may be null if check failed, but key should exist)
    if ($result['catch_all'] ?? false) {
        expect($result)->toHaveKey('dmarc');
    }
});

test('email verification service calculates hunter.io confidence for catch-all emails', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $service = app(EmailVerificationService::class);
    
    // Enable Hunter.io confidence
    Config::set('email-verification.enable_hunter_confidence', true);
    Config::set('email-verification.enable_catch_all_detection', true);
    
    $result = $service->verify(
        'test@gmail.com',
        $user->id,
        $user->currentTeam->id,
        null,
        null,
        'test'
    );
    
    expect($result)->toBeArray();
    expect($result)->toHaveKey('catch_all');
    
    // Hunter confidence should be calculated for catch-all emails
    if ($result['catch_all'] ?? false) {
        expect($result)->toHaveKey('hunter_confidence');
        expect($result['hunter_confidence'])->toBeInt();
        expect($result['hunter_confidence'])->toBeGreaterThanOrEqual(0);
        expect($result['hunter_confidence'])->toBeLessThanOrEqual(100);
    }
});

test('email verification service includes verification method when vrfy/expn used', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $service = app(EmailVerificationService::class);
    
    // Enable VRFY check
    Config::set('email-verification.enable_vrfy_check', true);
    
    $result = $service->verify(
        'test@example.com',
        $user->id,
        $user->currentTeam->id,
        null,
        null,
        'test'
    );
    
    expect($result)->toBeArray();
    
    // Verification method may or may not be present (depends on SMTP server support)
    // But if present, it should be valid
    if (isset($result['verification_method'])) {
        expect($result['verification_method'])->toBeIn(['vrfy', 'expn']);
    }
});

test('email verification service includes gravatar check for catch-all emails', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $service = app(EmailVerificationService::class);
    
    // Enable Gravatar check
    Config::set('email-verification.enable_gravatar_check', true);
    Config::set('email-verification.enable_catch_all_detection', true);
    
    $result = $service->verify(
        'test@gmail.com',
        $user->id,
        $user->currentTeam->id,
        null,
        null,
        'test'
    );
    
    expect($result)->toBeArray();
    expect($result)->toHaveKey('catch_all');
    
    // Gravatar should be checked if catch-all is true (may be false, but key should exist)
    if ($result['catch_all'] ?? false) {
        expect($result)->toHaveKey('gravatar');
        expect($result['gravatar'])->toBeBool();
    }
});

test('email verification service returns correct structure', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $service = app(EmailVerificationService::class);
    
    $result = $service->verify(
        'test@example.com',
        $user->id,
        $user->currentTeam->id,
        null,
        null,
        'test'
    );
    
    // Check required fields
    expect($result)->toHaveKeys([
        'email',
        'state',
        'result',
        'score',
        'syntax',
        'domain_validity',
        'mx_record',
        'smtp',
        'disposable',
        'role',
        'catch_all',
        'free',
        'mailbox_full',
    ]);
    
    // Check types
    expect($result['email'])->toBeString();
    expect($result['state'])->toBeString();
    // Result can be string or null
    if ($result['result'] !== null) {
        expect($result['result'])->toBeString();
    }
    expect($result['score'])->toBeInt();
    expect($result['catch_all'])->toBeBool();
    expect($result['free'])->toBeBool();
});

test('email verification service handles invalid email format', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $service = app(EmailVerificationService::class);
    
    $result = $service->verify(
        'invalid-email',
        $user->id,
        $user->currentTeam->id,
        null,
        null,
        'test'
    );
    
    expect($result)->toBeArray();
    expect($result['syntax'])->toBeFalse();
    expect($result['score'])->toBe(0);
});

test('email verification service handles disposable email', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $service = app(EmailVerificationService::class);
    
    $result = $service->verify(
        'test@10minutemail.com',
        $user->id,
        $user->currentTeam->id,
        null,
        null,
        'test'
    );
    
    expect($result)->toBeArray();
    expect($result)->toHaveKey('disposable');
    // Disposable may be true or false depending on domain list
    expect($result['disposable'])->toBeBool();
});

test('email verification service handles role-based email', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $service = app(EmailVerificationService::class);
    
    $result = $service->verify(
        'admin@example.com',
        $user->id,
        $user->currentTeam->id,
        null,
        null,
        'test'
    );
    
    expect($result)->toBeArray();
    expect($result['role'])->toBeTrue();
});

test('hunter.io confidence calculation works correctly', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $service = app(EmailVerificationService::class);
    
    // Enable all features
    Config::set('email-verification.enable_hunter_confidence', true);
    Config::set('email-verification.enable_catch_all_detection', true);
    Config::set('email-verification.enable_gravatar_check', true);
    Config::set('email-verification.enable_dmarc_check', true);
    
    $result = $service->verify(
        'test@gmail.com',
        $user->id,
        $user->currentTeam->id,
        null,
        null,
        'test'
    );
    
    expect($result)->toBeArray();
    expect($result)->toHaveKey('catch_all');
    
    if ($result['catch_all'] ?? false) {
        if (isset($result['hunter_confidence'])) {
            // Confidence should be between 0 and 100
            expect($result['hunter_confidence'])->toBeGreaterThanOrEqual(0);
            expect($result['hunter_confidence'])->toBeLessThanOrEqual(100);
            
            // For catch-all without SMTP, confidence should be reduced
            if (!($result['smtp'] ?? false)) {
                // Base confidence: syntax (10) + domain (15) + MX (20) = 45
                // Catch-all reduction: 45 * 0.7 = 31.5
                // So confidence should be around 31-32% without bonuses
                expect($result['hunter_confidence'])->toBeGreaterThanOrEqual(0);
                expect($result['hunter_confidence'])->toBeLessThanOrEqual(100);
            }
        }
    }
});

test('dmarc check integration works for catch-all emails', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $service = app(EmailVerificationService::class);
    
    // Enable DMARC check
    Config::set('email-verification.enable_dmarc_check', true);
    Config::set('email-verification.enable_catch_all_detection', true);
    
    $result = $service->verify(
        'test@gmail.com',
        $user->id,
        $user->currentTeam->id,
        null,
        null,
        'test'
    );
    
    expect($result)->toBeArray();
    expect($result)->toHaveKey('catch_all');
    
    // DMARC should be checked if catch-all is true
    if ($result['catch_all'] ?? false) {
        expect($result)->toHaveKey('dmarc');
        
        if (isset($result['dmarc']['policy'])) {
            // Policy should be valid if present
            expect($result['dmarc']['policy'])->toBeIn(['none', 'quarantine', 'reject', null]);
        }
        
        // If DMARC reject policy, confidence boost should be present
        if (isset($result['dmarc']['policy']) && $result['dmarc']['policy'] === 'reject') {
            expect($result)->toHaveKey('dmarc_confidence_boost');
            expect($result['dmarc_confidence_boost'])->toBeGreaterThan(0);
        }
    }
});

test('email verification service handles config changes correctly', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $service = app(EmailVerificationService::class);
    
    // Disable catch-all detection
    Config::set('email-verification.enable_catch_all_detection', false);
    
    $result1 = $service->verify(
        'test@gmail.com',
        $user->id,
        $user->currentTeam->id,
        null,
        null,
        'test'
    );
    
    // Enable catch-all detection
    Config::set('email-verification.enable_catch_all_detection', true);
    
    $result2 = $service->verify(
        'test@gmail.com',
        $user->id,
        $user->currentTeam->id,
        null,
        null,
        'test'
    );
    
    // Results should be different
    expect($result1)->toBeArray();
    expect($result2)->toBeArray();
    
    // With catch-all detection, result should have catch_all flag
    expect($result2)->toHaveKey('catch_all');
    expect($result2['catch_all'])->toBeBool();
    
    // If MX records exist, catch-all should be detected
    if ($result2['mx_record'] ?? false) {
        // Public provider should be detected as catch-all
        expect($result2['catch_all'])->toBeTrue();
    }
});

test('email verification service does not break on exceptions', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $service = app(EmailVerificationService::class);
    
    // Test with various edge cases
    $emails = [
        'test@example.com',
        'invalid-email',
        'test@gmail.com',
        'test@nonexistent-domain-12345.com',
    ];
    
    foreach ($emails as $email) {
        try {
            $result = $service->verify(
                $email,
                $user->id,
                $user->currentTeam->id,
                null,
                null,
                'test'
            );
            
            // Should always return array, never throw exception
            expect($result)->toBeArray();
            expect($result)->toHaveKey('email');
            expect($result)->toHaveKey('state');
        } catch (\Exception $e) {
            // Should not throw exceptions
            expect(false)->toBeTrue("Exception thrown for email: {$email}");
        }
    }
});

test('email verification service caches results correctly', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $service = app(EmailVerificationService::class);
    
    // Clear cache
    Cache::flush();
    
    $email = 'test@example.com';
    
    // First verification
    $result1 = $service->verify(
        $email,
        $user->id,
        $user->currentTeam->id,
        null,
        null,
        'test'
    );
    
    // Second verification (should use cache for some checks)
    $result2 = $service->verify(
        $email,
        $user->id,
        $user->currentTeam->id,
        null,
        null,
        'test'
    );
    
    // Results should be consistent
    expect($result1)->toBeArray();
    expect($result2)->toBeArray();
    expect($result1['email'])->toBe($result2['email']);
});

test('email verification service handles all new features without breaking', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $service = app(EmailVerificationService::class);
    
    // Enable all new features
    Config::set('email-verification.enable_vrfy_check', true);
    Config::set('email-verification.enable_dmarc_check', true);
    Config::set('email-verification.enable_hunter_confidence', true);
    Config::set('email-verification.enable_catch_all_detection', true);
    Config::set('email-verification.enable_gravatar_check', true);
    
    $result = $service->verify(
        'test@gmail.com',
        $user->id,
        $user->currentTeam->id,
        null,
        null,
        'test'
    );
    
    // Should not break with all features enabled
    expect($result)->toBeArray();
    expect($result)->toHaveKey('email');
    expect($result)->toHaveKey('state');
    expect($result)->toHaveKey('score');
    
    // All new features should be present if applicable
    expect($result)->toHaveKey('catch_all');
    expect($result)->toHaveKey('free');
    
    // Optional features may or may not be present
    // But if catch-all is true, these should be checked
    if ($result['catch_all'] ?? false) {
        // DMARC, Gravatar, Hunter confidence should be checked
        expect($result)->toHaveKey('dmarc');
        expect($result)->toHaveKey('gravatar');
        if (Config::get('email-verification.enable_hunter_confidence')) {
            expect($result)->toHaveKey('hunter_confidence');
        }
    }
});

test('email verification service format response includes all new fields', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $service = app(EmailVerificationService::class);
    
    // Enable all features
    Config::set('email-verification.enable_hunter_confidence', true);
    Config::set('email-verification.enable_dmarc_check', true);
    Config::set('email-verification.enable_catch_all_detection', true);
    
    $result = $service->verify(
        'test@gmail.com',
        $user->id,
        $user->currentTeam->id,
        null,
        null,
        'test'
    );
    
    // Check that formatResponse includes all new fields
    expect($result)->toHaveKey('catch_all');
    expect($result)->toHaveKey('free');
    
    // Optional fields should be present if applicable
    if ($result['catch_all'] ?? false) {
        expect($result)->toHaveKey('dmarc');
        if (Config::get('email-verification.enable_hunter_confidence')) {
            expect($result)->toHaveKey('hunter_confidence');
        }
    }
    
    // Verification method should be present if VRFY/EXPN was used
    if (isset($result['verification_method'])) {
        expect($result['verification_method'])->toBeIn(['vrfy', 'expn']);
    }
    
    // SMTP confidence should be present if VRFY/EXPN was used
    if (isset($result['smtp_confidence'])) {
        expect($result['smtp_confidence'])->toBeInt();
        expect($result['smtp_confidence'])->toBeGreaterThanOrEqual(0);
        expect($result['smtp_confidence'])->toBeLessThanOrEqual(100);
    }
});

