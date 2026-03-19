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
        Schema::create('company_integrations', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 30)->comment('meta, google, tiktok...');

            // Credenciais
            $table->text('access_token');
            $table->string('account_id', 50)->comment('act_XXXXXXXXXX')->nullable();
            $table->string('page_id', 50)->comment('para AutoPost (JIRA XPLDR-34)')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            // Estado
            $table->enum('status', ['active', 'expired', 'revoked', 'error'])->default('active');
            $table->string('error_message', 500)->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'platform']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_integrations');
    }
};
