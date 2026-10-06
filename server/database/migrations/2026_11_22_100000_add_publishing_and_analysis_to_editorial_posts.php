<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F3d: Publicado e Análise (à mão).
 *
 *  · editorial_posts: hora prevista (Lisboa) e pilar; o link e a hora real da publicação,
 *    quem a marcou (com a pessoa real); aviso de atraso enviado (uma vez); notas de
 *    aprendizagem ("O que funcionou" e "O que mudar").
 *  · editorial_post_metrics: um número por métrica e por ORIGEM (manual agora; a F6
 *    acrescenta a da Meta sem a confundir), com a data da medição.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('editorial_posts', function (Blueprint $table) {
            $table->string('publish_time', 5)->nullable()->after('publish_date'); // HH:MM, Lisboa
            $table->string('pillar', 60)->nullable()->after('keyword');
            $table->string('published_url', 500)->nullable()->after('approved_version_id');
            $table->dateTime('published_at')->nullable()->after('published_url');
            $table->foreignId('published_by_user_id')->nullable()->after('published_at')->constrained('users')->nullOnDelete();
            $table->foreignId('published_by_impersonator_id')->nullable()->after('published_by_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('overdue_alerted_at')->nullable()->after('published_by_impersonator_id');
            $table->text('analysis_worked')->nullable()->after('overdue_alerted_at');
            $table->text('analysis_change')->nullable()->after('analysis_worked');

            $table->index(['stage', 'publish_date'], 'ed_posts_stage_date_idx');
        });

        Schema::create('editorial_post_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('editorial_post_id')->constrained('editorial_posts')->cascadeOnDelete();
            $table->string('metric', 20);   // reach | interactions | likes | comments | saves | shares | clicks | video_views
            $table->string('source', 10);   // manual (F3d) | meta (F6)
            $table->unsignedBigInteger('value');
            $table->date('measured_on');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('impersonator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['editorial_post_id', 'metric', 'source'], 'ed_metrics_post_metric_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editorial_post_metrics');
        Schema::table('editorial_posts', function (Blueprint $table) {
            $table->dropIndex('ed_posts_stage_date_idx');
            $table->dropConstrainedForeignId('published_by_impersonator_id');
            $table->dropConstrainedForeignId('published_by_user_id');
            $table->dropColumn(['publish_time', 'pillar', 'published_url', 'published_at', 'overdue_alerted_at', 'analysis_worked', 'analysis_change']);
        });
    }
};
