<?php

declare(strict_types=1);

namespace Tests\Feature\ConsultantSite;

use App\Models\Company;
use App\Models\ContentSector;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Verificação por ramo: o ramo (pelo preset da criação) decide o que cada empresa vê. Uma empresa
 * de restauração, um stand e uma empresa sem ramo imobiliário não têm os imóveis nem o site do
 * consultor; uma empresa do ramo Imobiliário tem os imóveis, e o site do consultor só depois de o
 * root o ligar.
 */
class BranchVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const SITE_ROUTES = ['consultant-site', 'inbound-contacts', 'consultant-site/listings-summary'];

    private function company(?string $sector): Company
    {
        $planId = DB::table('plans')->value('id') ?? DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);

        return app(CompanyService::class)->store(['nipc' => '', 'fiscal_name' => 'Empresa de teste (fictícia)', 'plan_id' => $planId,
            'content_sector_id' => $sector ? ContentSector::where('slug', $sector)->value('id') : null]);
    }

    private function adminOf(Company $c): User
    {
        $u = User::factory()->create(['company_id' => $c->id, 'role' => 'admin']);
        \App\Access\CompatibilityMigration::run();

        return $u;
    }

    private function modules(Company $c): array
    {
        return app(CompanyModuleService::class)->enabledKeys($c->id);
    }

    public static function otherBranches(): array
    {
        return ['restauração' => ['restauracao'], 'stand (carros)' => ['carros'], 'stand (autocaravanas)' => ['autocaravanas'], 'sem ramo' => [null]];
    }

    #[DataProvider('otherBranches')]
    public function test_other_branches_never_get_the_real_estate_modules_nor_their_routes(?string $sector): void
    {
        if ($sector && ! ContentSector::where('slug', $sector)->exists()) {
            $this->markTestSkipped("O ramo {$sector} não existe nas migrações.");
        }
        $c = $this->company($sector);
        $this->assertNotContains('imoveis', $this->modules($c));
        $this->assertNotContains('site_consultor', $this->modules($c));
        $admin = $this->adminOf($c);
        $access = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/companies/{$c->id}/my-access")->assertOk()->json('data');
        $this->assertNotContains('imoveis', $access['modules']);
        $this->assertNotContains('site_consultor', $access['modules']);
        foreach (['properties', 'developments', ...self::SITE_ROUTES] as $uri) {
            $this->actingAs($admin, 'sanctum')->getJson("/api/v1/companies/{$c->id}/{$uri}")->assertForbidden();
        }
    }

    public function test_the_real_estate_branch_gets_the_portfolio_and_the_site_only_when_root_turns_it_on(): void
    {
        $c = $this->company('imobiliario');
        $this->assertContains('imoveis', $this->modules($c));
        $this->assertNotContains('site_consultor', $this->modules($c), 'o site do consultor nasce desligado, também no ramo Imobiliário');
        $admin = $this->adminOf($c);
        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/companies/{$c->id}/properties")->assertOk();
        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/companies/{$c->id}/developments")->assertOk(); // os empreendimentos vêm com os imóveis
        foreach (self::SITE_ROUTES as $uri) {
            $this->actingAs($admin, 'sanctum')->getJson("/api/v1/companies/{$c->id}/{$uri}")->assertForbidden();
        }
        app(CompanyModuleService::class)->enable($c->id, 'site_consultor');
        foreach (self::SITE_ROUTES as $uri) {
            $this->actingAs($admin, 'sanctum')->getJson("/api/v1/companies/{$c->id}/{$uri}")->assertOk();
        }
    }

    public function test_choosing_or_changing_the_sector_later_does_not_change_modules(): void
    {
        $c = $this->company(null);
        $before = $this->modules($c);
        app(\App\Services\EditorialLineService::class)->setSector($c, (int) ContentSector::where('slug', 'imobiliario')->value('id'));
        $this->assertSame($before, $this->modules($c->fresh()), 'a primeira escolha do ramo não liga módulos');
        $this->assertNotContains('imoveis', $this->modules($c));
    }
}
