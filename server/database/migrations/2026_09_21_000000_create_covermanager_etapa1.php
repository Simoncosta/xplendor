<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — CoverManager (reservas) Etapa 1. Uma loja tem DOIS conjuntos de
 * credenciais: PingWin (winrest_store_id) e CoverManager (slug + token). O token
 * é cifrado (cast EncryptedLegacy). Guardamos SÓ o AGREGADO por turno (RGPD:
 * nunca o PII — nome/email/telefone). Aditiva e reversível.
 *
 * ⚠️ IDEMPOTENTE (correção 2026-09-20): esta migração falhou a meio em produção
 *  — o índice único auto-gerado ('..._location_id_business_date_shift_unique',
 *  67 chars) excede o limite de 64 do MySQL, e uma tentativa anterior já tinha
 *  criado as colunas cm_*. Agora:
 *   · cada coluna só é adicionada se AINDA NÃO existir (Schema::hasColumn);
 *   · a tabela só é criada se AINDA NÃO existir (Schema::hasTable);
 *   · o índice único tem um NOME CURTO explícito ('cm_shift_summary_unique').
 *  Assim pode re-correr num estado parcial e completar só o que falta, SEM
 *  perder dados.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Colunas CoverManager na loja — só as que ainda faltam (não rebenta com
        // "Duplicate column" se uma tentativa anterior já as criou).
        Schema::table('pingwin_locations', function (Blueprint $table) {
            if (! Schema::hasColumn('pingwin_locations', 'cm_slug')) {
                $table->string('cm_slug')->nullable()->after('is_active');   // não-secreto
            }
            if (! Schema::hasColumn('pingwin_locations', 'cm_token')) {
                $table->text('cm_token')->nullable();                        // apikey — CIFRADO
            }
            if (! Schema::hasColumn('pingwin_locations', 'cm_base_url')) {
                $table->string('cm_base_url')->nullable();                   // override opcional
            }
        });

        // Tabela de agregados por turno — só se ainda não existir.
        if (! Schema::hasTable('cm_reservation_shift_summary')) {
            Schema::create('cm_reservation_shift_summary', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('location_id')->constrained('pingwin_locations')->cascadeOnDelete();
                $table->date('business_date');
                $table->string('shift'); // lunch | dinner | other
                $table->unsignedInteger('guests_total')->default(0);       // Σ "for" (nº pessoas)
                $table->unsignedInteger('reservations_count')->default(0); // reservas não canceladas
                $table->unsignedInteger('walk_ins_count')->default(0);     // provenance walk-in
                $table->unsignedInteger('cancelled_count')->default(0);    // status a começar por "-"
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();

                // ⚠️ NOME CURTO explícito (<64 chars) — o auto-gerado (67) rebenta no MySQL.
                $table->unique(['location_id', 'business_date', 'shift'], 'cm_shift_summary_unique');
                $table->index(['company_id', 'business_date'], 'cm_shift_summary_company_date_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cm_reservation_shift_summary');
        Schema::table('pingwin_locations', function (Blueprint $table) {
            foreach (['cm_slug', 'cm_token', 'cm_base_url'] as $col) {
                if (Schema::hasColumn('pingwin_locations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
