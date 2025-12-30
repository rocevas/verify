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
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('api_key_id')->nullable()->comment('Sanctum token ID reference');
            $table->foreignId('bulk_verification_job_id')->nullable()->constrained('bulk_verification_jobs')->nullOnDelete();
            $table->string('source')->nullable();
            $table->string('email')->index();
            $table->string('account')->nullable();
            $table->string('domain')->nullable();
            $table->enum('status', ['valid', 'invalid', 'catch_all', 'unknown', 'spamtrap', 'abuse', 'do_not_mail', 'risky'])->default('unknown');
            $table->json('checks')->nullable();
            $table->integer('score')->nullable();
            $table->text('error')->nullable();
            $table->string('file_path')->nullable()->comment('Optional file path for future use');
            $table->timestamp('verified_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Index for team-based queries (used in controllers)
            $table->index(['team_id', 'created_at']);
            $table->index(['team_id', 'deleted_at']);
            // Index for user (informational only - to track who created it)
            $table->index('user_id');
            // Other indexes
            $table->index(['email', 'status']);
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
