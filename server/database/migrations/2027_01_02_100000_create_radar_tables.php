<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Radar de oportunidade, R1 (aditiva: só tabelas novas, sem company_id: a recolha é da
 * plataforma, não de uma empresa).
 *  · radar_runs: cada recolha de uma fonte (também as simulações e os 304), com o código HTTP,
 *    o ETag e o Last-Modified (para o pedido condicional seguinte) e os pedidos feitos (o teto
 *    de 4 por fonte e por hora soma esta coluna).
 *  · radar_items: cada item lido, só com o título, a ligação, a fonte, a hora e o volume
 *    aproximado (nunca o corpo do artigo), o veredicto do filtro e a tendência. Único por
 *    (source_key, external_key).
 *  · radar_topics: a tendência, que junta itens (Trends, notícias de 2 ou mais jornais, aviso
 *    do IPMA, decisão do BCE), com a janela (window_until) e o veredicto herdado dos itens.
 * Tudo é apagado aos 30 dias (radar-purge-items).
 *
 * As horas são dateTime (em UTC, como a aplicação) e não timestamp: com o
 * explicit_defaults_for_timestamp desligado (o MariaDB do dev), a primeira coluna timestamp
 * obrigatória passava a atualizar-se sozinha a cada gravação (first_seen_at das tendências).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radar_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source_key', 40);
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->string('status', 10); // ok | erro
            $table->boolean('dry_run')->default(false);
            $table->boolean('from_fixture')->default(false);
            $table->unsignedTinyInteger('requests')->default(0);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('etag', 255)->nullable();
            $table->string('last_modified', 64)->nullable();
            $table->unsignedInteger('items_seen')->default(0);
            $table->unsignedInteger('items_new')->default(0);
            $table->unsignedInteger('items_repeated')->default(0);
            $table->unsignedInteger('items_blocked')->default(0);
            $table->unsignedInteger('items_out_of_scope')->default(0);
            $table->json('blocked_reasons')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->index(['source_key', 'started_at']);
            $table->index('started_at');
        });

        Schema::create('radar_topics', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10); // trend | news | weather | bce
            $table->string('title', 255);
            $table->string('normalized_key', 191);
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->unsignedInteger('approx_volume')->nullable();
            $table->string('approx_volume_label', 20)->nullable();
            $table->json('sources')->nullable();
            $table->json('districts')->nullable();
            $table->string('filter_verdict', 10)->default('passed'); // passed | blocked
            $table->json('filter_reasons')->nullable();
            $table->json('filter_flags')->nullable();
            $table->dateTime('window_until');
            $table->dateTime('expires_at');
            $table->timestamps();
            $table->index(['kind', 'normalized_key']);
            $table->index(['filter_verdict', 'window_until']);
            $table->index('expires_at');
        });

        Schema::create('radar_items', function (Blueprint $table) {
            $table->id();
            $table->string('source_key', 40);
            $table->string('external_key', 64);
            $table->string('title', 255);
            $table->string('url', 2048)->nullable();
            $table->string('source_name', 120);
            $table->dateTime('published_at')->nullable();
            $table->dateTime('fetched_at');
            $table->unsignedInteger('approx_volume')->nullable();
            $table->string('approx_volume_label', 20)->nullable();
            $table->json('extra')->nullable();
            $table->string('filter_verdict', 10); // passed | blocked
            $table->json('filter_reasons')->nullable();
            $table->json('filter_flags')->nullable();
            $table->foreignId('topic_id')->nullable()->constrained('radar_topics')->nullOnDelete();
            $table->dateTime('expires_at');
            $table->timestamps();
            $table->unique(['source_key', 'external_key']);
            $table->index('fetched_at');
            $table->index(['filter_verdict', 'fetched_at']);
            $table->index(['source_key', 'published_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radar_items');
        Schema::dropIfExists('radar_topics');
        Schema::dropIfExists('radar_runs');
    }
};
