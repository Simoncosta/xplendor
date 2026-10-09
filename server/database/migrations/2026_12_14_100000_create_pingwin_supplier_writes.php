<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — FN: escritas de FORNECEDORES no PingWin (criar | editar | anular), assíncronas
 * (job no worker + polling). Auditoria e alvo do polling da UI. `fields` = o que foi pedido;
 * `result` = a confirmação por releitura (ou o fornecedor existente, se o NIF já existir).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pingwin_supplier_writes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 10);                     // criar | editar | anular
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('pingwin_id', 30)->nullable();
            $table->json('fields')->nullable();
            $table->boolean('allow_duplicate_nif')->default(false);
            $table->string('status', 12)->default('pendente'); // pendente | ok | erro | duplicado
            $table->text('error_message')->nullable();
            $table->json('result')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        // Espelho: o que faltava para o formulário (morada/cód. postal/localidade já existem).
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('country_pingwin_id', 30)->nullable()->after('city');
            $table->string('paycond_pingwin_id', 30)->nullable()->after('country_pingwin_id');
            $table->string('obs', 250)->nullable()->after('paycond_pingwin_id');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['country_pingwin_id', 'paycond_pingwin_id', 'obs']);
        });
        Schema::dropIfExists('pingwin_supplier_writes');
    }
};
