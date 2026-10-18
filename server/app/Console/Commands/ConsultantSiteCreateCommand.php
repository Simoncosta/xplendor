<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\ConsultantSite;
use App\Models\ConsultantSiteHost;
use App\Models\ContentSector;
use App\Models\Plan;
use App\Services\CompanyModuleService;
use App\Services\CompanyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Site do consultor: cria a empresa do consultor (ramo Imobiliário, preset real_estate), liga o
 * módulo site_consultor e cria o perfil do site com o host. Sem dados pessoais além do nome
 * público. Simulação por omissão; --execute grava. Nunca mexe numa empresa que já exista.
 *
 *   php artisan consultor:site-create "Simon Costa" --host=simoncosta.localhost --execute
 */
class ConsultantSiteCreateCommand extends Command
{
    protected $signature = 'consultor:site-create {name : o nome público do consultor} {--host= : o host principal do site} {--execute : grava (sem isto só simula)}';

    protected $description = 'Site do consultor: cria a empresa do consultor (ramo Imobiliário), liga o módulo e cria o perfil do site com o host.';

    public function handle(CompanyModuleService $modules): int
    {
        $name = trim((string) $this->argument('name'));
        $host = ConsultantSiteHost::normalize((string) $this->option('host'));
        if ($name === '' || $host === '' || ! preg_match('/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $host)) {
            $this->error('Indique o nome público e um host válido (--host=nome.xplendor.tech).');

            return self::FAILURE;
        }
        if (ConsultantSiteHost::where('host', $host)->exists()) {
            $this->error("O host {$host} já está em uso.");

            return self::FAILURE;
        }
        $sector = ContentSector::where('slug', 'imobiliario')->first();
        if (! $sector) {
            $this->error('O ramo "Imobiliário" não existe (falta a migração da Bússola Master R3).');

            return self::FAILURE;
        }
        $this->line("Empresa nova: {$name} (ramo Imobiliário, preset real_estate, módulo site_consultor)");
        $this->line("Site: {$host} (rascunho)");
        if (! $this->option('execute')) {
            $this->warn('Simulação: nada foi gravado. Use --execute para gravar.');

            return self::SUCCESS;
        }

        $company = DB::transaction(function () use ($name, $host, $sector, $modules) {
            $company = app(CompanyService::class)->store([
                'nipc' => '', 'fiscal_name' => $name, 'trade_name' => $name, 'content_sector_id' => $sector->id,
                'plan_id' => Plan::query()->orderBy('id')->value('id'),
            ]);
            $modules->enable((int) $company->id, 'site_consultor');
            $site = ConsultantSite::create(['company_id' => $company->id, 'network_label' => 'Consultor Chave Nova']); // o nome é o da empresa
            $site->hosts()->create(['host' => $host, 'is_primary' => true]);

            return $company;
        });
        $this->info("Criada a empresa #{$company->id} com o site {$host}. Para o token do contentor: php artisan consultor:site-token --site={$company->id}");

        return self::SUCCESS;
    }
}
