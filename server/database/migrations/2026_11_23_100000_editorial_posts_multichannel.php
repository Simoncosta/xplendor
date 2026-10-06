<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Publicação multicanal: uma publicação pode ir para o Instagram e/ou o Facebook, com um
 * só conteúdo e uma só aprovação. O que é de cada rede passa para editorial_post_networks
 * (formato, link, hora real, quem marcou, ou "não publicar nesta rede" com o motivo); os
 * números da análise ganham a rede. Na F2, cada linha daqui dá UMA publicação na rede.
 *
 *  · editorial_posts.channel: instagram | facebook | site  →  social | site
 *  · editorial_post_networks (nova): uma linha por rede escolhida (fonte de verdade das redes
 *    e dos formatos); a versão guarda uma cópia congelada no envio (media_formats)
 *  · editorial_post_versions: media_format → media_formats (json); network_captions (json,
 *    legenda própria opcional por rede; por omissão, a mesma)
 *  · editorial_post_metrics: + network; único por (publicação, rede, métrica, origem)
 *  · editorial_posts: saem media_format, published_url, published_at e published_by_*
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editorial_post_networks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('editorial_post_id')->constrained('editorial_posts')->cascadeOnDelete();
            $table->string('network', 20);                  // instagram | facebook
            $table->string('media_format', 30)->nullable(); // formato nesta rede
            $table->unsignedTinyInteger('position')->default(0);
            $table->string('published_url', 500)->nullable();
            $table->dateTime('published_at')->nullable();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by_impersonator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('skipped_at')->nullable();     // "Não publicar nesta rede"
            $table->string('skip_reason', 500)->nullable();
            $table->foreignId('skipped_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('skipped_by_impersonator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['editorial_post_id', 'network'], 'ed_post_networks_unique');
            $table->index(['company_id', 'network'], 'ed_post_networks_company_network_idx');
        });

        Schema::table('editorial_post_versions', function (Blueprint $table) {
            $table->json('media_formats')->nullable()->after('media_format');
            $table->json('network_captions')->nullable()->after('caption');
        });
        Schema::table('editorial_post_metrics', function (Blueprint $table) {
            $table->string('network', 20)->nullable()->after('editorial_post_id');
        });

        // Dados: lidos ANTES de tirar as colunas e escritos DEPOIS (no SQLite, tirar uma coluna
        // com chave estrangeira reconstrói a tabela e apagaria em cascata o que já estivesse
        // ligado; no MariaDB a ordem é indiferente).
        $posts = DB::table('editorial_posts')->whereIn('channel', ['instagram', 'facebook'])->orderBy('id')
            ->get(['id', 'company_id', 'channel', 'media_format', 'published_url', 'published_at', 'published_by_user_id', 'published_by_impersonator_id']);
        $versions = DB::table('editorial_post_versions')->whereIn('editorial_post_id', $posts->pluck('id'))->whereNotNull('media_format')
            ->get(['id', 'editorial_post_id', 'media_format']);

        Schema::table('editorial_post_metrics', function (Blueprint $table) {
            $table->unique(['editorial_post_id', 'network', 'metric', 'source'], 'ed_metrics_post_network_metric_source_unique');
        });
        Schema::table('editorial_post_metrics', function (Blueprint $table) {
            $table->dropUnique('ed_metrics_post_metric_source_unique');
        });
        Schema::table('editorial_post_versions', function (Blueprint $table) {
            $table->dropColumn('media_format');
        });
        Schema::table('editorial_posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('published_by_impersonator_id');
            $table->dropConstrainedForeignId('published_by_user_id');
            $table->dropColumn(['media_format', 'published_url', 'published_at']);
        });

        $now = now();
        foreach ($posts->chunk(500) as $chunk) {
            DB::table('editorial_post_networks')->insert($chunk->map(fn ($p) => [
                'company_id' => $p->company_id, 'editorial_post_id' => $p->id, 'network' => $p->channel,
                'media_format' => $p->media_format, 'position' => 0,
                'published_url' => $p->published_url, 'published_at' => $p->published_at,
                'published_by_user_id' => $p->published_by_user_id, 'published_by_impersonator_id' => $p->published_by_impersonator_id,
                'created_at' => $now, 'updated_at' => $now,
            ])->values()->all());
        }
        $channelOf = $posts->pluck('channel', 'id');
        foreach ($versions as $v) {
            DB::table('editorial_post_versions')->where('id', $v->id)->update(['media_formats' => json_encode([$channelOf[$v->editorial_post_id] => $v->media_format])]);
        }
        foreach ($posts->groupBy('channel') as $channel => $list) {
            foreach ($list->pluck('id')->chunk(500) as $ids) {
                DB::table('editorial_post_metrics')->whereIn('editorial_post_id', $ids->all())->update(['network' => $channel]);
                DB::table('editorial_posts')->whereIn('id', $ids->all())->update(['channel' => 'social']);
            }
        }
        // editorial_post_creatives.media_format fica: o criativo sugerido é um por publicação,
        // pensado para a primeira rede.
    }

    public function down(): void
    {
        // Volta ao canal único: fica a primeira rede de cada publicação (as outras perdem-se).
        Schema::table('editorial_posts', function (Blueprint $table) {
            $table->string('media_format', 30)->nullable()->after('format');
            $table->string('published_url', 500)->nullable()->after('approved_version_id');
            $table->dateTime('published_at')->nullable()->after('published_url');
            $table->foreignId('published_by_user_id')->nullable()->after('published_at')->constrained('users')->nullOnDelete();
            $table->foreignId('published_by_impersonator_id')->nullable()->after('published_by_user_id')->constrained('users')->nullOnDelete();
        });
        Schema::table('editorial_post_versions', function (Blueprint $table) {
            $table->string('media_format', 30)->nullable()->after('first_comment');
        });

        $first = DB::table('editorial_post_networks')->orderBy('position')->orderBy('id')->get()->unique('editorial_post_id');
        foreach ($first as $n) {
            DB::table('editorial_posts')->where('id', $n->editorial_post_id)->update([
                'channel' => $n->network, 'media_format' => $n->media_format, 'published_url' => $n->published_url, 'published_at' => $n->published_at,
                'published_by_user_id' => $n->published_by_user_id, 'published_by_impersonator_id' => $n->published_by_impersonator_id,
            ]);
            foreach (DB::table('editorial_post_versions')->where('editorial_post_id', $n->editorial_post_id)->whereNotNull('media_formats')->get(['id', 'media_formats']) as $v) {
                DB::table('editorial_post_versions')->where('id', $v->id)->update(['media_format' => json_decode($v->media_formats, true)[$n->network] ?? null]);
            }
            DB::table('editorial_post_metrics')->where('editorial_post_id', $n->editorial_post_id)->where('network', '!=', $n->network)->delete();
        }
        DB::table('editorial_posts')->where('channel', 'social')->update(['channel' => 'instagram']); // sem redes: Instagram

        Schema::table('editorial_post_metrics', function (Blueprint $table) {
            $table->unique(['editorial_post_id', 'metric', 'source'], 'ed_metrics_post_metric_source_unique');
        });
        Schema::table('editorial_post_metrics', function (Blueprint $table) {
            $table->dropUnique('ed_metrics_post_network_metric_source_unique');
            $table->dropColumn('network');
        });
        Schema::table('editorial_post_versions', function (Blueprint $table) {
            $table->dropColumn(['media_formats', 'network_captions']);
        });
        Schema::dropIfExists('editorial_post_networks');
    }
};
