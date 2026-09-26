<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Linha Editorial (Publicações P1): posts concretos pendurados em datas.
 * Modelo C: a publicação vive numa data QUALQUER, independente das âncoras; PODE ligar-se
 * opcionalmente a UMA âncora — herdada (anchor_id → content_anchors) OU própria
 * (own_anchor_id → editorial_own_anchors), no máximo uma (validado no service). nullOnDelete:
 * se a âncora ligada for apagada, o post NÃO morre — só desliga (vive na sua data).
 * Scoped por empresa. content_anchors NÃO muda (tabela separada).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editorial_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('publish_date');
            $table->string('title');
            $table->string('format');   // enum-em-código (18 formatos)
            $table->string('status');   // rascunho | revisao | publicada | otimizada
            $table->string('channel');  // instagram | facebook
            $table->string('keyword')->nullable();
            $table->foreignId('anchor_id')->nullable()->constrained('content_anchors')->nullOnDelete();
            $table->foreignId('own_anchor_id')->nullable()->constrained('editorial_own_anchors')->nullOnDelete();
            $table->timestamps();

            $table->index('company_id');
            $table->index('publish_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editorial_posts');
    }
};
