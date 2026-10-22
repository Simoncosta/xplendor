<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F2.1 · Publicação automática (desenho aprovado a 22/10/2026):
 *  · social_publications: uma por linha de editorial_post_networks (a publicação dessa rede na
 *    Meta), com o estado (agendado, a publicar, publicado, falhou, cancelado), a hora marcada, as
 *    tentativas, o contentor, o media, o permalink e o erro;
 *  · companies.auto_publishing_enabled: o interruptor "Publicação automática", desligado por omissão;
 *  · editorial_posts.auto_publish: a caixa por publicação, desmarcada por omissão.
 *
 * ADITIVA: uma tabela nova e duas colunas com valor por omissão.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('social_publications')) {
            Schema::create('social_publications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('editorial_post_id')->constrained()->cascadeOnDelete();
                $table->foreignId('editorial_post_network_id')->unique()->constrained('editorial_post_networks')->cascadeOnDelete();
                $table->string('network', 20);
                $table->string('status', 20)->default('scheduled');
                $table->timestamp('scheduled_for')->nullable();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamp('next_attempt_at')->nullable();
                $table->string('container_id', 100)->nullable();
                $table->string('media_id', 100)->nullable();
                $table->string('permalink', 500)->nullable();
                $table->string('error_code', 50)->nullable();
                $table->string('error_message', 500)->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'scheduled_for']);
                $table->index(['status', 'next_attempt_at']);
            });
        }

        if (! Schema::hasColumn('companies', 'auto_publishing_enabled')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('auto_publishing_enabled')->default(false);
            });
        }

        if (! Schema::hasColumn('editorial_posts', 'auto_publish')) {
            Schema::table('editorial_posts', function (Blueprint $table) {
                $table->boolean('auto_publish')->default(false)->after('publish_time');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('social_publications');
        if (Schema::hasColumn('companies', 'auto_publishing_enabled')) {
            Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('auto_publishing_enabled'));
        }
        if (Schema::hasColumn('editorial_posts', 'auto_publish')) {
            Schema::table('editorial_posts', fn (Blueprint $table) => $table->dropColumn('auto_publish'));
        }
    }
};
