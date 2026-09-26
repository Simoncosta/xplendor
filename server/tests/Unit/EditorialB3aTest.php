<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Company;
use App\Models\ContentAnchor;
use App\Models\ContentSector;
use App\Models\EditorialOwnAnchor;
use App\Services\EditorialAnchorResolver;
use App\Services\EditorialLineService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * XPLENDOR — Linha Editorial (B3a): esconder HERDADAS por ocorrência + criar/apagar
 * PRÓPRIAS. Regra transversal: tudo só em mês ABERTO (senão 422). Dois espaços de id
 * distintos (content_anchors p/ hide/show ; editorial_own_anchors p/ delete) — não misturar.
 * Tempo congelado a 15/Dez/2026 → janela Dez/2026..Nov/2027 (inclui Ano Novo 2027).
 */
class EditorialB3aTest extends TestCase
{
    use RefreshDatabase;

    private EditorialLineService $service;
    private Company $company;
    private ContentAnchor $anoNovo; // fiável, fixa 1/1 (herdada de Universal)

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-15 12:00:00', 'Europe/Lisbon'));

        $this->service = new EditorialLineService(new EditorialAnchorResolver());
        $this->company = $this->makeCompany('restauracao');
        $this->anoNovo = ContentAnchor::where('rule_type', 'fixa')->where('month', 1)->where('day', 1)->firstOrFail();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function makeCompany(string $sectorSlug): Company
    {
        $planId = DB::table('plans')->insertGetId([
            'name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sector = ContentSector::where('slug', $sectorSlug)->firstOrFail();

        return Company::create([
            'nipc' => (string) random_int(500000000, 599999999),
            'fiscal_name' => 'C Lda', 'plan_id' => $planId, 'content_sector_id' => $sector->id,
        ]);
    }

    /** Encontra um item do calendário por título + ano da ocorrência. */
    private function item(array $cal, string $title, int $occYear): ?array
    {
        foreach ($cal['items'] as $it) {
            if ($it['title'] === $title && $it['occ_year'] === $occYear) {
                return $it;
            }
        }
        return null;
    }

    public function test_esconder_e_mostrar_herdada_em_mes_aberto(): void
    {
        $this->service->openMonth($this->company, 2026, 12);
        $this->service->openMonth($this->company, 2027, 1); // Ano Novo 2027 vive aqui

        $cal = $this->service->hideAnchorOccurrence($this->company, $this->anoNovo->id, 2027);
        $this->assertTrue($this->item($cal, 'Ano Novo', 2027)['hidden']); // não some — vem esbatida

        $cal = $this->service->showAnchorOccurrence($this->company, $this->anoNovo->id, 2027);
        $this->assertFalse($this->item($cal, 'Ano Novo', 2027)['hidden']);
    }

    public function test_esconder_e_por_ocorrencia_nao_afeta_outro_ano(): void
    {
        $this->service->openMonth($this->company, 2026, 12);
        $this->service->openMonth($this->company, 2027, 1);

        $this->service->hideAnchorOccurrence($this->company, $this->anoNovo->id, 2027);

        // A chave é (anchor, ano): 2027 escondido, 2028 intacto → em 2028 reaparece.
        $this->assertDatabaseHas('editorial_hidden_anchors', [
            'company_id' => $this->company->id, 'anchor_id' => $this->anoNovo->id, 'occurrence_year' => 2027,
        ]);
        $this->assertDatabaseMissing('editorial_hidden_anchors', [
            'company_id' => $this->company->id, 'anchor_id' => $this->anoNovo->id, 'occurrence_year' => 2028,
        ]);
    }

    public function test_propria_carrega_o_gancho_suggestion(): void
    {
        $this->service->openMonth($this->company, 2026, 12);
        $cal = $this->service->createOwnAnchor($this->company, [
            'title' => 'Aniversário', 'rule_type' => 'fixa', 'month' => 12, 'day' => 20,
            'suggestion' => 'Bastidores da equipa; retrospetiva do ano',
        ]);
        $it = null;
        foreach ($cal['items'] as $x) { if ($x['title'] === 'Aniversário') $it = $x; }
        $this->assertNotNull($it);
        $this->assertSame('Bastidores da equipa; retrospetiva do ano', $it['suggestion']);
    }

    public function test_esconder_em_mes_bloqueado_falha(): void
    {
        $this->service->openMonth($this->company, 2026, 12); // Janeiro/2027 fica FECHADO

        $this->expectException(ValidationException::class);
        $this->service->hideAnchorOccurrence($this->company, $this->anoNovo->id, 2027);
    }

    public function test_criar_propria_fixa_aparece_e_apagar_some(): void
    {
        $this->service->openMonth($this->company, 2026, 12);

        $cal = $this->service->createOwnAnchor($this->company, [
            'title' => 'Aniversário da Empresa', 'rule_type' => 'fixa', 'month' => 12, 'day' => 20,
        ]);
        $ownId = $cal['own_anchor_id'];
        $it = $this->item($cal, 'Aniversário da Empresa', 2026);
        $this->assertNotNull($it);
        $this->assertTrue($it['owned']);
        $this->assertFalse($it['hidden']);

        $cal = $this->service->deleteOwnAnchor($this->company, $ownId);
        $this->assertNull($this->item($cal, 'Aniversário da Empresa', 2026));
    }

    public function test_criar_propria_em_mes_bloqueado_falha(): void
    {
        $this->service->openMonth($this->company, 2026, 12); // Jan/2027 fechado

        $this->expectException(ValidationException::class);
        // fixa 15/1 → cai em Jan/2027 (fechado) → recusa.
        $this->service->createOwnAnchor($this->company, [
            'title' => 'Fora de mês aberto', 'rule_type' => 'fixa', 'month' => 1, 'day' => 15,
        ]);
    }

    public function test_apagar_no_mes_bloqueado_falha(): void
    {
        // Cria própria em Dez (aberto), depois fecha Dez → apagar deve recusar (mês fechado).
        $this->service->openMonth($this->company, 2026, 12);
        $cal = $this->service->createOwnAnchor($this->company, [
            'title' => 'X', 'rule_type' => 'fixa', 'month' => 12, 'day' => 5,
        ]);
        $ownId = $cal['own_anchor_id'];
        $this->service->closeMonth($this->company, 2026, 12);

        $this->expectException(ValidationException::class);
        $this->service->deleteOwnAnchor($this->company, $ownId);
    }

    public function test_tenancy_nao_apaga_propria_de_outra_empresa(): void
    {
        $other = $this->makeCompany('carros');
        $this->service->openMonth($other, 2026, 12);
        $cal = $this->service->createOwnAnchor($other, [
            'title' => 'Da outra', 'rule_type' => 'fixa', 'month' => 12, 'day' => 10,
        ]);
        $otherOwnId = $cal['own_anchor_id'];

        $this->service->openMonth($this->company, 2026, 12);

        // A minha empresa não pode apagar a própria da outra.
        try {
            $this->service->deleteOwnAnchor($this->company, $otherOwnId);
            $this->fail('Devia recusar apagar própria de outra empresa.');
        } catch (ValidationException $e) {
            $this->assertDatabaseHas('editorial_own_anchors', ['id' => $otherOwnId]); // continua lá
        }
    }

    public function test_ids_nao_se_misturam_entre_espacos(): void
    {
        $this->service->openMonth($this->company, 2026, 12);

        // (a) id de HERDADA (content_anchors) no endpoint de apagar PRÓPRIA → não existe no espaço próprio.
        try {
            $this->service->deleteOwnAnchor($this->company, $this->anoNovo->id);
            $this->fail('deleteOwnAnchor não devia aceitar id de herdada.');
        } catch (ValidationException $e) {
            $this->assertDatabaseHas('content_anchors', ['id' => $this->anoNovo->id]); // herdada intacta
        }

        // (b) id de PRÓPRIA (fora do espaço content_anchors) no endpoint de esconder HERDADA.
        $own = EditorialOwnAnchor::create([
            'company_id' => $this->company->id, 'title' => 'P', 'rule_type' => 'fixa', 'month' => 12, 'day' => 3,
        ]);
        $ownOnlyId = $own->id + 100000; // id garantidamente inexistente em content_anchors
        $this->expectException(ValidationException::class);
        $this->service->hideAnchorOccurrence($this->company, $ownOnlyId, 2026);
    }
}
