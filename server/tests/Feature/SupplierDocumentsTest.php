<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\SupplierDocumentsSyncInProgress;
use App\Jobs\DispatchSupplierDocumentsSyncJob;
use App\Jobs\SyncPingwinSupplierDocumentsJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PingwinDocumentSyncRun;
use App\Models\PingwinSupplier;
use App\Models\PingwinSupplierDocument;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\PingwinService;
use App\Services\SupplierDocumentsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — Documentos de fornecedor do PingWin (F1). O Python é simulado
 * (fetchSupplierDocuments). Cobre: upsert (sem apagar, first_seen_at preservado,
 * anulação como estado), runs ok/failed, bloqueio de run duplicado e expiração,
 * filtros/gate/tenancy da API, disparo assíncrono e despacho noturno.
 */
class SupplierDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Company $resto;
    private Company $other;
    private User $user;
    private User $otherUser;
    /** @var callable|null */
    public $fetch = null;

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->resto = Company::create(['nipc' => '500013300', 'fiscal_name' => 'Resto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500013301', 'fiscal_name' => 'Outra', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->resto->id, 'restaurant');
        app(CompanyModuleService::class)->applyPreset($this->other->id, 'restaurant');
        $this->user = User::factory()->create(['company_id' => $this->resto->id, 'role' => 'admin']);
        $this->otherUser = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);
        CompanyIntegration::create(['company_id' => $this->resto->id, 'platform' => 'pingwin', 'status' => 'active',
            'access_token' => 'x', 'config' => ['username' => 'op', 'database' => 'yuko']]);

        $test = $this;
        $this->app->instance(PingwinService::class, new class($test) extends PingwinService {
            public function __construct(private $test) {}
            public function fetchSupplierDocuments(int $companyId, string $from, string $to): array
            {
                return ($this->test->fetch)($companyId, $from, $to);
            }
        });
    }

    private function doc(string $id, string $cfg, string $doctype, string $document, float $total, string $date, string $ref = '', array $extra = []): array
    {
        return array_merge([
            'id' => $id, 'docconfig_id' => $cfg, 'doctype' => $doctype, 'document' => $document,
            'entity_id' => '584955579139628810', 'entity_name' => 'TALHO PIMENTA', 'fiscalname' => 'Talho Pimenta Lda',
            'tax_number' => '501234567', 'store_id' => '1099511639284', 'store' => 'Yuko BO',
            'doc_date' => str_replace('-', '', $date) . 'T00:00:00', 'fiscal_date' => str_replace('-', '', $date) . 'T00:00:00',
            'doc_time' => '00000000T10:42:49', 'total' => $total, 'paid' => 0.0, 'docstatus_id' => '8002',
            'docstatus_description' => 'Fechado', 'employee' => 'Adriana Oliveira', 'docreference_number' => $ref,
        ], $extra);
    }

    private function service(): SupplierDocumentsService
    {
        return app(SupplierDocumentsService::class);
    }

    private function seedDocs(): void
    {
        $this->service()->persist($this->resto->id, [
            $this->doc('584955579139786478', '1209', 'Fatura de fornecedor', 'VFT BOVFT/1020', 1170.10, '2026-10-05', '27731'),
            $this->doc('584955579139787956', '1205', 'Nota de crédito de fornecedor', 'VNC BOVNC/77', 1415.82, '2026-10-05', ''),
            $this->doc('1', '1209', 'Fatura de fornecedor', 'VFT BOVFT/900', 50.00, '2026-09-20', '', ['entity_id' => '999', 'entity_name' => 'Outro']),
            $this->doc('2', '1200', 'Encomenda', 'ENCF X/1', 10.00, '2026-10-04', ''),
        ]);
    }

    // ── Persistência ────────────────────────────────────────────────────────

    public function test_upsert_nao_apaga_preserva_first_seen_e_anulacao_e_estado(): void
    {
        PingwinSupplier::create(['company_id' => $this->resto->id, 'pingwin_id' => '584955579139628810', 'code' => '9', 'name' => 'Carnes', 'is_active' => true]);
        $this->travelTo('2026-10-05 08:00:00');
        $this->seedDocs();
        $doc = PingwinSupplierDocument::where('docheader_id', '584955579139786478')->first();
        $this->assertSame(117010, $doc->total_cents);
        $this->assertSame('10:42:49', $doc->doc_time);
        $this->assertSame('2026-10-05', $doc->doc_date->toDateString());
        $this->assertSame('Adriana Oliveira', $doc->employee_name);
        $this->assertNotNull($doc->supplier_id);
        $this->assertNull(PingwinSupplierDocument::where('docheader_id', '1')->value('supplier_id')); // fornecedor fora do espelho

        // 2.ª sync, dias depois: a fatura foi ANULADA e ganhou nº doc.; os outros não vêm (não se apagam).
        $this->travelTo('2026-10-08 08:00:00');
        $this->service()->persist($this->resto->id, [
            $this->doc('584955579139786478', '1209', 'Fatura de fornecedor', 'VFT BOVFT/1020', 1170.10, '2026-10-05', '27731-A',
                ['docstatus_id' => '8003', 'docstatus_description' => 'Anulado']),
        ]);

        $this->assertSame(4, PingwinSupplierDocument::count());
        $doc->refresh();
        $this->assertSame('Anulado', $doc->docstatus_description);
        $this->assertSame('27731-A', $doc->docreference_number);
        $this->assertSame('2026-10-05 08:00:00', $doc->first_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-08 08:00:00', $doc->last_seen_at->format('Y-m-d H:i:s'));
    }

    // ── Runs ────────────────────────────────────────────────────────────────

    public function test_run_ok(): void
    {
        $this->fetch = fn ($c, $from, $to) => ['documents' => [
            $this->doc('A', '1209', 'Fatura de fornecedor', 'VFT/1', 10, $from),
            $this->doc('B', '1205', 'Nota de crédito de fornecedor', 'VNC/1', 5, $to),
        ], 'blocks' => 1, 'splits' => 0];

        $run = $this->service()->createRun($this->resto->id, '2026-10-05', '2026-10-05', 'manual');
        $run = $this->service()->executeRun($run->id);

        $this->assertSame('ok', $run->status);
        $this->assertSame(2, $run->docs_count);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(2, PingwinSupplierDocument::count());
    }

    public function test_run_failed_nao_toca_nos_documentos(): void
    {
        $this->seedDocs();
        $this->fetch = fn () => throw new \RuntimeException('Documentos de fornecedor PingWin falharam: HTTP 500');

        $run = $this->service()->createRun($this->resto->id, '2026-10-01', '2026-10-07', 'nightly');
        $run = $this->service()->executeRun($run->id);

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('HTTP 500', $run->error);
        $this->assertSame(4, PingwinSupplierDocument::count());
    }

    public function test_bloqueia_run_duplicado_e_liberta_quando_termina_ou_expira(): void
    {
        $this->fetch = fn () => ['documents' => [], 'blocks' => 1, 'splits' => 0];
        $first = $this->service()->createRun($this->resto->id, '2026-10-01', '2026-10-07', 'manual');

        try {
            $this->service()->createRun($this->resto->id, '2026-10-01', '2026-10-07', 'manual');
            $this->fail('devia bloquear');
        } catch (SupplierDocumentsSyncInProgress $e) {
            $this->assertSame($first->id, $e->run->id);
        }

        // A outra empresa não é afetada.
        $this->assertSame('queued', $this->service()->createRun($this->other->id, '2026-10-01', '2026-10-07', 'manual')->status);

        // Termina → liberta.
        $this->service()->executeRun($first->id);
        $second = $this->service()->createRun($this->resto->id, '2026-10-01', '2026-10-07', 'manual');

        // Fica preso (sem conclusão em 30 min) → expira e liberta.
        $this->travel(31)->minutes();
        $third = $this->service()->createRun($this->resto->id, '2026-10-01', '2026-10-07', 'manual');
        $this->assertSame('failed', $second->fresh()->status);
        $this->assertStringContainsString('Expirou', $second->fresh()->error);
        $this->assertSame('queued', $third->status);
    }

    public function test_execute_e_idempotente(): void
    {
        $calls = 0;
        $this->fetch = function () use (&$calls) { $calls++; return ['documents' => [], 'blocks' => 1, 'splits' => 0]; };
        $run = $this->service()->createRun($this->resto->id, '2026-10-05', '2026-10-05', 'manual');
        $this->service()->executeRun($run->id);
        $this->service()->executeRun($run->id);

        $this->assertSame(1, $calls);
    }

    // ── API ─────────────────────────────────────────────────────────────────

    private function url(string $suffix = '', ?int $company = null): string
    {
        return '/api/v1/companies/' . ($company ?? $this->resto->id) . '/integrations/pingwin/supplier-documents' . $suffix;
    }

    public function test_api_lista_com_filtros(): void
    {
        $this->seedDocs();
        $get = fn (string $qs) => $this->actingAs($this->user, 'sanctum')->getJson($this->url() . $qs)->assertOk()->json('data');

        $docs = fn ($d) => array_column($d['documents']['data'], 'document');
        $this->assertSame(['ENCF X/1', 'VFT BOVFT/1020', 'VFT BOVFT/900', 'VNC BOVNC/77'], $this->sorted($docs($get(''))));
        $this->assertSame(['VFT BOVFT/1020', 'VNC BOVNC/77'], $this->sorted($docs($get('?from=2026-10-05&to=2026-10-05'))));
        $this->assertSame(['VFT BOVFT/1020', 'VFT BOVFT/900', 'VNC BOVNC/77'], $this->sorted($docs($get('?types=1209,1205'))));
        $this->assertSame(['VFT BOVFT/900'], $docs($get('?supplier=999')));
        $this->assertSame(['ENCF X/1', 'VFT BOVFT/900', 'VNC BOVNC/77'], $this->sorted($docs($get('?missing_ref=1'))));

        $d = $get('?from=2026-10-05&to=2026-10-05&types=1209');
        $row = $d['documents']['data'][0];
        $this->assertSame(1170.1, $row['total']);
        $this->assertSame('27731', $row['docreference_number']);
        $this->assertSame('Adriana Oliveira', $row['employee_name']);
        $this->assertSame(['1209', '584955579139752236', '1205', '1206'], $d['facets']['default_types']);
        $this->assertCount(3, $d['facets']['types']);
        $this->assertNull($d['last_run']);
    }

    private function sorted(array $a): array
    {
        sort($a);

        return $a;
    }

    public function test_api_sync_assincrono_409_e_estado_do_run(): void
    {
        Bus::fake([SyncPingwinSupplierDocumentsJob::class]);

        $res = $this->actingAs($this->user, 'sanctum')->postJson($this->url('/sync'), ['from' => '2026-10-01', 'to' => '2026-10-07'])
            ->assertStatus(202)->assertJsonPath('data.run.status', 'queued');
        $runId = $res->json('data.run.id');
        Bus::assertDispatched(SyncPingwinSupplierDocumentsJob::class, fn ($j) => $j->runId === $runId);

        $this->actingAs($this->user, 'sanctum')->postJson($this->url('/sync'), ['from' => '2026-10-01', 'to' => '2026-10-07'])
            ->assertStatus(409)->assertJsonPath('errors.run.id', $runId);

        $this->actingAs($this->user, 'sanctum')->getJson($this->url("/sync-runs/{$runId}"))
            ->assertOk()->assertJsonPath('data.run.status', 'queued')->assertJsonPath('data.run.start_date', '2026-10-01');

        $this->actingAs($this->user, 'sanctum')->getJson($this->url())->assertJsonPath('data.last_run.id', $runId);
    }

    public function test_api_valida_periodo(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson($this->url('/sync'), ['from' => '2026-10-07', 'to' => '2026-10-01'])->assertStatus(422);
        $this->actingAs($this->user, 'sanctum')->postJson($this->url('/sync'), ['from' => 'ontem'])->assertStatus(422);
    }

    public function test_api_tenancy_e_run_de_outra_empresa(): void
    {
        $run = $this->service()->createRun($this->resto->id, '2026-10-01', '2026-10-07', 'manual');

        $this->actingAs($this->otherUser, 'sanctum')->getJson($this->url())->assertStatus(403);
        $this->actingAs($this->otherUser, 'sanctum')->getJson($this->url("/sync-runs/{$run->id}", $this->other->id))->assertStatus(404);
    }

    // ── Despacho noturno ────────────────────────────────────────────────────

    public function test_despacho_noturno_cria_run_dos_ultimos_7_dias_e_salta_quem_esta_ocupado(): void
    {
        Bus::fake([SyncPingwinSupplierDocumentsJob::class]);
        $this->travelTo('2026-10-09 07:00:00');

        (new DispatchSupplierDocumentsSyncJob())->handle($this->service());

        $run = PingwinDocumentSyncRun::where('company_id', $this->resto->id)->first();
        $this->assertSame('nightly', $run->trigger);
        $this->assertSame('2026-10-02', $run->start_date->toDateString());
        $this->assertSame('2026-10-09', $run->end_date->toDateString());
        Bus::assertDispatchedTimes(SyncPingwinSupplierDocumentsJob::class, 1);

        (new DispatchSupplierDocumentsSyncJob())->handle($this->service()); // run anterior ainda queued
        $this->assertSame(1, PingwinDocumentSyncRun::where('company_id', $this->resto->id)->count());
    }
}
