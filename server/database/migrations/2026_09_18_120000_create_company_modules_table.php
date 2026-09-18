<?php

declare(strict_types=1);

use App\Modules\ModuleRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — Módulos ativáveis por empresa (Incremento 1). PRESENÇA = ativo:
 * uma linha (company_id, module_key) significa que o módulo está LIGADO; a
 * ausência significa desligado.
 *
 * ⚠️ Migração de dados (cautela 2026-06-09): as empresas que JÁ existem têm hoje
 * acesso a tudo. Para NADA mudar, ligamos-lhes o preset AUTOMOTIVO (todos os
 * módulos) — assim, quando o incremento 2 (esconder) entrar, não perdem acesso.
 *
 * ADITIVA e reversível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('module_key', 40);
            $table->timestamps();

            $table->unique(['company_id', 'module_key']);
            $table->index('module_key');
        });

        // Backfill: todas as empresas existentes → preset Automotivo (tudo ligado).
        $automotive = ModuleRegistry::presetKeys('automotive');
        $now = now();

        DB::table('companies')->orderBy('id')->pluck('id')->each(function ($companyId) use ($automotive, $now) {
            $rows = array_map(fn (string $key) => [
                'company_id' => $companyId,
                'module_key' => $key,
                'created_at' => $now,
                'updated_at' => $now,
            ], $automotive);

            // insertOrIgnore respeita o unique (idempotente se correr 2×).
            DB::table('company_modules')->insertOrIgnore($rows);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_modules');
    }
};
