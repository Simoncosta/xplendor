<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CarService;
use App\Services\ExpenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * F0 do isolamento: os IDs de outra empresa dão 422, campo a campo, e os serviços voltam
 * a verificar (despesas: categoria, fornecedor e viatura; viaturas: vendedor).
 */
class CompanyIsolationF0Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $other;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'Plano', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500000910', 'fiscal_name' => 'Empresa A Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500000911', 'fiscal_name' => 'Empresa B Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->admin = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
    }

    private function draftCar(Company $company, User $user): Car
    {
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/companies/{$company->id}/cars", ['status' => 'draft', 'vehicle_type' => 'car'])
            ->assertStatus(200);

        return Car::where('company_id', $company->id)->latest('id')->firstOrFail();
    }

    /** @return array<string, array{0: string}> */
    public static function expenseFields(): array
    {
        return ['categoria' => ['expense_category_id'], 'fornecedor' => ['supplier_id'], 'viatura' => ['car_id']];
    }

    private function foreignIdFor(string $field): int
    {
        return match ($field) {
            'expense_category_id' => ExpenseCategory::create(['company_id' => $this->other->id, 'name' => 'Da outra'])->id,
            'supplier_id' => Supplier::create(['company_id' => $this->other->id, 'name' => 'Fornecedor da outra'])->id,
            'car_id' => $this->draftCar($this->other, User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']))->id,
        };
    }

    private function ownIdFor(string $field): int
    {
        return match ($field) {
            'expense_category_id' => ExpenseCategory::create(['company_id' => $this->company->id, 'name' => 'Nossa'])->id,
            'supplier_id' => Supplier::create(['company_id' => $this->company->id, 'name' => 'Fornecedor nosso'])->id,
            'car_id' => $this->draftCar($this->company, $this->admin)->id,
        };
    }

    /** @dataProvider expenseFields */
    public function test_creating_an_expense_with_an_id_of_another_company_gives_422(string $field): void
    {
        $foreign = $this->foreignIdFor($field);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/companies/{$this->company->id}/expenses", ['description' => 'Teste', 'amount' => 10, 'date' => '2026-10-01', $field => $foreign])
            ->assertStatus(422)->assertJsonValidationErrors([$field]);
        $this->assertSame(0, Expense::where('company_id', $this->company->id)->count());
    }

    /** @dataProvider expenseFields */
    public function test_updating_an_expense_with_an_id_of_another_company_gives_422(string $field): void
    {
        $expense = Expense::create(['company_id' => $this->company->id, 'description' => 'Teste', 'amount' => 10, 'date' => '2026-10-01']);
        $foreign = $this->foreignIdFor($field);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/companies/{$this->company->id}/expenses/{$expense->id}", [$field => $foreign])
            ->assertStatus(422)->assertJsonValidationErrors([$field]);
        $this->assertNull($expense->fresh()->{$field});
    }

    /** @dataProvider expenseFields */
    public function test_an_id_of_the_same_company_is_still_accepted(string $field): void
    {
        $own = $this->ownIdFor($field);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/companies/{$this->company->id}/expenses", ['description' => 'Teste', 'amount' => 10, 'date' => '2026-10-01', $field => $own])
            ->assertStatus(200);
        $this->assertSame($own, (int) Expense::where('company_id', $this->company->id)->value($field));
    }

    /** @dataProvider expenseFields */
    public function test_the_expense_service_checks_again(string $field): void
    {
        $foreign = $this->foreignIdFor($field);
        $this->expectException(ValidationException::class);

        app(ExpenseService::class)->store(['company_id' => $this->company->id, 'description' => 'Teste', 'amount' => 10, 'date' => '2026-10-01', $field => $foreign]);
    }

    public function test_a_car_with_a_seller_of_another_company_gives_422_on_create_and_update(): void
    {
        $foreignSeller = User::factory()->create(['company_id' => $this->other->id, 'role' => 'user']);
        $url = "/api/v1/companies/{$this->company->id}/cars";

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($url, ['status' => 'draft', 'vehicle_type' => 'car', 'seller_user_id' => $foreignSeller->id])
            ->assertStatus(422)->assertJsonValidationErrors(['seller_user_id']);

        $car = $this->draftCar($this->company, $this->admin);
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("{$url}/{$car->id}", ['_method' => 'PUT', 'status' => 'draft', 'vehicle_type' => 'car', 'seller_user_id' => $foreignSeller->id])
            ->assertStatus(422)->assertJsonValidationErrors(['seller_user_id']);
        $this->assertNull($car->fresh()->seller_user_id);

        $ownSeller = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user']);
        $this->actingAs($this->admin, 'sanctum')
            ->postJson($url, ['status' => 'draft', 'vehicle_type' => 'car', 'seller_user_id' => $ownSeller->id])
            ->assertStatus(200);
    }

    public function test_the_public_seller_contact_never_comes_from_another_company(): void
    {
        $foreignSeller = User::factory()->create(['company_id' => $this->other->id, 'role' => 'user', 'name' => 'Vendedor de fora', 'mobile' => '910000000']);
        $car = $this->draftCar($this->company, $this->admin);
        DB::table('cars')->where('id', $car->id)->update(['seller_user_id' => $foreignSeller->id]); // dado antigo, anterior à validação

        $out = app(CarService::class)->appendPublicSellerContact(Car::find($car->id));

        $this->assertNotSame($foreignSeller->id, $out->seller_contact['id'] ?? null);
        $this->assertSame($this->admin->id, $out->seller_contact['id'] ?? null); // fica o contacto do stand
    }

    public function test_the_public_lead_and_view_only_accept_cars_of_the_token_company(): void
    {
        $this->company->forceFill(['public_api_token' => (string) \Illuminate\Support\Str::uuid()])->save();
        $own = $this->draftCar($this->company, $this->admin);
        $foreign = $this->draftCar($this->other, User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']));
        $token = $this->company->public_api_token;
        $lead = ['name' => 'Rui', 'email' => 'rui@exemplo.pt', 'message' => 'Olá'];

        $this->postJson("/api/public/car-lead?token={$token}", $lead + ['car_id' => $foreign->id])->assertStatus(422)->assertJsonValidationErrors(['car_id']);
        $this->postJson("/api/public/car-view?token={$token}", ['car_id' => $foreign->id])->assertStatus(422)->assertJsonValidationErrors(['car_id']);
        $this->assertSame(0, DB::table('car_leads')->where('car_id', $foreign->id)->count());
        $this->assertSame(0, DB::table('car_views')->where('car_id', $foreign->id)->count());

        $this->postJson("/api/public/car-view?token={$token}", ['car_id' => $own->id])->assertOk();
    }

    public function test_an_ad_campaign_cannot_point_to_a_car_of_another_company(): void
    {
        $foreign = $this->draftCar($this->other, User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']));

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/companies/{$this->company->id}/cars/{$foreign->id}/ad-campaigns", ['platform' => 'meta', 'campaign_id' => '123', 'level' => 'campaign'])
            ->assertNotFound();
        $this->assertSame(0, DB::table('car_ad_campaigns')->count());
    }
}
