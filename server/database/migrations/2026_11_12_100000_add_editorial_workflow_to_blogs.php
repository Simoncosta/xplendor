<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Blog (Ticket 11), parte ADITIVA: palavra-chave, confirmação manual do checklist de SEO,
 * fluxo de aprovação (quem enviou, quem aprovou, nota de alterações) e a data da primeira
 * publicação (a partir dela o slug fica fixo). Backfill: artigos já publicados ficam com
 * first_published_at = published_at (ou created_at), para o slug ficar fixo também neles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blogs', function (Blueprint $table) {
            $table->string('focus_keyword', 100)->nullable()->after('meta_description');
            $table->boolean('seo_answer_first_ok')->default(false)->after('focus_keyword');
            $table->timestamp('first_published_at')->nullable()->after('published_at');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();

            $table->index(['status', 'published_at']);
        });

        DB::table('blogs')->where('status', 'published')->whereNull('first_published_at')
            ->update(['first_published_at' => DB::raw('COALESCE(published_at, created_at)')]);
    }

    public function down(): void
    {
        Schema::table('blogs', function (Blueprint $table) {
            $table->dropIndex(['status', 'published_at']);
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['focus_keyword', 'seo_answer_first_ok', 'first_published_at', 'submitted_at', 'approved_at', 'review_note']);
        });
    }
};
