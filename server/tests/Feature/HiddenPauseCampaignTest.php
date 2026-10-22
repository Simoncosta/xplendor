<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Pausar campanha" escondido até haver gestão de anúncios (decisão de 22/10/2026): o servidor
 * recusa com uma mensagem clara e nunca chega à Meta; as outras ações não mudam.
 */
class HiddenPauseCampaignTest extends TestCase
{
    use RefreshDatabase;

    public function test_pause_campaign_is_refused_without_calling_the_meta(): void
    {
        Http::fake();
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $company = Company::create(['nipc' => '500019551', 'fiscal_name' => 'Stand Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->enable($company->id, 'stock');
        $admin = User::factory()->create(['company_id' => $company->id, 'role' => 'admin']);
        $car = Car::withoutEvents(fn () => Car::factory()->create(['company_id' => $company->id]));

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/companies/{$company->id}/cars/{$car->id}/execute-action", ['action' => 'pause_campaign', 'context' => ['target_id' => '123']])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Esta ação ainda não está disponível: a ligação à Meta só permite ler os anúncios.']);
        Http::assertNothingSent();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/companies/{$company->id}/cars/{$car->id}/execute-action", ['action' => 'suggest_new_vehicle'])
            ->assertOk()->assertJsonPath('data.status', 'stub');
    }
}
