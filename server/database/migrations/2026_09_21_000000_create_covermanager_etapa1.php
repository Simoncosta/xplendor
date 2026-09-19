<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — CoverManager (reservas) Etapa 1. Uma loja tem DOIS conjuntos de
 * credenciais: PingWin (winrest_store_id) e CoverManager (slug + token). O token
 * é cifrado (cast EncryptedLegacy). Guardamos SÓ o AGREGADO por turno (RGPD:
 * nunca o PII — nome/email/telefone). Aditiva e reversível (cautelas 2026-06-09).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pingwin_locations', function (Blueprint $table) {
            $table->string('cm_slug')->nullable()->after('is_active');   // não-secreto
            $table->text('cm_token')->nullable()->after('cm_slug');      // apikey — CIFRADO
            $table->string('cm_base_url')->nullable()->after('cm_token'); // override opcional
        });

        // SÓ agregados por turno — nenhum campo PII (RGPD).
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

            $table->unique(['location_id', 'business_date', 'shift']); // UPSERT idempotente
            $table->index(['company_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cm_reservation_shift_summary');
        Schema::table('pingwin_locations', function (Blueprint $table) {
            $table->dropColumn(['cm_slug', 'cm_token', 'cm_base_url']);
        });
    }
};
