<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarMarketAggregate;
use App\Models\CarModel;
use App\Models\Company;
use App\Models\CompassText;
use App\Models\MetaAd;
use App\Models\MetaAdInsightDaily;
use App\Models\PermissionProfile;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Compass\Stand\StandCompassService;
use App\Services\Compass\Stand\StandSignalService;
use App\Services\MetaAdCarAllocator;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bússola Master: o stand não pode mudar de comportamento. Este teste de caracterização grava
 * as respostas da Bússola do stand ANTES do Radar e do imobiliário, e confirma depois que são
 * iguais byte a byte: a página completa, só os carros, só as autocaravanas e só as jogadas (o
 * dashboard), para o administrador e para uma pessoa sem Finanças. Os identificadores são
 * fixos (aparecem nas chaves e nas respostas). Hoje é quinta-feira, 08/10/2026.
 * Para regravar (só quando o stand muda de propósito): BUSSOLA_GOLDEN=write.
 */
class StandCompassGoldenTest extends TestCase
{
    use RefreshDatabase;

    private const DIR = __DIR__ . '/../Fixtures/bussola/stand';
    private const NOW = '2026-10-08 10:00:00';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW));
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::NOW));
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = new Company(['nipc' => '500009940', 'fiscal_name' => 'Stand Golden Lda', 'trade_name' => 'Stand Golden', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->company->forceFill(['id' => 990002])->save();
        foreach (['stock', 'linha_editorial'] as $m) {
            app(CompanyModuleService::class)->enable($this->company->id, $m);
        }
        $this->company->forceFill(['bussola_stand_enabled' => true])->save();
        (new CarBrand(['name' => 'Peugeot', 'slug' => 'peugeot-golden', 'vehicle_type' => 'car']))->forceFill(['id' => 990001])->save();
        (new CarModel(['name' => '208', 'car_brand_id' => 990001]))->forceFill(['id' => 990001])->save();
        (new CarBrand(['name' => 'Knaus', 'slug' => 'knaus-golden', 'vehicle_type' => 'motorhome']))->forceFill(['id' => 990002])->save();
        (new CarModel(['name' => 'Sky', 'car_brand_id' => 990002]))->forceFill(['id' => 990002])->save();
        \App\Access\CompatibilityMigration::run();
        $this->seedScenario();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function car(int $id, int $daysOld, array $attrs = []): Car
    {
        $car = Car::factory()->make(array_merge([
            'company_id' => $this->company->id, 'car_brand_id' => 990001, 'car_model_id' => 990001, 'vehicle_type' => 'car',
            'status' => 'active', 'is_resume' => 0, 'price_gross' => 20000, 'promo_price_gross' => null, 'registration_year' => 2019,
            'registration_month' => 6, 'mileage_km' => 85000, 'version' => 'GT', 'fuel_type' => 'Diesel', 'power_hp' => 130,
            'exterior_color' => 'Preto', 'interior_color' => 'Preto', 'segment' => 'SUV', 'transmission' => 'Manual',
        ], array_diff_key($attrs, ['sold_at' => 1])));
        $car->forceFill(['id' => $id])->save();
        DB::table('cars')->where('id', $id)->update(['created_at' => now()->startOfDay()->subDays($daysOld), 'car_created_at' => null]
            + (array_key_exists('sold_at', $attrs) ? ['sold_at' => $attrs['sold_at']] : []));

        return $car->fresh();
    }

    private function aggregate(Car $car, float $median, string $confidence = 'high', int $comparables = 12): void
    {
        CarMarketAggregate::create(['car_id' => $car->id, 'vehicle_type' => $car->vehicle_type, 'status' => 'success', 'confidence' => $confidence,
            'comparables_count' => $comparables, 'median_price' => $median, 'fallback_used' => false, 'method' => 'car_v1']);
    }

    private function adSpend(string $adId, string $name, float $spend, string $date, string $status = 'ACTIVE'): void
    {
        MetaAdInsightDaily::create(['company_id' => $this->company->id, 'account_id' => '123', 'date' => $date, 'campaign_id' => 'C1',
            'adset_id' => 'S1', 'ad_id' => $adId, 'ad_name' => $name, 'spend' => $spend, 'impressions' => 100, 'clicks' => 3]);
        MetaAd::updateOrCreate(['company_id' => $this->company->id, 'account_id' => '123', 'ad_id' => $adId],
            ['ad_name' => $name, 'campaign_id' => 'C1', 'effective_status' => $status]);
        app(MetaAdCarAllocator::class)->rebuild($this->company->id, '123');
    }

    /** Um veículo de cada jogada, uma autocaravana acima do mercado, uma caravana, um contacto e uma frase da IA. */
    private function seedScenario(): void
    {
        $sold = $this->car(990101, 80, ['status' => 'sold', 'sold_at' => now()->subDays(5)]);
        $this->adSpend('A1', "Peugeot 208 [id:{$sold->id}]", 40.0, now()->subDays(2)->toDateString());
        $price = $this->car(990102, 50, ['price_gross' => 22400]);
        $this->aggregate($price, 20000);
        $this->car(990103, 70);
        $this->car(990104, 60);
        $views = $this->car(990105, 10);
        DB::table('car_views')->insert(array_fill(0, 70, ['company_id' => $this->company->id, 'car_id' => $views->id, 'ip_address' => '127.0.0.1',
            'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]));
        $stage = $this->car(990106, 12, ['price_gross' => 19000]);
        $this->aggregate($stage, 20000);
        $silent = $this->car(990107, 25);
        $this->adSpend('A9', "Peugeot 208 [id:{$silent->id}]", 45.0, now()->subDay()->toDateString());
        $motorhome = $this->car(990108, 130, ['car_brand_id' => 990002, 'car_model_id' => 990002, 'vehicle_type' => 'motorhome', 'price_gross' => 60000, 'registration_year' => 2021]);
        $this->aggregate($motorhome, 55000, 'medium', 8);
        $this->car(990109, 50, ['car_brand_id' => 990002, 'car_model_id' => 990002, 'vehicle_type' => 'caravan', 'price_gross' => 15000, 'registration_year' => 2020]);
        // Um contacto (sem dados pessoais) no veículo com o preço acima.
        DB::table('car_interactions')->insert(['company_id' => $this->company->id, 'car_id' => $price->id, 'interaction_type' => 'whatsapp_click',
            'visitor_id' => 'v-golden', 'session_id' => 's-golden', 'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3)]);

        app(StandSignalService::class)->compute($this->company->id);
        $svc = app(StandCompassService::class);
        $today = CarbonImmutable::parse(self::NOW)->startOfDay();
        // As frases da IA (simuladas) das jogadas escolhidas de "Todos": as jogadas de "Carros" e de "Autocaravanas" ficam com o modelo de frase.
        foreach ($svc->choose($svc->candidateGroups($svc->visibleSignals($this->company->id, 'all', $today))) as $i => $group) {
            CompassText::create(['provider' => StandSignalService::PROVIDER, 'company_id' => $this->company->id, 'play_key' => StandCompassService::textKey($group),
                'text' => 'Frase simulada da IA número ' . ($i + 1) . ' para esta jogada, sem números nem causas.', 'generated_at' => now()]);
        }
    }

    private function users(): array
    {
        $admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $profile = PermissionProfile::create(['company_id' => $this->company->id, 'side' => 'cliente', 'name' => 'Marketing sem Finanças', 'is_system' => false, 'is_suggestion' => false]);
        $profile->syncPermissions(['bussola.ver', 'bussola.criar', 'bussola.editar', 'editorial.ver']);
        $marketing = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user', 'profile_id' => $profile->id]);

        return ['admin' => $admin, 'sem_financas' => $marketing];
    }

    public function test_the_stand_compass_answers_exactly_as_before(): void
    {
        $base = "/api/v1/companies/{$this->company->id}";
        $cases = [
            'pagina' => '/marketing/bussola-stand',
            'carros' => '/marketing/bussola-stand?type=car',
            'autocaravanas' => '/marketing/bussola-stand?type=motorhome',
            'jogadas' => '/marketing/bussola-stand?plays_only=1',
        ];
        $write = getenv('BUSSOLA_GOLDEN') === 'write';
        foreach ($this->users() as $who => $user) {
            foreach ($cases as $name => $path) {
                $json = json_encode($this->actingAs($user, 'sanctum')->getJson($base . $path)->assertOk()->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                $file = self::DIR . "/{$who}-{$name}.json";
                if ($write) {
                    @mkdir(self::DIR, 0775, true);
                    file_put_contents($file, $json);

                    continue;
                }
                $this->assertFileExists($file, 'Corra uma vez com BUSSOLA_GOLDEN=write antes do Radar.');
                $this->assertSame(file_get_contents($file), $json, "A resposta {$who}-{$name} da Bússola do stand mudou.");
            }
        }
        if ($write) {
            $this->markTestSkipped('Respostas gravadas.');
        }
    }
}
