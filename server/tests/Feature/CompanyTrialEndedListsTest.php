<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collaborator;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Regressão de "Method isNow does not exist" (30/10/2026): uma empresa ainda em 'trial' com o prazo
 * já passado. O middleware da subscrição só a passa a 'expired' quando alguém dessa empresa faz um
 * pedido; até lá, qualquer ecrã que a mostre (o "is_active" sai em cada empresa serializada) passava
 * pelo ramo que rebentava. Listas e detalhes de Empresas, Colaboradores e Agências, com o root e com
 * a agência.
 */
class CompanyTrialEndedListsTest extends TestCase
{
    use RefreshDatabase;

    private int $plan;
    private Company $xplendor;
    private Company $trialEnded;
    private Company $agency;
    private Company $client;
    private Company $agencyTrialEnded;
    private User $root;
    private User $agencyMember;
    private User $agencyAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['data' => []], 200)]);
        Queue::fake();
        Mail::fake();
        $this->plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->xplendor = $this->company('XPLENDOR');
        $this->root = User::factory()->create(['company_id' => $this->xplendor->id, 'role' => 'root']);
        $this->agency = $this->company('Agência Exemplo');
        $this->agencyMember = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'user']);
        $this->agencyAdmin = User::factory()->create(['company_id' => $this->agency->id, 'role' => 'admin']);
        $this->client = $this->company('Cliente Gerido Exemplo');
        $this->agencyTrialEnded = $this->company('Agência Em Teste Exemplo');

        // A relação de gestão, pela plataforma (enquanto todas estão ativas).
        foreach ([$this->agency, $this->agencyTrialEnded] as $a) {
            $this->as($this->root)->patchJson("/api/v1/admin/companies/{$a->id}/agency", ['enabled' => true])->assertOk();
        }
        $this->as($this->root)->putJson("/api/v1/admin/companies/{$this->client->id}/management", ['agency_company_id' => $this->agency->id])->assertOk();

        // O período de teste acabou e ninguém dessas empresas entrou desde então: continuam em 'trial'.
        $this->trialEnded = $this->company('Stand Em Teste Exemplo');
        foreach ([$this->trialEnded, $this->client, $this->agencyTrialEnded] as $c) {
            $c->forceFill(['subscription_status' => Company::SUBSCRIPTION_STATUS_TRIAL, 'trial_starts_at' => now()->subDays(33), 'trial_ends_at' => now()->subDays(3)])->save();
        }
        Collaborator::create(['company_id' => $this->trialEnded->id, 'name' => 'Colaborador Exemplo', 'status' => 'active']);
    }

    private function company(string $name): Company
    {
        return Company::create(['nipc' => (string) random_int(500000000, 599999999), 'fiscal_name' => $name, 'plan_id' => $this->plan, 'subscription_status' => 'active']);
    }

    private function as(User $u): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    public function test_the_access_rule_with_the_trial_ended_today_yesterday_and_tomorrow(): void
    {
        $c = $this->trialEnded;
        $this->assertFalse($c->hasPlatformAccess());
        $this->assertFalse($c->is_active);
        $this->assertTrue($c->isTrialExpired());

        // Ainda no prazo (incluindo o próprio instante do fim, como no scope SQL: trial_ends_at >= now()).
        $this->travelTo(now()->startOfSecond());
        $c->forceFill(['trial_ends_at' => now()])->save();
        $this->assertTrue($c->fresh()->hasPlatformAccess());
        $this->assertTrue(Company::active()->whereKey($c->id)->exists());
        $c->forceFill(['trial_ends_at' => now()->addDay()])->save();
        $this->assertTrue($c->fresh()->hasPlatformAccess());
        $c->forceFill(['trial_ends_at' => now()->subSecond()])->save();
        $this->assertFalse($c->fresh()->hasPlatformAccess());
        $this->assertFalse(Company::active()->whereKey($c->id)->exists());

        // Empresa gerida: o prazo dela não conta, conta a agência.
        $this->assertTrue($this->client->fresh()->hasPlatformAccess());
    }

    public function test_companies_list_and_detail(): void
    {
        $rows = collect($this->as($this->root)->getJson('/api/v1/companies?page=1&perPage=100')->assertOk()->json('data.data') ?? []);
        $this->assertNotEmpty($rows);
        $byId = $rows->keyBy('id');
        $this->assertFalse($byId[$this->trialEnded->id]['is_active']);
        $this->assertTrue($byId[$this->client->id]['is_active']); // gerida por uma agência ativa
        $this->assertFalse($byId[$this->agencyTrialEnded->id]['is_active']);

        $this->as($this->root)->getJson("/api/v1/companies/{$this->trialEnded->id}")->assertOk()->assertJsonPath('data.is_active', false);
        $this->as($this->root)->getJson("/api/v1/companies/{$this->client->id}")->assertOk()->assertJsonPath('data.is_active', true);

        // A consola da plataforma (Empresas do /admin).
        $admin = collect($this->as($this->root)->getJson('/api/v1/admin/companies')->assertOk()->json('data.companies') ?? $this->as($this->root)->getJson('/api/v1/admin/companies')->json('data'));
        $this->assertFalse($admin->firstWhere('id', $this->trialEnded->id)['has_access']);
        $this->assertTrue($admin->firstWhere('id', $this->client->id)['has_access']);
    }

    public function test_collaborators_list_and_detail(): void
    {
        $list = $this->as($this->root)->getJson("/api/v1/companies/{$this->trialEnded->id}/collaborators")->assertOk();
        $this->assertStringContainsString('Colaborador Exemplo', $list->getContent());
        $id = Collaborator::where('company_id', $this->trialEnded->id)->value('id');
        $this->as($this->root)->getJson("/api/v1/companies/{$this->trialEnded->id}/collaborators/{$id}")->assertOk();
        $this->as($this->root)->getJson("/api/v1/companies/{$this->trialEnded->id}/users")->assertOk();
    }

    public function test_agencies_list_and_the_agency_panel(): void
    {
        $agencies = collect($this->as($this->root)->getJson('/api/v1/admin/agencies')->assertOk()->json('data.agencies'));
        $this->assertEqualsCanonicalizing([$this->agency->id, $this->agencyTrialEnded->id], $agencies->pluck('id')->all());
        $this->as($this->root)->getJson("/api/v1/admin/companies/{$this->client->id}/management")->assertOk();

        // A agência vê o cliente gerido (com o período de teste dele acabado).
        foreach (['/companies', '/panel', '/assignments', '/company-requests'] as $path) {
            $this->as($this->agencyAdmin)->getJson("/api/v1/agencies/{$this->agency->id}{$path}")->assertOk();
        }
        // O seletor da empresa de trabalho da agência (GET /companies): a agência e o cliente gerido.
        $mine = collect($this->as($this->agencyAdmin)->getJson('/api/v1/companies')->assertOk()->json('data')); // sem perPage: a lista toda
        $this->assertTrue($mine->firstWhere('id', $this->client->id)['is_active']);
    }
}
