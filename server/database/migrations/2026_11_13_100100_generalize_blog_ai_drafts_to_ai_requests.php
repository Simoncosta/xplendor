<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * IA: blog_ai_drafts passa a ai_requests, a fila e o registo de TODOS os pedidos à IA
 * (versão do prompt, modelo, tokens) e o contador do limite mensal, agora POR MODO:
 *  · mode:    blog | brand_profile | creative;
 *  · variant: subtipo dentro do modo (no blog: topic | from_post, o antigo "mode").
 * O blog com IA ainda não estava em produção; a tabela antiga é renomeada (os dados
 * que existam ficam como mode = blog).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('blog_ai_drafts', 'ai_requests');

        Schema::table('ai_requests', function (Blueprint $table) {
            $table->renameColumn('mode', 'variant');
        });

        Schema::table('ai_requests', function (Blueprint $table) {
            // Só o blog tem subtipo; os outros modos ficam sem ele.
            $table->string('variant', 20)->nullable()->change();
            $table->string('mode', 20)->default('blog')->after('user_id');
            $table->index(['company_id', 'mode', 'created_at'], 'ai_requests_company_mode_created_idx');
        });
    }

    public function down(): void
    {
        // A tabela antiga só conhecia o blog: os pedidos dos outros modos não cabem nela.
        DB::table('ai_requests')->where('mode', '!=', 'blog')->delete();

        Schema::table('ai_requests', function (Blueprint $table) {
            $table->dropIndex('ai_requests_company_mode_created_idx');
            $table->dropColumn('mode');
        });

        Schema::table('ai_requests', function (Blueprint $table) {
            $table->renameColumn('variant', 'mode');
        });

        Schema::table('ai_requests', function (Blueprint $table) {
            $table->string('mode', 20)->nullable(false)->change();
        });

        Schema::rename('ai_requests', 'blog_ai_drafts');
    }
};
