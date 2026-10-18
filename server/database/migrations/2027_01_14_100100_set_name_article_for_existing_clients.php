<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — migração de dados: o artigo do nome dos clientes já existentes (a Yuko escreve-se "os
 * Yuko"). Só preenche onde está vazio; ajusta-se depois na ficha da empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('companies')->whereNull('name_article')
            ->where(fn ($q) => $q->where('trade_name', 'like', '%Yuko%')->orWhere('fiscal_name', 'like', '%Yuko%'))
            ->update(['name_article' => 'os']);
    }

    public function down(): void
    {
        // Nada a desfazer: a coluna sai com a migração anterior.
    }
};
