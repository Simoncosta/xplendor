<?php

declare(strict_types=1);

namespace App\Services\Setup;

use App\Models\Company;
use App\Models\CompanyManagement;
use App\Models\CompanySetupLink;
use App\Services\Agency\AgencyNotifier;

/**
 * Avisos do link de configuração (sino e email): à agência gestora, se a empresa for gerida;
 * senão, aos administradores da empresa.
 */
class SetupLinkNotifier
{
    public function __construct(private readonly AgencyNotifier $notify) {}

    public function completed(CompanySetupLink $link): void
    {
        $company = $link->company;
        $done = array_map(fn ($k) => CompanySetupLink::STEPS[$k], $link->stepKeys());
        $this->send($link, "Configuração concluída: {$this->name($company)}", [
            "O cliente concluiu o link de configuração de {$this->name($company)}.",
            'Ligações autorizadas: ' . implode(', ', $done) . '.',
        ], 'opportunity');
    }

    public function notApproved(CompanySetupLink $link, string $step): void
    {
        $company = $link->company;
        $this->send($link, "A ligação ao Facebook ainda não está disponível: {$this->name($company)}", [
            "O cliente de {$this->name($company)} tentou o passo \"" . CompanySetupLink::STEPS[$step] . '" e a Meta não ofereceu as permissões.',
            'Até a Meta aprovar a app, este passo só funciona para contas de teste. O cliente viu uma mensagem a explicar que a agência foi avisada.',
        ], 'warning');
    }

    public function stalled(CompanySetupLink $link, string $step): void
    {
        $company = $link->company;
        $this->send($link, "O cliente não concluiu a ligação ao Facebook: {$this->name($company)}", [
            "O cliente de {$this->name($company)} começou o passo \"" . CompanySetupLink::STEPS[$step] . '" há mais de 30 minutos e não o concluiu.',
            'Pode ter ficado num erro da Meta dentro do Facebook (por exemplo, enquanto a app não estiver aprovada só as contas de teste conseguem ligar). Fale com o cliente ou envie de novo o link.',
        ], 'warning');
    }

    private function send(CompanySetupLink $link, string $title, array $lines, string $type): void
    {
        $company = $link->company;
        $path = '/companies/' . $company->id . '?tab=integrations';
        $management = CompanyManagement::active()->where('managed_company_id', $company->id)->with('agency')->first();
        if ($management?->agency) {
            $this->notify->agency($management->agency, $title, $lines, $path, $type);
        } else {
            $this->notify->companyAdmins($company, $title, $lines, $path, $type);
        }
    }

    private function name(?Company $company): string
    {
        return AgencyNotifier::name($company);
    }
}
