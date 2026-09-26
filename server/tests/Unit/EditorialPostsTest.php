<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Company;
use App\Models\ContentAnchor;
use App\Models\ContentSector;
use App\Models\EditorialOwnAnchor;
use App\Services\EditorialAnchorResolver;
use App\Services\EditorialLineService;
use App\Services\EditorialPostService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * XPLENDOR — Linha Editorial (Publicações P1): CRUD dos posts. Modelo C (data qualquer,
 * âncora opcional). Regra transversal: criar/editar/apagar só em mês ABERTO. Espaço de id
 * distinto das âncoras. Tempo congelado a 15/Dez/2026 → Dez/2026 abrível.
 */
class EditorialPostsTest extends TestCase
{
    use RefreshDatabase;

    private EditorialLineService $line;
    private EditorialPostService $posts;
    private Company $company;
    private ContentAnchor $anchorDez; // âncora herdada em Dezembro (mês aberto no teste)

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-15 12:00:00', 'Europe/Lisbon'));

        $this->line = new EditorialLineService(new EditorialAnchorResolver());
        $this->posts = new EditorialPostService($this->line);
        $this->company = $this->makeCompany('restauracao');
        $this->anchorDez = ContentAnchor::where('rule_type', 'fixa')->where('month', 12)->orderBy('day')->firstOrFail();

        $this->line->openMonth($this->company, 2026, 12); // Dezembro aberto; Janeiro/2027 fechado
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function makeCompany(string $slug): Company
    {
        $planId = DB::table('plans')->insertGetId([
            'name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return Company::create([
            'nipc' => (string) random_int(500000000, 599999999),
            'fiscal_name' => 'C Lda', 'plan_id' => $planId,
            'content_sector_id' => ContentSector::where('slug', $slug)->firstOrFail()->id,
        ]);
    }

    private function base(array $over = []): array
    {
        return array_merge([
            'title' => 'Post de Natal', 'publish_date' => '2026-12-24',
            'format' => 'Carrossel', 'status' => 'rascunho', 'channel' => 'instagram', 'keyword' => 'natal',
        ], $over);
    }

    private function findPost(array $cal, int $id): ?array
    {
        foreach ($cal['posts'] as $p) { if ($p['id'] === $id) return $p; }
        return null;
    }

    public function test_criar_post_em_mes_aberto_ligado_a_herdada(): void
    {
        $cal = $this->posts->createPost($this->company, $this->base(['anchor_id' => $this->anchorDez->id]));

        $this->assertCount(1, $cal['posts']);
        $p = $cal['posts'][0];
        $this->assertSame('Post de Natal', $p['title']);
        $this->assertSame('Carrossel', $p['format']);
        $this->assertSame('instagram', $p['channel']);
        $this->assertSame('natal', $p['keyword']);
        $this->assertSame($this->anchorDez->id, $p['anchor_id']);
        $this->assertNull($p['own_anchor_id']);
        $this->assertNotNull($p['linked_title']);
    }

    public function test_criar_post_sem_ancora_modelo_c(): void
    {
        $cal = $this->posts->createPost($this->company, $this->base(['title' => 'Post solto']));
        $this->assertCount(1, $cal['posts']);
        $this->assertNull($cal['posts'][0]['anchor_id']);
        $this->assertNull($cal['posts'][0]['own_anchor_id']);
    }

    public function test_criar_post_ligado_a_propria(): void
    {
        $own = EditorialOwnAnchor::create([
            'company_id' => $this->company->id, 'title' => 'Aniversário', 'rule_type' => 'fixa', 'month' => 12, 'day' => 10,
        ]);
        $cal = $this->posts->createPost($this->company, $this->base(['own_anchor_id' => $own->id]));
        $this->assertSame($own->id, $cal['posts'][0]['own_anchor_id']);
        $this->assertNull($cal['posts'][0]['anchor_id']);
    }

    public function test_ambas_ancoras_recusa(): void
    {
        $own = EditorialOwnAnchor::create([
            'company_id' => $this->company->id, 'title' => 'Aniversário', 'rule_type' => 'fixa', 'month' => 12, 'day' => 10,
        ]);
        $this->expectException(ValidationException::class);
        $this->posts->createPost($this->company, $this->base(['anchor_id' => $this->anchorDez->id, 'own_anchor_id' => $own->id]));
    }

    public function test_criar_em_mes_bloqueado_recusa(): void
    {
        $this->expectException(ValidationException::class);
        // Janeiro/2027 está fechado.
        $this->posts->createPost($this->company, $this->base(['publish_date' => '2027-01-10']));
    }

    public function test_enum_invalido_recusa(): void
    {
        $this->expectException(ValidationException::class);
        $this->posts->createPost($this->company, $this->base(['format' => 'Meme']));
    }

    public function test_editar_e_apagar_em_mes_aberto(): void
    {
        $cal = $this->posts->createPost($this->company, $this->base());
        $id = $cal['posts'][0]['id'];

        $cal = $this->posts->updatePost($this->company, $id, $this->base(['title' => 'Editado', 'status' => 'revisao']));
        $this->assertSame('Editado', $this->findPost($cal, $id)['title']);
        $this->assertSame('revisao', $this->findPost($cal, $id)['status']);

        $cal = $this->posts->deletePost($this->company, $id);
        $this->assertNull($this->findPost($cal, $id));
    }

    public function test_editar_para_mes_bloqueado_recusa(): void
    {
        $cal = $this->posts->createPost($this->company, $this->base());
        $id = $cal['posts'][0]['id'];

        $this->expectException(ValidationException::class);
        // Mover para Janeiro/2027 (fechado) → recusa.
        $this->posts->updatePost($this->company, $id, $this->base(['publish_date' => '2027-01-05']));
    }

    public function test_tenancy_nao_edita_post_de_outra_empresa(): void
    {
        $other = $this->makeCompany('carros');
        $this->line->openMonth($other, 2026, 12);
        $cal = $this->posts->createPost($other, $this->base(['title' => 'Da outra']));
        $otherId = $cal['posts'][0]['id'];

        $this->expectException(ValidationException::class);
        $this->posts->deletePost($this->company, $otherId); // não é da minha empresa
    }

    public function test_ids_nao_se_misturam(): void
    {
        // Apagar publicação com um id inexistente no espaço dos posts (ex.: id alto) → 422.
        $this->expectException(ValidationException::class);
        $this->posts->deletePost($this->company, 999999);
    }

    public function test_ancora_e_post_coexistem_no_mesmo_dia(): void
    {
        // A âncora herdada em Dezembro resolve para 2026-12-DD; ponho um post no mesmo dia.
        $day = sprintf('2026-12-%02d', (int) $this->anchorDez->day);
        $cal = $this->posts->createPost($this->company, $this->base(['publish_date' => $day, 'anchor_id' => $this->anchorDez->id]));

        $hasAnchor = false;
        foreach ($cal['items'] as $it) {
            if (($it['date'] ?? null) === $day && $it['anchor_id'] === $this->anchorDez->id) $hasAnchor = true;
        }
        $hasPost = false;
        foreach ($cal['posts'] as $p) {
            if ($p['publish_date'] === $day) $hasPost = true;
        }
        $this->assertTrue($hasAnchor, 'A âncora devia continuar no dia.');
        $this->assertTrue($hasPost, 'O post devia coexistir no mesmo dia.');
    }
}
