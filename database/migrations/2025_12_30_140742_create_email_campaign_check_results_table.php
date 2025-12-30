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
        Schema::create('email_campaign_check_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_campaign_id')->constrained()->onDelete('cascade');
            $table->string('check_type')->default('spamassassin')->comment('Type of check: spamassassin, mailtester, etc.');
            $table->decimal('spam_score', 5, 2)->nullable()->comment('Spam score');
            $table->decimal('spam_threshold', 5, 2)->default(5.0)->comment('Spam threshold');
            $table->boolean('is_spam')->default(false)->comment('Whether email is considered spam');
            $table->json('spam_rules')->nullable()->comment('Spam rules that matched');
            $table->json('check_details')->nullable()->comment('Full check details');
            $table->json('deliverability_score')->nullable()->comment('Deliverability score breakdown');
            $table->text('recommendations')->nullable()->comment('Recommendations for improvement');
            $table->timestamp('checked_at')->useCurrent()->comment('Check timestamp');
            $table->timestamps();

            $table->index(['email_campaign_id', 'checked_at']);
            $table->index('is_spam');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_campaign_check_results');
    }
};
