<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Company;
use App\Models\ContentAnchor;
use App\Models\ContentSector;
use App\Models\EditorialOwnAnchor;
use App\Models\EditorialPost;
use App\Services\EditorialAnchorResolver;
use App\Services\EditorialLineService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * XPLENDOR — Linha Editorial (B3b): TROCA de ramo (destrutiva). changeSector muda a
 * herança + reseta o planeamento do ramo velho (apaga editorial_months + hidden), mas
 * PRESERVA as âncoras próprias. setSector (1.ª escolha) e changeSector (troca) são
 * caminhos distintos que não se confundem. Tempo congelado a 15/Dez/2026.
 */
class EditorialB3bTest extends TestCase
{
    use RefreshDatabase;

    private EditorialLineService $service;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-15 12:00:00', 'Europe/Lisbon'));
        $this->service = new EditorialLineService(new EditorialAnchorResolver());
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function makeCompany(?string $sectorSlug): Company
    {
        $planId = DB::table('plans')->insertGetId([
            'name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sectorId = $sectorSlug ? ContentSector::where('slug', $sectorSlug)->firstOrFail()->id : null;

        return Company::create([
            'nipc' => (string) random_int(500000000, 599999999),
            'fiscal_name' => 'C Lda', 'plan_id' => $planId, 'content_sector_id' => $sectorId,
        ]);
    }

    public function test_troca_reseta_ramo_velho_mas_preserva_proprias(): void
    {
        $company = $this->makeCompany('restauracao');
        $restauracao = ContentSector::where('slug', 'restauracao')->firstOrFail();
        $carros = ContentSector::where('slug', 'carros')->firstOrFail();
        $anoNovo = ContentAnchor::where('rule_type', 'fixa')->where('month', 1)->where('day', 1)->firstOrFail();

        // Estado do ramo velho: mês aberto + 1 herdada escondida + 1 própria (aniversário).
        $this->service->openMonth($company, 2026, 12);
        $this->service->openMonth($company, 2027, 1);
        $this->service->hideAnchorOccurrence($company, $anoNovo->id, 2027);
        $own = $this->service->createOwnAnchor($company, [
            'title' => 'Aniversário da Empresa', 'rule_type' => 'fixa', 'month' => 12, 'day' => 20,
        ]);
        $ownId = $own['own_anchor_id'];

        // P1: uma publicação em Dezembro (mês aberto) — deve sobreviver à troca.
        $post = EditorialPost::create([
            'company_id' => $company->id, 'publish_date' => '2026-12-24',
            'title' => 'Post de Natal', 'format' => 'Carrossel', 'status' => 'rascunho', 'channel' => 'instagram',
        ]);

        // TROCA para Carros.
        $cal = $this->service->changeSector($company, $carros->id);

        // content_sector_id mudou.
        $this->assertSame($carros->id, (int) $company->fresh()->content_sector_id);
        $this->assertSame($carros->id, $cal['sector']['id']);

        // Meses apagados (todos fechados por ausência de linha).
        $this->assertDatabaseCount('editorial_months', 0);
        foreach ($cal['months'] as $m) {
            $this->assertSame('closed', $m['state']);
        }

        // Escondidas apagadas.
        $this->assertDatabaseMissing('editorial_hidden_anchors', ['company_id' => $company->id]);

        // Própria PRESERVADA (linha na BD + aparece no calendar com owned:true).
        $this->assertDatabaseHas('editorial_own_anchors', ['id' => $ownId, 'company_id' => $company->id]);
        $found = false;
        foreach ($cal['items'] as $it) {
            if ($it['title'] === 'Aniversário da Empresa' && $it['owned'] === true) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'A âncora própria devia manter-se após a troca.');

        // P1: a publicação PRESERVA-SE (não depende do ramo; changeSector não lhe toca).
        $this->assertDatabaseHas('editorial_posts', ['id' => $post->id, 'company_id' => $company->id]);
    }

    public function test_troca_nao_afeta_outras_empresas(): void
    {
        $a = $this->makeCompany('restauracao');
        $b = $this->makeCompany('restauracao');
        $this->service->openMonth($b, 2026, 12); // planeamento da empresa B

        $carros = ContentSector::where('slug', 'carros')->firstOrFail();
        $this->service->changeSector($a, $carros->id);

        // A troca de A não apagou os meses de B.
        $this->assertDatabaseHas('editorial_months', ['company_id' => $b->id, 'year' => 2026, 'month' => 12]);
    }

    public function test_change_recusa_mesmo_ramo(): void
    {
        $company = $this->makeCompany('restauracao');
        $restauracao = ContentSector::where('slug', 'restauracao')->firstOrFail();

        $this->expectException(ValidationException::class);
        $this->service->changeSector($company, $restauracao->id);
    }

    public function test_change_recusa_sem_ramo_esse_e_setSector(): void
    {
        $company = $this->makeCompany(null); // ainda sem ramo
        $carros = ContentSector::where('slug', 'carros')->firstOrFail();

        $this->expectException(ValidationException::class);
        $this->service->changeSector($company, $carros->id);
    }

    public function test_setSector_recusa_se_ja_tem_ramo_esse_e_changeSector(): void
    {
        $company = $this->makeCompany('restauracao');
        $carros = ContentSector::where('slug', 'carros')->firstOrFail();

        $this->expectException(ValidationException::class);
        $this->service->setSector($company, $carros->id);
    }

    public function test_change_recusa_ramo_nao_folha(): void
    {
        $company = $this->makeCompany('restauracao');
        $agrupador = ContentSector::where('slug', 'automovel')->firstOrFail(); // agrupador, não folha

        $this->expectException(ValidationException::class);
        $this->service->changeSector($company, $agrupador->id);
    }
}
