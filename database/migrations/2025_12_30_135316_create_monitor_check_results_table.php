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
        Schema::create('monitor_check_results', function (Blueprint $table) {
            $table->id();
            $table->string('monitor_type')->comment('Type: blocklist_monitor or dmarc_monitor');
            $table->unsignedBigInteger('monitor_id')->comment('ID of the monitor');
            $table->boolean('has_issue')->default(false)->comment('Whether issue was detected');
            $table->json('check_details')->nullable()->comment('Check result details');
            $table->boolean('notification_sent')->default(false)->comment('Whether notification was sent');
            $table->timestamp('checked_at')->useCurrent()->comment('Check timestamp');
            $table->timestamps();

            $table->index(['monitor_type', 'monitor_id', 'checked_at']);
            $table->index('has_issue');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitor_check_results');
    }
};
