<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — S2: a página "Conta Corrente Fornecedores" tem módulo próprio
 * (restauracao_conta_corrente). As empresas de restauração que já existem (com o umbrella
 * 'pingwin' ativo) recebem-no, como as outras secções receberam em 2026_10_03. Idempotente;
 * o down remove só esta chave.
 */
return new class extends Migration
{
    private const KEY = 'restauracao_conta_corrente';

    public function up(): void
    {
        $companyIds = DB::table('company_modules')->where('module_key', 'pingwin')->pluck('company_id')->unique();
        foreach ($companyIds as $companyId) {
            $exists = DB::table('company_modules')->where('company_id', $companyId)->where('module_key', self::KEY)->exists();
            if (! $exists) {
                DB::table('company_modules')->insert([
                    'company_id' => $companyId, 'module_key' => self::KEY, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('company_modules')->where('module_key', self::KEY)->delete();
    }
};
