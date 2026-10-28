<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-mail 10b · A pasta do Lixo escolhida à mão, por caixa: só para os servidores que não marcam
 * nenhuma pasta com \Trash. Sempre uma pasta que já existe na caixa (a XPLENDOR nunca cria pastas).
 * Só acrescenta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->string('trash_folder', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', fn (Blueprint $t) => $t->dropColumn('trash_folder'));
    }
};
