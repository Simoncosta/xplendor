<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil da Marca: acrescenta já os campos previstos para brand_profiles no plano
 * Social (F1), com os mesmos nomes, para a migração empresa → marca ser direta.
 *  · pillars: [{name, description}] (a ordem é a prioridade);
 *  · hashtags_default: lista; cta_default: texto; emoji_policy: none | light | free;
 *  · notes: notas livres.
 * Aditiva: nenhum dado existente é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_brand_profiles', function (Blueprint $table) {
            $table->json('pillars')->nullable()->after('topics_to_avoid');
            $table->json('hashtags_default')->nullable()->after('pillars');
            $table->string('cta_default', 300)->nullable()->after('hashtags_default');
            $table->string('emoji_policy', 10)->nullable()->after('cta_default');
            $table->text('notes')->nullable()->after('emoji_policy');
        });
    }

    public function down(): void
    {
        Schema::table('company_brand_profiles', function (Blueprint $table) {
            $table->dropColumn(['pillars', 'hashtags_default', 'cta_default', 'emoji_policy', 'notes']);
        });
    }
};
