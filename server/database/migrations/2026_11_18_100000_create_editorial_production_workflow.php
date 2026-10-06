<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Linha Editorial, F3a (aditiva): produção e aprovação dentro da XPLENDOR.
 *
 *  · editorial_posts: etapa (Ideia → Planeamento → Produção → Revisão → Aprovação →
 *    Programado → Publicado → Análise), versão atual e versão aprovada, responsável.
 *    O estado antigo (status) mantém-se, sincronizado a partir da etapa, e é convertido
 *    aqui: rascunho → planning, revisao → internal_review, publicada → published,
 *    otimizada → analysis.
 *  · editorial_post_versions: o conteúdo (legenda, hashtags, chamada à ação, primeiro
 *    comentário, formato) por versão; congelada no envio ao cliente.
 *  · editorial_post_comments: comentários internos (só a equipa) ou partilhados.
 *  · editorial_post_reviews: decisões do cliente; uma só aprovação por versão.
 *  · editorial_post_events: histórico de tudo, com a pessoa real (impersonator_user_id).
 *  · users.can_approve_content e as definições do fluxo por empresa.
 */
return new class extends Migration
{
    private const STATUS_TO_STAGE = [
        'rascunho'  => 'planning',
        'revisao'   => 'internal_review',
        'publicada' => 'published',
        'otimizada' => 'analysis',
    ];

    public function up(): void
    {
        Schema::create('editorial_post_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('editorial_post_id')->constrained('editorial_posts')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->text('caption')->nullable();
            $table->json('hashtags')->nullable();
            $table->string('cta', 300)->nullable();
            $table->text('first_comment')->nullable();
            $table->string('media_format', 30)->nullable();
            // draft | sent | approved | changes_requested | superseded
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('impersonator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_impersonator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('frozen_at')->nullable();
            $table->timestamps();

            $table->unique(['editorial_post_id', 'number'], 'ed_versions_post_number_unique');
        });

        Schema::table('editorial_posts', function (Blueprint $table) {
            $table->string('stage', 20)->default('planning')->after('status');
            $table->timestamp('stage_changed_at')->nullable()->after('stage');
            $table->timestamp('changes_requested_at')->nullable()->after('stage_changed_at');
            $table->foreignId('current_version_id')->nullable()->after('changes_requested_at')
                ->constrained('editorial_post_versions')->nullOnDelete();
            $table->foreignId('approved_version_id')->nullable()->after('current_version_id')
                ->constrained('editorial_post_versions')->nullOnDelete();
            $table->foreignId('assignee_user_id')->nullable()->after('approved_version_id')->constrained('users')->nullOnDelete();

            $table->index(['company_id', 'stage'], 'ed_posts_company_stage_idx');
        });

        $this->backfillStages();

        Schema::create('editorial_post_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('editorial_post_id')->constrained('editorial_posts')->cascadeOnDelete();
            $table->foreignId('version_id')->nullable()->constrained('editorial_post_versions')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('impersonator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name')->nullable();
            $table->text('body');
            $table->string('visibility', 10)->default('shared'); // internal | shared
            $table->timestamps();

            $table->index(['editorial_post_id', 'created_at'], 'ed_comments_post_created_idx');
        });

        Schema::create('editorial_post_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('editorial_post_id')->constrained('editorial_posts')->cascadeOnDelete();
            $table->foreignId('version_id')->constrained('editorial_post_versions')->cascadeOnDelete();
            $table->string('decision', 20);         // approved | changes_requested
            $table->string('via', 10)->default('app'); // app | link (F3c)
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reviewer_name')->nullable();
            $table->text('message')->nullable();
            // Só preenchido na aprovação: garante uma única aprovação por versão.
            $table->unsignedBigInteger('approved_version_id')->nullable()->unique();
            $table->timestamp('created_at')->nullable();

            $table->index(['editorial_post_id', 'created_at'], 'ed_reviews_post_created_idx');
        });

        Schema::create('editorial_post_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('editorial_post_id')->constrained('editorial_posts')->cascadeOnDelete();
            $table->string('type', 20);              // created | stage | version | review | comment | content
            $table->string('from_stage', 20)->nullable();
            $table->string('to_stage', 20)->nullable();
            $table->foreignId('version_id')->nullable()->constrained('editorial_post_versions')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('impersonator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('message', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['editorial_post_id', 'created_at'], 'ed_events_post_created_idx');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_approve_content')->default(false)->after('role');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('content_approval_required')->default(true);
            $table->boolean('internal_review_required')->default(false);
        });
    }

    /** Estado antigo → etapa (público para o teste da conversão). */
    public function backfillStages(): void
    {
        foreach (self::STATUS_TO_STAGE as $status => $stage) {
            DB::table('editorial_posts')->where('status', $status)->update(['stage' => $stage]);
        }
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['content_approval_required', 'internal_review_required']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('can_approve_content'));
        Schema::dropIfExists('editorial_post_events');
        Schema::dropIfExists('editorial_post_reviews');
        Schema::dropIfExists('editorial_post_comments');
        Schema::table('editorial_posts', function (Blueprint $table) {
            $table->dropIndex('ed_posts_company_stage_idx');
            $table->dropConstrainedForeignId('assignee_user_id');
            $table->dropConstrainedForeignId('approved_version_id');
            $table->dropConstrainedForeignId('current_version_id');
            $table->dropColumn(['stage', 'stage_changed_at', 'changes_requested_at']);
        });
        Schema::dropIfExists('editorial_post_versions');
    }
};
