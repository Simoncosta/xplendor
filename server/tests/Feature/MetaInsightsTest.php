<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — Meta: re-exposição (leitura) dos dados que o pipeline já ingere.
 * Confirma que a vista agrega gasto/cliques/CTR + vendas atribuídas da BD, com
 * tenancy (empresa A não vê Meta da B) e sem escrever na Meta.
 */
class MetaInsightsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $other;
    private User $user;
    private int $carId;

    protected function setUp(): void
    {
        parent::setUp();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->company = Company::create(['nipc' => '500001400', 'fiscal_name' => 'Stand A', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500001401', 'fiscal_name' => 'Stand B', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);

        $brand = CarBrand::firstOrCreate(['slug' => 'vw'], ['name' => 'VW', 'vehicle_type' => 'car']);
        $model = CarModel::firstOrCreate(['name' => 'Golf', 'car_brand_id' => $brand->id]);
        $this->carId = Car::factory()->create(['company_id' => $this->company->id, 'car_brand_id' => $brand->id, 'car_model_id' => $model->id])->id;
    }

    private function url(?int $companyId = null): string
    {
        return '/api/v1/companies/' . ($companyId ?? $this->company->id) . '/analytics/meta/overview';
    }

    /** Cria um mapping (car_ad_campaigns) + uma linha de métricas ligada a ele. */
    private function metric(int $companyId, array $extra = []): void
    {
        $campaignId = $extra['campaign_id'] ?? '111';
        $campaignName = $extra['campaign_name'] ?? ('Camp ' . $campaignId);

        // mapping_id é FK para car_ad_campaigns → criar o mapping primeiro.
        $mappingId = DB::table('car_ad_campaigns')->insertGetId([
            'company_id' => $companyId, 'car_id' => $this->carId, 'platform' => 'meta',
            'campaign_id' => $campaignId, 'campaign_name' => $campaignName,
            'adset_id' => $extra['adset_id'] ?? '222', 'level' => 'campaign', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('campaign_car_metrics_daily')->insert(array_merge([
            'company_id' => $companyId, 'car_id' => $this->carId, 'mapping_id' => $mappingId,
            'campaign_id' => $campaignId, 'adset_id' => '222', 'date' => now()->toDateString(),
            'impressions' => 1000, 'clicks' => 50, 'spend_normalized' => 25.00,
            'ctr' => 5, 'cpc' => 0.5, 'cpm' => 25, 'allocation_factor' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], array_diff_key($extra, ['campaign_name' => 1])));
    }

    public function test_overview_aggregates_meta_metrics_from_db(): void
    {
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'meta', 'access_token' => 't', 'status' => 'active']);
        $this->metric($this->company->id, ['campaign_name' => 'Golf Verão', 'impressions' => 1000, 'clicks' => 50, 'spend_normalized' => 25.00]);
        $this->metric($this->company->id, ['campaign_name' => 'Golf Verão', 'impressions' => 1000, 'clicks' => 30, 'spend_normalized' => 15.00, 'adset_id' => '333']);

        $res = $this->actingAs($this->user, 'sanctum')->getJson($this->url())->assertStatus(200);

        $res->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.by_campaign.0.campaign_name', 'Golf Verão');
        $this->assertEquals(40, $res->json('data.overview.spend'));       // 25 + 15
        $this->assertEquals(2000, $res->json('data.overview.impressions'));
        $this->assertEquals(80, $res->json('data.overview.clicks'));
        $this->assertEquals(4, $res->json('data.overview.ctr'));          // 80/2000*100
    }

    public function test_attributed_sales_are_shown(): void
    {
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'meta', 'access_token' => 't', 'status' => 'active']);
        DB::table('car_sale_attributions')->insert([
            'company_id' => $this->company->id, 'car_id' => $this->carId, 'sold_at' => now(),
            'sale_price' => 30000, 'attributed_platform' => 'meta', 'attributed_campaign_id' => '111',
            'attribution_model' => 'last_touch', 'attribution_window_days' => 30, 'match_type' => 'campaign_match',
            'confidence_score' => 80, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->user, 'sanctum')->getJson($this->url())
            ->assertStatus(200)
            ->assertJsonPath('data.attributed.sales', 1)
            ;
        $this->assertEquals(30000, $this->actingAs($this->user, 'sanctum')->getJson($this->url())->json('data.attributed.revenue'));
    }

    public function test_not_connected_when_no_integration(): void
    {
        $this->actingAs($this->user, 'sanctum')->getJson($this->url())
            ->assertStatus(200)
            ->assertJsonPath('data.connected', false)
            ->assertJsonPath('data.overview.spend', 0);
    }

    public function test_tenancy_only_own_company_meta_data(): void
    {
        // Métricas da empresa B não podem aparecer para a empresa A.
        $this->metric($this->other->id, ['campaign_id' => '999', 'spend_normalized' => 999.00]);
        CompanyIntegration::create(['company_id' => $this->company->id, 'platform' => 'meta', 'access_token' => 't', 'status' => 'active']);
        $this->metric($this->company->id, ['spend_normalized' => 10.00]);

        $res = $this->actingAs($this->user, 'sanctum')->getJson($this->url())->assertStatus(200);
        $this->assertEquals(10, $res->json('data.overview.spend')); // só os 10 da empresa A, não os 999 da B
    }

    public function test_cannot_access_other_company(): void
    {
        $this->actingAs($this->user, 'sanctum')->getJson($this->url($this->other->id))->assertStatus(403);
    }
}
