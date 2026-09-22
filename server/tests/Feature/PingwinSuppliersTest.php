<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SyncPingwinSuppliersJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PingwinSupplier;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — PingWin Fornecedores (Fase 1, só leitura): guardar (UPSERT
 * idempotente), o CAMINHO COMPLETO do sync (Job → syncSuppliers → mock Python →
 * BD — não deixar método por criar, lição das famílias), lista paginada +
 * pesquisa/filtro, gate do módulo + tenancy. O Python é substituído por um fake.
 */
class PingwinSuppliersTest extends TestCase
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

    private function fakeService(array $suppliers): PingwinService
    {
        return new class($suppliers) extends PingwinService {
            public array $seen = [];
            public function __construct(private array $sups) {}
            protected function invoke(array $payload): array
            {
                $this->seen = $payload;
                return ['ok' => true, 'mode' => 'suppliers', 'suppliers' => $this->sups];
            }
        };
    }

    /**
     * Nomes de campo REAIS do HAR (fornecedores.har): name, fiscalname, tax_number,
     * base_address, postalcode, postalcode_description (= localidade), phone, email,
     * deleted. O 2.º usa aliases alternativos (description/nif/town) por robustez.
     */
    private function sampleSuppliers(): array
    {
        return [
            ['id' => '10', 'code' => 'F001', 'name' => 'Makro', 'fiscalname' => 'Makro Cash SA', 'tax_number' => '500000001',
             'base_address' => 'Rua de Costa Cabral', 'postalcode' => '4200-232', 'postalcode_description' => 'Porto',
             'phone' => '210000000', 'email' => 'geral@makro.pt', 'deleted' => 0],
            ['id' => '20', 'code' => 'F002', 'description' => 'Recheio', 'nif' => '500000002', 'town' => 'Lisboa', 'deleted' => 0],
            ['id' => '30', 'code' => 'F003', 'name' => 'Antigo', 'tax_number' => '500000003', 'deleted' => 1],
        ];
    }

    public function test_sync_persists_suppliers_with_all_fields(): void
    {
        $fake = $this->fakeService($this->sampleSuppliers());
        $count = $fake->syncSuppliers($this->resto->id);

        $this->assertSame(3, $count);
        $this->assertSame('suppliers', $fake->seen['mode']);
        // Tabela unificada: os fornecedores PingWin vivem em `suppliers` (source='pingwin').
        $this->assertSame(3, PingwinSupplier::count());

        // Campos do HAR — incluindo os que tinham ficado por mapear.
        $makro = PingwinSupplier::where('company_id', $this->resto->id)->where('pingwin_id', '10')->first();
        $this->assertSame('Makro', $makro->name);
        $this->assertSame('Makro Cash SA', $makro->fiscal_name);          // fiscalname → nome fiscal
        $this->assertSame('500000001', $makro->tax_number);
        $this->assertSame('Rua de Costa Cabral', $makro->address);        // base_address → morada
        $this->assertSame('4200-232', $makro->postal_code);               // postalcode
        $this->assertSame('Porto', $makro->city);                         // postalcode_description → localidade
        $this->assertSame('210000000', $makro->phone);
        $this->assertTrue($makro->is_active);

        // Aliases alternativos: description→name, nif→tax_number, town→city.
        $recheio = PingwinSupplier::where('pingwin_id', '20')->first();
        $this->assertSame('Recheio', $recheio->name);
        $this->assertSame('500000002', $recheio->tax_number);
        $this->assertSame('Lisboa', $recheio->city);

        // deleted:1 → inativo.
        $this->assertFalse(PingwinSupplier::where('pingwin_id', '30')->first()->is_active);
    }

    public function test_sync_is_idempotent_upsert(): void
    {
        $this->fakeService($this->sampleSuppliers())->syncSuppliers($this->resto->id);
        $changed = $this->sampleSuppliers();
        $changed[0]['name'] = 'Makro PT';
        $this->fakeService($changed)->syncSuppliers($this->resto->id);

        // Tabela unificada: os fornecedores PingWin vivem em `suppliers` (source='pingwin').
        $this->assertSame(3, PingwinSupplier::count());
        $this->assertSame('Makro PT', PingwinSupplier::where('pingwin_id', '10')->first()->name);
    }

    /**
     * CAMINHO COMPLETO ponta-a-ponta: o JOB resolve o PingwinService REAL do
     * container (só o Python/invoke é substituído) e corre handle(). Prova que
     * Job → syncSuppliers (método existe!) → guarda BD funciona. (Este é o teste
     * que teria apanhado um "Call to undefined method", como aconteceu nas famílias.)
     */
    public function test_job_runs_full_chain(): void
    {
        $this->app->instance(PingwinService::class, $this->fakeService($this->sampleSuppliers()));

        SyncPingwinSuppliersJob::dispatchSync($this->resto->id);

        // Tabela unificada: os fornecedores PingWin vivem em `suppliers` (source='pingwin').
        $this->assertSame(3, PingwinSupplier::count());
        $this->assertDatabaseHas('alerts', ['company_id' => $this->resto->id, 'title' => 'Fornecedores atualizados']);
    }

    public function test_list_endpoint_paginates_searches_and_filters(): void
    {
        $this->fakeService($this->sampleSuppliers())->syncSuppliers($this->resto->id);
        $base = "/api/v1/companies/{$this->resto->id}/integrations/pingwin/suppliers";

        // Paginação de exibição (Laravel).
        $this->actingAs($this->restoUser, 'sanctum')->getJson("{$base}?perPage=2&page=1")
            ->assertStatus(200)
            ->assertJsonPath('data.suppliers.current_page', 1)
            ->assertJsonPath('data.suppliers.last_page', 2)
            ->assertJsonPath('data.suppliers.total', 3)
            ->assertJsonCount(2, 'data.suppliers.data');

        // Pesquisa por NIF.
        $this->actingAs($this->restoUser, 'sanctum')->getJson("{$base}?search=500000002")
            ->assertStatus(200)
            ->assertJsonPath('data.suppliers.total', 1)
            ->assertJsonPath('data.suppliers.data.0.name', 'Recheio');

        // Pesquisa por nome.
        $this->actingAs($this->restoUser, 'sanctum')->getJson("{$base}?search=Makro")
            ->assertStatus(200)
            ->assertJsonPath('data.suppliers.total', 1);

        // Filtro estado=inativo → só o Antigo (30).
        $this->actingAs($this->restoUser, 'sanctum')->getJson("{$base}?active=0")
            ->assertStatus(200)
            ->assertJsonPath('data.suppliers.total', 1)
            ->assertJsonPath('data.suppliers.data.0.pingwin_id', '30');
    }

    public function test_sync_endpoint_queues_job(): void
    {
        Bus::fake();
        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson("/api/v1/companies/{$this->resto->id}/integrations/pingwin/suppliers/sync")
            ->assertStatus(200)->assertJsonPath('data.queued', true);
        Bus::assertDispatched(SyncPingwinSuppliersJob::class, fn ($j) => $j->companyId === $this->resto->id);
    }

    public function test_module_gate_and_tenancy(): void
    {
        $this->actingAs($this->autoUser, 'sanctum')
            ->getJson("/api/v1/companies/{$this->auto->id}/integrations/pingwin/suppliers")
            ->assertStatus(403);
    }
}
