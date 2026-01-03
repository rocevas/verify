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
        Schema::create('bulk_verification_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->foreignId('user_id')->constrained()->nullOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('api_key_id')->nullable()->comment('Sanctum token ID reference');
            $table->string('source')->nullable();
            $table->string('filename');
            $table->string('file_path')->nullable();
            $table->integer('total_emails')->default(0);
            $table->integer('processed_emails')->default(0);
            $table->integer('valid_count')->default(0);
            $table->integer('invalid_count')->default(0);
            $table->integer('risky_count')->default(0);
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->text('error')->nullable();
            $table->string('result_file_path')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Index for team-based queries (used in controllers)
            $table->index(['team_id', 'created_at']);
            $table->index(['team_id', 'deleted_at']);
            // Index for user (informational only - to track who created it)
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bulk_verification_jobs');
    }
};
