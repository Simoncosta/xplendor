<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DMS Pós-venda (Incremento 2) — fotos carregadas pelo CLIENTE no relatório.
 *
 * Até 3 por relatório (teto validado no servidor). `social_consent_at` regista o
 * momento do consentimento RGPD — o próprio acto de carregar É o consentimento
 * (texto explícito no widget). Aditiva e reversível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('satisfaction_report_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('satisfaction_report_id')->constrained('satisfaction_reports')->cascadeOnDelete();
            $table->string('path');                          // /storage/company_X/reports/{id}/...webp
            $table->unsignedTinyInteger('order')->default(0);
            $table->timestamp('social_consent_at')->nullable(); // momento do consentimento (RGPD)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('satisfaction_report_photos');
    }
};
