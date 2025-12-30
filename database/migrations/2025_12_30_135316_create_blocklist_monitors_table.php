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
        Schema::create('blocklist_monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('team_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('name')->comment('Monitor name');
            $table->enum('type', ['domain', 'ip'])->comment('Type: domain or IP address');
            $table->string('target')->comment('Domain or IP address to monitor');
            $table->boolean('active')->default(true)->comment('Whether monitoring is active');
            $table->integer('check_interval_minutes')->default(60)->comment('Check interval in minutes');
            $table->timestamp('last_checked_at')->nullable()->comment('Last check timestamp');
            $table->boolean('is_blocklisted')->default(false)->comment('Current blocklist status');
            $table->json('last_check_details')->nullable()->comment('Last check details');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'active']);
            $table->index(['type', 'target']);
            $table->index('last_checked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blocklist_monitors');
    }
};
