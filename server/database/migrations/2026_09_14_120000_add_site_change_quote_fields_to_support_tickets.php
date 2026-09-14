<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DMS — Camada de ORÇAMENTO para o novo tipo de ticket "site_change" (pago).
 *
 * Os tipos existentes (idea/improvement/bug/suggestion) continuam grátis e
 * IGNORAM estas colunas (ficam null). Só os tickets site_change usam o fluxo
 * de orçamento: quote_status conduz o fluxo próprio (a aguardar orçamento →
 * orçado → aprovado → pago → concluído / rejeitado), enquanto o `status`
 * genérico continua a refletir o ciclo de vida para os filtros/contagens do
 * painel admin (mantido em sincronia pelo SupportTicketService).
 *
 * Migration ADITIVA e reversível — categoria mais segura (cautela 2026-06-09).
 * Nada de migrate:fresh/refresh; só colunas nullable no fim da tabela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->decimal('estimated_hours', 6, 2)->nullable()->after('screenshot_path');
            $table->decimal('quoted_amount', 10, 2)->nullable()->after('estimated_hours');
            $table->string('invoice_path')->nullable()->after('quoted_amount');
            // awaiting_quote | quoted | approved | paid | completed | rejected (null nos tipos grátis)
            $table->string('quote_status', 30)->nullable()->after('invoice_path');

            $table->index(['type', 'quote_status']);
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropIndex(['type', 'quote_status']);
            $table->dropColumn(['estimated_hours', 'quoted_amount', 'invoice_path', 'quote_status']);
        });
    }
};
