<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\WritePingwinBomJob;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\PermissionProfile;
use App\Models\PingwinLocation;
use App\Models\PingwinProductBom;
use App\Models\PingwinProductBomLine;
use App\Models\PingwinUnit;
use App\Models\RecipeDraft;
use App\Models\RecipeWrite;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\PingwinService;
use App\Services\Restaurant\RecipeWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRecipes;
use Tests\TestCase;

/**
 * Fatia 4 — criar, editar e apagar fichas técnicas na PingWin: regras de versão (nova por omissão,
 * corrigir, data futura, loja sem ficha), o plano, a fila (uma ficha de cada vez), a concorrência
 * (espelho mudado → recusa), a releitura (não bate → falha registada, sem repetir), o histórico,
 * o rascunho, a guarda do teste real, a ACL e o isolamento por empresa.
 */
class RecipeWriteTest extends TestCase
{
    use BuildsRecipes;
    use RefreshDatabase;

    private Company $company;
    private User $admin;
    private const PROD = '1001';      // o artigo da ficha (Francesinha)
    private const V0 = 'BF0';
    /** @var list<array> pedidos ao "Python" */
    public array $calls = [];
    /** @var callable(array $payload): array */
    public $respond;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 16:00:00');
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500009930', 'fiscal_name' => 'Yuko Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->company->id, 'restaurant');
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'pingwin', 'status' => 'active', 'access_token' => 'x', 'config' => ['username' => 'op', 'database' => 'yuko']]);
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin', 'name' => 'Ana Admin']);
        $c = $this->company->id;
        PingwinLocation::create(['company_id' => $c, 'winrest_store_id' => 'S1', 'display_name' => 'Baixa', 'is_active' => true, 'opened_on' => '2026-01-10']);
        PingwinLocation::create(['company_id' => $c, 'winrest_store_id' => 'S2', 'display_name' => 'Costa', 'is_active' => true, 'opened_on' => '2026-01-10']);
        foreach ([['11001', 'Unidade', null, 1], ['11002', 'Quilograma', null, 1], ['GRD24', 'Grade 24', '11001', 24], ['SACO5', 'Saco 5kg', '11002', 5]] as [$id, $d, $p, $v]) {
            PingwinUnit::create(['company_id' => $c, 'pingwin_id' => $id, 'description' => $d, 'parent_pingwin_id' => $p, 'unit_value' => $v]);
        }
        $this->catalog($c, self::PROD, ['description' => 'Francesinha', 'has_bom' => true]);
        $this->catalog($c, '2001', ['description' => 'BIFE', 'purchaseunit' => 'Quilograma']);
        $this->catalog($c, '2002', ['description' => 'PAO', 'purchaseunit' => 'Unidade']);
        $this->catalog($c, '2003', ['description' => 'QUEIJO', 'purchaseunit' => 'Quilograma']);
        $this->persistBom($c, $this->bom(self::PROD, [[ '00000000', self::V0, '2026-05-26', ['S1' => true, 'S2' => true], [
            $this->bomLine('2001', 0.15, ['unit_id' => '11002', 'unit_desc' => 'Quilograma', 'unit_cost' => 13, 'cost' => 1.95]),
            $this->bomLine('2002', 1, ['unit_id' => '11001']),
        ]]]));

        $this->respond = fn (array $p) => $this->okResult($p);
        $test = $this;
        $this->app->instance(PingwinService::class, new class($test) extends PingwinService {
            public function __construct(private $test) {}

            protected function invokeWithProgress(array $payload, callable $onProgress): array
            {
                $this->test->calls[] = $payload;
                foreach ([['step' => 'abrir'], ['step' => 'ler'], ['step' => 'linhas', 'n' => 1, 'm' => 1], ['step' => 'gravar'], ['step' => 'confirmar']] as $s) {
                    $onProgress($s);
                }

                return ($this->test->respond)($payload);
            }
        });
    }

    // ── auxiliares ───────────────────────────────────────────────────────────

    private function url(string $tail): string
    {
        return "/api/v1/companies/{$this->company->id}/restaurant/recipes/" . self::PROD . $tail;
    }

    private function signature(): string
    {
        return RecipeWriteService::signature((new RecipeWriteService($this->company->id))->snapshot(self::PROD));
    }

    private function userWith(array $permissions): User
    {
        $p = PermissionProfile::create(['company_id' => $this->company->id, 'side' => 'cliente', 'name' => 'P ' . implode(',', $permissions), 'is_system' => false, 'is_suggestion' => false]);
        $p->syncPermissions($permissions);

        return User::factory()->create(['company_id' => $this->company->id, 'role' => 'user', 'profile_id' => $p->id]);
    }

    private function draft(array $over = []): array
    {
        return array_merge([
            'mode' => 'nova_versao', 'valid_from' => '2026-10-15', 'stores' => ['S1' => true, 'S2' => true],
            'lines' => [
                ['component_id' => '2001', 'unit_id' => '11002', 'qnt' => 0.18, 'waste' => 5],
                ['component_id' => '2003', 'unit_id' => 'SACO5', 'qnt' => 0.02, 'waste' => 0],
            ],
            'base_signature' => $this->signature(),
        ], $over);
    }

    /** A releitura da PingWin com a versão pedida (ou a nova) tal como o pedido. */
    private function okResult(array $payload, array $tweak = []): array
    {
        $ops = $payload['plan']['ops'];
        $versions = [];
        foreach ($payload['plan']['expected']['versions'] as $v) {
            $versions[$v['id']] = ['id' => $v['id'], 'key' => $v['key'], 'valid_from' => $v['valid_from'], 'deleted' => 0, 'stores' => array_map(fn ($s) => ['store_id' => $s['store_id'], 'store_code' => $s['store_id'], 'store_name' => $s['name'], 'active' => $s['active']], $v['stores']),
                'lines' => ['' => array_map(fn ($l) => ['key' => $l['key'], 'productbom_id' => $v['id'], 'component_id' => $l['component_id'], 'component_code' => null, 'component_description' => $l['description'], 'unit_id' => $l['unit_id'], 'unit_desc' => null, 'qnt' => $l['qnt'], 'qnt_base' => $l['qnt'], 'waste' => $l['waste'], 'recovery' => $l['recovery'], 'unit_cost' => 0, 'cost' => 0, 'isbom' => 0, 'status' => 1], $v['lines'])]];
        }
        $refs = [];
        foreach ($ops as $op) {
            $vid = str_starts_with((string) ($op['version'] ?? ''), 'ref:') ? 'BNEW' : ($op['version'] ?? null);
            if ($op['op'] === 'new_version') {
                $refs['new'] = ['id' => 'BNEW', 'key' => '00000001'];
                $versions['BNEW'] = ['id' => 'BNEW', 'key' => '00000001', 'valid_from' => $op['valid_from'], 'deleted' => 0, 'lines' => ['' => []],
                    'stores' => array_map(fn ($s) => ['store_id' => $s, 'store_code' => $s, 'store_name' => $s, 'active' => true], ['S1', 'S2'])];
            } elseif ($op['op'] === 'add_line') {
                $versions[$vid]['lines'][''][] = ['key' => sprintf('%08d', count($versions[$vid]['lines'][''])), 'productbom_id' => $vid, 'component_id' => $op['component_id'], 'component_code' => null,
                    'component_description' => $op['description'], 'unit_id' => $op['unit_id'], 'unit_desc' => null, 'qnt' => $op['qnt'], 'qnt_base' => $op['qnt'], 'waste' => $op['waste'], 'recovery' => $op['recovery'], 'unit_cost' => 0, 'cost' => 0, 'isbom' => 0, 'status' => 1];
            } elseif ($op['op'] === 'edit_line') {
                foreach ($versions[$vid]['lines'][''] as &$l) {
                    if ($l['key'] === $op['key']) {
                        $l = array_merge($l, ['component_id' => $op['component_id'], 'unit_id' => $op['unit_id'], 'qnt' => $op['qnt'], 'waste' => $op['waste'], 'recovery' => $op['recovery']]);
                    }
                }
                unset($l);
            } elseif ($op['op'] === 'delete_line') {
                $versions[$vid]['lines'][''] = array_values(array_filter($versions[$vid]['lines'][''], fn ($l) => $l['key'] !== $op['key']));
            } elseif ($op['op'] === 'set_stores') {
                foreach ($versions[$vid]['stores'] as &$s) {
                    $s['active'] = (bool) ($op['stores'][$s['store_id']] ?? $s['active']);
                }
                unset($s);
            } elseif ($op['op'] === 'delete_version') {
                unset($versions[$op['version']]);
            }
        }
        $reread = $this->bom(self::PROD, []) + [];
        $reread['versions'] = array_values($versions);
        foreach ($tweak as $k => $v) {
            data_set($reread, $k, $v);
        }

        return ['ok' => true, 'mode' => 'bom_write', 'saved' => true, 'refs' => $refs, 'reread' => $reread];
    }

    // ── Regras de versão e plano ─────────────────────────────────────────────

    public function test_new_version_is_the_default_plan_and_stores_only_when_one_is_left_out(): void
    {
        $svc = new RecipeWriteService($this->company->id);
        $snap = $svc->snapshot(self::PROD);
        $plan = $svc->plan($snap, $this->draft());
        $this->assertSame(['new_version', 'add_line', 'add_line'], array_column($plan['ops'], 'op'));
        $this->assertSame(['2026-10-15', 'new'], [$plan['ops'][0]['valid_from'], $plan['ops'][0]['ref']]);
        $this->assertSame('ref:new', $plan['ops'][1]['version']);

        $plan = $svc->plan($snap, $this->draft(['stores' => ['S1' => true, 'S2' => false]]));
        $this->assertSame(['op' => 'set_stores', 'version' => 'ref:new', 'stores' => ['S1' => true, 'S2' => false]], end($plan['ops']));
    }

    public function test_correct_version_edits_adds_and_deletes_from_the_last_key(): void
    {
        $svc = new RecipeWriteService($this->company->id);
        $snap = $svc->snapshot(self::PROD);
        $plan = $svc->plan($snap, ['mode' => 'corrigir', 'bom_id' => self::V0, 'stores' => ['S1' => true, 'S2' => false], 'lines' => [
            ['key' => '00000000', 'component_id' => '2001', 'unit_id' => '11002', 'qnt' => 0.2, 'waste' => 5],   // editada
            ['component_id' => '2003', 'unit_id' => '11002', 'qnt' => 0.1, 'waste' => 0],                        // nova
        ]]);                                                                                                       // a 00000001 sai
        $this->assertSame(['edit_line', 'add_line', 'delete_line', 'set_stores'], array_column($plan['ops'], 'op'));
        $this->assertSame(['00000000', '00000001'], [$plan['ops'][0]['key'], $plan['ops'][2]['key']]);
        $this->assertSame(self::V0, $plan['target']);

        $nothing = $svc->plan($snap, ['mode' => 'corrigir', 'bom_id' => self::V0, 'stores' => ['S1' => true, 'S2' => true], 'lines' => $snap['versions'][0]['lines']]);
        $this->assertSame([], $nothing['ops']);
    }

    public function test_date_today_or_future_and_store_without_bom_in_the_preview(): void
    {
        $as = $this->actingAs($this->admin, 'sanctum');
        $as->postJson($this->url('/write'), $this->draft(['valid_from' => '2026-10-14']))->assertStatus(422)->assertJsonFragment(['message' => 'A data da versão nova tem de ser hoje ou uma data futura.']);
        Bus::fake();
        $as->postJson($this->url('/write'), $this->draft(['valid_from' => '2026-11-01']))->assertOk();

        // Corrigir a única versão tirando a Costa: a Costa fica sem ficha em vigor.
        $p = $as->postJson($this->url('/preview'), ['mode' => 'corrigir', 'bom_id' => self::V0, 'stores' => ['S1' => true, 'S2' => false],
            'lines' => [['key' => '00000000', 'component_id' => '2001', 'unit_id' => '11002', 'qnt' => 0.15]]])->assertOk()->json('data');
        $costa = collect($p['store_effects'])->firstWhere('store_id', 'S2');
        $this->assertSame([true, true, 'Costa'], [$costa['loses_bom'], $costa['without_bom'], $costa['name']]);
        // A unidade "Saco 5kg" vale 5 kg: a quantidade base da linha é 0,1 kg.
        $p = $as->postJson($this->url('/preview'), $this->draft())->json('data');
        $queijo = collect($p['lines'])->firstWhere('component_id', '2003');
        $this->assertEqualsWithDelta(0.1, $queijo['qnt_base'], 1e-9);
        $this->assertContains('SACO5', array_column($queijo['units'], 'id'));
        $this->assertNotContains('GRD24', array_column($queijo['units'], 'id'));   // outra família
    }

    // ── Gravar: fila, releitura, histórico ───────────────────────────────────

    public function test_write_queues_one_job_and_the_job_confirms_by_rereading(): void
    {
        RecipeDraft::create(['company_id' => $this->company->id, 'product_pingwin_id' => self::PROD, 'user_id' => $this->admin->id, 'payload' => ['x' => 1], 'base_signature' => 's']);
        Bus::fake();
        $res = $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/write'), $this->draft())->assertOk()->json('data.write');
        $this->assertSame(['nova_versao', 'queued', 3], [$res['operation'], $res['status'], $res['ops']]);
        Bus::assertDispatched(WritePingwinBomJob::class, 1);
        // Uma ficha de cada vez: com uma gravação na fila, outra é recusada.
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/write'), $this->draft())->assertStatus(409);

        (new WritePingwinBomJob($res['id']))->handle(app(PingwinService::class), app(\App\Services\PingwinBomSyncService::class));
        $w = RecipeWrite::find($res['id']);
        $this->assertSame('ok', $w->status, json_encode($w->result));
        $this->assertSame('BNEW', $w->bom_pingwin_id);
        $this->assertSame(['abrir', 'ler', 'linhas', 'gravar', 'confirmar'], array_column($w->progress['steps'], 'step'));
        $this->assertSame('bom_write', $this->calls[0]['mode']);
        $this->assertSame(self::PROD, $this->calls[0]['product_id']);
        // O espelho deste artigo passou a ter a versão nova; o rascunho foi-se.
        $this->assertTrue(PingwinProductBom::where('bom_pingwin_id', 'BNEW')->exists());
        $this->assertSame(2, PingwinProductBomLine::where('bom_pingwin_id', 'BNEW')->count());
        $this->assertSame(0, RecipeDraft::count());

        $h = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('/history'))->assertOk()->json('data.history.0');
        $this->assertSame(['nova_versao', 'ok', 'Ana Admin', '2026-10-15'], [$h['operation'], $h['status'], $h['user'], $h['valid_from']]);
        $this->assertSame('BIFE', $h['names']['components']['2001']);
        $this->assertCount(2, $h['after']['lines']);
        $this->assertSame(self::V0, $h['before']['versions'][0]['id']);
    }

    public function test_reread_that_does_not_match_is_recorded_as_failed_and_not_repeated(): void
    {
        Bus::fake();
        $id = $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/write'), $this->draft())->json('data.write.id');
        // A PingWin gravou 0,19 em vez de 0,18.
        $this->respond = fn (array $p) => $this->okResult($p, ['versions.1.lines..0.qnt' => 0.19]);
        $job = new WritePingwinBomJob($id);
        $job->handle(app(PingwinService::class), app(\App\Services\PingwinBomSyncService::class));
        $w = RecipeWrite::find($id);
        $this->assertSame('mismatch', $w->status);
        $this->assertNotEmpty($w->result['diff']);
        $this->assertSame(1, $job->tries);
        // Correr outra vez não faz nada (só corre a partir de "queued").
        $job->handle(app(PingwinService::class), app(\App\Services\PingwinBomSyncService::class));
        $this->assertCount(1, $this->calls);
    }

    public function test_conflict_when_the_bom_changed_in_pingwin_and_when_the_mirror_changed(): void
    {
        // 1) O espelho mudou depois de abrir o editor: a assinatura já não bate → recusa logo.
        $old = $this->signature();
        PingwinProductBomLine::where('company_id', $this->company->id)->where('line_key', '00000001')->update(['qnt' => 2]);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/write'), $this->draft(['base_signature' => $old]))
            ->assertStatus(409)->assertJsonFragment(['message' => 'A ficha mudou desde que a abriu (outra gravação ou a leitura noturna): recarregue antes de gravar.']);

        // 2) A ficha mudou na PingWin depois da leitura: o Python pára sem gravar (conflict).
        Bus::fake();
        $id = $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/write'), $this->draft())->json('data.write.id');
        $this->respond = fn () => ['ok' => false, 'mode' => 'bom_write', 'conflict' => true, 'saved' => false, 'diff' => ['versão 2026-05-26: as linhas mudaram']];
        (new WritePingwinBomJob($id))->handle(app(PingwinService::class), app(\App\Services\PingwinBomSyncService::class));
        $w = RecipeWrite::find($id);
        $this->assertSame(['conflict', ['versão 2026-05-26: as linhas mudaram']], [$w->status, $w->result['diff']]);
        $this->assertFalse(PingwinProductBom::where('bom_pingwin_id', 'BNEW')->exists());
    }

    public function test_a_failure_is_recorded(): void
    {
        Bus::fake();
        $id = $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/write'), $this->draft())->json('data.write.id');
        $this->respond = fn () => ['ok' => false, 'error' => 'RuntimeError: ficha técnica: EDIT productbom.prodbomdetail falhou (HTTP 500)'];
        (new WritePingwinBomJob($id))->handle(app(PingwinService::class), app(\App\Services\PingwinBomSyncService::class));
        $w = RecipeWrite::find($id);
        $this->assertSame('failed', $w->status);
        $this->assertStringContainsString('HTTP 500', $w->error);
    }

    public function test_delete_version_and_delete_all_with_double_confirmation(): void
    {
        Bus::fake();
        $as = $this->actingAs($this->admin, 'sanctum');
        $eff = $as->getJson($this->url('/delete-effects?bom_ids=' . self::V0))->assertOk()->json('data.store_effects');
        $this->assertSame([true, true], array_column($eff, 'loses_bom'));
        $as->postJson($this->url('/delete-all'), ['base_signature' => $this->signature(), 'confirm' => true])->assertStatus(422); // falta a 2.ª confirmação
        $w = $as->postJson($this->url('/delete-version'), ['bom_id' => self::V0, 'base_signature' => $this->signature(), 'confirm' => true])->assertOk()->json('data.write');
        $this->assertSame(['apagar_versao', 1], [$w['operation'], $w['ops']]);
        $plan = RecipeWrite::find($w['id'])->plan;
        $this->assertSame([['op' => 'delete_version', 'version' => self::V0]], $plan['ops']);

        (new WritePingwinBomJob($w['id']))->handle(app(PingwinService::class), app(\App\Services\PingwinBomSyncService::class));
        $this->assertSame('ok', RecipeWrite::find($w['id'])->status);
        $this->assertSame(0, PingwinProductBom::where('product_pingwin_id', self::PROD)->count());
    }

    public function test_draft_is_saved_per_person(): void
    {
        $as = $this->actingAs($this->admin, 'sanctum');
        $as->putJson($this->url('/draft'), ['payload' => ['mode' => 'nova_versao', 'lines' => []], 'base_signature' => 'abc'])->assertOk();
        $this->assertSame('abc', $as->getJson($this->url('/editor'))->json('data.draft.base_signature'));
        $this->assertNull($this->actingAs($this->userWith(['restauracao.ver', 'restauracao.editar']), 'sanctum')->getJson($this->url('/draft'))->json('data.draft'));
        $this->actingAs($this->admin, 'sanctum')->deleteJson($this->url('/draft'))->assertOk();
        $this->assertSame(0, RecipeDraft::count());
    }

    // ── Guarda, ACL e empresas ───────────────────────────────────────────────

    public function test_without_the_variable_there_is_no_guard_for_who_can_edit(): void
    {
        Bus::fake();
        // Por omissão (PINGWIN_BOM_WRITE_ALLOWLIST por definir): quem tem restauracao.editar grava a ficha de qualquer artigo da sua empresa.
        $this->assertSame('', config('services.pingwin.bom_write_allowlist'));
        $this->assertSame([], PingwinService::bomWriteAllowlist());
        $cook = $this->userWith(['restauracao.ver', 'restauracao.editar']);
        $this->assertTrue($this->actingAs($cook, 'sanctum')->getJson($this->url('/editor'))->json('data.write_allowed'));
        $this->actingAs($cook, 'sanctum')->postJson($this->url('/write'), $this->draft())->assertOk();
        $this->assertSame([], $this->calls);   // vai para a fila; o job leva allowed_product_ids vazio
        (new WritePingwinBomJob(RecipeWrite::value('id')))->handle(app(PingwinService::class), app(\App\Services\PingwinBomSyncService::class));
        $this->assertSame([], $this->calls[0]['allowed_product_ids']);
    }

    public function test_guard_only_allows_writing_the_authorised_article(): void
    {
        Bus::fake();
        config(['services.pingwin.bom_write_allowlist' => '999999']);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/write'), $this->draft())
            ->assertForbidden()->assertJsonFragment(['message' => 'Gravar fichas técnicas na PingWin só está autorizado no artigo de teste.']);
        $this->assertFalse($this->actingAs($this->admin, 'sanctum')->getJson($this->url('/editor'))->json('data.write_allowed'));
        config(['services.pingwin.bom_write_allowlist' => '0']);   // "0" = nenhum artigo (não é "vazio")
        $this->assertSame(['0'], PingwinService::bomWriteAllowlist());
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/write'), $this->draft())->assertForbidden();
        config(['services.pingwin.bom_write_allowlist' => '999999, ' . self::PROD]);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/write'), $this->draft())->assertOk();
        $this->assertSame(['999999', self::PROD], PingwinService::bomWriteAllowlist());
    }

    public function test_acl_and_company_isolation(): void
    {
        Bus::fake();
        $viewer = $this->userWith(['restauracao.ver', 'financas.ver']);
        $this->actingAs($viewer, 'sanctum')->postJson($this->url('/write'), $this->draft())->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->postJson($this->url('/delete-version'), ['bom_id' => self::V0, 'base_signature' => 'x', 'confirm' => true])->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->putJson($this->url('/draft'), ['payload' => []])->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->getJson($this->url('/history'))->assertOk();

        // Edita sem ver as Finanças: grava, mas a pré-visualização vem sem euros.
        $cook = $this->userWith(['restauracao.ver', 'restauracao.editar']);
        $p = json_encode($this->actingAs($cook, 'sanctum')->postJson($this->url('/preview'), $this->draft())->assertOk()->json('data'));
        $this->assertDoesNotMatchRegularExpression('/"[a-z_]+_eur":(?!null)/', $p);
        $this->actingAs($cook, 'sanctum')->postJson($this->url('/write'), $this->draft())->assertOk();
        $this->assertSame(0, RecipeWrite::where('status', '!=', 'queued')->count());

        // Outra empresa: não vê nem escreve nesta, nem com o id do artigo desta na dela.
        $other = Company::create(['nipc' => '503504564', 'fiscal_name' => 'Outra', 'plan_id' => DB::table('plans')->value('id'), 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($other->id, 'restaurant');
        $stranger = User::factory()->create(['company_id' => $other->id, 'role' => 'admin']);
        $this->actingAs($stranger, 'sanctum')->postJson($this->url('/write'), $this->draft())->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->getJson($this->url('/history'))->assertForbidden();
        $own = "/api/v1/companies/{$other->id}/restaurant/recipes/" . self::PROD;
        $this->actingAs($stranger, 'sanctum')->postJson("{$own}/write", $this->draft())->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->getJson("{$own}/editor")->assertNotFound();
        $wid = RecipeWrite::value('id');
        $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/companies/{$other->id}/restaurant/recipes/writes/{$wid}")->assertNotFound();
    }
}
