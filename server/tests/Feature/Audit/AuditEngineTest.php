<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Audit\AuditEngine;
use App\Audit\AuditFindingData;
use App\Audit\AuditRuleBase;
use App\Audit\AuditRunService;
use App\Audit\AuditScope;
use App\Models\AuditFinding;
use App\Models\AuditRun;
use App\Models\Company;
use App\Models\PermissionProfile;
use App\Models\User;
use App\Services\CompanyModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** Regra de teste da restauração: devolve o que o teste lhe der (por empresa). */
final class FakeRestaurantRule extends AuditRuleBase
{
    /** @var array<int, list<AuditFindingData>> */
    public static array $items = [];

    public function code(): string
    {
        return 'teste.restauracao';
    }

    public function area(): string
    {
        return 'restauracao';
    }

    public function entityType(): string
    {
        return 'artigo';
    }

    public function title(): string
    {
        return 'Regra de teste';
    }

    public function screens(): array
    {
        return ['artigos'];
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        return self::$items[$companyId] ?? [];
    }
}

/** Regra de teste que rebenta: a corrida tem de continuar. */
final class FailingRestaurantRule extends AuditRuleBase
{
    public function code(): string
    {
        return 'teste.rebenta';
    }

    public function area(): string
    {
        return 'restauracao';
    }

    public function entityType(): string
    {
        return 'artigo';
    }

    public function title(): string
    {
        return 'Regra que falha';
    }

    public function screens(): array
    {
        return ['faturas'];
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        throw new RuntimeException('Rebentou a ler o espelho.');
    }
}

/** Regra de teste do automóvel (outra área, outra permissão). */
final class FakeCarRule extends AuditRuleBase
{
    public static array $items = [];

    public function code(): string
    {
        return 'teste.automovel';
    }

    public function area(): string
    {
        return 'automovel';
    }

    public function entityType(): string
    {
        return 'viatura';
    }

    public function title(): string
    {
        return 'Regra de teste (automóvel)';
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        return self::$items[$companyId] ?? [];
    }
}

/**
 * Fatia 2 — o motor da Auditoria: impressão digital (sem duplicados entre corridas), estados e
 * transições, Resolvido automático, Marcado como corrigido confirmado pela corrida seguinte, Ignorado
 * que reabre quando a evidência muda, Pareto, os euros só com financas.ver, a ACL por área, o
 * isolamento por empresa, uma corrida de cada vez e o pipeline (a falha de uma regra não pára as
 * outras).
 */
class AuditEngineTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auditoria.rules' => [FakeRestaurantRule::class, FailingRestaurantRule::class, FakeCarRule::class]]);
        FakeRestaurantRule::$items = [];
        FakeCarRule::$items = [];
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500009930', 'fiscal_name' => 'Yuko Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->company->id, 'restaurant');
        app(CompanyModuleService::class)->enable($this->company->id, 'stock');
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin', 'name' => 'Ana Admin']);
    }

    private function userWith(array $permissions, ?Company $company = null): User
    {
        $c = $company ?? $this->company;
        $p = PermissionProfile::create(['company_id' => $c->id, 'side' => 'cliente', 'name' => 'P ' . implode(',', $permissions), 'is_system' => false, 'is_suggestion' => false]);
        $p->syncPermissions($permissions);

        return User::factory()->create(['company_id' => $c->id, 'role' => 'user', 'profile_id' => $p->id]);
    }

    private static function item(string $id, ?int $impact = null, string $signature = 'a', array $evidence = [], ?int $weight = null, ?int $pending = null): AuditFindingData
    {
        return new AuditFindingData(
            entityType: 'artigo', entityId: $id, entityLabel: "Artigo {$id}", title: "Achado {$id}",
            why: 'Custa 1 280,64 € a mais.', suggestion: 'Corrija no PingWin.', evidence: $evidence ?: [
                'facts' => [['label' => 'Total', 'value' => 1280.64, 'format' => 'eur'], ['label' => 'Documento', 'value' => 'VFT BOVFT/1044']],
                'table' => ['columns' => [['key' => 'qty', 'label' => 'Qtd'], ['key' => 'total_cents', 'label' => 'Total', 'format' => 'cents'], ['key' => 'base_price', 'label' => 'Preço', 'format' => 'eur4']],
                    'rows' => [['qty' => 96, 'total_cents' => 128064, 'base_price' => 13.34]]],
            ],
            signature: $signature, impactCents: $impact, weightCents: $weight, fixUrl: "/restauracao/artigos/{$id}",
            refs: [['fornecedor', 'F1']], impactPendingCents: $pending,
        );
    }

    private function audit(string $area = 'restauracao', string $origin = 'central', ?int $companyId = null): AuditRun
    {
        return app(AuditRunService::class)->start($companyId ?? $this->company->id, $area, $origin)->fresh();
    }

    private function finding(string $entityId): AuditFinding
    {
        return AuditFinding::where('company_id', $this->company->id)->where('entity_id', $entityId)->firstOrFail();
    }

    private function url(string $tail = ''): string
    {
        return "/api/v1/companies/{$this->company->id}/audit{$tail}";
    }

    // ── Motor ────────────────────────────────────────────────────────────────

    public function test_fingerprint_keeps_one_finding_across_runs(): void
    {
        FakeRestaurantRule::$items[$this->company->id] = [self::item('A', 100), self::item('A', 100), self::item('B', 50)];
        $first = $this->audit();
        $this->assertSame(2, AuditFinding::where('rule', 'teste.restauracao')->count());   // o duplicado na mesma corrida conta uma vez
        $step = collect($first->steps)->firstWhere('rule', 'teste.restauracao');
        $this->assertSame([2, 2, 0], [$step['found'], $step['new'], $step['resolved']]);

        $second = $this->audit();
        $this->assertSame(2, AuditFinding::where('rule', 'teste.restauracao')->count());
        $this->assertSame(0, $second->new_count);
        $this->assertSame(1, $this->finding('A')->events()->count());                       // só o "Encontrado"
        $this->assertEqualsCanonicalizing([['artigo', 'A'], ['fornecedor', 'F1']],
            DB::table('audit_finding_refs')->where('finding_id', $this->finding('A')->id)->get()->map(fn ($r) => [$r->entity_type, $r->entity_id])->all());
    }

    public function test_resolved_automatically_and_reopened_when_it_comes_back(): void
    {
        FakeRestaurantRule::$items[$this->company->id] = [self::item('A'), self::item('B')];
        $this->audit();
        FakeRestaurantRule::$items[$this->company->id] = [self::item('B')];
        $run = $this->audit();
        $a = $this->finding('A');
        $this->assertSame('resolvido', $a->state);
        $this->assertNotNull($a->resolved_at);
        $this->assertSame(1, $run->resolved_count);
        $this->assertSame('Resolvido: a auditoria deixou de o encontrar.', $a->events()->latest('id')->first()->reason);

        FakeRestaurantRule::$items[$this->company->id] = [self::item('A'), self::item('B')];
        $this->audit();
        $this->assertSame('aberto', $this->finding('A')->state);
        $this->assertNull($this->finding('A')->resolved_at);
    }

    public function test_marked_fixed_is_confirmed_by_the_next_run(): void
    {
        FakeRestaurantRule::$items[$this->company->id] = [self::item('A')];
        $this->audit();
        $as = $this->actingAs($this->admin, 'sanctum');
        $as->postJson($this->url('/restauracao/findings/' . $this->finding('A')->id . '/state'), ['action' => 'corrigido'])->assertOk()
            ->assertJsonPath('data.finding.state', 'corrigido');

        $this->audit();   // ainda aparece → volta a Aberto, com a nota
        $a = $this->finding('A');
        $this->assertSame(['aberto', 'Ainda aparece'], [$a->state, $a->note]);

        $as->postJson($this->url("/restauracao/findings/{$a->id}/state"), ['action' => 'corrigido'])->assertOk();
        FakeRestaurantRule::$items[$this->company->id] = [];
        $this->audit();   // já não aparece → Resolvido
        $this->assertSame('resolvido', $this->finding('A')->state);
        $this->assertSame(['aberto', 'corrigido', 'aberto', 'corrigido', 'resolvido'], $this->finding('A')->events()->orderBy('id')->pluck('to_state')->all());
    }

    public function test_ignored_needs_a_reason_and_reopens_when_the_evidence_changes(): void
    {
        FakeRestaurantRule::$items[$this->company->id] = [self::item('A', 100, 'taxa 0')];
        $this->audit();
        $id = $this->finding('A')->id;
        $as = $this->actingAs($this->admin, 'sanctum');
        $as->postJson($this->url("/restauracao/findings/{$id}/state"), ['action' => 'ignorar'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $as->postJson($this->url("/restauracao/findings/{$id}/state"), ['action' => 'ignorar', 'reason' => 'Combinado com o contabilista.'])->assertOk();

        $this->audit();   // a mesma evidência: continua ignorado
        $this->assertSame('ignorado', $this->finding('A')->state);

        FakeRestaurantRule::$items[$this->company->id] = [self::item('A', 100, 'taxa 0 e 13')];
        $this->audit();   // a evidência mudou: reabre
        $a = $this->finding('A');
        $this->assertSame(['aberto', 'Os dados mudaram'], [$a->state, $a->note]);
    }

    public function test_state_transitions(): void
    {
        FakeRestaurantRule::$items[$this->company->id] = [self::item('A')];
        $this->audit();
        $id = $this->finding('A')->id;
        $as = $this->actingAs($this->admin, 'sanctum');
        $as->postJson($this->url("/restauracao/findings/{$id}/state"), ['action' => 'aceitar'])->assertOk()->assertJsonPath('data.finding.state', 'aceite');
        $as->postJson($this->url("/restauracao/findings/{$id}/state"), ['action' => 'aceitar'])->assertStatus(422);    // já está aceite
        $as->postJson($this->url("/restauracao/findings/{$id}/state"), ['action' => 'reabrir'])->assertOk()->assertJsonPath('data.finding.state', 'aberto');
        $as->postJson($this->url("/restauracao/findings/{$id}/state"), ['action' => 'resolver'])->assertStatus(422);    // Resolvido é só automático
        $as->postJson($this->url("/automovel/findings/{$id}/state"), ['action' => 'aceitar'])->assertNotFound();      // a rota de outra área
        $events = $this->finding('A')->events()->orderBy('id')->get();
        $this->assertSame(['aberto', 'aceite', 'aberto'], $events->pluck('to_state')->all());
        $this->assertSame($this->admin->id, $events[1]->user_id);

        FakeRestaurantRule::$items[$this->company->id] = [];
        $this->audit();
        $as->postJson($this->url("/restauracao/findings/{$id}/state"), ['action' => 'aceitar'])->assertStatus(422);
    }

    // ── Pipeline e corridas ──────────────────────────────────────────────────

    public function test_pipeline_progress_and_a_failing_rule_does_not_stop_the_others(): void
    {
        FakeRestaurantRule::$items[$this->company->id] = [self::item('A')];
        $run = $this->audit();
        $this->assertSame('done', $run->status);
        $this->assertSame(['teste.restauracao', 'teste.rebenta'], array_column($run->steps, 'rule'));
        [$ok, $failed] = $run->steps;
        $this->assertSame(['ok', 1, 1], [$ok['status'], $ok['found'], $ok['new']]);
        $this->assertArrayHasKey('ms', $ok);
        $this->assertSame('failed', $failed['status']);
        $this->assertSame('Rebentou a ler o espelho.', $failed['error']);
        $this->assertSame('Uma regra não conseguiu correr; as outras correram.', $run->error);
        $this->assertSame(1, $run->open_count);

        // Pelo botão do ecrã: só as regras desse ecrã (Artigos não corre a das Faturas).
        $screen = $this->audit('restauracao', 'artigos');
        $this->assertSame(['teste.restauracao'], $screen->rules);
        $this->assertSame('restauracao:artigos', $screen->scope_key);
    }

    public function test_one_run_at_a_time_per_company_and_scope(): void
    {
        AuditRun::create(['company_id' => $this->company->id, 'area' => 'restauracao', 'origin' => 'artigos', 'scope_key' => 'restauracao:artigos',
            'rules' => ['teste.restauracao'], 'status' => 'running', 'steps' => []]);
        $as = $this->actingAs($this->admin, 'sanctum');
        $as->postJson($this->url('/restauracao/runs'), ['origin' => 'artigos'])->assertStatus(409)->assertJsonPath('errors.run.status', 'running');
        $as->postJson($this->url('/restauracao/runs'), ['origin' => 'central'])->assertStatus(202);   // outro âmbito: pode
        $as->postJson($this->url('/restauracao/runs'), ['origin' => 'nada'])->assertStatus(422);

        // A que ficou parada (o worker morreu) é dada como falhada e deixa correr outra.
        AuditRun::where('scope_key', 'restauracao:artigos')->update(['updated_at' => now()->subHour()]);
        $as->postJson($this->url('/restauracao/runs'), ['origin' => 'artigos'])->assertStatus(202);
        $this->assertSame('failed', AuditRun::where('scope_key', 'restauracao:artigos')->orderBy('id')->first()->status);
    }

    public function test_the_engine_runs_a_queued_run_only_once(): void
    {
        FakeRestaurantRule::$items[$this->company->id] = [self::item('A')];
        $run = $this->audit();
        app(AuditEngine::class)->execute($run->fresh());   // já não está na fila: não faz nada
        $this->assertSame(1, $this->finding('A')->events()->count());
    }

    // ── Lista: Pareto, euros, ACL e empresas ─────────────────────────────────

    public function test_honest_pareto_money_first_then_pending_then_weight(): void
    {
        FakeRestaurantRule::$items[$this->company->id] = [
            self::item('peso_grande', null, 'a', [], 9_000_000),                 // só peso (vendas): por último, por muito que pese
            self::item('pequeno', 1000), self::item('grande', 7000), self::item('medio', 2000),
            self::item('pendente', null, 'a', [], null, 50_000),                // a confirmar: depois do dinheiro, fora da soma
            self::item('peso_pequeno', null, 'a', [], 500),
        ];
        $this->audit();
        $res = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('/findings'))->assertOk();
        $rows = $res->json('data.findings.data');
        $this->assertSame(['grande', 'medio', 'pequeno', 'pendente', 'peso_grande', 'peso_pequeno'], array_column($rows, 'entity_id'));
        $this->assertEquals([70.0, 90.0, 100.0, null, null, null], array_column($rows, 'pareto_pct'));   // só o impacto € conta
        $this->assertSame(2, $res->json('data.summary.pareto_n80'));            // 7000 + 2000 de 10000
        $this->assertSame(10000, $res->json('data.summary.pareto_total_cents'));
        $this->assertSame(50000, $res->json('data.summary.pending_total_cents'));
        $this->assertSame(3, $res->json('data.summary.with_impact'));
        $this->assertSame(50000, $rows[3]['impact_pending_cents']);
    }

    public function test_group_by_rule(): void
    {
        FakeRestaurantRule::$items[$this->company->id] = [self::item('A', 1000), self::item('B', 500), self::item('C', null, 'a', [], 300, 700)];
        FakeCarRule::$items[$this->company->id] = [new AuditFindingData('viatura', '80', '#80', 'Autocaravana sem categoria', '', '', weightCents: 9_000_000)];
        $this->audit();
        $this->audit('automovel');
        $groups = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('/findings/groups'))->assertOk()->json('data.groups');
        $this->assertSame(['teste.restauracao', 'teste.automovel'], array_column($groups, 'rule'));   // primeiro o dinheiro em jogo
        $this->assertSame([3, 1500, 700, 300, 'media'], [$groups[0]['count'], $groups[0]['impact_cents'], $groups[0]['pending_cents'], $groups[0]['weight_cents'], $groups[0]['severity']]);
        $this->assertSame('Regra de teste', $groups[0]['rule_title']);
        $cook = $this->userWith(['restauracao.ver']);
        $mine = $this->actingAs($cook, 'sanctum')->getJson($this->url('/findings/groups'))->assertOk()->json('data.groups');
        $this->assertSame(['teste.restauracao'], array_column($mine, 'rule'));   // só as áreas que vê
        $this->assertNull($mine[0]['impact_cents']);                              // e sem euros sem Finanças
        $this->assertSame(3, $mine[0]['count']);
    }

    public function test_euros_only_for_who_sees_finance(): void
    {
        FakeRestaurantRule::$items[$this->company->id] = [self::item('A', 122728)];
        $this->audit();
        $id = $this->finding('A')->id;

        $fin = $this->actingAs($this->admin, 'sanctum')->getJson($this->url("/findings/{$id}"))->assertOk();
        $this->assertSame(122728, $fin->json('data.finding.impact_cents'));
        $this->assertSame('Custa 1 280,64 € a mais.', $fin->json('data.finding.why'));
        $this->assertEquals(128064, $fin->json('data.finding.evidence.table.rows.0.total_cents'));

        $cook = $this->userWith(['restauracao.ver', 'restauracao.editar']);
        $no = $this->actingAs($cook, 'sanctum')->getJson($this->url("/findings/{$id}"))->assertOk();
        $this->assertNull($no->json('data.finding.impact_cents'));
        $this->assertFalse($no->json('data.financial.visible'));
        $this->assertStringNotContainsString('1 280,64', $no->json('data.finding.why'));
        $this->assertNull($no->json('data.finding.evidence.facts.0.value'));
        $this->assertTrue($no->json('data.finding.evidence.facts.0.hidden'));
        $this->assertSame('VFT BOVFT/1044', $no->json('data.finding.evidence.facts.1.value'));
        $this->assertNull($no->json('data.finding.evidence.table.rows.0.total_cents'));
        $this->assertNull($no->json('data.finding.evidence.table.rows.0.base_price'));
        $this->assertSame(96, $no->json('data.finding.evidence.table.rows.0.qty'));
        $list = $this->actingAs($cook, 'sanctum')->getJson($this->url('/findings'))->assertOk();
        $this->assertNull($list->json('data.findings.data.0.impact_cents'));
        $this->assertNull($list->json('data.findings.data.0.pareto_pct'));
        $this->assertNull($list->json('data.summary.pareto_total_cents'));
        $this->actingAs($cook, 'sanctum')->getJson($this->url('/findings?sort=impact'))->assertStatus(422);   // ordenar pelos euros também não
    }

    public function test_acl_by_area_and_company_isolation(): void
    {
        FakeRestaurantRule::$items[$this->company->id] = [self::item('A')];
        FakeCarRule::$items[$this->company->id] = [new AuditFindingData('viatura', '80', '#80', 'Autocaravana sem categoria', '', '')];
        $this->audit();
        $this->audit('automovel');
        $this->assertSame(2, AuditFinding::where('company_id', $this->company->id)->count());

        // Só a restauração: vê só os achados da restauração e não muda o estado sem "editar".
        $viewer = $this->userWith(['restauracao.ver']);
        $res = $this->actingAs($viewer, 'sanctum')->getJson($this->url('/findings'))->assertOk();
        $this->assertSame(['restauracao'], array_values(array_unique(array_column($res->json('data.findings.data'), 'area'))));
        $this->assertSame(['restauracao'], array_column($this->actingAs($viewer, 'sanctum')->getJson($this->url('/meta'))->json('data.areas'), 'key'));
        $car = AuditFinding::where('area', 'automovel')->first();
        $this->actingAs($viewer, 'sanctum')->getJson($this->url("/findings/{$car->id}"))->assertNotFound();
        $this->actingAs($viewer, 'sanctum')->postJson($this->url('/restauracao/findings/' . $this->finding('A')->id . '/state'), ['action' => 'aceitar'])->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->postJson($this->url('/automovel/runs'), ['origin' => 'central'])->assertForbidden();
        $this->assertFalse($this->actingAs($viewer, 'sanctum')->getJson($this->url('/findings/' . $this->finding('A')->id))->json('data.finding.can_edit'));
        $counts = $this->actingAs($viewer, 'sanctum')->getJson($this->url('/counts?entity_type=artigo'))->assertOk()->json('data.counts');
        $this->assertSame(['A' => ['open' => 1, 'severity' => 'media']], $counts);
        $this->assertSame([], (array) $this->actingAs($viewer, 'sanctum')->getJson($this->url('/counts?entity_type=viatura'))->json('data.counts'));

        // Sem nenhuma área: não vê nada (e o menu esconde a página).
        $nobody = $this->userWith(['empresa.ver']);
        $this->assertSame(0, $this->actingAs($nobody, 'sanctum')->getJson($this->url('/findings'))->json('data.summary.total'));
        $this->assertSame([], $this->actingAs($nobody, 'sanctum')->getJson($this->url('/meta'))->json('data.areas'));

        // Outra empresa: nem os achados, nem as corridas, nem pelo id.
        $other = Company::create(['nipc' => '503504564', 'fiscal_name' => 'Outra', 'plan_id' => DB::table('plans')->value('id'), 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($other->id, 'restaurant');
        $stranger = User::factory()->create(['company_id' => $other->id, 'role' => 'admin']);
        $this->actingAs($stranger, 'sanctum')->getJson($this->url('/findings'))->assertForbidden();
        $own = "/api/v1/companies/{$other->id}/audit";
        $this->assertSame(0, $this->actingAs($stranger, 'sanctum')->getJson("{$own}/findings")->json('data.summary.total'));
        $this->actingAs($stranger, 'sanctum')->getJson("{$own}/findings/" . $this->finding('A')->id)->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->postJson("{$own}/restauracao/findings/" . $this->finding('A')->id . '/state', ['action' => 'aceitar'])->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->getJson("{$own}/runs/" . AuditRun::where('company_id', $this->company->id)->value('id'))->assertNotFound();
        $this->assertSame('aberto', $this->finding('A')->state);
    }
}
