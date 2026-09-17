<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\User;
use App\Services\Ga4\Ga4ClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — Integração GA4 (tráfego do site do cliente, Service Account).
 * Cobre: guardar property_id por empresa, o serviço a normalizar métricas (via
 * cliente falso, sem rede), demografia vazia → "sem dados", tenancy (A não vê B)
 * e a cache (2ª leitura não volta a chamar a Data API).
 */
class Ga4IntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $other;
    private User $user;
    private User $stranger;
    private FakeGa4Client $fake;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']); // cache isolada e determinística no teste

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan', 'price' => 0, 'car_limit' => 99,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->company = Company::create(['nipc' => '500000700', 'fiscal_name' => 'Stand A Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500000701', 'fiscal_name' => 'Stand B Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $this->stranger = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);

        // Substitui o cliente REST real por um falso (sem rede).
        $this->fake = new FakeGa4Client();
        $this->app->instance(Ga4ClientInterface::class, $this->fake);
    }

    private function connectUrl(?int $companyId = null): string
    {
        return "/api/v1/companies/" . ($companyId ?? $this->company->id) . "/integrations/google/connect";
    }

    private function trafficUrl(?int $companyId = null): string
    {
        return "/api/v1/companies/" . ($companyId ?? $this->company->id) . "/analytics/ga4/traffic";
    }

    public function test_stores_property_id_per_company(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson($this->connectUrl(), ['property_id' => '398765432'])
            ->assertStatus(200)
            ->assertJsonPath('data.property_id', '398765432');

        $this->assertDatabaseHas('company_integrations', [
            'company_id' => $this->company->id,
            'platform' => 'google',
            'property_id' => '398765432',
            'status' => 'active',
        ]);
    }

    public function test_property_id_round_trips_through_integrations_index(): void
    {
        // Regressão: o property_id gravava mas a leitura (selectRaw do repositório)
        // não o incluía → a rota /integrations devolvia null. Aqui garantimos que
        // conectar e depois LER o índice devolve o valor gravado, não null.
        $this->actingAs($this->user, 'sanctum')
            ->postJson($this->connectUrl(), ['property_id' => '544947543'])
            ->assertStatus(200);

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/companies/{$this->company->id}/integrations")
            ->assertStatus(200);

        $google = collect($res->json('data'))->firstWhere('platform', 'google');
        $this->assertNotNull($google, 'Integração google ausente no índice.');
        $this->assertSame('544947543', $google['property_id']); // NÃO null
    }

    public function test_validates_property_id_format(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson($this->connectUrl(), ['property_id' => 'not-a-number'])
            ->assertStatus(422);
    }

    public function test_traffic_reports_not_connected_without_property(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson($this->trafficUrl())
            ->assertStatus(200)
            ->assertJsonPath('data.connected', false);
    }

    public function test_service_reads_and_normalizes_metrics(): void
    {
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'google', 'access_token' => '', 'property_id' => '398765432', 'status' => 'active']);

        $res = $this->actingAs($this->user, 'sanctum')->getJson($this->trafficUrl())->assertStatus(200);

        $res->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.traffic.overview.active_users', 120)
            ->assertJsonPath('data.traffic.overview.sessions', 150)
            ->assertJsonPath('data.traffic.demographics.available', true);

        // Páginas mais vistas normalizadas (path/title/views).
        $this->assertSame('/', $res->json('data.traffic.top_pages.0.path'));
        $this->assertSame(200, $res->json('data.traffic.top_pages.0.views'));
        // Tendência ordenada por data ascendente.
        $trend = $res->json('data.traffic.trend');
        $this->assertSame('2026-09-14', $trend[0]['date']);
    }

    public function test_demographics_empty_shows_no_data(): void
    {
        $this->fake->emptyDemographics = true;
        $this->fake->thresholded = true;
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'google', 'access_token' => '', 'property_id' => '398765432', 'status' => 'active']);

        $this->actingAs($this->user, 'sanctum')->getJson($this->trafficUrl())
            ->assertStatus(200)
            ->assertJsonPath('data.traffic.demographics.available', false)
            ->assertJsonPath('data.traffic.demographics.reason', 'thresholded');
    }

    public function test_demographics_error_is_best_effort(): void
    {
        // Um erro na demografia (ex.: Signals off) não rebenta o resto do painel.
        $this->fake->throwOnDemographics = true;
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'google', 'access_token' => '', 'property_id' => '398765432', 'status' => 'active']);

        $this->actingAs($this->user, 'sanctum')->getJson($this->trafficUrl())
            ->assertStatus(200)
            ->assertJsonPath('data.traffic.overview.active_users', 120) // resto funciona
            ->assertJsonPath('data.traffic.demographics.available', false)
            ->assertJsonPath('data.traffic.demographics.reason', 'no_data');
    }

    public function test_cache_avoids_second_api_call(): void
    {
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'google', 'access_token' => '', 'property_id' => '398765432', 'status' => 'active']);

        $this->actingAs($this->user, 'sanctum')->getJson($this->trafficUrl())->assertStatus(200);
        $afterFirst = $this->fake->calls;
        $this->assertGreaterThan(0, $afterFirst);

        // 2ª leitura → servida da cache → o cliente GA4 NÃO é chamado de novo.
        $this->actingAs($this->user, 'sanctum')->getJson($this->trafficUrl())->assertStatus(200);
        $this->assertSame($afterFirst, $this->fake->calls);
    }

    public function test_errors_are_not_cached_and_retry_succeeds(): void
    {
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'google', 'access_token' => '', 'property_id' => '398765432', 'status' => 'active']);

        // 1ª tentativa rebenta (ex.: permissão) → resposta com erro, NADA cacheado.
        $this->fake->throwAll = true;
        $this->actingAs($this->user, 'sanctum')->getJson($this->trafficUrl())
            ->assertStatus(200)
            ->assertJsonPath('data.traffic', null)
            ->assertJsonPath('data.error', fn ($e) => is_string($e));

        // Corrigido o acesso → a leitura seguinte tem de FUNCIONAR (o erro não ficou preso na cache).
        $this->fake->throwAll = false;
        $this->actingAs($this->user, 'sanctum')->getJson($this->trafficUrl())
            ->assertStatus(200)
            ->assertJsonPath('data.traffic.overview.active_users', 120);
    }

    public function test_fresh_param_bypasses_cache(): void
    {
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'google', 'access_token' => '', 'property_id' => '398765432', 'status' => 'active']);

        $this->actingAs($this->user, 'sanctum')->getJson($this->trafficUrl())->assertStatus(200);
        $afterFirst = $this->fake->calls;

        // ?fresh=1 → ignora a cache → o cliente GA4 É chamado de novo.
        $this->actingAs($this->user, 'sanctum')->getJson($this->trafficUrl() . '?fresh=1')->assertStatus(200);
        $this->assertGreaterThan($afterFirst, $this->fake->calls);
    }

    public function test_redact_strips_private_key(): void
    {
        $raw = 'Could not find keyfile: {"type":"service_account","private_key":"-----BEGIN PRIVATE KEY-----\nSECRETSECRET\n-----END PRIVATE KEY-----\n","client_email":"x@y"}';
        $safe = \App\Services\Ga4\Ga4Redact::message($raw);
        $this->assertStringNotContainsString('SECRETSECRET', $safe);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $safe);
    }

    public function test_tenancy_company_cannot_touch_another(): void
    {
        // Camada 1: utilizador da empresa A na rota da empresa B → 403.
        $this->actingAs($this->user, 'sanctum')->getJson($this->trafficUrl($this->other->id))->assertStatus(403);
        $this->actingAs($this->user, 'sanctum')->postJson($this->connectUrl($this->other->id), ['property_id' => '111111111'])->assertStatus(403);
    }
}

/**
 * Cliente GA4 falso — devolve dados canónicos por tipo de relatório (inspeciona
 * a 1ª dimensão do spec) e conta chamadas (para testar a cache). Sem rede.
 */
class FakeGa4Client implements Ga4ClientInterface
{
    public int $calls = 0;
    public bool $emptyDemographics = false;
    public bool $thresholded = false;
    public bool $throwOnDemographics = false;
    public bool $throwAll = false; // simula erro real (ex.: permissão) em TODOS os relatórios

    public function runReport(int $propertyId, array $spec): array
    {
        $this->calls++;

        if ($this->throwAll) {
            throw new \RuntimeException('PERMISSION_DENIED: user does not have access to property');
        }

        $dim = $spec['dimensions'][0] ?? null;

        if ($dim === null) { // visão geral (7 métricas)
            return ['rows' => [['dimensions' => [], 'metrics' => ['120', '80', '150', '300', '65.5', '0.72', '0.28']]], 'subjectToThresholding' => false];
        }

        return match ($dim) {
            'pagePath' => ['rows' => [
                ['dimensions' => ['/', 'Home'], 'metrics' => ['200']],
                ['dimensions' => ['/carros', 'Carros'], 'metrics' => ['100']],
            ], 'subjectToThresholding' => false],
            'sessionDefaultChannelGroup' => ['rows' => [
                ['dimensions' => ['Organic Search'], 'metrics' => ['90']],
                ['dimensions' => ['Direct'], 'metrics' => ['60']],
            ], 'subjectToThresholding' => false],
            'deviceCategory' => ['rows' => [
                ['dimensions' => ['mobile'], 'metrics' => ['100']],
                ['dimensions' => ['desktop'], 'metrics' => ['50']],
            ], 'subjectToThresholding' => false],
            'country' => ['rows' => [['dimensions' => ['Portugal', 'Porto'], 'metrics' => ['120']]], 'subjectToThresholding' => false],
            'date' => ['rows' => [
                ['dimensions' => ['20260915'], 'metrics' => ['60', '70']],
                ['dimensions' => ['20260914'], 'metrics' => ['50', '65']],
            ], 'subjectToThresholding' => false],
            'userAgeBracket', 'userGender' => $this->demographics(),
            default => ['rows' => [], 'subjectToThresholding' => false],
        };
    }

    private function demographics(): array
    {
        if ($this->throwOnDemographics) {
            throw new \RuntimeException('demographics unavailable (Signals off)');
        }
        if ($this->emptyDemographics) {
            return ['rows' => [], 'subjectToThresholding' => $this->thresholded];
        }

        return ['rows' => [['dimensions' => ['25-34'], 'metrics' => ['40']]], 'subjectToThresholding' => false];
    }
}
