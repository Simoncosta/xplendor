<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DMS — Tickets de suporte (stands reportam ideias/melhorias/bugs/sugestões ao
 * super-admin). Scoped por company_id (partilhado entre utilizadores da empresa).
 * Aditiva e reversível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // quem abriu
            $table->string('type', 20);      // idea | improvement | bug | suggestion
            $table->string('title');
            $table->text('description');
            $table->string('status', 20)->default('open'); // open|in_review|resolved|closed
            $table->string('screenshot_path')->nullable();  // só bug
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['status', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
