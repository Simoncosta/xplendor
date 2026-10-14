<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Radar (Bússola Master, R2): a relevância por ramo, os sinais de cada empresa e as definições.
 *  · radar_relevances: uma vez por tendência e por ramo (sem empresa); guarda o modelo, os tokens
 *    e o custo da IA, que se somam no painel do root.
 *  · radar_signals: o cartão de uma empresa (no máximo 3 por dia), com a cópia das fontes e a
 *    ligação à ideia criada pelo "Reagir". Apaga-se com a tendência (30 dias).
 *  · radar_settings: o desporto, os clubes e os temas silenciados de cada empresa.
 * Tabelas novas; nada do que existe muda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radar_relevances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained('radar_topics')->cascadeOnDelete();
            $table->string('sector_slug', 40);
            $table->boolean('relevant')->default(false);
            $table->unsignedTinyInteger('score')->default(0);
            $table->string('theme', 80)->nullable();
            $table->string('reason', 255)->nullable();
            $table->string('model', 60)->nullable();
            $table->string('effort', 10)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->dateTime('created_at')->nullable();
            $table->unique(['topic_id', 'sector_slug']);
            $table->index(['sector_slug', 'relevant']);
        });
        Schema::create('radar_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained('radar_topics')->cascadeOnDelete();
            $table->string('why', 255);
            $table->string('why_source', 10)->default('template'); // ai | template
            $table->dateTime('window_until');
            $table->string('status', 12)->default('new'); // new | reacted | ignored | muted
            $table->unsignedTinyInteger('score')->default(0);
            $table->json('flags')->nullable();
            $table->json('sources')->nullable();
            $table->foreignId('editorial_post_id')->nullable()->constrained('editorial_posts')->nullOnDelete();
            $table->dateTime('acted_at')->nullable();
            $table->unsignedBigInteger('acted_by_user_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'topic_id']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'created_at']);
        });
        Schema::create('radar_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();
            $table->boolean('sports_enabled')->default(false);
            $table->json('sports_clubs')->nullable();
            $table->json('muted')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radar_settings');
        Schema::dropIfExists('radar_signals');
        Schema::dropIfExists('radar_relevances');
    }
};
