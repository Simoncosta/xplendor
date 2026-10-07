<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F1c-2: o link gerado a partir da tarefa de um ticket de arranque guarda esse ticket. As
 * tarefas desse ticket marcam-se pela chave, mesmo que o ticket esteja noutra empresa (por
 * exemplo, na XPLENDOR, num orçamento de prospeto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_setup_links', function (Blueprint $table) {
            $table->foreignId('support_ticket_id')->nullable()->after('company_management_id')->constrained('support_tickets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('company_setup_links', fn (Blueprint $t) => $t->dropConstrainedForeignId('support_ticket_id'));
    }
};
