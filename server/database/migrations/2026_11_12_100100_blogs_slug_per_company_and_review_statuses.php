<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blog (Ticket 11), parte NÃO ADITIVA (autorizada; ver a lista de deploy):
 *  1. O slug passa a ser único POR EMPRESA: sai o índice único global (blogs_slug_unique)
 *     e entra o composto (company_id, slug). O composto é menos restritivo do que o global,
 *     por isso os dados atuais cumprem-no sempre; não há conflitos possíveis ao subir.
 *  2. Os estados ganham "em revisão" e "aprovado" (o enum só alarga; nenhum valor muda).
 *
 * Risco do down(): repor o índice global falha se, entretanto, duas empresas tiverem
 * artigos com o mesmo slug; e os artigos em "em revisão"/"aprovado" voltam a rascunho.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blogs', function (Blueprint $table) {
            $table->dropUnique('blogs_slug_unique');
            $table->unique(['company_id', 'slug']);
        });

        Schema::table('blogs', function (Blueprint $table) {
            $table->enum('status', ['draft', 'in_review', 'approved', 'published'])->default('draft')->change();
        });
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('blogs')->whereIn('status', ['in_review', 'approved'])->update(['status' => 'draft']);

        Schema::table('blogs', function (Blueprint $table) {
            $table->enum('status', ['draft', 'published'])->default('draft')->change();
        });

        Schema::table('blogs', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'slug']);
            $table->unique('slug');
        });
    }
};
