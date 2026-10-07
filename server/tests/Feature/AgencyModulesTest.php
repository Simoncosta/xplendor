<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\CompanyModuleEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Uma agência tem sempre a Linha Editorial: marcá-la liga o módulo (com registo no
 * histórico de módulos) e as agências já marcadas são acertadas pela migração. As mudanças
 * manuais e por preset também ficam no histórico.
 */
class AgencyModulesTest extends TestCase
{
    use RefreshDatabase;

    private int $plan;
    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->root = User::factory()->create(['company_id' => $this->company('XPLENDOR')->id, 'role' => 'root', 'name' => 'Simon']);
    }

    /** Uma empresa sem módulos nem histórico (a criação aplica um preset). */
    private function company(string $name): Company
    {
        $c = Company::create(['nipc' => (string) random_int(500000000, 599999999), 'fiscal_name' => $name, 'plan_id' => $this->plan, 'subscription_status' => 'active']);
        CompanyModule::where('company_id', $c->id)->delete();
        CompanyModuleEvent::where('company_id', $c->id)->delete();

        return $c;
    }

    private function editorial(Company $c): bool
    {
        return CompanyModule::where('company_id', $c->id)->where('module_key', 'linha_editorial')->exists();
    }

    public function test_marking_an_agency_enables_the_editorial_line_and_records_it(): void
    {
        $agency = $this->company('Agência Norte');
        $this->assertFalse($this->editorial($agency));

        $this->actingAs($this->root, 'sanctum')->patchJson("/api/v1/admin/companies/{$agency->id}/agency", ['enabled' => true])->assertOk();

        $this->assertTrue($this->editorial($agency));
        $event = CompanyModuleEvent::where('company_id', $agency->id)->sole();
        $this->assertSame(['linha_editorial', 'enabled', 'agency', $this->root->id], [$event->module_key, $event->action, $event->source, $event->user_id]);
        $this->assertSame('Ativado ao marcar a empresa como agência.', $event->note);

        // O histórico aparece no ecrã dos módulos.
        $history = $this->actingAs($this->root, 'sanctum')->getJson("/api/v1/admin/companies/{$agency->id}/modules")->assertOk()->json('data.history');
        $this->assertSame([['Linha Editorial', 'enabled', 'agency', 'Simon']], array_map(fn ($h) => [$h['module'], $h['action'], $h['source'], $h['user']], $history));
    }

    public function test_marking_again_or_an_agency_that_already_has_it_records_nothing_new(): void
    {
        $agency = $this->company('Agência Sul');
        CompanyModule::create(['company_id' => $agency->id, 'module_key' => 'linha_editorial']);

        $this->actingAs($this->root, 'sanctum')->patchJson("/api/v1/admin/companies/{$agency->id}/agency", ['enabled' => true])->assertOk();
        $this->actingAs($this->root, 'sanctum')->patchJson("/api/v1/admin/companies/{$agency->id}/agency", ['enabled' => true])->assertOk();

        $this->assertSame(0, CompanyModuleEvent::where('company_id', $agency->id)->count());
    }

    public function test_unmarking_does_not_switch_the_module_off(): void
    {
        $agency = $this->company('Agência Centro');
        $this->actingAs($this->root, 'sanctum')->patchJson("/api/v1/admin/companies/{$agency->id}/agency", ['enabled' => true])->assertOk();
        $this->actingAs($this->root, 'sanctum')->patchJson("/api/v1/admin/companies/{$agency->id}/agency", ['enabled' => false])->assertOk();

        $this->assertTrue($this->editorial($agency));
    }

    public function test_the_migration_fixes_agencies_already_marked(): void
    {
        $old = $this->company('Agência Antiga');
        $old->forceFill(['agency_enabled_at' => now()->subMonth()])->save();
        $ready = $this->company('Agência Pronta');
        $ready->forceFill(['agency_enabled_at' => now()->subMonth()])->save();
        CompanyModule::create(['company_id' => $ready->id, 'module_key' => 'linha_editorial']);
        $plain = $this->company('Empresa Normal');

        Schema::drop('company_module_events');
        (require database_path('migrations/2026_11_27_100000_create_company_module_events.php'))->up();

        $this->assertTrue($this->editorial($old));
        $this->assertSame('agency', DB::table('company_module_events')->where('company_id', $old->id)->value('source'));
        $this->assertSame(0, DB::table('company_module_events')->where('company_id', $ready->id)->count());
        $this->assertFalse($this->editorial($plain));
    }

    public function test_manual_changes_and_presets_go_to_the_history(): void
    {
        $c = $this->company('Domiway');
        $root = $this->actingAs($this->root, 'sanctum');
        $root->patchJson("/api/v1/admin/companies/{$c->id}/modules", ['module_key' => 'linha_editorial', 'enabled' => true])->assertOk();
        $root->patchJson("/api/v1/admin/companies/{$c->id}/modules", ['module_key' => 'linha_editorial', 'enabled' => false])->assertOk();
        $root->postJson("/api/v1/admin/companies/{$c->id}/modules/preset", ['preset' => 'base'])->assertOk();

        $rows = CompanyModuleEvent::where('company_id', $c->id)->orderBy('id')->get()->map(fn ($e) => [$e->module_key, $e->action, $e->source])->all();
        $this->assertSame(['linha_editorial', 'enabled', 'manual'], $rows[0]);
        $this->assertSame(['linha_editorial', 'disabled', 'manual'], $rows[1]);
        $this->assertEqualsCanonicalizing(
            [['marketing_analytics', 'enabled', 'preset'], ['support_tasks', 'enabled', 'preset'], ['linha_editorial', 'enabled', 'preset']],
            array_slice($rows, 2),
        );
    }
}
