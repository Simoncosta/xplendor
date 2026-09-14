<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Orçamentos avulsos (gestão comercial da agência). TRANSVERSAL:
 * pertencem ao super-admin, NÃO são scoped por company (como os tickets no
 * /admin). O cliente pode nem estar na plataforma (ex.: Spacedrive).
 *
 * Base do futuro mini-CRM comercial. `company_id` fica nullable e preparado
 * para, um dia, ligar um orçamento a um stand da plataforma — por agora é
 * sempre null (não há custo em deixar a coluna pronta). O "tipo de serviço"
 * vive por agora em `description` (texto livre); formalizar é futuro.
 *
 * Migration ADITIVA e reversível — categoria mais segura (cautela 2026-06-09).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            // Ligação opcional a um stand da plataforma (futuro); null hoje.
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('client_name');                 // texto livre (cliente pode não estar na plataforma)
            $table->string('client_contact')->nullable();  // email/telefone, texto livre
            $table->text('description');                    // o que é o orçamento (tipo de serviço vive aqui por agora)
            $table->decimal('amount', 10, 2);               // valor do orçamento
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->text('notes')->nullable();              // observações
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
