<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SyncPingwinDocumentsJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PingwinDocumentConfig;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — PingWin Documentos (Fase 1, só leitura): buscar+agregar a lista de
 * tipos de documento (browserdataset, como o fetch_stores), UPSERT idempotente,
 * gate do módulo + tenancy. O Python é substituído por um fake (override invoke).
 */
class PingwinDocumentsTest extends TestCase
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

        // Credenciais PingWin da empresa (necessárias ao syncDocuments).
        CompanyIntegration::create([
            'company_id' => $this->resto->id, 'platform' => 'pingwin', 'status' => 'active',
            'access_token' => 'segredo', 'config' => ['username' => 'op', 'database' => 'yuko'],
        ]);
    }

    /** Fake do serviço: override de invoke() → resposta do browserdataset, sem docker. */
    private function fakeService(array $documents): PingwinService
    {
        return new class($documents) extends PingwinService {
            public array $seen = [];
            public function __construct(private array $docs) {}
            protected function invoke(array $payload): array
            {
                $this->seen = $payload;
                return ['ok' => true, 'mode' => 'documents', 'documents' => $this->docs];
            }
        };
    }

    private function sampleDocs(): array
    {
        return [
            ['id' => '10', 'code' => 'FT', 'description' => 'Fatura', 'entitytype' => 'Cliente', 'fiscaltype' => 'FT', 'fiscaltype_description' => 'Fatura', 'deleted' => false],
            ['id' => '20', 'code' => 'GT', 'description' => 'Guia de Transporte', 'entitytype' => 'Armazém', 'fiscaltype' => 'GT', 'fiscaltype_description' => 'Guia', 'deleted' => false],
        ];
    }

    public function test_sync_persists_document_types_and_requests_documents_mode(): void
    {
        $fake = $this->fakeService($this->sampleDocs());
        $count = $fake->syncDocuments($this->resto->id);

        $this->assertSame(2, $count);
        $this->assertSame('documents', $fake->seen['mode']); // pediu o modo certo ao Python
        $this->assertDatabaseCount('pingwin_document_configs', 2);
        $ft = PingwinDocumentConfig::where('company_id', $this->resto->id)->where('external_id', '10')->first();
        $this->assertSame('Fatura', $ft->description);
        $this->assertSame('Cliente', $ft->entitytype);
        $this->assertSame('FT', $ft->fiscaltype);
    }

    public function test_sync_is_idempotent_upsert(): void
    {
        $this->fakeService($this->sampleDocs())->syncDocuments($this->resto->id);
        $this->fakeService($this->sampleDocs())->syncDocuments($this->resto->id); // re-sync
        $this->assertDatabaseCount('pingwin_document_configs', 2);
    }

    public function test_list_endpoint_returns_documents_paginated(): void
    {
        $this->fakeService($this->sampleDocs())->syncDocuments($this->resto->id);

        $this->actingAs($this->restoUser, 'sanctum')
            ->getJson("/api/v1/companies/{$this->resto->id}/integrations/pingwin/documents")
            ->assertStatus(200)
            // A EXIBIÇÃO passou a paginar (paginate()) → data.documents.data[].
            ->assertJsonPath('data.documents.current_page', 1)
            ->assertJsonPath('data.documents.total', 2)
            ->assertJsonPath('data.documents.data.0.description', 'Fatura');
    }

    public function test_list_endpoint_paginates_and_searches(): void
    {
        // 25 docs → com perPage=10 dá 3 páginas; a pesquisa filtra no servidor.
        $docs = [];
        for ($i = 1; $i <= 25; $i++) {
            $docs[] = ['id' => (string) $i, 'code' => 'D' . $i, 'description' => 'Doc ' . $i, 'entitytype' => 'Cliente', 'deleted' => false];
        }
        $this->fakeService($docs)->syncDocuments($this->resto->id);

        $this->actingAs($this->restoUser, 'sanctum')
            ->getJson("/api/v1/companies/{$this->resto->id}/integrations/pingwin/documents?perPage=10&page=2")
            ->assertStatus(200)
            ->assertJsonPath('data.documents.current_page', 2)
            ->assertJsonPath('data.documents.last_page', 3)
            ->assertJsonPath('data.documents.total', 25)
            ->assertJsonCount(10, 'data.documents.data');

        // Pesquisa por código único → 1 resultado.
        $this->actingAs($this->restoUser, 'sanctum')
            ->getJson("/api/v1/companies/{$this->resto->id}/integrations/pingwin/documents?search=D7")
            ->assertStatus(200)
            ->assertJsonPath('data.documents.total', 1)
            ->assertJsonPath('data.documents.data.0.code', 'D7');
    }

    public function test_sync_endpoint_queues_job(): void
    {
        Bus::fake();
        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson("/api/v1/companies/{$this->resto->id}/integrations/pingwin/documents/sync")
            ->assertStatus(200)->assertJsonPath('data.queued', true);
        Bus::assertDispatched(SyncPingwinDocumentsJob::class, fn ($j) => $j->companyId === $this->resto->id);
    }

    public function test_module_gate_and_tenancy(): void
    {
        // Empresa automotiva (sem módulo pingwin) → 403.
        $this->actingAs($this->autoUser, 'sanctum')
            ->getJson("/api/v1/companies/{$this->auto->id}/integrations/pingwin/documents")
            ->assertStatus(403);
    }
}
