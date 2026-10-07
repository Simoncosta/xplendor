<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registo mensal (no dia 1) da contagem de empresas que contam para cada agência (paga quem
 * dá o acesso: as geridas sem subscrição própria ativa, a partir do mês seguinte ao início).
 * Base da futura cobrança às agências (Cobrança 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_billing_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_company_id')->constrained('companies')->cascadeOnDelete();
            $table->char('month', 7); // AAAA-MM
            $table->unsignedInteger('companies_count');
            $table->json('company_ids');
            $table->unsignedSmallInteger('monthly_fee');
            $table->timestamp('computed_at');
            $table->unique(['agency_company_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_billing_counts');
    }
};
