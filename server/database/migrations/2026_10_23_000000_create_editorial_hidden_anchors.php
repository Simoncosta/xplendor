<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Linha Editorial (B3a): âncoras HERDADAS escondidas por OCORRÊNCIA.
 * O cliente esconde uma âncora herdada só num ano concreto (Ano Novo 2027 ≠ 2028).
 * Granularidade = (company_id, anchor_id, occurrence_year): amarra à ocorrência, não à
 * âncora-mãe (que não se toca) — cada âncora resolve 1x/ano. Por empresa, reversível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editorial_hidden_anchors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('anchor_id')->constrained('content_anchors')->cascadeOnDelete();
            $table->smallInteger('occurrence_year');
            $table->timestamps();

            // Nome explícito e curto (o auto-gerado excede o limite de 64 chars do MySQL).
            $table->unique(['company_id', 'anchor_id', 'occurrence_year'], 'eha_company_anchor_year_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editorial_hidden_anchors');
    }
};
