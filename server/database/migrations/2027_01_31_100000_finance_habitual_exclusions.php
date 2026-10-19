<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finanças: as entidades tiradas dos "Documentos habituais em falta" (só na XPLENDOR):
 *  · "Não vem este mês" (scope mes): só esse mês;
 *  · "Não é habitual" (scope sempre): desse mês em diante.
 * Repor não apaga: fica com quem e quando repôs (o histórico fica completo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_habitual_exclusions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('entity_pingwin_id', 32);
            $t->string('entity_name', 160)->nullable();
            $t->string('scope', 10);                    // mes | sempre
            $t->char('month', 7);                       // AAAA-MM: o mês (mes) ou a partir de quando (sempre)
            $t->bigInteger('avg_cents')->nullable();    // o valor médio que faltava, para o histórico
            $t->string('reason', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('removed_at')->nullable();
            $t->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['company_id', 'entity_pingwin_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_habitual_exclusions');
    }
};
