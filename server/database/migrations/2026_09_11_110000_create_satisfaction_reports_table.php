<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DMS — Módulo de Pós-Venda / Relatório de Satisfação (Incremento 1).
 *
 * Cada relatório pendura numa venda (car_sale) e é aberto pelo cliente por um
 * link público não-adivinhável (public_token). Aditiva e reversível.
 *
 * Campos rating/public_message/submitted_at já ficam previstos para os
 * incrementos seguintes (estrelas + Google review); nesta fase só se usam
 * status pending|opened e opened_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('satisfaction_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('car_sale_id')->constrained('car_sales')->cascadeOnDelete();
            // Denormalizado para puxar as fotos da viatura sem depender da venda.
            $table->unsignedBigInteger('car_id')->index();

            // Chave de acesso público — aleatória, não-adivinhável, única.
            $table->string('public_token', 64)->unique();

            // pending → opened (1.ª abertura) → submitted (incrementos seguintes).
            $table->string('status', 20)->default('pending');

            // Reservados para os próximos incrementos (estrelas / mensagem Google).
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('public_message')->nullable();

            $table->timestamp('opened_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('satisfaction_reports');
    }
};
