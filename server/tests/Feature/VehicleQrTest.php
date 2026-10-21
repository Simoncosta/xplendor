<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarQrScan;
use App\Models\Company;
use App\Models\User;
use App\Services\Cars\VehicleQr;
use App\Services\CompanyModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Ficha da viatura (A4) e o QR contado na plataforma: o endereço curto conta a leitura e
 * reencaminha para a viatura no site da empresa com os parâmetros da origem; robôs e repetições
 * em 30 minutos não contam; nada sobre quem leu é gravado; a validação do modelo do endereço;
 * a ficha com e sem modelo; as leituras na análise da viatura, só da empresa da viatura.
 */
class VehicleQrTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    private Company $company;
    private Company $other;
    private User $admin;
    private Car $car;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        config(['services.vehicle_qr.base_url' => 'https://xplendor.tech']);
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500019301', 'fiscal_name' => 'Autocaravanas Exemplo Lda', 'trade_name' => 'Exemplo', 'plan_id' => $plan,
            'subscription_status' => 'active', 'website' => 'exemplo.pt', 'vehicle_url_template' => 'https://exemplo.pt/vehicles/{id}']);
        $this->other = Company::create(['nipc' => '500019302', 'fiscal_name' => 'Outro Stand Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        foreach ([$this->company, $this->other] as $c) {
            app(CompanyModuleService::class)->enable($c->id, 'stock');
        }
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        \App\Access\CompatibilityMigration::run();
        $this->car = Car::factory()->create(['company_id' => $this->company->id, 'vehicle_type' => 'motorhome', 'status' => 'active']);
    }

    private function scan(string $code, string $ua = self::PHONE, string $ip = '10.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->withHeaders(['User-Agent' => $ua])->get("/q/{$code}");
    }

    public function test_a_scan_counts_and_redirects_to_the_vehicle_on_the_company_site_with_the_origin_parameters(): void
    {
        $code = VehicleQr::ensureCode($this->car);
        $res = $this->scan($code);
        $res->assertStatus(302);
        $this->assertSame("https://exemplo.pt/vehicles/{$this->car->id}?origem=qr&utm_source=qr&utm_medium=offline&utm_campaign=ficha_stand", $res->headers->get('Location'));
        $this->assertSame(1, CarQrScan::where('car_id', $this->car->id)->where('company_id', $this->company->id)->count());
        $this->assertNull($res->headers->get('Set-Cookie'), 'sem cookies');
    }

    public function test_robots_do_not_count(): void
    {
        $code = VehicleQr::ensureCode($this->car);
        foreach (['facebookexternalhit/1.1', 'WhatsApp/2.23.20', 'Googlebot/2.1 (+http://www.google.com/bot.html)', 'curl/8.4.0', ''] as $ua) {
            $this->scan($code, $ua)->assertStatus(302);
        }
        $this->assertSame(0, CarQrScan::count());
    }

    public function test_the_same_device_counts_once_in_30_minutes(): void
    {
        $code = VehicleQr::ensureCode($this->car);
        $this->scan($code);
        $this->scan($code);
        $this->scan($code, self::PHONE, '10.0.0.2');
        $this->assertSame(2, CarQrScan::count(), 'outro aparelho conta');
        $this->travel(31)->minutes();
        $this->scan($code);
        $this->assertSame(3, CarQrScan::count(), 'passados 30 minutos, conta outra vez');
    }

    public function test_nothing_about_who_read_is_stored(): void
    {
        $this->assertSame(['id', 'company_id', 'car_id', 'scanned_at'], Schema::getColumnListing('car_qr_scans'));
        $this->scan(VehicleQr::ensureCode($this->car), self::PHONE, '203.0.113.9');
        $row = (array) DB::table('car_qr_scans')->first();
        $this->assertStringNotContainsString('203.0.113.9', json_encode($row));
        $this->assertStringNotContainsString('iPhone', json_encode($row));
    }

    public function test_without_template_goes_to_the_company_site_or_the_unavailable_page(): void
    {
        $code = VehicleQr::ensureCode($this->car);
        $this->company->forceFill(['vehicle_url_template' => null])->save();
        $this->scan($code)->assertRedirect('https://exemplo.pt');
        $this->company->forceFill(['website' => null])->save();
        $this->travel(31)->minutes();
        $this->scan($code)->assertOk()->assertSee('Viatura indisponível');
        $this->assertSame(2, CarQrScan::count(), 'a leitura de um código antigo conta na mesma');
    }

    public function test_unknown_code_shows_the_unavailable_page(): void
    {
        $this->scan('zzzzzzzz')->assertNotFound()->assertSee('Viatura indisponível');
        $this->assertSame(0, CarQrScan::count());
    }

    public function test_codes_are_random_unique_and_never_the_id(): void
    {
        $cars = Car::factory()->count(60)->create(['company_id' => $this->company->id]);
        $codes = $cars->map(fn ($c) => VehicleQr::ensureCode($c))->all();
        $this->assertCount(60, array_unique($codes));
        foreach ($codes as $i => $code) {
            $this->assertMatchesRegularExpression('/^[abcdefghjkmnpqrstuvwxyz23456789]{8}$/', $code);
            $this->assertNotSame((string) $cars[$i]->id, $code);
        }
        $this->assertSame($codes[0], VehicleQr::ensureCode($cars[0]->fresh()), 'o código não muda depois de criado');
    }

    public function test_the_template_validation(): void
    {
        $this->assertNull(VehicleQr::templateError(''));
        $this->assertNull(VehicleQr::templateError('https://quebom.pt/vehicles/{id}'));
        $this->assertStringContainsString('https://', (string) VehicleQr::templateError('http://quebom.pt/vehicles/{id}'));
        $this->assertStringContainsString('{id}', (string) VehicleQr::templateError('https://quebom.pt/vehicles/'));
        $this->assertNotNull(VehicleQr::templateError('https://{id}/{id}'));

        $url = "/api/v1/companies/{$this->company->id}";
        $this->actingAs($this->admin, 'sanctum')->putJson($url, ['vehicle_url_template' => 'http://quebom.pt/vehicles/{id}'])
            ->assertStatus(422)->assertJsonPath('errors.vehicle_url_template.0', 'O endereço da viatura tem de começar por https://.');
        $this->actingAs($this->admin, 'sanctum')->putJson($url, ['vehicle_url_template' => 'https://quebom.pt/vehicles'])->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->putJson($url, ['vehicle_url_template' => 'https://quebom.pt/vehicles/{id}'])->assertOk();
        $this->assertSame('https://quebom.pt/vehicles/{id}', $this->company->fresh()->vehicle_url_template);
    }

    public function test_the_print_sheet_has_the_presentation_and_the_qr_or_says_it_is_missing(): void
    {
        $url = "/api/v1/companies/{$this->company->id}/cars/{$this->car->id}/print-sheet";
        $data = $this->actingAs($this->admin, 'sanctum')->getJson($url)->assertOk()->json('data');
        $code = $this->car->fresh()->qr_code;
        $this->assertNotNull($code, 'o código cria-se quando a ficha precisa dele');
        $this->assertSame(['available' => true, 'short_url' => "https://xplendor.tech/q/{$code}", 'site_url' => "https://exemplo.pt/vehicles/{$this->car->id}",
            'site_label' => "exemplo.pt/vehicles/{$this->car->id}"], array_intersect_key($data['qr'], array_flip(['available', 'short_url', 'site_url', 'site_label'])));
        $this->assertStringStartsWith('<svg', trim($data['qr']['svg']));
        $this->assertSame('presentation-v1', $data['presentation']['version']);

        $this->company->forceFill(['vehicle_url_template' => null])->save();
        $this->actingAs($this->admin, 'sanctum')->getJson($url)->assertOk()->assertJsonPath('data.qr', ['available' => false, 'reason' => 'missing_template']);
    }

    public function test_the_public_api_has_the_presentation(): void
    {
        $public = \App\Http\Resources\Public\CarPublicResource::make($this->car->fresh(['brand', 'model', 'category']))->resolve(request());
        $this->assertSame('presentation-v1', $public['presentation']['version']);
        $this->assertArrayHasKey('sections', $public['presentation']);
    }

    public function test_the_scans_in_traffic_and_channels_only_for_the_company_of_the_vehicle(): void
    {
        $code = VehicleQr::ensureCode($this->car);
        $this->scan($code);
        $this->scan($code, self::PHONE, '10.0.0.7');
        $otherCar = Car::factory()->create(['company_id' => $this->other->id]);
        $this->scan(VehicleQr::ensureCode($otherCar));
        DB::table('car_views')->insert(['company_id' => $this->company->id, 'car_id' => $this->car->id, 'ip_address' => '127.0.0.1', 'utm_source' => 'qr',
            'created_at' => now(), 'updated_at' => now()]);

        // A análise completa usa funções do MySQL (o SQLite dos testes não as tem): as leituras
        // testam-se pelo método que a análise usa; a tenancy, pelo endereço da análise.
        $qr = VehicleQr::stats($this->car->fresh());
        $this->assertSame(2, $qr['total']);
        $this->assertSame(2, $qr['last_24h']);
        $this->assertSame(1, $qr['site_visits_from_qr'], 'as visitas do site com utm_source=qr são outra medida');
        $this->assertCount(30, $qr['by_day']);
        $this->assertSame(2, end($qr['by_day'])['total']);
        $this->assertNotNull($qr['last_at']);

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/companies/{$this->company->id}/cars/{$otherCar->id}/analytics")->assertNotFound();
        $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/companies/{$this->other->id}/cars/{$otherCar->id}/analytics")->assertForbidden();
    }
}
