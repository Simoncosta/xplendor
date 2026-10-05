<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 11 (aditiva):
 *  · company_brand_profiles: perfil de marca simples por empresa (1:1). Os nomes das colunas
 *    são os previstos para brand_profiles no plano Social (tone_of_voice, audience,
 *    words_to_use, words_to_avoid, language), mais topics_to_avoid. Quando as marcas
 *    existirem, a linha passa para a marca por omissão da empresa.
 *  · blog_ai_drafts: cada pedido "Ajudar a escrever" / "a partir de uma publicação". Serve de
 *    fila (estado), de registo (versão do prompt, modelo, tokens) e de contador do limite
 *    mensal por empresa.
 *  · editorial_posts.blog_id: canal "Site" da Linha Editorial ligado ao artigo do blog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_brand_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('tone_of_voice')->nullable();
            $table->text('audience')->nullable();
            $table->json('words_to_use')->nullable();
            $table->json('words_to_avoid')->nullable();
            $table->json('topics_to_avoid')->nullable();
            $table->string('language', 10)->default('pt-PT');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('blog_ai_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('blog_id')->nullable()->constrained('blogs')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('mode', 20);                       // topic | from_post
            $table->string('status', 20)->default('queued');  // queued | processing | done | error
            $table->json('input');
            $table->json('context')->nullable();              // perfil e público usados no prompt
            $table->json('result')->nullable();
            $table->string('model', 40);
            $table->string('prompt_version', 20);
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
        });

        Schema::table('editorial_posts', function (Blueprint $table) {
            $table->foreignId('blog_id')->nullable()->after('own_anchor_id')->constrained('blogs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('editorial_posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('blog_id');
        });
        Schema::dropIfExists('blog_ai_drafts');
        Schema::dropIfExists('company_brand_profiles');
    }
};
