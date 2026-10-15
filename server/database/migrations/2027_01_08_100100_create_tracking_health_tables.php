<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Fatia 2, Parte B (Auditoria · saúde do tracking dos sites, GA4 × XPLENDOR):
 *  · tracking_event_maps: por site (empresa), o nome do evento no GA4 → o tipo na XPLENDOR, com
 *    filtros opcionais (ex.: whatsapp_click sem o page_context floating_button). Sem linhas, os
 *    nomes são iguais.
 *  · tracking_daily_counts: as contagens diárias (GA4 e XPLENDOR) por tipo, dos últimos 28 dias.
 *  · tracking_rejections_daily: os pedidos recusados pelo /api/public/track, agregados por dia,
 *    empresa, tipo, estado e motivo (sem dados pessoais).
 * Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tracking_event_maps')) {
            Schema::create('tracking_event_maps', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('ga4_event', 64);
                $table->string('xplendor_type', 40);
                $table->json('filters')->nullable();   // {"exclude_page_context": [...], "page_context": [...]}
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['company_id', 'ga4_event', 'xplendor_type']);
            });
        }

        if (! Schema::hasTable('tracking_daily_counts')) {
            Schema::create('tracking_daily_counts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->date('day');
                $table->string('source', 12);           // ga4 | xplendor
                $table->string('event_type', 40);        // o tipo na XPLENDOR
                $table->unsignedInteger('count')->default(0);
                $table->timestamps();

                $table->unique(['company_id', 'day', 'source', 'event_type']);
            });
        }

        if (! Schema::hasTable('tracking_rejections_daily')) {
            Schema::create('tracking_rejections_daily', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->date('day');
                $table->string('event_type', 40);
                $table->unsignedSmallInteger('status');
                $table->string('reason', 120);
                $table->unsignedInteger('count')->default(0);
                $table->timestamps();

                $table->unique(['company_id', 'day', 'event_type', 'status', 'reason'], 'tracking_rejections_daily_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_rejections_daily');
        Schema::dropIfExists('tracking_daily_counts');
        Schema::dropIfExists('tracking_event_maps');
    }
};
