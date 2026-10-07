<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico de módulos por empresa: cada módulo ligado ou desligado fica registado (com a
 * origem: manual, preset ou agência, e quem o fez). E o acerto das agências já marcadas:
 * uma agência tem sempre a Linha Editorial ativa (a vista de todos os clientes vive lá).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_module_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('module_key', 40);
            $table->string('action', 10); // enabled | disabled
            $table->string('source', 20); // manual | preset | agency
            $table->string('note', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'created_at']);
        });

        $now = now();
        $agencies = DB::table('companies')->whereNotNull('agency_enabled_at')->pluck('id');
        foreach ($agencies as $companyId) {
            if (DB::table('company_modules')->where('company_id', $companyId)->where('module_key', 'linha_editorial')->exists()) {
                continue;
            }
            DB::table('company_modules')->insert(['company_id' => $companyId, 'module_key' => 'linha_editorial', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('company_module_events')->insert([
                'company_id' => $companyId, 'module_key' => 'linha_editorial', 'action' => 'enabled', 'source' => 'agency',
                'note' => 'Ativado por a empresa ser uma agência (acerto das agências já marcadas).', 'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_module_events');
    }
};
