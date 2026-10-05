<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico das mudanças de TIPO de um ticket (só a equipa XPLENDOR muda o tipo).
 * Guarda o tipo anterior e o novo e, quando havia orçamento, o estado, o valor e
 * as horas que tinha: o ticket fica a null, o rasto fica aqui. Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_ticket_type_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('from_type', 30);
            $table->string('to_type', 30);
            $table->string('previous_quote_status', 30)->nullable();
            $table->decimal('previous_quoted_amount', 10, 2)->nullable();
            $table->decimal('previous_estimated_hours', 6, 2)->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('support_ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_type_changes');
    }
};
