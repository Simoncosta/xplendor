<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — AUDITORIA da 1ª ESCRITA no PingWin: criação de unidades. Cada tentativa
 * de criar (Action NEW) fica registada — quem, quando, o quê, e o resultado (id novo
 * ou o erro REAL). Serve também de alvo de POLLING da UI (a criação corre no worker).
 * Aditiva/idempotente; nome de índice CURTO explícito (<64 chars).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pingwin_unit_creations')) {
            return;
        }

        Schema::create('pingwin_unit_creations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();       // quem criou (auditoria)
            // Snapshot do que foi enviado ao PingWin.
            $table->string('description');
            $table->string('shortname');
            $table->string('parent_pingwin_id')->nullable();
            $table->decimal('parent_qnt', 16, 5)->nullable();
            $table->decimal('net_weight', 16, 5)->nullable();
            $table->string('external_measure')->nullable();
            $table->string('status')->default('a_criar');            // a_criar|criada|erro
            $table->string('pingwin_id')->nullable();                // id novo devolvido pelo PingWin
            $table->text('error_message')->nullable();               // motivo REAL se falhar
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'pw_unit_crt_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pingwin_unit_creations');
    }
};
