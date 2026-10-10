<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Arquitetura da tenancy: a decisão "pode trabalhar nesta empresa?" vive num só sítio
 * (CompanyAccess, usado pelo tenant e pelo authorizeCompany). Nenhum controller volta a
 * comparar a empresa do utilizador por conta própria (seria uma regra que esquece a
 * agência gestora, ou que filtra pela empresa errada). ACL (F3): nenhum controller compara
 * o papel, as permissões vêm do Access (middleware permission, um só 403 com motivo) e só
 * ficam os 403 da lista ALLOWED_403; os testes leem também as subpastas (Admin, Agency).
 */
class TenancyArchitectureTest extends TestCase
{
    /** Comparações (ou filtros) com a empresa do utilizador autenticado. */
    private const OWN_COMPANY = '/(\$(user|authUser|actor|auth)|Auth::user\(\)\??|\$request->user\(\)\??)->company_id\s*(===|!==|==|!=)|(===|!==|==|!=)\s*(\(int\)\s*)?(\$(user|authUser|actor|auth)|Auth::user\(\)\??|\$request->user\(\)\??)->company_id|=>\s*\$(user|authUser)->company_id\b/';

    /**
     * ACL (F3): os 403 que ainda podem sair de um controller (ficheiro => ocorrências), cada um
     * com o porquê. As permissões saem do middleware permission (um só 403, com motivo); a
     * verificação da empresa ("Acesso negado: utilizador inválido.") fica como defesa em
     * profundidade e não conta aqui.
     */
    private const ALLOWED_403 = [
        'Admin/AdminController.php' => 2,                 // rotas do root (já atrás do ensure_super_admin): defesa
        'Admin/ChargeController.php' => 1,
        'Admin/CompanyController.php' => 1,
        'Admin/CompanyManagementController.php' => 1,
        'Admin/ManagedCompanyRequestController.php' => 1,
        'Admin/QuoteController.php' => 1,
        'Admin/ServiceCatalogController.php' => 1,
        'Admin/StockController.php' => 1,
        'Admin/SupportTicketController.php' => 2,         // + a impersonation (regra transversal)
        'Agency/AgencyController.php' => 6,               // painel da agência (rotas /agencies, fora das rotas de empresa)
        'CompanyController.php' => 1,                     // criar empresas (rota sem empresa no endereço): só o root
        'EditorialLineController.php' => 1,               // modo de produção pela equipa (regra transversal)
        'EditorialWorkflowController.php' => 1,           // o modo de produção só o muda quem produz (regra transversal)
        'PlanController.php' => 1,                        // planos da plataforma (rota sem empresa no endereço)
        'RestaurantCompassController.php' => 1,           // agir na Bússola exige a Linha Editorial ativa e produção (regra transversal)
        'RestaurantSignalsController.php' => 1,           // idem
        'UserController.php' => 1,                        // utilizador de outra empresa
    ];

    /** FormRequests que comparam a empresa do utilizador autenticado (nenhum: a permissão é da rota). */
    private const CLIENT_RULE_REQUESTS = [];

    /** @return \Symfony\Component\Finder\SplFileInfo[] todos os controllers, com as subpastas. */
    private function controllers(): array
    {
        return File::allFiles(app_path('Http/Controllers/Api/V1'));
    }

    public function test_company_controllers_never_compare_the_users_company_themselves(): void
    {
        $offenders = [];
        foreach ($this->controllers() as $file) {
            foreach (explode("\n", $file->getContents()) as $i => $line) {
                if (preg_match(self::OWN_COMPANY, $line) && ! str_contains($line, 'managedCompanyIds')) {
                    $offenders[] = $file->getFilename() . ':' . ($i + 1) . '  ' . trim($line);
                }
            }
        }
        $this->assertSame([], $offenders, 'Use $this->authorizeCompany($companyId) (ou CompanyAccess) e filtre pela empresa do endereço.');
    }

    /** ACL (F3): nenhum controller compara o papel ("role === …"): o root e o admin têm isRoot() e isAdmin(), e as permissões vêm do Access. */
    public function test_no_controller_compares_the_role(): void
    {
        $found = [];
        foreach ($this->controllers() as $file) {
            foreach (explode("\n", $file->getContents()) as $i => $line) {
                if (preg_match("/->role\s*(===|!==|==|!=)|(===|!==|==|!=)\s*\$[a-zA-Z_>\-()?]*->role\b|in_array\([^)]*->role/", $line)) {
                    $found[] = $file->getRelativePathname() . ':' . ($i + 1) . '  ' . trim($line);
                }
            }
        }
        $this->assertSame([], $found, 'Use $user->isRoot() / isAdmin() ou o Access (app/Access): nunca comparar o papel num controller.');
    }

    /** ACL (F3): um só 403 com motivo, o do middleware permission. Só ficam os 403 da lista ALLOWED_403. */
    public function test_controllers_only_return_the_allowed_403s(): void
    {
        $found = [];
        foreach ($this->controllers() as $file) {
            $n = 0;
            foreach (explode("\n", $file->getContents()) as $line) {
                if (preg_match('/ApiResponse::error\([^;]*403|abort(_unless|_if)?\([^;]*403|HttpException\(403/', $line) && ! str_contains($line, 'Acesso negado: utilizador inválido.')) {
                    $n++;
                }
            }
            if ($n > 0) {
                $found[str_replace('\\', '/', $file->getRelativePathname())] = $n;
            }
        }
        ksort($found);
        $expected = self::ALLOWED_403;
        ksort($expected);
        $this->assertSame($expected, $found, 'Um 403 de permissão vem do middleware permission (RoutePermissions); uma regra transversal nova entra em ALLOWED_403 com o porquê.');
    }

    public function test_only_the_client_rules_compare_the_users_company_in_form_requests(): void
    {
        $found = [];
        foreach (File::allFiles(app_path('Http/Requests')) as $file) {
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
