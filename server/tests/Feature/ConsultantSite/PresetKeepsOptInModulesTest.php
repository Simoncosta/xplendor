<?php

declare(strict_types=1);

namespace Tests\Feature\ConsultantSite;

use App\Models\Company;
use App\Models\CompanyModuleEvent;
use App\Services\CompanyModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Aplicar um preset mantém os módulos que nenhum preset liga (o site do consultor, o Radar,
 * os Ficheiros), desde que as dependências continuem ligadas. O resto do preset não muda.
 */
class PresetKeepsOptInModulesTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);

        return Company::create(['nipc' => '', 'fiscal_name' => 'Empresa de teste (fictícia)', 'plan_id' => $plan, 'subscription_status' => 'active']);
    }

    private function modules(Company $c): array
    {
        $m = app(CompanyModuleService::class)->enabledKeys($c->id);
        sort($m);

        return $m;
    }

    public function test_the_consultant_site_survives_the_real_estate_preset(): void
    {
        $s = app(CompanyModuleService::class);
        $c = $this->company();
        $s->applyPreset($c->id, 'real_estate');
        $s->enable($c->id, 'site_consultor');
        $s->applyPreset($c->id, 'real_estate', null);
        $this->assertContains('site_consultor', $this->modules($c));
        $this->assertSame(0, CompanyModuleEvent::where('company_id', $c->id)->where('module_key', 'site_consultor')->where('action', CompanyModuleEvent::DISABLED)->count());
    }

    public function test_the_radar_survives_a_preset_in_another_company(): void
    {
        $s = app(CompanyModuleService::class);
        $c = $this->company();
        $s->applyPreset($c->id, 'restaurant');
        $s->enable($c->id, 'radar');
        $s->enable($c->id, 'ficheiros');
        $s->applyPreset($c->id, 'base');
        $this->assertContains('radar', $this->modules($c));
        $this->assertContains('ficheiros', $this->modules($c));
        $this->assertNotContains('pingwin', $this->modules($c), 'o que o preset anterior ligou e o novo não tem sai, como antes');
        $this->assertSame(['ficheiros', 'linha_editorial', 'marketing_analytics', 'radar', 'support_tasks'], $this->modules($c));
    }

    public function test_an_opt_in_module_leaves_when_the_preset_removes_its_dependency(): void
    {
        $s = app(CompanyModuleService::class);
        $c = $this->company();
        $s->applyPreset($c->id, 'real_estate');
        $s->enable($c->id, 'site_consultor');
        $s->applyPreset($c->id, 'automotive');
        $this->assertNotContains('imoveis', $this->modules($c));
        $this->assertNotContains('site_consultor', $this->modules($c), 'sem imóveis não há site do consultor');
        $this->assertSame(1, CompanyModuleEvent::where('company_id', $c->id)->where('module_key', 'site_consultor')->where('action', CompanyModuleEvent::DISABLED)->count());
    }
}
