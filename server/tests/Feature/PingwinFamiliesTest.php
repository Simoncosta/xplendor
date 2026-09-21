<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SyncPingwinFamiliesJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinFamily;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — PingWin Famílias (Fase 1, só leitura): guardar FLAT (UPSERT
 * idempotente), montar a árvore por parent (órfãos → raiz), religar os artigos
 * às famílias (órfão → sem família, sem rebentar), gate do módulo + tenancy.
 * O Python é substituído por um fake (override invoke).
 */
class PingwinFamiliesTest extends TestCase
{
    use RefreshDatabase;

    private Company $resto;
    private Company $auto;
    private User $restoUser;
    private User $autoUser;

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->resto = Company::create(['nipc' => '500013100', 'fiscal_name' => 'Resto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->auto = Company::create(['nipc' => '500013101', 'fiscal_name' => 'Auto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->resto->id, 'restaurant');
        $this->restoUser = User::factory()->create(['company_id' => $this->resto->id, 'role' => 'admin']);
        $this->autoUser = User::factory()->create(['company_id' => $this->auto->id, 'role' => 'admin']);

        CompanyIntegration::create([
            'company_id' => $this->resto->id, 'platform' => 'pingwin', 'status' => 'active',
            'access_token' => 'segredo', 'config' => ['username' => 'op', 'database' => 'yuko'],
        ]);
    }

    private function fakeService(array $families): PingwinService
    {
        return new class($families) extends PingwinService {
            public array $seen = [];
            public function __construct(private array $fams) {}
            protected function invoke(array $payload): array
            {
                $this->seen = $payload;
                return ['ok' => true, 'mode' => 'families', 'families' => $this->fams];
            }
        };
    }

    /** Bebidas > Cerveja > Sangria; + uma família apagada; + uma órfã (parent inexistente). */
    private function sampleFamilies(): array
    {
        return [
            ['id' => '1', 'description' => 'Bebidas', 'parent_id' => '', 'deleted' => false],
            ['id' => '2', 'description' => 'Cerveja', 'parent_id' => '1', 'deleted' => false],
            ['id' => '3', 'description' => 'Sangria', 'parent_id' => '2', 'deleted' => false],
            ['id' => '4', 'description' => 'Apagada', 'parent_id' => '1', 'deleted' => true],
            ['id' => '9', 'description' => 'Órfã', 'parent_id' => '999', 'deleted' => false], // parent inexistente → raiz
        ];
    }

    public function test_sync_persists_families_flat_and_requests_families_mode(): void
    {
        $fake = $this->fakeService($this->sampleFamilies());
        $count = $fake->syncFamilies($this->resto->id);

        $this->assertSame(5, $count);
        $this->assertSame('families', $fake->seen['mode']);
        $this->assertDatabaseCount('pingwin_families', 5);

        $cerveja = PingwinFamily::where('company_id', $this->resto->id)->where('pingwin_id', '2')->first();
        $this->assertSame('Cerveja', $cerveja->description);
        $this->assertSame('1', $cerveja->parent_pingwin_id);   // guardado FLAT com parent
        $this->assertTrue($cerveja->is_active);

        $bebidas = PingwinFamily::where('pingwin_id', '1')->first();
        $this->assertNull($bebidas->parent_pingwin_id);        // raiz (parent vazio → null)

        $this->assertFalse(PingwinFamily::where('pingwin_id', '4')->first()->is_active); // deleted → inativa
    }

    public function test_sync_is_idempotent_upsert(): void
    {
        $this->fakeService($this->sampleFamilies())->syncFamilies($this->resto->id);
        $this->fakeService($this->sampleFamilies())->syncFamilies($this->resto->id);
        $this->assertDatabaseCount('pingwin_families', 5);
    }

    public function test_tree_builds_from_parent_with_orphans_as_roots(): void
    {
        $service = $this->fakeService($this->sampleFamilies());
        $service->syncFamilies($this->resto->id);

        $tree = $service->familyTree($this->resto->id, withCounts: false);

        // Raízes: Bebidas (1) + Órfã (9); a Apagada (4) é ignorada (inativa).
        $roots = collect($tree)->pluck('description')->sort()->values()->all();
        $this->assertSame(['Bebidas', 'Órfã'], $roots);

        $bebidas = collect($tree)->firstWhere('description', 'Bebidas');
        $this->assertCount(1, $bebidas['children']);            // só Cerveja (Apagada excluída)
        $this->assertSame('Cerveja', $bebidas['children'][0]['description']);
        $this->assertSame('Sangria', $bebidas['children'][0]['children'][0]['description']); // profundidade
    }

    public function test_articles_relink_to_family_and_orphans_stay_null(): void
    {
        // Artigo na Cerveja (2) e artigo órfão (família 777 inexistente).
        PingwinCatalogItem::create(['company_id' => $this->resto->id, 'pingwin_id' => 'A1', 'description' => 'Super Bock', 'family_pingwin_id' => '2']);
        PingwinCatalogItem::create(['company_id' => $this->resto->id, 'pingwin_id' => 'A2', 'description' => 'Fantasma', 'family_pingwin_id' => '777']);

        $service = $this->fakeService($this->sampleFamilies());
        $service->syncFamilies($this->resto->id);

        $cervejaId = PingwinFamily::where('company_id', $this->resto->id)->where('pingwin_id', '2')->value('id');
        $this->assertSame($cervejaId, PingwinCatalogItem::where('pingwin_id', 'A1')->value('family_id'));
        $this->assertNull(PingwinCatalogItem::where('pingwin_id', 'A2')->value('family_id')); // órfão → null (sem rebentar)
    }

    public function test_families_endpoint_returns_tree(): void
    {
        $this->fakeService($this->sampleFamilies())->syncFamilies($this->resto->id);

        $this->actingAs($this->restoUser, 'sanctum')
            ->getJson("/api/v1/companies/{$this->resto->id}/integrations/pingwin/families")
            ->assertStatus(200)
            ->assertJsonPath('data.total', 4) // 5 - 1 inativa
            ->assertJsonPath('data.tree.0.pingwin_id', '1'); // Bebidas (ordenado por description)
    }

    public function test_sync_endpoint_queues_job(): void
    {
        Bus::fake();
        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson("/api/v1/companies/{$this->resto->id}/integrations/pingwin/families/sync")
            ->assertStatus(200)->assertJsonPath('data.queued', true);
        Bus::assertDispatched(SyncPingwinFamiliesJob::class, fn ($j) => $j->companyId === $this->resto->id);
    }

    /**
     * CAMINHO COMPLETO ponta-a-ponta: o JOB corre e resolve o PingwinService REAL
     * do container (só o Python/invoke é substituído). Prova que Job → syncFamilies
     * (método existe!) → guarda BD → religa artigos funciona do início ao fim.
     * (Este é o teste que teria apanhado o "Call to undefined method".)
     */
    public function test_job_runs_full_chain_and_relinks_articles(): void
    {
        // Artigo que deve religar à Cerveja (2) + órfão (família inexistente).
        PingwinCatalogItem::create(['company_id' => $this->resto->id, 'pingwin_id' => 'A1', 'description' => 'Super Bock', 'family_pingwin_id' => '2']);
        PingwinCatalogItem::create(['company_id' => $this->resto->id, 'pingwin_id' => 'A2', 'description' => 'Fantasma', 'family_pingwin_id' => '777']);

        // Liga o PingwinService REAL (fake só do invoke) ao container → o Job resolve-o.
        $this->app->instance(PingwinService::class, $this->fakeService($this->sampleFamilies()));

        // dispatchSync corre o handle() do Job em processo, resolvendo as dependências
        // do container (PingwinService + AlertService) — exatamente como em produção.
        SyncPingwinFamiliesJob::dispatchSync($this->resto->id);

        // Famílias guardadas (flat).
        $this->assertDatabaseCount('pingwin_families', 5);
        // Artigos religados: A1 → Cerveja; A2 (órfão) → null.
        $cervejaId = PingwinFamily::where('company_id', $this->resto->id)->where('pingwin_id', '2')->value('id');
        $this->assertSame($cervejaId, PingwinCatalogItem::where('pingwin_id', 'A1')->value('family_id'));
        $this->assertNull(PingwinCatalogItem::where('pingwin_id', 'A2')->value('family_id'));
        // Notificação de sucesso criada (padrão dos outros syncs).
        $this->assertDatabaseHas('alerts', ['company_id' => $this->resto->id, 'title' => 'Famílias atualizadas']);
    }

    public function test_module_gate_and_tenancy(): void
    {
        $this->actingAs($this->autoUser, 'sanctum')
            ->getJson("/api/v1/companies/{$this->auto->id}/integrations/pingwin/families")
            ->assertStatus(403);
    }
}
