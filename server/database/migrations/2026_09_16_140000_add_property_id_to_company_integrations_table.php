<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Integração GA4 (ler tráfego do site do cliente). A autenticação é
 * por Service Account do SERVIDOR (uma chave para toda a XPLENDOR); o que muda
 * por empresa é a PROPRIEDADE GA4. Guarda-se o `property_id` por empresa na
 * tabela genérica `company_integrations` (platform='google').
 *
 * Na Opção A (Service Account) NÃO se guarda token por cliente — o `access_token`
 * fica vazio; só o `property_id` importa.
 *
 * Migration ADITIVA e reversível — categoria mais segura (cautela 2026-06-09).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_integrations', function (Blueprint $table) {
            // ID numérico da propriedade GA4 (ex.: "398765432"). String para não
            // perder zeros/limites de int e por ser um identificador, não número.
            $table->string('property_id', 32)->nullable()->after('page_id');
        });
    }

    public function down(): void
    {
        Schema::table('company_integrations', function (Blueprint $table) {
            $table->dropColumn('property_id');
        });
    }
};
