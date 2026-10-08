<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — F3 do marketing da restauração: "O que publicar e quando".
 *  · restaurant_signals: os sinais calculados (sugestões e informação), por empresa e loja.
 *    Recalculados de cada vez (a tabela é substituída por empresa); a chave é estável.
 *  · restaurant_signal_actions: registo do que as pessoas fizeram a cada sinal (ignorar,
 *    voltar a mostrar, publicação criada na Linha Editorial). Nunca se apaga.
 *  · restaurant_data_quality.signals_computed_at / signals_availability: quando se
 *    calcularam os sinais e, por loja, quando cada sinal fica disponível.
 * Aditiva e reversível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('pingwin_locations')->cascadeOnDelete();
            $table->string('type', 24);        // top_items | top_categories | item_up | item_down | weak_period | stale_item | lead_time | delivery_share | channels
            $table->string('signal_key', 160); // estável entre cálculos (para ignorar e ligar à publicação)
            $table->string('kind', 12);        // suggestion | info
            $table->string('confidence', 8);   // alta | media (a baixa nunca se guarda)
            $table->string('title');
            $table->text('sentence');
            $table->json('numbers')->nullable();
            $table->json('sample')->nullable();
            $table->string('theme')->nullable();        // tema sugerido para a publicação
            $table->date('suggested_date')->nullable(); // data sugerida para publicar
            $table->unsignedInteger('priority')->default(0);
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'signal_key'], 'rest_signals_company_key_unique');
            $table->index(['company_id', 'kind', 'priority'], 'rest_signals_company_kind_idx');
        });

        Schema::create('restaurant_signal_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('signal_key', 160);
            $table->string('action', 16); // ignored | restored | post_created
            $table->date('hidden_until')->nullable();
            $table->foreignId('editorial_post_id')->nullable()->constrained('editorial_posts')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'signal_key'], 'rest_signal_actions_company_key_idx');
        });

        Schema::table('restaurant_data_quality', function (Blueprint $table) {
            $table->timestamp('signals_computed_at')->nullable()->after('computed_at');
            $table->json('signals_availability')->nullable()->after('signals_computed_at');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_data_quality', function (Blueprint $table) {
            $table->dropColumn(['signals_computed_at', 'signals_availability']);
        });
        Schema::dropIfExists('restaurant_signal_actions');
        Schema::dropIfExists('restaurant_signals');
    }
};
