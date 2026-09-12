<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarImage;
use App\Models\CarSale;
use App\Models\Company;
use App\Models\SatisfactionReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DMS Pós-venda — Relatório de Satisfação (Incremento 1, read-only).
 * Criação interna (token único, idempotente) + endpoint público por token
 * (branding + fotos da viatura, SEM PII do comprador) + 404 para token inválido.
 */
class SatisfactionReportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private Car $car;
    private CarSale $sale;

    protected function setUp(): void
    {
        parent::setUp();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan', 'price' => 0, 'car_limit' => 99,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->company = Company::create([
            'nipc' => '500000090', 'fiscal_name' => 'Stand Montra Lda', 'plan_id' => $planId,
            'subscription_status' => 'active', 'logo_path' => '/storage/company_1/logo/logo.webp',
        ]);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);

        $this->car = Car::create([
            'company_id' => $this->company->id, 'vehicle_type' => 'car', 'status' => 'sold',
            'warranty_months' => 24, 'mileage_km' => 45000,
        ]);
        CarImage::create(['company_id' => $this->company->id, 'car_id' => $this->car->id, 'image' => '/storage/company_1/cars/x-1/images/1.webp', 'is_primary' => true, 'order' => 1]);
        CarImage::create(['company_id' => $this->company->id, 'car_id' => $this->car->id, 'image' => '/storage/company_1/cars/x-1/images/2.webp', 'is_primary' => false, 'order' => 2]);

        $this->sale = CarSale::create([
            'car_id' => $this->car->id, 'company_id' => $this->company->id,
            'sale_price' => 20000, 'buyer_gender' => 'male', 'buyer_age_range' => '31-45',
            'sale_channel' => 'in_person', 'sold_at' => now(),
            'buyer_name' => 'Carlos Comprador', 'buyer_email' => 'carlos@buyer.pt', 'buyer_phone' => '919999999',
        ]);
    }

    private function createUrl(): string
    {
        return "/api/v1/companies/{$this->company->id}/cars/{$this->car->id}/satisfaction-report";
    }

    public function test_creates_report_with_unique_token(): void
    {
        $res = $this->actingAs($this->user, 'sanctum')->postJson($this->createUrl());
        $res->assertStatus(200);
        $token = $res->json('data.public_token');
        $this->assertIsString($token);
        $this->assertSame(48, strlen($token));
        $this->assertSame("/r/{$token}", $res->json('data.path'));
        $this->assertDatabaseHas('satisfaction_reports', ['car_sale_id' => $this->sale->id, 'public_token' => $token, 'status' => 'pending']);
    }

    public function test_create_is_idempotent_per_sale(): void
    {
        $first = $this->actingAs($this->user, 'sanctum')->postJson($this->createUrl())->json('data.public_token');
        $second = $this->actingAs($this->user, 'sanctum')->postJson($this->createUrl())->json('data.public_token');
        $this->assertSame($first, $second);
        $this->assertSame(1, SatisfactionReport::where('car_sale_id', $this->sale->id)->count());
    }

    public function test_lazy_create_persists_same_token_across_reopens(): void
    {
        // Venda antiga sem relatório: 1.ª abertura cria; 2.ª devolve O MESMO token.
        $this->assertDatabaseMissing('satisfaction_reports', ['car_sale_id' => $this->sale->id]);

        $first = $this->actingAs($this->user, 'sanctum')->postJson($this->createUrl());
        $first->assertStatus(200);
        $token = $first->json('data.public_token');

        $second = $this->actingAs($this->user, 'sanctum')->postJson($this->createUrl());
        $this->assertSame($token, $second->json('data.public_token'));
        $this->assertSame(1, SatisfactionReport::where('car_sale_id', $this->sale->id)->count());
    }

    public function test_ensure_for_sale_never_changes_existing_token(): void
    {
        $report = SatisfactionReport::ensureForSale($this->sale);
        $token = $report->public_token;
        // Chamar várias vezes NUNCA regenera o token.
        $this->assertSame($token, SatisfactionReport::ensureForSale($this->sale->fresh())->public_token);
        $this->assertSame($token, SatisfactionReport::ensureForSale($this->sale->fresh())->public_token);
    }

    public function test_create_requires_sale(): void
    {
        $carNoSale = Car::create(['company_id' => $this->company->id, 'vehicle_type' => 'car', 'status' => 'active']);
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/companies/{$this->company->id}/cars/{$carNoSale->id}/satisfaction-report")
            ->assertStatus(422);
    }

    public function test_create_tenant_isolation(): void
    {
        $other = Company::create(['nipc' => '500000091', 'fiscal_name' => 'Outra Lda', 'plan_id' => $this->company->plan_id, 'subscription_status' => 'active']);
        $intruder = User::factory()->create(['company_id' => $other->id, 'role' => 'admin']);
        $this->actingAs($intruder, 'sanctum')->postJson($this->createUrl())->assertStatus(403);
    }

    public function test_public_endpoint_resolves_by_token_and_returns_photos(): void
    {
        $token = SatisfactionReport::generateToken();
        SatisfactionReport::create([
            'company_id' => $this->company->id, 'car_sale_id' => $this->sale->id, 'car_id' => $this->car->id,
            'public_token' => $token, 'status' => 'pending', 'expires_at' => now()->addDays(90),
        ]);

        $res = $this->getJson("/api/public/report/{$token}");
        $res->assertStatus(200);
        $res->assertJsonPath('data.company.name', 'Stand Montra Lda');
        $res->assertJsonPath('data.company.logo_path', '/storage/company_1/logo/logo.webp');
        $this->assertCount(2, $res->json('data.car.images'));
        $this->assertSame('/storage/company_1/cars/x-1/images/1.webp', $res->json('data.car.images.0.url'));
        // Atributos não sensíveis do carro (mostradores).
        $this->assertSame(45000, $res->json('data.car.mileage_km'));
        $this->assertSame(24, $res->json('data.warranty.total_months'));
    }

    public function test_public_endpoint_exposes_only_warranty_start_date_from_sale(): void
    {
        $token = SatisfactionReport::generateToken();
        SatisfactionReport::create([
            'company_id' => $this->company->id, 'car_sale_id' => $this->sale->id, 'car_id' => $this->car->id,
            'public_token' => $token, 'status' => 'pending', 'expires_at' => now()->addDays(90),
        ]);

        $res = $this->getJson("/api/public/report/{$token}");
        $res->assertStatus(200);
        // A data de início da garantia = sold_at da venda (o único dado de venda).
        $this->assertSame($this->sale->sold_at->toDateString(), $res->json('data.warranty.start_date'));
    }

    public function test_public_endpoint_exposes_company_socials_when_set(): void
    {
        $this->company->update(['instagram' => 'https://instagram.com/stand', 'facebook' => 'https://fb.com/stand']);
        $token = SatisfactionReport::generateToken();
        SatisfactionReport::create([
            'company_id' => $this->company->id, 'car_sale_id' => $this->sale->id, 'car_id' => $this->car->id,
            'public_token' => $token, 'status' => 'pending', 'expires_at' => now()->addDays(90),
        ]);

        $res = $this->getJson("/api/public/report/{$token}");
        $res->assertStatus(200)
            ->assertJsonPath('data.company.instagram', 'https://instagram.com/stand')
            ->assertJsonPath('data.company.facebook', 'https://fb.com/stand')
            ->assertJsonPath('data.company.website', null)
            ->assertJsonPath('data.company.youtube', null);
    }

    public function test_public_endpoint_marks_opened(): void
    {
        $token = SatisfactionReport::generateToken();
        $report = SatisfactionReport::create([
            'company_id' => $this->company->id, 'car_sale_id' => $this->sale->id, 'car_id' => $this->car->id,
            'public_token' => $token, 'status' => 'pending', 'expires_at' => now()->addDays(90),
        ]);

        $this->getJson("/api/public/report/{$token}")->assertStatus(200);
        $fresh = $report->fresh();
        $this->assertSame('opened', $fresh->status);
        $this->assertNotNull($fresh->opened_at);
    }

    public function test_public_endpoint_never_leaks_buyer_pii(): void
    {
        $token = SatisfactionReport::generateToken();
        SatisfactionReport::create([
            'company_id' => $this->company->id, 'car_sale_id' => $this->sale->id, 'car_id' => $this->car->id,
            'public_token' => $token, 'status' => 'pending', 'expires_at' => now()->addDays(90),
        ]);

        $body = $this->getJson("/api/public/report/{$token}")->getContent();
        $this->assertStringNotContainsString('Carlos Comprador', $body);
        $this->assertStringNotContainsString('carlos@buyer.pt', $body);
        $this->assertStringNotContainsString('919999999', $body);
        // Preço da venda NÃO sai (só a data de início da garantia é permitida).
        $this->assertStringNotContainsString('20000', $body);
    }

    public function test_invalid_token_returns_404(): void
    {
        $this->getJson('/api/public/report/does-not-exist-token')->assertStatus(404);
    }

    public function test_expired_token_returns_404(): void
    {
        $token = SatisfactionReport::generateToken();
        SatisfactionReport::create([
            'company_id' => $this->company->id, 'car_sale_id' => $this->sale->id, 'car_id' => $this->car->id,
            'public_token' => $token, 'status' => 'pending', 'expires_at' => now()->subDay(),
        ]);
        $this->getJson("/api/public/report/{$token}")->assertStatus(404);
    }
}
