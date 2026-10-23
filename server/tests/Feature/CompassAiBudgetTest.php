<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiRequest;
use App\Models\Company;
use App\Models\User;
use App\Services\Compass\CompassAiBudget;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bússola: o limite mensal da bussola_jogadas por empresa (decisão de 23/10: 60 pedidos por mês). Conta o
 * mês civil de Lisboa, só a função bussola_jogadas e só a empresa; o painel do root mostra o gasto do mês
 * ao lado do teto do Radar. IA simulada (nenhum pedido real).
 */
class CompassAiBudgetTest extends TestCase
{
    use RefreshDatabase;

    // 00:30 UTC de 1/11 = 00:30 de 1/11 em Lisboa (hora de inverno desde 25/10): já é novembro.
    private const NOVEMBER_IN_LISBON = '2026-11-01 00:30:00';

    private Company $company;
    private Company $other;
    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $team = Company::create(['nipc' => '500019100', 'fiscal_name' => 'Equipa Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->company = Company::create(['nipc' => '500019101', 'fiscal_name' => 'Tasca Lda', 'trade_name' => 'Tasca Exemplo', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500019102', 'fiscal_name' => 'Stand Lda', 'trade_name' => 'Stand Exemplo', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->root = User::factory()->create(['company_id' => $team->id, 'role' => 'root']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function requests(int $n, ?int $companyId, string $at, string $mode = 'bussola_jogadas', float $cost = 0.005): void
    {
        for ($i = 0; $i < $n; $i++) {
            AiRequest::forceCreate(['company_id' => $companyId, 'mode' => $mode, 'status' => AiRequest::DONE, 'input' => [], 'model' => 'claude-haiku-5-5',
                'prompt_version' => "{$mode}-v1", 'cost_usd' => $cost, 'created_at' => $at, 'updated_at' => $at]);
        }
    }

    public function test_sixty_requests_per_month_per_company_in_the_lisbon_month(): void
    {
        $now = CarbonImmutable::parse('2026-10-20 10:00:00');
        $this->assertSame(60, CompassAiBudget::monthlyLimit());
        // A fronteira é a de Lisboa (hora de verão, UTC+1): 22:30 UTC de 30/09 ainda é setembro; 23:10 UTC já é outubro.
        $this->requests(5, $this->company->id, '2026-09-30 22:30:00');
        $this->requests(2, $this->company->id, '2026-09-30 23:10:00');
        $this->assertSame(2, CompassAiBudget::used($this->company->id, $now));
        $this->requests(57, $this->company->id, '2026-10-02 09:00:00');
        $this->requests(3, $this->company->id, '2026-10-05 09:00:00', 'radar_porque');
        $this->requests(10, $this->other->id, '2026-10-05 09:00:00');
        $this->assertSame(59, CompassAiBudget::used($this->company->id, $now));
        $this->assertTrue(CompassAiBudget::allows($this->company->id, $now));

        $this->requests(1, $this->company->id, '2026-10-19 09:00:00');
        $this->assertFalse(CompassAiBudget::allows($this->company->id, $now));
        $this->assertTrue(CompassAiBudget::allows($this->other->id, $now), 'o limite é por empresa');

        // Em novembro o contador recomeça (00:00 UTC de 1/11 = 00:00 em Lisboa, já em hora de inverno).
        $this->assertTrue(CompassAiBudget::allows($this->company->id, CarbonImmutable::parse(self::NOVEMBER_IN_LISBON)));
    }

    public function test_the_limit_comes_from_the_configuration(): void
    {
        config(['radar.compass.monthly_requests_per_company' => 2]);
        $this->requests(2, $this->company->id, '2026-10-02 09:00:00');
        $this->assertFalse(CompassAiBudget::allows($this->company->id, CarbonImmutable::parse('2026-10-20 10:00:00')));
    }

    public function test_the_root_panel_shows_the_month_next_to_the_radar_cap(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 10:00:00'));
        $this->requests(60, $this->company->id, '2026-10-02 09:00:00', cost: 0.006);
        $this->requests(12, $this->other->id, '2026-10-03 09:00:00');
        $this->requests(4, $this->company->id, '2026-09-29 09:00:00');

        $data = $this->actingAs($this->root, 'sanctum')->getJson('/api/v1/admin/radar')->assertOk()->json('data');
        $this->assertArrayHasKey('budget', $data, 'o teto do Radar continua lá');
        $compass = $data['compass'];
        $this->assertSame(['bussola_jogadas', 60, '2026-10', 72, 0.42], [$compass['function'], $compass['monthly_limit'], $compass['month'], $compass['requests_month'], $compass['cost_month_usd']]);
        $this->assertSame([['Tasca Exemplo', 60, 0.36, true], ['Stand Exemplo', 12, 0.06, false]],
            array_map(fn ($c) => [$c['company'], $c['requests'], $c['cost_usd'], $c['reached']], $compass['companies']));
    }
}
