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
        Schema::create('car_interactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('car_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('interaction_type', 50);
            $table->string('interaction_target', 50)->nullable();

            $table->string('page_type', 50)->nullable();
            $table->string('page_context', 100)->nullable();
            $table->text('page_url')->nullable();

            $table->string('phone', 30)->nullable();
            $table->string('whatsapp_number', 30)->nullable();

            $table->string('referrer')->nullable();
            $table->text('landing_path')->nullable();
            $table->string('channel', 50)->nullable();
            $table->string('visitor_id', 64)->index();
            $table->string('session_id', 64)->index();

            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('utm_term')->nullable();

            $table->json('meta')->nullable();

            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'car_id']);
            $table->index(['company_id', 'interaction_type']);
            $table->index(['company_id', 'visitor_id']);
            $table->index(['company_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('car_interations');
    }
};
