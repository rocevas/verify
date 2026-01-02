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
        Schema::create('mx_skip_list', function (Blueprint $table) {
            $table->id();
            $table->string('mx_host')->unique()->index();
            $table->string('reason')->nullable()->comment('Why this MX server was added (e.g., SMTP connection failed, error pattern detected)');
            $table->text('response')->nullable()->comment('SMTP response that triggered the skip');
            $table->integer('failure_count')->default(1)->comment('Number of times this MX server failed');
            $table->timestamp('last_failed_at')->nullable()->comment('Last time this MX server failed');
            $table->timestamp('expires_at')->nullable()->comment('When this entry expires (null = never expires for manual entries)');
            $table->boolean('is_manual')->default(false)->comment('Whether this was manually added (true) or auto-added (false)');
            $table->timestamps();
            
            // Index for expiration cleanup
            $table->index('expires_at');
            // Index for checking if MX is in skip list
            $table->index(['mx_host', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mx_skip_list');
    }
};

