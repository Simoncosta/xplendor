<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Tarefas internas do cliente (Kanban do stand). Entidade NOVA,
 * separada dos tickets: são as tarefas livres da equipa da empresa (ex.: "ligar
 * ao cliente X", "preparar viatura Y"). PARTILHADAS por company_id — toda a
 * equipa da empresa vê e edita o mesmo quadro (não é por-utilizador).
 *
 * Colunas FIXAS (status enum): todo | doing | done ("A Fazer" | "Em Curso" |
 * "Concluído"). `order` guarda a posição dentro da coluna (drag persiste).
 * assignee_user_id é opcional e tem de ser um utilizador da MESMA empresa
 * (validado na aplicação). created_by regista quem criou.
 *
 * Migration ADITIVA e reversível — categoria mais segura (cautela 2026-06-09).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete(); // tenancy
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('todo'); // todo | doing | done
            $table->integer('order')->default(0);          // posição dentro da coluna
            // Responsável (opcional). Utilizador da mesma empresa; se sair, fica sem responsável.
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete(); // quem criou
            $table->timestamps();

            $table->index(['company_id', 'status', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_tasks');
    }
};
