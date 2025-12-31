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
        Schema::create('dmarc_monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('team_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('domain')->comment('Domain to monitor for DMARC');
            $table->string('report_email')->nullable()->comment('Email address to receive DMARC reports');
            $table->boolean('active')->default(true)->comment('Whether monitoring is active');
            $table->integer('check_interval_minutes')->default(1440)->comment('Check interval in minutes (default 24h)');
            $table->timestamp('last_checked_at')->nullable()->comment('Last check timestamp');
            $table->boolean('has_issue')->default(false)->comment('Whether DMARC has issues');
            $table->json('last_check_details')->nullable()->comment('Last check details');
            $table->json('dmarc_record')->nullable()->comment('Current DMARC record');
            $table->text('dmarc_record_string')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'active']);
            $table->index('domain');
            $table->index('last_checked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dmarc_monitors');
    }
};
