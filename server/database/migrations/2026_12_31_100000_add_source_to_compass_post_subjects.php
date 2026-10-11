<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bússola do stand (aditiva): de onde vem a ligação entre a publicação e o veículo.
 *  · bussola   a publicação foi criada a partir de uma jogada da Bússola;
 *  · ficheiros a publicação usa fotografias da pasta do veículo nos Ficheiros (refaz-se
 *              sempre que os ficheiros da publicação mudam).
 * As ligações que já existem são da Bússola (o valor por omissão).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compass_post_subjects', function (Blueprint $table) {
            $table->string('source', 20)->default('bussola');
            $table->index(['editorial_post_id', 'source'], 'compass_post_subject_post_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('compass_post_subjects', function (Blueprint $table) {
            $table->dropIndex('compass_post_subject_post_source_idx');
            $table->dropColumn('source');
        });
    }
};
