<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — AUDITORIA/estado de uma ESCRITA de config de documento no PingWin
 * (Fase D1: editar maindataset), à imagem de pingwin_paycond_writes: quem/quando/o
 * quê + resultado, e alvo de POLLING da UI (a escrita corre no worker). Scoped por
 * company_id. `fields` = campos do maindataset editados. Aditiva/idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pingwin_docconfig_writes')) {
            return;
        }

        Schema::create('pingwin_docconfig_writes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');                 // editar (D2/D3/D4 acrescentam)
            $table->string('docconfig_id');           // external_id / id PingWin do documento
            $table->string('code')->nullable();
            $table->string('description')->nullable();
            $table->json('fields')->nullable();        // campos do maindataset a sobrepor
            $table->string('status')->default('a_criar');   // a_criar|ok|erro
            $table->text('error_message')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'pw_docw_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pingwin_docconfig_writes');
    }
};
