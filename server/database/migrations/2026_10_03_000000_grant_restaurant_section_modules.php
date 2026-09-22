<?php

use App\Modules\ModuleRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — As secções de restauração passaram de UM módulo umbrella ('pingwin')
 * para módulos próprios (restauracao_lojas/…/unidades). Para NÃO PARTIR as empresas
 * de restauração já existentes (que só tinham 'pingwin' ativo), este data-migration
 * LIBERTA-LHES as 8 secções — mantendo o acesso que já tinham. Idempotente.
 * Aditiva/reversível (o down remove só as secções que este migration acrescenta).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Empresas com o umbrella 'pingwin' ativo.
        $companyIds = DB::table('company_modules')->where('module_key', 'pingwin')->pluck('company_id')->unique();

        foreach ($companyIds as $companyId) {
            $existing = DB::table('company_modules')->where('company_id', $companyId)->pluck('module_key')->all();
            foreach (ModuleRegistry::RESTAURANT_SECTIONS as $key) {
                if (! in_array($key, $existing, true)) {
                    DB::table('company_modules')->insert([
                        'company_id' => $companyId,
                        'module_key' => $key,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Remove só as secções (o umbrella 'pingwin' fica intacto).
        DB::table('company_modules')->whereIn('module_key', ModuleRegistry::RESTAURANT_SECTIONS)->delete();
    }
};
