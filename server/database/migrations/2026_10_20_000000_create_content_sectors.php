<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Linha Editorial (Fatia 1): ÁRVORE DE SETORES. Auto-referencial
 * (parent_id), hierarquia Universal → Setor/Agrupador → (Sub-setor) folha. Só as
 * FOLHAS são selecionáveis por uma empresa. O país é ATRIBUTO das âncoras (não um
 * nível da árvore). Transversal (nada a ver com o PingWin). Aditiva/reversível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_sectors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();               // chave estável p/ seed/código
            $table->foreignId('parent_id')->nullable()->constrained('content_sectors')->nullOnDelete();
            $table->string('type');                          // universal | agrupador | folha
            $table->boolean('is_selectable')->default(false); // só folhas = true
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_sectors');
    }
};
