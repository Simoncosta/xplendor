<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarInteraction;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Dedup de interações: PARTE 1 (dedup no ponto de entrada, TrackController) e
 * PARTE 2 (comando de limpeza histórica interactions:dedup, dry-run + apply).
 */
class CarInteractionDedupTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Car $car;

    protected function setUp(): void
    {
        parent::setUp();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan', 'price' => 0, 'car_limit' => 99,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->company = Company::create([
            'nipc' => '500000800', 'fiscal_name' => 'Empresa A', 'plan_id' => $planId,
            'subscription_status' => 'active', 'public_api_token' => (string) Str::uuid(),
        ]);
        $this->car = Car::factory()->create(['company_id' => $this->company->id]);
    }

    private function track(string $type, string $visitorId, array $extra = [])
    {
        return $this->postJson('/api/public/track?token=' . $this->company->public_api_token, [
            'type' => $type,
            'data' => ['car_id' => $this->car->id, ...$extra],
            'tracking' => ['visitor_id' => $visitorId, 'session_id' => (string) Str::uuid()],
        ]);
    }

    // ── PARTE 1 — dedup no ponto de entrada ──────────────────────────────────

    public function test_second_identical_click_within_5s_is_deduped(): void
    {
        $v = (string) Str::uuid();
        $this->track('whatsapp_click', $v)->assertStatus(201);
        // 2.º clique idêntico imediatamente a seguir → deduped, não grava.
        $this->track('whatsapp_click', $v)->assertStatus(200)->assertJsonPath('deduped', true);

        $this->assertSame(1, CarInteraction::where('car_id', $this->car->id)
            ->where('interaction_type', 'whatsapp_click')->count());
    }

    public function test_different_visitors_are_both_saved_the_car120_case(): void
    {
        // Dois visitantes DIFERENTES no mesmo carro/tipo → NÃO é duplicado.
        $this->track('whatsapp_click', (string) Str::uuid())->assertStatus(201);
        $this->track('whatsapp_click', (string) Str::uuid())->assertStatus(201);

        $this->assertSame(2, CarInteraction::where('car_id', $this->car->id)
            ->where('interaction_type', 'whatsapp_click')->count());
    }

    public function test_same_visitor_after_5s_is_saved(): void
    {
        $v = (string) Str::uuid();
        // Primeiro clique gravado "há 6 segundos" (fora da janela de 5s).
        DB::table('car_interactions')->insert([
            'company_id' => $this->company->id, 'car_id' => $this->car->id,
            'interaction_type' => 'whatsapp_click', 'visitor_id' => $v,
            'session_id' => (string) Str::uuid(),
            'created_at' => now()->subSeconds(6), 'updated_at' => now()->subSeconds(6),
        ]);
        // Novo clique agora → clique real, deve gravar (fica 2).
        $this->track('whatsapp_click', $v)->assertStatus(201);

        $this->assertSame(2, CarInteraction::where('car_id', $this->car->id)
            ->where('interaction_type', 'whatsapp_click')->count());
    }

    public function test_different_types_same_visitor_are_both_saved(): void
    {
        $v = (string) Str::uuid();
        $this->track('whatsapp_click', $v)->assertStatus(201);
        $this->track('call_click', $v)->assertStatus(201); // tipo diferente → não é duplicado

        $this->assertSame(2, CarInteraction::where('car_id', $this->car->id)->count());
    }

    // ── PARTE 2 — comando de limpeza (dry-run + apply) ───────────────────────

    /** Semeia: 1 par duplicado (2s) + 1 clique de outro visitante (27s) — o caso 120. */
    private function seedHistory(string $vDup, string $vOther): array
    {
        $base = Carbon::parse('2026-09-11 18:33:00');

        $keepId = DB::table('car_interactions')->insertGetId($this->row($vDup, $base));                 // manter (1.º da rajada)
        $dupId  = DB::table('car_interactions')->insertGetId($this->row($vDup, $base->copy()->addSeconds(2))); // apagar (2s depois)
        $otherId = DB::table('car_interactions')->insertGetId($this->row($vOther, $base->copy()->addSeconds(27))); // manter (visitante diferente, 27s)

        return [$keepId, $dupId, $otherId];
    }

    private function row(string $visitorId, Carbon $at): array
    {
        return [
            'company_id' => $this->company->id, 'car_id' => $this->car->id,
            'interaction_type' => 'whatsapp_click', 'visitor_id' => $visitorId,
            'session_id' => (string) Str::uuid(),
            'created_at' => $at, 'updated_at' => $at,
        ];
    }

    public function test_dry_run_lists_only_the_duplicate_and_deletes_nothing(): void
    {
        [$keepId, $dupId, $otherId] = $this->seedHistory((string) Str::uuid(), (string) Str::uuid());

        $this->artisan('interactions:dedup')
            ->assertExitCode(0);

        // Dry-run não apaga nada.
        $this->assertDatabaseHas('car_interactions', ['id' => $keepId]);
        $this->assertDatabaseHas('car_interactions', ['id' => $dupId]);
        $this->assertDatabaseHas('car_interactions', ['id' => $otherId]);
    }

    public function test_apply_deletes_only_the_technical_duplicate(): void
    {
        [$keepId, $dupId, $otherId] = $this->seedHistory((string) Str::uuid(), (string) Str::uuid());

        $this->artisan('interactions:dedup --apply')
            ->expectsConfirmation('Vais APAGAR 1 registos de produção. Confirmas?', 'yes')
            ->assertExitCode(0);

        // Só o duplicado técnico (2s, mesmo visitante) foi apagado.
        $this->assertDatabaseMissing('car_interactions', ['id' => $dupId]);
        $this->assertDatabaseHas('car_interactions', ['id' => $keepId]);   // 1.º da rajada intacto
        $this->assertDatabaseHas('car_interactions', ['id' => $otherId]);  // visitante diferente (27s) intacto

        // Idempotente: correr de novo não apaga mais nada.
        $this->artisan('interactions:dedup --apply')->assertExitCode(0);
        $this->assertSame(2, CarInteraction::count());
    }
}
