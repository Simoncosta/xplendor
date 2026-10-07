<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Arquitetura da tenancy: a decisão "pode trabalhar nesta empresa?" vive num só sítio
 * (CompanyAccess, usado pelo tenant e pelo authorizeCompany). Nenhum controller volta a
 * comparar a empresa do utilizador por conta própria (seria uma regra que esquece a
 * agência gestora, ou que filtra pela empresa errada); o "é root?" só aparece onde é a
 * regra da plataforma; e só as regras do cliente (acessos e dados da empresa) comparam a
 * empresa do utilizador nos FormRequests.
 */
class TenancyArchitectureTest extends TestCase
{
    /** Comparações (ou filtros) com a empresa do utilizador autenticado. */
    private const OWN_COMPANY = '/(\$(user|authUser|actor|auth)|Auth::user\(\)\??|\$request->user\(\)\??)->company_id\s*(===|!==|==|!=)|(===|!==|==|!=)\s*(\(int\)\s*)?(\$(user|authUser|actor|auth)|Auth::user\(\)\??|\$request->user\(\)\??)->company_id|=>\s*\$(user|authUser)->company_id\b/';

    /** "é root?" nos controllers de empresa: só onde é regra da plataforma. Ficheiro => ocorrências. */
    private const ROOT_RULES = [
        'CompanyController.php' => 3,           // lista todas as empresas; só o root cria e apaga empresas
        'CompanyManagementController.php' => 2, // o root também convida o primeiro admin
        'ImpersonationController.php' => 1,     // um root não é impersonado
        'PlanController.php' => 1,              // planos da plataforma
        'SupportTicketController.php' => 1,     // mensagens da equipa da plataforma
        'UserController.php' => 1,              // marca do browser da equipa
    ];

    /** FormRequests das regras do cliente (acessos e dados da empresa): a agência nunca. */
    private const CLIENT_RULE_REQUESTS = ['CompanyRequest.php', 'StoreUserRequest.php', 'UpdateUserRequest.php'];

    public function test_company_controllers_never_compare_the_users_company_themselves(): void
    {
        $offenders = [];
        foreach (File::files(app_path('Http/Controllers/Api/V1')) as $file) {
            foreach (explode("\n", $file->getContents()) as $i => $line) {
                if (preg_match(self::OWN_COMPANY, $line) && ! str_contains($line, 'managedCompanyIds')) {
                    $offenders[] = $file->getFilename() . ':' . ($i + 1) . '  ' . trim($line);
                }
            }
        }
        $this->assertSame([], $offenders, 'Use $this->authorizeCompany($companyId) (ou CompanyAccess) e filtre pela empresa do endereço.');
    }

    public function test_root_checks_in_company_controllers_are_only_the_platform_rules(): void
    {
        $found = [];
        foreach (File::files(app_path('Http/Controllers/Api/V1')) as $file) {
            $n = preg_match_all("/role\s*(===|!==)\s*'root'/", $file->getContents());
            if ($n > 0) {
                $found[$file->getFilename()] = $n;
            }
        }
        ksort($found);
        $expected = self::ROOT_RULES;
        ksort($expected);
        $this->assertSame($expected, $found, 'O root já passa pelo portão central (CompanyAccess); não repetir "|| root" nos controllers.');
    }

    public function test_only_the_client_rules_compare_the_users_company_in_form_requests(): void
    {
        $found = [];
        foreach (File::files(app_path('Http/Requests')) as $file) {
            if (preg_match(self::OWN_COMPANY, $file->getContents())) {
                $found[] = $file->getFilename();
            }
        }
        sort($found);
        $this->assertSame(self::CLIENT_RULE_REQUESTS, $found);
    }

    public function test_the_tenant_gate_and_the_controllers_use_the_central_access(): void
    {
        $this->assertStringContainsString('CompanyAccess', File::get(app_path('Http/Middleware/EnsureTenantAccess.php')));
        $this->assertStringContainsString('CompanyAccess', File::get(app_path('Http/Controllers/Controller.php')));
        $this->assertStringContainsString('CompanyAccess', File::get(app_path('Http/Middleware/CheckCompanySubscription.php')));
    }
}
