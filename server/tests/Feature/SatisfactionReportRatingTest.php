<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarSale;
use App\Models\Company;
use App\Models\SatisfactionReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DMS Pós-venda (Incremento 3) — avaliação por estrelas + lógica condicional
 * (≥4 → Google; <4 → interno) + google_review_url na empresa. Cobre a submissão
 * ÚNICA (fecha após submeter) e o isolamento do lado interno.
 */
class SatisfactionReportRatingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private Car $car;
    private CarSale $sale;
    private SatisfactionReport $report;

    protected function setUp(): void
    {
        parent::setUp();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan', 'price' => 0, 'car_limit' => 99,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->company = Company::create(['nipc' => '500000098', 'fiscal_name' => 'Stand Aval Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $this->car = Car::create(['company_id' => $this->company->id, 'vehicle_type' => 'car', 'status' => 'sold']);
        $this->sale = CarSale::create([
            'car_id' => $this->car->id, 'company_id' => $this->company->id,
            'sale_price' => 20000, 'buyer_gender' => 'male', 'buyer_age_range' => '31-45',
            'sale_channel' => 'in_person', 'sold_at' => now(), 'buyer_name' => 'Carlos Comprador',
        ]);
        $this->report = SatisfactionReport::create([
            'company_id' => $this->company->id, 'car_sale_id' => $this->sale->id, 'car_id' => $this->car->id,
            'public_token' => SatisfactionReport::generateToken(), 'status' => 'pending', 'expires_at' => now()->addDays(90),
        ]);
    }

    private function ratingUrl(): string
    {
        return "/api/public/report/{$this->report->public_token}/rating";
    }

    public function test_google_review_url_persists_on_company_and_is_exposed_public(): void
    {
        $url = 'https://g.page/r/CabcDEF123';
        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/v1/companies/{$this->company->id}", ['google_review_url' => $url])
            ->assertStatus(200);
        $this->assertDatabaseHas('companies', ['id' => $this->company->id, 'google_review_url' => $url]);

        $res = $this->getJson("/api/public/report/{$this->report->public_token}");
        $res->assertStatus(200)->assertJsonPath('data.company.google_review_url', $url);
    }

    public function test_high_rating_stores_public_message_for_google(): void
    {
        $res = $this->postJson($this->ratingUrl(), ['rating' => 5, 'comment' => 'Atendimento excelente!']);
        $res->assertStatus(200)->assertJsonPath('data.review_message', 'Atendimento excelente!');

        $this->assertDatabaseHas('satisfaction_reports', [
            'id' => $this->report->id, 'rating' => 5, 'status' => 'submitted',
            'public_message' => 'Atendimento excelente!', 'internal_feedback' => null,
        ]);
        $this->assertNotNull($this->report->fresh()->submitted_at);
    }

    public function test_low_rating_stores_internal_feedback_only(): void
    {
        $this->postJson($this->ratingUrl(), ['rating' => 2, 'comment' => 'Demorou demasiado tempo'])->assertStatus(200);

        $this->assertDatabaseHas('satisfaction_reports', [
            'id' => $this->report->id, 'rating' => 2, 'status' => 'submitted',
            'public_message' => null, 'internal_feedback' => 'Demorou demasiado tempo',
        ]);

        // O comentário <4 é INTERNO — não sai no público.
        $body = $this->getJson("/api/public/report/{$this->report->public_token}")->getContent();
        $this->assertStringNotContainsString('Demorou demasiado tempo', $body);
    }

    public function test_single_submission_only(): void
    {
        $this->postJson($this->ratingUrl(), ['rating' => 5, 'comment' => 'Top'])->assertStatus(200);
        // Segunda tentativa → recusada (não reavaliar).
        $this->postJson($this->ratingUrl(), ['rating' => 1, 'comment' => 'mudei de ideias'])->assertStatus(422);
        // Mantém a 1.ª submissão.
        $this->assertSame(5, $this->report->fresh()->rating);
    }

    public function test_public_show_reflects_submitted_state(): void
    {
        $this->postJson($this->ratingUrl(), ['rating' => 4, 'comment' => 'Muito bom'])->assertStatus(200);

        $res = $this->getJson("/api/public/report/{$this->report->public_token}");
        $res->assertStatus(200)
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.rating', 4)
            ->assertJsonPath('data.review_message', 'Muito bom');
    }

    public function test_rating_is_validated(): void
    {
        $this->postJson($this->ratingUrl(), ['rating' => 6])->assertStatus(422);
        $this->postJson($this->ratingUrl(), ['comment' => 'sem estrelas'])->assertStatus(422);
    }

    public function test_internal_review_endpoint_is_tenant_scoped(): void
    {
        $this->postJson($this->ratingUrl(), ['rating' => 2, 'comment' => 'A melhorar'])->assertStatus(200);

        $url = "/api/v1/companies/{$this->company->id}/cars/{$this->car->id}/satisfaction-report/review";
        $this->actingAs($this->user, 'sanctum')->getJson($url)
            ->assertStatus(200)
            ->assertJsonPath('data.review.rating', 2)
            ->assertJsonPath('data.review.comment', 'A melhorar');

        $other = Company::create(['nipc' => '500000099', 'fiscal_name' => 'Outra Lda', 'plan_id' => $this->company->plan_id, 'subscription_status' => 'active']);
        $intruder = User::factory()->create(['company_id' => $other->id, 'role' => 'admin']);
        $this->actingAs($intruder, 'sanctum')->getJson($url)->assertStatus(403);
    }
}
