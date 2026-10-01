<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — PingWin Condições de Pagamento (Fatia 1, só leitura): espelho das
 * condições (paycond) por empresa. Segue o padrão dos restantes lookups PingWin
 * (chave (company_id, pingwin_id), is_active = NOT deleted, coluna raw json).
 *
 * discount = desconto financeiro em PERCENTAGEM (NÃO cêntimos). days = dias de
 * vencimento (int). O filho tbdocs (documentos vinculados) guarda-se numa coluna
 * JSON — ver nota no model (sem tabela filha nesta fatia; o cross-docconfig é fatia
 * posterior). Aditiva/idempotente; nomes de índice CURTOS (<64 chars).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pingwin_payment_conditions')) {
            return;
        }

        Schema::create('pingwin_payment_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('pingwin_id');                      // id (dbid) da condição no PingWin
            $table->string('code')->nullable();                // código curto (<=10)
            $table->string('description')->nullable();         // descrição (<=50)
            $table->decimal('discount', 8, 2)->nullable();     // desconto financeiro % (NÃO cêntimos)
            $table->integer('days')->nullable();               // dias de vencimento
            $table->boolean('is_active')->default(true);       // = NOT deleted (anulado no PingWin)
            $table->json('tbdocs')->nullable();                // documentos vinculados (filho tbdocs)
            $table->json('raw')->nullable();                   // detalhe completo do servidor (fonte de verdade)
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'pingwin_id'], 'pw_paycond_company_pw_unique');
            $table->index(['company_id'], 'pw_paycond_company_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pingwin_payment_conditions');
    }
};
