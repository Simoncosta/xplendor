<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditRunService;
use App\Models\Alert;
use App\Models\AiRequest;
use App\Models\AuditFinding;
use App\Models\PingwinUnitWrite;
use App\Models\ProductUnitConversion;
use App\Models\ProductUnitProfile;
use App\Models\User;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsUnits;
use Tests\Concerns\FakesAi;
use Tests\TestCase;

/**
 * Auditoria v2 · o Guião de unidades pela API: a fila pelo Pareto, a vista (o que vimos, a proposta,
 * o que muda), "Confirmar" (perfil, conversões, achados corrigidos, euros resolvidos na corrida
 * seguinte), as três saídas do "Por identificar" (mais informação, IA simulada, administrador),
 * "Saltar" e "Não se aplica", a guarda do "Aplicar no PingWin" (UNIT_WRITE_PROVEN e a allowlist) e
 * o substituto do M2.
 */
class UnitGuideTest extends TestCase
{
    use BuildsUnits;
    use FakesAi;
    use RefreshDatabase;

    private User $admin;

    /** @var list<array> */
    public array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpUnits();
        $this->admin = User::factory()->create(['company_id' => $this->c, 'role' => 'admin', 'name' => 'Ana Gestora']);
        config(['auditoria.unit_write_proven' => false, 'services.pingwin.unit_write_allowlist' => '0', 'ai.fake' => false]);
        // A lata de 28 faturada como «Unidade» com fator 1 (U1), num artigo de bebidas com comparação.
        $this->peers(self::DRINKS, '11001', 'Unidade', 0.6);
        $this->art('9001', 'Refrigerante lata', self::DRINKS);
        $this->buy('9001', '2026-09-20', 8, 14400, 'REFRIGERANTE LATA 28X33CL');
        // O açúcar com descrições que se contradizem (U8).
        $this->art('9002', 'Açúcar');
        $this->buy('9002', '2026-09-20', 3, 3000, 'ACUCAR SAQUETAS');
        $this->buy('9002', '2026-09-21', 10, 900, 'ACUCAR 1KG', '11001', 'Unidade', 1.0, '500000002', 'Fornecedor B');
    }

    private function url(string $tail = ''): string
    {
        return "/api/v1/companies/{$this->c}/restaurant/units/guide{$tail}";
    }

    private function audit(): void
    {
        app(AuditRunService::class)->start($this->c, 'restauracao', 'unidades', $this->admin->id);
    }

    public function test_queue_is_by_pareto_and_the_view_has_seen_proposal_and_impact(): void
    {
        $q = $this->actingAs($this->admin, 'sanctum')->getJson($this->url())->assertOk()->json('data');
        $ids = array_column($q['items'], 'product_id');
        $this->assertSame('9001', $ids[0]); // 144 € em 90 dias, à frente do açúcar (39 €)
        $this->assertContains('9002', array_column(array_filter($q['items'], fn ($i) => $i['por_identificar']), 'product_id'));
        $this->assertNotContains('9001', array_column(array_filter($q['items'], fn ($i) => $i['por_identificar']), 'product_id'));

        $v = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('/9001'))->assertOk()->json('data');
        $this->assertSame('un', $v['proposal']['base']);
        $this->assertSame('1 Caixa 28 = 28 latas', $v['proposal']['purchase_units'][0]['label']);
        $this->assertStringContainsString('REFRIGERANTE LATA 28X33CL', $v['seen'][1]['text']);
        $this->assertSame(['faturas', 'faturas'], [$v['seen'][0]['source'], $v['seen'][1]['source']]);
        $this->assertSame('Comprado em 90 dias: 144 €. Desde setembro: 144 €.', $v['seen'][0]['text']);
        $this->assertContains(['source' => 'fichas', 'text' => 'Ainda não entra em nenhuma ficha: decidido só pelas faturas.'], $v['seen']);
        // A conclusão antes das evidências: a lata faturada como «Unidade» com fator 1 precisa de mudança.
        $this->assertSame(['mudar', 'Precisa de mudança.'], [$v['verdict']['key'], $v['verdict']['title']]);
        $this->assertSame('Lançar a Caixa 28 com fator 28 em vez de 1. E mais 1 mudança em baixo.', $v['verdict']['text']);
        $this->assertContains($v['confidence_label']['label'], ['Alta', 'Média', 'Baixa']);
        $this->assertSame('Disponível em breve: a escrita das unidades no PingWin ainda não foi provada neste ambiente.', $v['apply']['guard']['reason']);
        $this->assertNotEmpty($v['apply']['manual']);
    }

    public function test_confirm_saves_the_profile_and_the_conversion_fixes_the_findings_and_counts_the_resolved_euros(): void
    {
        $this->audit();
        $f = AuditFinding::where('company_id', $this->c)->where('rule', 'restauracao.unidade_embalagem_fator_1')->where('entity_id', '9001')->sole();
        $this->assertSame((int) round(144 * 27 / 28 * 100), $f->impact_cents);

        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/9001/confirm'), ['reason' => 'Visto na fatura.'])->assertOk()
            ->assertJsonPath('data.profile.state', 'confirmado');
        $p = ProductUnitProfile::where('company_id', $this->c)->where('product_pingwin_id', '9001')->sole();
        $this->assertSame(['un', 'embalado_inteiro', $this->admin->id], [$p->base_confirmed, $p->article_type, $p->confirmed_by]);
        $conv = ProductUnitConversion::where('company_id', $this->c)->where('product_pingwin_id', '9001')->where('purpose', 'compra')->sole();
        $this->assertSame([28.0, '500000001', 'confirmado', $p->id], [$conv->factor_to_base, $conv->supplier_nif, $conv->source, $conv->profile_id]);
        $this->assertSame(['confirmar'], $p->events()->pluck('action')->all());

        // "Marcado como corrigido" com o € no momento; a corrida seguinte (a do Confirmar) confirma-o.
        $f->refresh();
        $this->assertSame('resolvido', $f->state);
        $this->assertSame([$f->impact_cents, $this->admin->id], [$f->resolved_cents, $f->resolved_by]);
        $sum = $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/companies/{$this->c}/audit/summary?area=restauracao")->assertOk()->json('data');
        $this->assertSame($f->resolved_cents, $sum['month']['cents']);
        $this->assertSame('Ana Gestora', $sum['month']['by_person'][0]['label']);
        $this->assertSame('Embalagem na descrição, fator 1 no PingWin', $sum['total']['by_rule'][0]['label']);
    }

    public function test_confirm_is_refused_while_the_article_is_to_identify(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/9002/confirm'))->assertStatus(422);
        $this->assertSame(0, ProductUnitConversion::count());
    }

    public function test_to_identify_more_information_reevaluates_at_once(): void
    {
        Storage::fake('local');
        config(['storage_targets.private_disk' => 'local']);
        $v = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('/9002'))->assertOk()->json('data');
        $this->assertTrue($v['por_identificar']);
        $this->assertSame('forma', $v['proposal']['questions'][0]['id']);

        $v = $this->actingAs($this->admin, 'sanctum')->post($this->url('/9002/info'), [
            'text' => 'São caixas de 1000 saquetas.', 'answers' => ['forma' => 'doses'], 'photo' => UploadedFile::fake()->image('caixa.jpg', 400, 300),
        ], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertFalse($v['proposal']['conflicts'] !== []);
        $this->assertSame('Saqueta', $v['proposal']['base_name']);
        $this->assertTrue($v['profile']['has_photo']);
        $this->assertNotNull($v['profile']['photo_url']);
        $this->assertSame('São caixas de 1000 saquetas.', ProductUnitProfile::where('product_pingwin_id', '9002')->value('info_text'));
    }

    public function test_to_identify_ask_the_ai_validated_cached_and_always_to_confirm(): void
    {
        // Sem chave: o botão fica desativado com a explicação.
        config(['ai.providers.anthropic.key' => '', 'ai.providers.openai.key' => '']);
        $v = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('/9002'))->json('data');
        $this->assertFalse($v['ai']['available']);
        $this->assertStringContainsString('não está configurado', $v['ai']['reason']);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/9002/ai'))->assertStatus(422);

        $this->configureAi();
        $this->fakeAi(['tipo' => 'contavel', 'embalagem' => 'Caixa de 1000 saquetas', 'base' => 'un', 'nome_base' => 'Saqueta',
            'unidades_compra' => [['unidade' => 'Caixa 1000', 'fator' => 1000]], 'confianca' => 0.7, 'justificacao' => 'As saquetas vêm em caixas.']);
        $r = $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/9002/ai'));
        $this->assertSame(200, $r->status(), (string) $r->getContent());
        $v = $r->json('data');
        $s = $v['ai']['suggestion'];
        $this->assertSame(['contavel', 'Saqueta', 'Sugestão automática, a confirmar'], [$s['type'], $s['base_name'], $s['label']]);
        $this->assertNotSame('confirmado', ProductUnitProfile::where('product_pingwin_id', '9002')->value('state')); // nada passa a confirmado
        // Só dados do artigo: nem o nome nem o NIF do fornecedor.
        $prompt = $this->sentAiPrompt();
        $this->assertStringNotContainsString('Fornecedor B', $prompt);
        $this->assertStringNotContainsString('500000002', $prompt);
        // A mesma pergunta não volta à IA (cache).
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/9002/ai'))->assertOk();
        $this->assertSame(1, AiRequest::where('mode', 'unit_advisor')->count());
    }

    public function test_to_identify_send_to_the_administrator(): void
    {
        $this->audit();
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/9002/send-admin'), ['note' => 'Não sei como chega.'])->assertOk();
        $f = AuditFinding::where('company_id', $this->c)->where('rule', 'restauracao.unidade_por_identificar')->where('entity_id', '9002')->sole();
        $this->assertSame(['administrador', $this->admin->id], [$f->assigned_to, $f->assigned_by]);
        $alert = Alert::where('company_id', $this->c)->latest('id')->first();
        $this->assertStringContainsString('Artigo por identificar', $alert->title);
        $this->assertSame('/restauracao/auditoria/guiao?fila=administrador&artigo=9002', $alert->detail_path);
        $q = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('?fila=administrador'))->json('data');
        $this->assertSame(['9002'], array_column($q['items'], 'product_id')); // só o que foi enviado
    }

    public function test_skip_and_not_applicable(): void
    {
        $this->audit();
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/9001/skip'))->assertOk();
        $q = $this->actingAs($this->admin, 'sanctum')->getJson($this->url())->json('data');
        $mudar = array_values(array_filter($q['items'], fn ($i) => $i['verdict'] === 'mudar'));
        $this->assertSame('9001', end($mudar)['product_id']); // saltado: vai para o fim do seu grupo

        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/9002/not-applicable'))->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/9002/not-applicable'), ['reason' => 'É um artigo de oferta.'])->assertOk();
        $this->assertSame('nao_se_aplica', ProductUnitProfile::where('product_pingwin_id', '9002')->value('state'));
        $this->assertSame('ignorado', AuditFinding::where('rule', 'restauracao.unidade_por_identificar')->where('entity_id', '9002')->value('state'));
    }

    public function test_apply_in_pingwin_needs_the_proof_and_the_allowlist_one_change_at_a_time(): void
    {
        $test = $this;
        $this->app->instance(PingwinService::class, new class($test) extends PingwinService {
            public function __construct(private $test) {}

            protected function invoke(array $payload): array
            {
                return ['ok' => true, 'units' => []];
            }

            protected function invokeWithProgress(array $payload, callable $onProgress): array
            {
                $this->test->calls[] = $payload;
                $onProgress(['step' => 'ler', 'status' => 'ok']);
                $after = ['code' => 'P9001', 'base_unit_id' => '11001', 'default_purchase_unit_id' => $payload['op'] === 'set_purchase' ? $payload['data']['unit_id'] : null];

                return ['ok' => true, 'op' => $payload['op'], 'before' => ['code' => 'P9001', 'base_unit_id' => '11001'], 'after' => $after,
                    'unit' => ['id' => $payload['op'] === 'create' ? 'NEWU' : $payload['data']['unit_id']], 'note' => 'ok'];
            }
        });
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/9001/confirm'))->assertOk();

        // Sem a prova: "Disponível em breve".
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/9001/apply'), ['step' => 0, 'confirm' => true])->assertStatus(422)
            ->assertJsonFragment(['Disponível em breve: a escrita das unidades no PingWin ainda não foi provada neste ambiente.']);
        // Com a prova e a guarda "0": desligada.
        config(['auditoria.unit_write_proven' => true]);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/9001/apply'), ['step' => 0, 'confirm' => true])->assertStatus(422);
        $this->assertSame([], $this->calls);
        // Com a prova e o artigo na lista: um passo de cada vez, com releitura.
        config(['services.pingwin.unit_write_allowlist' => 'P9001']);
        $v = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('/9001'))->json('data');
        $this->assertSame(['create', 'set_purchase'], array_column($v['apply']['steps'], 'op'));
        // Regra 1: não há nenhuma unidade com 28 na Unidade: cria-se «Caixa 28 un» (nunca «Lata 28»).
        $this->assertEquals(['description' => 'Caixa 28 un', 'parent_qnt' => 28], $v['apply']['steps'][0]['data']);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/9001/apply'), ['step' => 1, 'confirm' => true])->assertStatus(422); // falta criar a unidade
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/9001/apply'), ['step' => 0, 'confirm' => true])->assertOk();
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/9001/apply'), ['step' => 1, 'confirm' => true])->assertOk();

        $this->assertSame(['create', 'set_purchase'], array_column($this->calls, 'op'));
        $this->assertSame(['P9001'], $this->calls[0]['allowed_codes']);
        $this->assertSame('NEWU', $this->calls[1]['data']['unit_id']);
        $this->assertSame(['ok', 'ok'], PingwinUnitWrite::orderBy('id')->pluck('status')->all());
        $this->assertSame('aplicado_no_pingwin', ProductUnitProfile::where('product_pingwin_id', '9001')->value('state'));
        // Nunca a unidade base.
        $this->assertArrayNotHasKey('base_unit_id', $this->calls[1]['data']);
    }

    public function test_the_plan_reuses_the_unit_that_exists_and_the_old_one_stops_being_used(): void
    {
        \App\Models\PingwinUnit::create(['company_id' => $this->c, 'pingwin_id' => 'CX28', 'description' => 'Caixa 28 un', 'parent_pingwin_id' => '11001', 'unit_value' => 28, 'is_active' => true]);
        \App\Models\PingwinCatalogItem::where('pingwin_id', '9001')->update(['default_purchase_unit_id' => 'CX6']);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/9001/confirm'))->assertOk();
        $v = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('/9001'))->json('data');
        $this->assertSame([['op' => 'set_purchase', 'unit_id' => 'CX28']], array_map(fn ($s) => ['op' => $s['op'], 'unit_id' => $s['data']['unit_id']], $v['apply']['steps']));
        $this->assertContains('Deixar de usar «Caixa 6 Unidades» neste artigo: é uma unidade de todos os artigos, por isso não se anula nem se altera.', $v['apply']['manual']);
        $this->assertNotContains('void', array_column($v['apply']['steps'], 'op'));
    }

    public function test_u9_create_the_right_unit_once_then_the_articles_one_by_one(): void
    {
        $test = $this;
        $this->app->instance(PingwinService::class, new class($test) extends PingwinService {
            public function __construct(private $test) {}

            protected function invoke(array $payload): array
            {
                return ['ok' => true, 'units' => []];
            }

            protected function invokeWithProgress(array $payload, callable $onProgress): array
            {
                $this->test->calls[] = $payload;

                return ['ok' => true, 'op' => $payload['op'], 'before' => ['code' => 'P9010', 'base_unit_id' => '11001'], 'after' => ['code' => 'P9010', 'base_unit_id' => '11001'],
                    'unit' => ['id' => 'NEW6'], 'note' => 'Criada.'];
            }
        });
        foreach (['9010', '9011'] as $pid) {
            $this->art($pid, "Artigo {$pid}");
            $this->buy($pid, '2026-09-20', 2, 1200, "ARTIGO {$pid}", 'CX6', 'Caixa 6 Unidades');
        }
        $v = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('/global/CX6'))->assertOk()->json('data');
        $this->assertSame(['Caixa 6 un', false, null], [$v['right']['name'], $v['right']['reuse'], $v['right']['status']]);
        $this->assertSame(['9010', '9011'], array_column($v['articles'], 'product_id'));
        $this->assertSame(2000, $v['impact_cents']);

        config(['auditoria.unit_write_proven' => true, 'services.pingwin.unit_write_allowlist' => 'P9010']);
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/global/CX6/apply'), ['confirm' => true])->assertOk();
        $this->assertSame([['create', ['description' => 'Caixa 6 un', 'parent_qnt' => 6]]], array_map(fn ($c) => [$c['op'], $c['data']], $this->calls));
        $v = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('/global/CX6'))->json('data');
        $this->assertSame(['ok', 'NEW6'], [$v['right']['status'], $v['right']['unit_id']]);
        // Uma vez só: não se cria outra.
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/global/CX6/apply'), ['confirm' => true])->assertStatus(422);
        $this->assertCount(1, $this->calls);
    }

    public function test_confirm_all_that_are_right_only_in_xplendor_and_undo(): void
    {
        $this->art('9020', 'Farinha', self::FOOD, '11002');
        $this->buy('9020', '2026-09-20', 25, 2000, 'FARINHA TIPO 65', '11002', 'Quilograma');
        $this->dish('9021', [['9020', 0.2, '11002', 'Quilograma']]);

        $q = $this->actingAs($this->admin, 'sanctum')->getJson($this->url())->json('data');
        $farinha = collect($q['items'])->firstWhere('product_id', '9020');
        $this->assertSame('certo', $farinha['verdict']);
        $this->assertSame('Compra-se ao kg e usa-se ao kg nas fichas.', $farinha['verdict_text']);
        $v = $this->actingAs($this->admin, 'sanctum')->getJson($this->url('/9020'))->json('data');
        $this->assertSame('Está certo: nada a mudar.', $v['verdict']['title']);
        $this->assertSame([], $v['changes']);
        // Os grupos: precisa de mudança, por identificar, está certo.
        $order = array_values(array_unique(array_column($q['items'], 'verdict')));
        $this->assertSame(array_values(array_intersect(['mudar', 'ajuda', 'certo'], $order)), $order);

        $r = $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/confirm-all'), ['product_ids' => ['9020', '9001'], 'confirm' => true])->assertOk()->json('data');
        $this->assertSame(['9020'], $r['confirmed']);
        $this->assertSame('9001', $r['skipped'][0]['product_id']); // precisa de mudança: fica de fora
        $p = ProductUnitProfile::where('product_pingwin_id', '9020')->sole();
        $this->assertSame('confirmado', $p->state);
        $this->assertSame($r['batch'], $p->events()->where('action', 'confirmar')->first()->after['batch']);

        $this->actingAs($this->admin, 'sanctum')->postJson($this->url('/undo-batch'), ['batch' => $r['batch']])->assertOk()->assertJsonPath('data.undone', ['9020']);
        $p->refresh();
        $this->assertNotSame('confirmado', $p->state);
        $this->assertNull($p->confirmed_by);
        $this->assertSame(['desfazer', 'confirmar'], $p->events()->pluck('action')->all());
    }

    public function test_m2_substitute_with_the_cost_before_and_after(): void
    {
        $this->art('9003', 'Refrigerante zero garrafa', self::DRINKS);
        $this->art('9004', 'Refrigerante zero lata', self::DRINKS);
        $this->buy('9004', '2026-09-20', 48, 2880, 'REFRIGERANTE ZERO LATA');
        $this->dish('9005', [['9003', 1, '11001', 'Unidade']], 120);

        $s = $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/companies/{$this->c}/restaurant/recipes/9005/substitute?component=9003")->assertOk()->json('data');
        $this->assertTrue($s['found']);
        $c = $s['candidates'][0];
        $this->assertSame('9004', $c['product_id']);
        $this->assertEqualsWithDelta(0.6, $c['cost_after_eur'], 1e-6);   // 1 lata × 0,60 €
        $this->assertEqualsWithDelta(24.0, $c['monthly_after_eur'], 1e-6); // 120 vendas em 90 dias ÷ 3 × 0,60 €
        // Vai como linha nova (sem a chave): o PingWin não troca o componente de uma linha existente.
        $this->assertEquals(['key' => null, 'replaces_key' => '00000000', 'component_id' => '9004', 'unit_id' => '11001', 'qnt' => 1],
            array_intersect_key($c['line'], array_flip(['key', 'replaces_key', 'component_id', 'unit_id', 'qnt'])));
    }
}
