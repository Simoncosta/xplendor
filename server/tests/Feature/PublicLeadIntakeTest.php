<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarLead;
use App\Models\CarModel;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * XPLENDOR — Regressão: entrada de leads via API PÚBLICA (sites dos clientes,
 * ex. Quebom). Depois das mudanças na Lead (CRM/estados + Fases 1-3), uma lead
 * do site — que envia SÓ os campos básicos (nome/email/telefone/veículo/mensagem)
 * — tem de continuar a ENTRAR sem erro, com estado inicial válido ("new"/Nova),
 * e aparecer no funil. Se falhasse, perdiam-se leads reais em silêncio.
 */
class PublicLeadIntakeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;
    private int $carId;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake(); // o store envia NewLeadMail — não queremos mail real no teste

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan', 'price' => 0, 'car_limit' => 999,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->company = Company::create([
            'nipc' => '500001200', 'fiscal_name' => 'Quebom Lda', 'trade_name' => 'Quebom',
            'email' => 'stand@quebom.pt', 'plan_id' => $planId, 'subscription_status' => 'active',
            'public_api_token' => (string) Str::uuid(),
        ]);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);

        $brand = CarBrand::firstOrCreate(['slug' => 'bmw'], ['name' => 'BMW', 'vehicle_type' => 'car']);
        $model = CarModel::firstOrCreate(['name' => 'Serie 3', 'car_brand_id' => $brand->id]);
        $this->carId = Car::factory()->create(['company_id' => $this->company->id, 'car_brand_id' => $brand->id, 'car_model_id' => $model->id])->id;
    }

    private function publicUrl(): string
    {
        return '/api/public/car-lead?token=' . $this->company->public_api_token;
    }

    public function test_site_lead_with_only_basic_fields_enters_as_new(): void
    {
        // Exatamente o que um site envia: nome, contacto, veículo, mensagem. Sem
        // status, sem cliente, sem os campos novos do CRM/venda.
        $res = $this->postJson($this->publicUrl(), [
            'name' => 'Maria Visitante',
            'email' => 'maria@exemplo.pt',
            'phone' => '912 000 111',
            'message' => 'Tenho interesse nesta viatura.',
            'car_id' => $this->carId,
        ]);

        $res->assertStatus(200);

        // Entrou, ligada à empresa do token, com estado inicial válido "new" (Nova).
        $this->assertDatabaseHas('car_leads', [
            'company_id' => $this->company->id,
            'car_id' => $this->carId,
            'email' => 'maria@exemplo.pt',
            'status' => 'new',
        ]);

        // Aparece no funil (fase "new") — sem erro na leitura do CRM.
        $lead = CarLead::where('company_id', $this->company->id)->first();
        $this->assertSame('new', $lead->status);
        $this->assertContains($lead->status, CarLead::FUNNEL_STAGES); // cai numa coluna do funil
        $this->assertNull($lead->closed_at);
    }

    public function test_site_lead_appears_in_stand_funnel_index(): void
    {
        $this->postJson($this->publicUrl(), [
            'name' => 'João Site', 'email' => 'joao.site@exemplo.pt', 'car_id' => $this->carId,
        ])->assertStatus(200);

        // A lista/funil do stand (lado autenticado) mostra a lead entrada pelo site.
        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/companies/{$this->company->id}/leads")
            ->assertStatus(200);

        $emails = collect($res->json('data'))->pluck('email');
        $this->assertTrue($emails->contains('joao.site@exemplo.pt'));
    }

    public function test_site_lead_without_phone_still_enters(): void
    {
        // Muitos formulários não têm telefone — não pode rejeitar.
        $this->postJson($this->publicUrl(), [
            'name' => 'Sem Telefone', 'email' => 'semtel@exemplo.pt', 'car_id' => $this->carId,
        ])->assertStatus(200);

        $this->assertDatabaseHas('car_leads', ['email' => 'semtel@exemplo.pt', 'status' => 'new']);
    }

    public function test_invalid_token_rejected(): void
    {
        // Sanidade: sem token válido não entra (segurança, não regressão).
        $this->postJson('/api/public/car-lead?token=invalid', [
            'name' => 'X', 'email' => 'x@x.pt', 'car_id' => $this->carId,
        ])->assertStatus(401);
    }
}
