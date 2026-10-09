<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\WritePingwinSupplierJob;
use App\Models\Company;
use App\Models\PingwinPaymentCondition;
use App\Models\PingwinSupplier;
use App\Models\PingwinSupplierWrite;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\PingwinService;
use App\Services\PingwinSupplierWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — Fornecedores: ESCRITA no PingWin (FN). O Python é simulado (métodos do
 * PingwinService). Cobre: validação de NIF PT, guarda de NIF (espelho → 409; PingWin vivo →
 * "duplicado"; tax_number_count → "duplicado"), escritas ok/erro com confirmação por
 * releitura, espelho só após confirmar, idempotência, tenancy/gate e findOrCreateByNif.
 */
class PingwinSupplierWriteTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $other;
    private User $user;
    private User $otherUser;
    private PingwinSupplier $talho;
    /** @var array<string, mixed> respostas simuladas */
    public array $fake = [];
    public array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500013500', 'fiscal_name' => 'Resto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500013501', 'fiscal_name' => 'Outra', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->company->id, 'restaurant');
        app(CompanyModuleService::class)->applyPreset($this->other->id, 'restaurant');
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $this->otherUser = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);
        PingwinPaymentCondition::create(['company_id' => $this->company->id, 'pingwin_id' => '584955579139752253', 'code' => '1', 'description' => '30 dias', 'is_active' => true]);
        $this->talho = PingwinSupplier::create(['company_id' => $this->company->id, 'pingwin_id' => '584955579139647570', 'code' => '56',
            'name' => 'TALHO PIMENTA', 'tax_number' => '143209884', 'is_active' => true]);

        $test = $this;
        $this->app->instance(PingwinService::class, new class($test) extends PingwinService {
            public function __construct(private $t) {}
            public function createSupplier(int $c, array $f, bool $a = false): array { $this->t->calls[] = ['create', $f, $a]; return $this->t->fake['create']; }
            public function updateSupplier(int $c, string $id, array $f, bool $a = false): array { $this->t->calls[] = ['update', $id, $f]; return $this->t->fake['update']; }
            public function voidSupplier(int $c, string $id): array { $this->t->calls[] = ['void', $id]; return $this->t->fake['void']; }
            public function findSuppliersByNif(int $c, string $nif): array { $this->t->calls[] = ['find', $nif]; return $this->t->fake['find'] ?? ['active' => [], 'voided' => []]; }
        });
    }

    private function confirm(array $over = []): array
    {
        return array_merge(['id' => '584955579139790999', 'code' => '197', 'description' => 'TESTE XPLENDOR A', 'fiscalname' => 'Teste Lda',
            'tax_number' => '123456789', 'deleted' => 0, 'issupplier' => 1, 'paycond_id' => '584955579139752253', 'supplier_deleted' => 0,
            'address' => 'Rua A, 1', 'postalcode' => '4200-232', 'postalcode_description' => 'Porto', 'country_id' => '30000', 'obs' => 'nota',
            'deleted_flags' => ['datasetclient' => [1], 'datasetemployee' => [1]]], $over);
    }

    private function url(string $suffix = '', ?int $company = null): string
    {
        return '/api/v1/companies/' . ($company ?? $this->company->id) . '/integrations/pingwin/suppliers' . $suffix;
    }

    private function service(): PingwinSupplierWriteService
    {
        return app(PingwinSupplierWriteService::class);
    }

    private const FORM = ['description' => 'TESTE XPLENDOR A', 'fiscalname' => 'Teste Lda', 'tax_number' => '123456789',
        'paycond_id' => '584955579139752253', 'address' => 'Rua A, 1', 'postalcode' => '4200-232',
        'postalcode_description' => 'Porto', 'country_id' => '30000', 'obs' => 'nota'];

    // ── NIF ─────────────────────────────────────────────────────────────────

    public function test_validacao_de_nif_portugues(): void
    {
        foreach (['123456789', '143209884', '999999990', '501442600'] as $ok) {
            $this->assertTrue(PingwinSupplierWriteService::isValidPortugueseNif($ok), $ok);
        }
        foreach (['123456780', '12345678', '1234567890', 'ABC123456', '', 'ES12345678'] as $bad) {
            $this->assertFalse(PingwinSupplierWriteService::isValidPortugueseNif($bad), $bad);
        }
    }

    public function test_api_nif_invalido_avisa_e_permite_forcar(): void
    {
        Bus::fake();
        $this->actingAs($this->user, 'sanctum')->postJson($this->url(), array_merge(self::FORM, ['tax_number' => 'ES12345678']))
            ->assertStatus(422)->assertJsonPath('errors.code', 'nif_invalido');
        $this->actingAs($this->user, 'sanctum')->postJson($this->url(), array_merge(self::FORM, ['tax_number' => 'ES12345678', 'allow_invalid_nif' => true]))
            ->assertStatus(202);
    }

    public function test_api_nif_duplicado_no_espelho_409_com_o_existente(): void
    {
        Bus::fake();
        $this->actingAs($this->user, 'sanctum')->postJson($this->url(), array_merge(self::FORM, ['tax_number' => '143209884']))
            ->assertStatus(409)->assertJsonPath('errors.code', 'nif_duplicado')->assertJsonPath('errors.existing.code', '56');
        Bus::assertNothingDispatched();

        $this->actingAs($this->user, 'sanctum')->postJson($this->url(), array_merge(self::FORM, ['tax_number' => '143209884', 'allow_duplicate_nif' => true]))
            ->assertStatus(202);
    }

    // ── API ─────────────────────────────────────────────────────────────────

    public function test_api_criar_202_regista_e_despacha(): void
    {
        Bus::fake();
        $res = $this->actingAs($this->user, 'sanctum')->postJson($this->url(), self::FORM)->assertStatus(202)->assertJsonPath('data.status', 'pendente');
        $w = PingwinSupplierWrite::find($res->json('data.write_id'));
        $this->assertSame('criar', $w->action);
        $this->assertSame(self::FORM, $w->fields);
        Bus::assertDispatched(WritePingwinSupplierJob::class, fn ($j) => $j->writeId === $w->id);

        $this->actingAs($this->user, 'sanctum')->getJson($this->url("/writes/{$w->id}"))->assertOk()->assertJsonPath('data.status', 'pendente');
    }

    public function test_api_validacoes_e_tenancy(): void
    {
        Bus::fake();
        $this->actingAs($this->user, 'sanctum')->postJson($this->url(), array_merge(self::FORM, ['description' => '   ']))->assertStatus(422);
        $this->actingAs($this->user, 'sanctum')->postJson($this->url(), array_merge(self::FORM, ['paycond_id' => '999']))->assertStatus(422);
        $this->actingAs($this->otherUser, 'sanctum')->postJson($this->url(), self::FORM)->assertStatus(403);
        $this->actingAs($this->user, 'sanctum')->putJson($this->url('/999999'), ['description' => 'x'])->assertStatus(404);

        $this->talho->update(['is_active' => false]);
        $this->actingAs($this->user, 'sanctum')->putJson($this->url("/{$this->talho->id}"), ['description' => 'x'])->assertStatus(422);
        $this->actingAs($this->user, 'sanctum')->deleteJson($this->url("/{$this->talho->id}"))->assertStatus(422);
    }

    public function test_api_editar_envia_so_os_campos_alterados(): void
    {
        Bus::fake();
        $res = $this->actingAs($this->user, 'sanctum')->putJson($this->url("/{$this->talho->id}"), ['description' => 'TALHO PIMENTA LDA', 'obs' => 'x'])
            ->assertStatus(202);
        $this->assertSame(['description' => 'TALHO PIMENTA LDA', 'obs' => 'x'], PingwinSupplierWrite::find($res->json('data.write_id'))->fields);
        // NIF inalterado não é tratado como duplicado do próprio.
        $this->actingAs($this->user, 'sanctum')->putJson($this->url("/{$this->talho->id}"), ['tax_number' => '143209884'])->assertStatus(202);
    }

    // ── Execução (worker) ───────────────────────────────────────────────────

    public function test_criar_ok_atualiza_o_espelho_so_apos_confirmar(): void
    {
        $this->fake['create'] = ['ok' => true, 'persisted' => true, 'pingwin_id' => '584955579139790999', 'code' => '197',
            'checks' => ['description' => true], 'confirm' => $this->confirm(), 'capture' => ['merge_payload' => ['big' => 1], 'save_http' => 200]];
        $w = PingwinSupplierWrite::create(['company_id' => $this->company->id, 'action' => 'criar', 'fields' => self::FORM, 'status' => 'pendente']);

        $w = $this->service()->execute($w->id);

        $this->assertSame('ok', $w->status);
        $this->assertSame([['find', '123456789'], ['create', self::FORM, false]], $this->calls);
        $s = PingwinSupplier::where('pingwin_id', '584955579139790999')->first();
        $this->assertSame(['197', 'TESTE XPLENDOR A', '123456789', '4200-232', 'Porto', '30000', '584955579139752253', 'nota', true],
            [$s->code, $s->name, $s->tax_number, $s->postal_code, $s->city, $s->country_pingwin_id, $s->paycond_pingwin_id, $s->obs, $s->is_active]);
        $this->assertSame($s->id, $w->supplier_id);
        $this->assertArrayNotHasKey('merge_payload', $w->result['capture']); // o payload grande não fica na auditoria
    }

    public function test_criar_nif_ja_existe_no_pingwin_vivo_fica_duplicado_sem_criar(): void
    {
        $this->fake['find'] = ['active' => [['id' => '584955579139647570', 'code' => '56', 'name' => 'TALHO PIMENTA', 'tax_number' => '123456789']], 'voided' => []];
        $w = PingwinSupplierWrite::create(['company_id' => $this->company->id, 'action' => 'criar', 'fields' => self::FORM, 'status' => 'pendente']);

        $w = $this->service()->execute($w->id);

        $this->assertSame('duplicado', $w->status);
        $this->assertSame([['find', '123456789']], $this->calls);       // nunca chegou a criar
        $this->assertSame('56', $w->result['existing'][0]['code']);
    }

    public function test_criar_tax_number_count_do_servidor_fica_duplicado(): void
    {
        $this->fake['create'] = ['ok' => false, 'persisted' => false, 'duplicate_nif' => true, 'aborted_before_commit' => true, 'error' => 'NIF já existe noutra entidade'];
        $w = PingwinSupplierWrite::create(['company_id' => $this->company->id, 'action' => 'criar', 'fields' => self::FORM, 'status' => 'pendente']);

        $this->assertSame('duplicado', $this->service()->execute($w->id)->status);
        $this->assertSame(1, PingwinSupplier::count()); // só o Talho
    }

    public function test_criar_nao_confirmado_fica_erro_e_espelho_intacto(): void
    {
        $this->fake['create'] = ['ok' => false, 'persisted' => false, 'error' => 'SAVE falhou (HTTP 500) — O campo Descrição não pode estar em branco!'];
        $w = PingwinSupplierWrite::create(['company_id' => $this->company->id, 'action' => 'criar', 'fields' => self::FORM, 'status' => 'pendente']);

        $w = $this->service()->execute($w->id);

        $this->assertSame('erro', $w->status);
        $this->assertStringContainsString('não pode estar em branco', $w->error_message);
        $this->assertSame(1, PingwinSupplier::count());
    }

    public function test_editar_ok_e_anular_ok_e_nao_confirmado(): void
    {
        $this->fake['update'] = ['ok' => true, 'persisted' => true, 'pingwin_id' => '584955579139647570',
            'confirm' => $this->confirm(['id' => '584955579139647570', 'code' => '56', 'description' => 'TALHO PIMENTA LDA', 'tax_number' => '143209884'])];
        $w = PingwinSupplierWrite::create(['company_id' => $this->company->id, 'action' => 'editar', 'supplier_id' => $this->talho->id,
            'pingwin_id' => '584955579139647570', 'fields' => ['description' => 'TALHO PIMENTA LDA'], 'status' => 'pendente']);
        $this->assertSame('ok', $this->service()->execute($w->id)->status);
        $this->assertSame([['update', '584955579139647570', ['description' => 'TALHO PIMENTA LDA']]], $this->calls); // NIF não mudou → sem pesquisa
        $this->assertSame('TALHO PIMENTA LDA', $this->talho->fresh()->name);

        $this->fake['void'] = ['ok' => false, 'voided_confirmed' => false, 'in_state0_ativos' => true];
        $v1 = PingwinSupplierWrite::create(['company_id' => $this->company->id, 'action' => 'anular', 'supplier_id' => $this->talho->id,
            'pingwin_id' => '584955579139647570', 'status' => 'pendente']);
        $this->assertSame('erro', $this->service()->execute($v1->id)->status);
        $this->assertTrue($this->talho->fresh()->is_active);

        $this->fake['void'] = ['ok' => true, 'voided_confirmed' => true, 'in_state0_ativos' => false, 'in_state1_anulados' => true];
        $v2 = PingwinSupplierWrite::create(['company_id' => $this->company->id, 'action' => 'anular', 'supplier_id' => $this->talho->id,
            'pingwin_id' => '584955579139647570', 'status' => 'pendente']);
        $this->assertSame('ok', $this->service()->execute($v2->id)->status);
        $this->assertFalse($this->talho->fresh()->is_active);
    }

    public function test_execucao_e_idempotente(): void
    {
        $w = PingwinSupplierWrite::create(['company_id' => $this->company->id, 'action' => 'criar', 'fields' => self::FORM, 'status' => 'ok']);
        $this->service()->execute($w->id);
        $this->assertSame([], $this->calls);
    }

    public function test_find_or_create_by_nif(): void
    {
        Bus::fake();
        $found = $this->service()->findOrCreateByNif($this->company->id, $this->user->id, ['tax_number' => '143209884', 'description' => 'x']);
        $this->assertSame($this->talho->id, $found['supplier']->id);
        $this->assertNull($found['write']);

        $new = $this->service()->findOrCreateByNif($this->company->id, $this->user->id, self::FORM);
        $this->assertNull($new['supplier']);
        $this->assertSame('criar', $new['write']->action);
        Bus::assertDispatched(WritePingwinSupplierJob::class);
    }
}
