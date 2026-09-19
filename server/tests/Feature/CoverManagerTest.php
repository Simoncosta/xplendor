<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CmReservationShiftSummary;
use App\Models\Company;
use App\Models\PingwinLocation;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\CoverManagerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * XPLENDOR — CoverManager Etapa 1: credenciais cifradas por loja + buscar/agregar
 * reservas guardando SÓ o agregado por turno. ⚠️ RGPD: ZERO PII na BD.
 */
class CoverManagerTest extends TestCase
{
    use RefreshDatabase;

    private Company $resto;
    private Company $auto;
    private User $restoUser;
    private User $autoUser;

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId([
            'name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->resto = Company::create(['nipc' => '500007100', 'fiscal_name' => 'Resto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->auto = Company::create(['nipc' => '500007101', 'fiscal_name' => 'Auto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->resto->id, 'restaurant');

        $this->restoUser = User::factory()->create(['company_id' => $this->resto->id, 'role' => 'admin']);
        $this->autoUser = User::factory()->create(['company_id' => $this->auto->id, 'role' => 'admin']);
    }

    private function location(array $attrs = []): PingwinLocation
    {
        return PingwinLocation::create(array_merge([
            'company_id' => $this->resto->id,
            'winrest_store_id' => 'w' . uniqid(),
            'display_name' => 'Yuko Baixa',
            'is_active' => true,
            'cm_slug' => 'yuko-baixa',
            'cm_token' => 'TOKEN-SECRETO',
        ], $attrs));
    }

    // ── Credencial cifrada por loja ────────────────────────────────────────────

    public function test_cm_token_is_stored_encrypted_and_never_exposed(): void
    {
        $loc = $this->location();

        $raw = DB::table('pingwin_locations')->where('id', $loc->id)->value('cm_token');
        $this->assertNotSame('TOKEN-SECRETO', $raw);
        $this->assertStringNotContainsString('TOKEN-SECRETO', (string) $raw);
        // O modelo decifra de volta…
        $this->assertSame('TOKEN-SECRETO', (string) $loc->fresh()->cm_token);
        // …mas nunca serializa o token (o UI usa cm_connected).
        $json = $loc->fresh()->toArray();
        $this->assertArrayNotHasKey('cm_token', $json);
        $this->assertTrue($json['cm_connected']);
    }

    // ── Agregação sem PII + regras de contagem ─────────────────────────────────

    public function test_aggregate_counts_by_shift_without_pii(): void
    {
        $svc = app(CoverManagerService::class);
        $reservs = [
            ['meal_shift' => 'almoço', 'for' => 4, 'status' => 'confirmed', 'user_name' => 'João', 'user_phone' => '912'],
            ['meal_shift' => 'lunch', 'for' => 2, 'status' => 'seated', 'provenance' => 'walk in', 'email' => 'a@b.pt'],
            ['meal_shift' => 'jantar', 'for' => 6, 'status' => 'confirmed'],
            ['meal_shift' => 'dinner', 'for' => 3, 'status' => '-cancelled'], // cancelada
            ['meal_shift' => 'qualquer', 'for' => 1, 'status' => 'confirmed'], // other
        ];

        $agg = $svc->aggregate($reservs);

        // Almoço: 2 reservas, 6 pessoas, 1 walk-in, 0 canceladas.
        $this->assertSame(['guests_total' => 6, 'reservations_count' => 2, 'walk_ins_count' => 1, 'cancelled_count' => 0], $agg['lunch']);
        // Jantar: 1 reserva/6 pessoas + 1 cancelada (não conta em guests/reservations).
        $this->assertSame(['guests_total' => 6, 'reservations_count' => 1, 'walk_ins_count' => 0, 'cancelled_count' => 1], $agg['dinner']);
        $this->assertSame(1, $agg['other']['reservations_count']);
    }

    public function test_sync_persists_only_aggregates_zero_pii_in_db(): void
    {
        $loc = $this->location();
        // Resposta do CoverManager COM PII — que NÃO pode chegar à BD.
        Http::fake([
            '*' => Http::response([
                'reservs' => [
                    ['meal_shift' => 'lunch', 'for' => 4, 'status' => 'confirmed', 'user_name' => 'Maria Silva', 'email' => 'maria@x.pt', 'user_phone' => '911222333'],
                    ['meal_shift' => 'dinner', 'for' => 2, 'status' => 'confirmed', 'user_name' => 'Rui'],
                ],
            ], 200),
        ]);

        app(CoverManagerService::class)->sync($this->resto->id, '2026-09-18');

        $lunch = CmReservationShiftSummary::where('location_id', $loc->id)->where('shift', 'lunch')->first();
        $this->assertNotNull($lunch);
        $this->assertSame(4, $lunch->guests_total);
        $this->assertSame(1, $lunch->reservations_count);

        // ⚠️ ZERO PII: nenhum valor pessoal em NENHUMA coluna da tabela agregada.
        $allRows = json_encode(DB::table('cm_reservation_shift_summary')->get());
        foreach (['Maria', 'maria@x.pt', '911222333', 'Rui', 'user_name', 'email'] as $pii) {
            $this->assertStringNotContainsString($pii, $allRows);
        }
        // A tabela só tem as colunas de números (+ chaves/datas) — sem colunas PII.
        $cols = \Illuminate\Support\Facades\Schema::getColumnListing('cm_reservation_shift_summary');
        foreach (['user_name', 'email', 'user_phone', 'raw_json', 'name', 'phone'] as $pii) {
            $this->assertNotContains($pii, $cols);
        }
    }

    public function test_sync_is_idempotent_upsert(): void
    {
        $loc = $this->location();
        Http::fake(['*' => Http::response(['reservs' => [
            ['meal_shift' => 'lunch', 'for' => 4, 'status' => 'confirmed'],
        ]], 200)]);

        app(CoverManagerService::class)->sync($this->resto->id, '2026-09-18');
        app(CoverManagerService::class)->sync($this->resto->id, '2026-09-18'); // re-sync mesmo dia

        $this->assertSame(1, CmReservationShiftSummary::where('location_id', $loc->id)->where('shift', 'lunch')->count());
    }

    public function test_one_location_failing_does_not_abort_others(): void
    {
        $bad = $this->location(['winrest_store_id' => 'bad', 'display_name' => 'Loja Má', 'cm_slug' => 'bad-slug']);
        $good = $this->location(['winrest_store_id' => 'good', 'display_name' => 'Loja Boa', 'cm_slug' => 'good-slug']);

        // A loja "bad-slug" devolve 500; a "good-slug" devolve reservas.
        Http::fake([
            '*good-slug*' => Http::response(['reservs' => [['meal_shift' => 'lunch', 'for' => 3, 'status' => 'ok']]], 200),
            '*bad-slug*' => Http::response('erro', 500),
        ]);

        $result = app(CoverManagerService::class)->sync($this->resto->id, '2026-09-18');

        $this->assertSame(1, $result['synced']);
        $this->assertContains('Loja Má', $result['failed']);
        // A loja boa foi guardada apesar da má ter falhado.
        $this->assertSame(1, CmReservationShiftSummary::where('location_id', $good->id)->count());
        $this->assertSame(0, CmReservationShiftSummary::where('location_id', $bad->id)->count());
    }

    // ── Endpoint: gate do módulo + tenancy ─────────────────────────────────────

    public function test_sync_endpoint_is_module_gated(): void
    {
        $this->actingAs($this->autoUser, 'sanctum')
            ->postJson("/api/v1/companies/{$this->auto->id}/integrations/covermanager/sync", ['date' => '2026-09-18'])
            ->assertStatus(403);
    }

    public function test_sync_endpoint_clear_message_without_credentials(): void
    {
        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson("/api/v1/companies/{$this->resto->id}/integrations/covermanager/sync", ['date' => '2026-09-18'])
            ->assertStatus(422);
    }

    public function test_location_endpoint_saves_cm_credentials_encrypted(): void
    {
        $loc = PingwinLocation::create(['company_id' => $this->resto->id, 'winrest_store_id' => 'w1', 'display_name' => 'X']);

        $this->actingAs($this->restoUser, 'sanctum')
            ->patchJson("/api/v1/companies/{$this->resto->id}/integrations/pingwin/locations/{$loc->id}", [
                'winrest_store_id' => 'w1', 'cm_slug' => 'yuko', 'cm_token' => 'SEGREDO-CM',
            ])->assertStatus(200);

        $raw = DB::table('pingwin_locations')->where('id', $loc->id)->value('cm_token');
        $this->assertNotSame('SEGREDO-CM', $raw);
        $this->assertSame('SEGREDO-CM', (string) $loc->fresh()->cm_token);
        $this->assertSame('yuko', $loc->fresh()->cm_slug);
    }

    // ── Token de EMPRESA + override por loja (resolução loja > empresa) ─────────

    private function connectCompanyToken(string $token = 'EMPRESA-TOKEN'): void
    {
        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson("/api/v1/companies/{$this->resto->id}/integrations/covermanager/connect", ['token' => $token])
            ->assertStatus(200);
    }

    public function test_company_token_is_stored_encrypted_and_connected_status(): void
    {
        $this->connectCompanyToken('EMPRESA-TOKEN');

        $raw = DB::table('company_integrations')->where('company_id', $this->resto->id)->where('platform', 'covermanager')->value('access_token');
        $this->assertNotSame('EMPRESA-TOKEN', $raw);
        $this->assertStringNotContainsString('EMPRESA-TOKEN', (string) $raw);
        $this->assertSame('EMPRESA-TOKEN', app(CoverManagerService::class)->companyToken($this->resto->id));

        $this->actingAs($this->restoUser, 'sanctum')
            ->getJson("/api/v1/companies/{$this->resto->id}/integrations/covermanager")
            ->assertStatus(200)->assertJsonPath('data.connected', true);
    }

    public function test_resolution_uses_company_token_when_location_has_no_override(): void
    {
        $this->connectCompanyToken('EMPRESA-TOKEN');
        // Loja SÓ com slug (sem override).
        $loc = $this->location(['cm_slug' => 'yuko-baixa']);
        $loc->update(['cm_token' => null]);

        $captured = null;
        Http::fake(function ($request) use (&$captured) {
            $captured = $request->header('apikey')[0] ?? null;
            return Http::response(['reservs' => [['meal_shift' => 'lunch', 'for' => 3, 'status' => 'ok']]], 200);
        });

        app(CoverManagerService::class)->sync($this->resto->id, '2026-09-18');
        $this->assertSame('EMPRESA-TOKEN', $captured); // usou o token da EMPRESA
        $this->assertSame(1, CmReservationShiftSummary::where('location_id', $loc->id)->count());
    }

    public function test_resolution_location_override_takes_priority(): void
    {
        $this->connectCompanyToken('EMPRESA-TOKEN');
        $loc = $this->location(['cm_slug' => 'yuko-baixa', 'cm_token' => 'OVERRIDE-LOJA']);

        $captured = null;
        Http::fake(function ($request) use (&$captured) {
            $captured = $request->header('apikey')[0] ?? null;
            return Http::response(['reservs' => []], 200);
        });

        app(CoverManagerService::class)->sync($this->resto->id, '2026-09-18');
        $this->assertSame('OVERRIDE-LOJA', $captured); // a LOJA tem prioridade
    }

    public function test_slug_gate_needs_company_integration(): void
    {
        // Sem integração de empresa e sem override → não sincronizável (mensagem clara).
        $this->location(['cm_slug' => 'yuko-baixa'])->update(['cm_token' => null]);

        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson("/api/v1/companies/{$this->resto->id}/integrations/covermanager/sync", ['date' => '2026-09-18'])
            ->assertStatus(422);

        // Ligando o token de empresa → passa a sincronizável.
        $this->connectCompanyToken();
        Http::fake(['*' => Http::response(['reservs' => []], 200)]);
        $this->actingAs($this->restoUser, 'sanctum')
            ->postJson("/api/v1/companies/{$this->resto->id}/integrations/covermanager/sync", ['date' => '2026-09-18'])
            ->assertStatus(200);
    }

    public function test_company_integration_endpoints_are_tenant_scoped(): void
    {
        // Utilizador automotivo (sem módulo pingwin) → 403 no gate da rota.
        $this->actingAs($this->autoUser, 'sanctum')
            ->getJson("/api/v1/companies/{$this->auto->id}/integrations/covermanager")
            ->assertStatus(403);
    }
}
