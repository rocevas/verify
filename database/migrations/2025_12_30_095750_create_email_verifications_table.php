<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('email_verifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('api_key_id')->nullable()->comment('Sanctum token ID reference');
            $table->foreignId('bulk_verification_job_id')->nullable()->constrained('bulk_verification_jobs')->nullOnDelete();
            $table->enum('state', ['deliverable', 'undeliverable', 'risky', 'unknown', 'error'])->default('unknown')->comment('deliverable, undeliverable, risky, unknown, error');
            $table->string('result')->nullable()->comment('Result: valid, syntax_error, typo, mailbox_not_found, disposable, blocked, catch_all, mailbox_full, role, error');
            $table->string('reason')->nullable()->comment('Reason: accepted_email, invalid_email, invalid_domain, rejected_email, invalid_smtp, low_quality, low_deliverability, no_connect, timeout, unavailable_smtp, unexpected_error');

            $table->integer('score')->nullable();
            $table->integer('email_score')->nullable();
            $table->string('source')->nullable();
            $table->string('email')->index();
            $table->string('account')->nullable();
            $table->string('domain')->nullable();

            // Email attributes
            $table->integer('numerical_characters')->default(0)->comment('Number of numerical characters in email local part');
            $table->integer('alphabetical_characters')->default(0)->comment('Number of alphabetical characters in email local part');
            $table->integer('unicode_symbols')->default(0)->comment('Number of Unicode symbols in email local part');

            $table->boolean('ai_analysis')->default(false);
            $table->integer('ai_confidence')->nullable();
            $table->string('ai_insights')->nullable();
            $table->json('ai_risk_factors')->nullable();

            $table->boolean('blacklist')->default(false);
            $table->boolean('domain_validity')->default(false);
            $table->boolean('syntax')->default(false);
            $table->boolean('mx_record')->default(false);
            $table->boolean('smtp')->default(false);
            $table->boolean('disposable')->default(false);
            $table->boolean('role')->default(false);
            $table->boolean('no_reply')->default(false);
            $table->boolean('typo_domain')->default(false);
            $table->boolean('mailbox_full')->default(false);
            $table->boolean('is_free')->default(false);
            $table->boolean('isp_esp')->default(false);
            $table->boolean('government_tld')->default(false);
            $table->boolean('gravatar')->default(false);

            $table->string('did_you_mean')->nullable();
            $table->string('alias_of')->nullable()->comment('Canonical email address if this is an alias');

            // MX and SMTP information
            $table->string('mx_record_string')->nullable()->comment('First MX record hostname (e.g., route3.mx.cloudflare.net)');
            $table->string('smtp_provider')->nullable()->comment('SMTP provider name (e.g., Cloudflare, Google, Microsoft)');
            $table->boolean('implicit_mx_record')->default(false)->comment('Whether domain uses implicit MX (falls back to A record)');
            $table->boolean('secure_email_gateway')->default(false)->comment('Whether email uses Secure Email Gateway (SEG)');

            $table->timestamp('verified_at')->nullable();
            $table->decimal('duration', 10, 2)->nullable()->comment('Verification duration in milliseconds');
            $table->timestamps();

            // Index for team-based queries (used in controllers)
            $table->index(['team_id', 'created_at']);
            // Index for user (informational only - to track who created it)
            $table->index('user_id');
            // Other indexes
            $table->index(['email', 'state']);
            $table->index(['state', 'result']); // For filtering by state and result
            $table->index(['state', 'reason']);
            $table->index('bulk_verification_job_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_verifications');
    }
};
