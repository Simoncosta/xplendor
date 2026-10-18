<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ConsultantSite\PropertyInterestSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Site do consultor: atualiza as contagens de interesse da carteira (contactos e visitas dos últimos
 * 30 dias) a partir do site, nas empresas com o módulo site_consultor. Corre de noite (a janela dos
 * 30 dias anda); a cada contacto e a cada clique no WhatsApp já se atualiza o imóvel tocado.
 */
class ConsultantSiteInterestCommand extends Command
{
    protected $signature = 'consultor:interest-sync';

    protected $description = 'Site do consultor: as contagens de interesse dos imóveis a partir do site (contactos e visitas dos últimos 30 dias).';

    public function handle(PropertyInterestSync $sync): int
    {
        $total = 0;
        foreach (DB::table('company_modules')->where('module_key', 'site_consultor')->pluck('company_id') as $companyId) {
            $total += $sync->refreshProperties((int) $companyId);
        }
        $this->info("Imóveis atualizados: {$total}.");

        return self::SUCCESS;
    }
}
