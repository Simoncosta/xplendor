<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarLead;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Financing\FinancingLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * O site da Quebom a enviar a retoma a partir do browser: https://quebom.pt e https://www.quebom.pt
 * estão no CORS (e o pedido prévio autoriza o cabeçalho X-Financing-Token) e passam a verificação
 * das origens da empresa; http, outra porta ou outro domínio não passam. Os campos da empresa que
 * contam: o endereço do site ou o modelo de endereço das viaturas.
 */
class QuebomOriginTest extends TestCase
{
    use RefreshDatabase;

    private Company $quebom;
    private CarLead $lead;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['financing.require_antivirus' => false]);
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->quebom = Company::create(['nipc' => '500019371', 'fiscal_name' => 'Quebom Exemplo Lda', 'plan_id' => $plan, 'subscription_status' => 'active',
            'public_api_token' => (string) Str::uuid(), 'website' => null, 'vehicle_url_template' => 'https://quebom.pt/vehicles/{id}']);
        app(CompanyModuleService::class)->enable($this->quebom->id, 'stock');
        app(CompanyModuleService::class)->enable($this->quebom->id, 'commercial_crm');
        User::factory()->create(['company_id' => $this->quebom->id, 'role' => 'admin']);
        $car = Car::withoutEvents(fn () => Car::factory()->create(['company_id' => $this->quebom->id]));
        $this->lead = CarLead::create(['company_id' => $this->quebom->id, 'car_id' => $car->id, 'name' => 'C', 'email' => 'c@x.invalid',
            'trade_in' => ['brand' => 'Renault', 'model' => 'Clio']]);
    }

    private function token(): string
    {
        $link = app(FinancingLinkService::class)->createTradeIn($this->lead, null, now()->addHours(24))->links()->first();

        return $link->token();
    }

    private function fetchFrom(string $token, string $origin)
    {
        return $this->withHeaders(['X-Financing-Token' => $token, 'Origin' => $origin])->getJson('/api/public/financing');
    }

    public function test_both_quebom_origins_are_in_the_cors_list_and_the_preflight_accepts_the_header(): void
    {
        $origins = config('cors.allowed_origins');
        $this->assertContains('https://quebom.pt', $origins);
        $this->assertContains('https://www.quebom.pt', $origins);
        $this->assertNotContains('http://quebom.pt', $origins);
        $this->assertNotContains('http://www.quebom.pt', $origins);
        foreach (['https://quebom.pt', 'https://www.quebom.pt'] as $origin) {
            $pre = $this->call('OPTIONS', '/api/public/financing/documents', [], [], [], [
                'HTTP_ORIGIN' => $origin, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST', 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-financing-token',
            ]);
            $this->assertSame($origin, $pre->headers->get('Access-Control-Allow-Origin'), $origin);
            $this->assertStringContainsStringIgnoringCase('x-financing-token', (string) $pre->headers->get('Access-Control-Allow-Headers'));
        }
        foreach (['http://quebom.pt', 'http://www.quebom.pt'] as $origin) {
            $pre = $this->call('OPTIONS', '/api/public/financing', [], [], [], ['HTTP_ORIGIN' => $origin, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-financing-token']);
            $this->assertNull($pre->headers->get('Access-Control-Allow-Origin'), "{$origin} não é autorizado");
        }
    }

    public function test_the_company_origin_check_accepts_https_with_and_without_www_only(): void
    {
        $token = $this->token();
        $this->fetchFrom($token, 'https://quebom.pt')->assertOk();
        $this->fetchFrom($token, 'https://www.quebom.pt')->assertOk();
        foreach (['http://quebom.pt', 'http://www.quebom.pt', 'https://quebom.pt:8443', 'https://quebom.pt.exemplo.invalid', 'https://outroquebom.pt', 'https://paautomoveis.pt'] as $bad) {
            $this->fetchFrom($token, $bad)->assertStatus(403)->assertJsonPath('code', 'origin_not_allowed');
        }
        // A página da XPLENDOR (aqui, o APP_URL dos testes) e um pedido sem Origin (não vem de um browser).
        $this->fetchFrom($token, 'http://localhost')->assertOk();
        $this->withHeaders(['X-Financing-Token' => $token])->getJson('/api/public/financing')->assertOk();
    }

    public function test_the_company_fields_that_count(): void
    {
        $token = $this->token();
        // Só o endereço do site (sem esquema e com www) também chega.
        $this->quebom->forceFill(['vehicle_url_template' => null, 'website' => 'www.quebom.pt'])->save();
        $this->fetchFrom($token, 'https://quebom.pt')->assertOk();
        $this->fetchFrom($token, 'https://www.quebom.pt')->assertOk();
        // Sem nenhum dos dois, o site não é aceite (só a página da XPLENDOR).
        $this->quebom->forceFill(['vehicle_url_template' => null, 'website' => null])->save();
        $this->fetchFrom($token, 'https://quebom.pt')->assertStatus(403);
        $this->fetchFrom($token, 'http://localhost')->assertOk();
    }
}
