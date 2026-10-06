<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F3c: link de aprovação por lote (sem conta), com o padrão do link do orçamento.
 *
 *  · content_review_links: um lote de publicações em Aprovação; token de 64 caracteres em
 *    hash (pesquisa) e cifrado (para a equipa o copiar de novo); validade prolongável;
 *    revogável; contadores de aberturas e lembretes enviados.
 *  · content_review_link_items: as publicações do lote e a versão enviada de cada uma.
 *  · content_review_link_opens: aberturas reais (sem IP; identificador do browser em hash).
 *  · content_review_notifications: avisos à equipa, para o resumo por email a cada 15 minutos.
 *  · Decisões e comentários feitos no link ficam ligados ao link (review_link_id).
 *  · Foto de perfil das contas ligadas (para a pré-visualização como na rede).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_review_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->string('recipient_name', 120)->nullable();
            $table->string('recipient_email', 190)->nullable();
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('impersonator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_sent_at')->nullable();
            $table->dateTime('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->timestamp('first_opened_at')->nullable();
            $table->timestamp('last_opened_at')->nullable();
            $table->timestamp('last_open_alert_at')->nullable();
            $table->timestamp('client_reminder_sent_at')->nullable();
            $table->timestamp('team_reminder_sent_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at'], 'crl_company_created_idx');
        });

        Schema::create('content_review_link_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_review_link_id')->constrained('content_review_links')->cascadeOnDelete();
            $table->foreignId('editorial_post_id')->constrained('editorial_posts')->cascadeOnDelete();
            $table->foreignId('version_id')->constrained('editorial_post_versions')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('urgent_alert_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['content_review_link_id', 'editorial_post_id'], 'crl_items_link_post_unique');
            $table->index('version_id', 'crl_items_version_idx');
        });

        Schema::create('content_review_link_opens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_review_link_id')->constrained('content_review_links')->cascadeOnDelete();
            $table->string('visitor_hash', 64);
            $table->string('device', 10);
            $table->dateTime('opened_at');
            $table->dateTime('last_seen_at');

            $table->index(['content_review_link_id', 'visitor_hash', 'last_seen_at'], 'crl_opens_visit_idx');
            $table->index('opened_at', 'crl_opens_opened_idx');
        });

        Schema::create('content_review_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_review_link_id')->nullable()->constrained('content_review_links')->cascadeOnDelete();
            $table->string('severity', 10)->default('medium');
            $table->string('title', 190);
            $table->string('message', 1000);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('emailed_at')->nullable();

            $table->index(['emailed_at', 'created_at'], 'crn_pending_idx');
        });

        Schema::table('editorial_post_reviews', function (Blueprint $table) {
            $table->foreignId('review_link_id')->nullable()->after('via')->constrained('content_review_links')->nullOnDelete();
            $table->string('device', 10)->nullable()->after('message');
        });

        Schema::table('editorial_post_comments', function (Blueprint $table) {
            $table->foreignId('review_link_id')->nullable()->after('impersonator_user_id')->constrained('content_review_links')->nullOnDelete();
        });

        Schema::table('social_connection_accounts', function (Blueprint $table) {
            $table->string('profile_picture_path')->nullable()->after('username');
            $table->timestamp('profile_picture_updated_at')->nullable()->after('profile_picture_path');
        });
    }

    public function down(): void
    {
        Schema::table('social_connection_accounts', function (Blueprint $table) {
            $table->dropColumn(['profile_picture_path', 'profile_picture_updated_at']);
        });
        Schema::table('editorial_post_comments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('review_link_id');
        });
        Schema::table('editorial_post_reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('review_link_id');
            $table->dropColumn('device');
        });
        Schema::dropIfExists('content_review_notifications');
        Schema::dropIfExists('content_review_link_opens');
        Schema::dropIfExists('content_review_link_items');
        Schema::dropIfExists('content_review_links');
    }
};
