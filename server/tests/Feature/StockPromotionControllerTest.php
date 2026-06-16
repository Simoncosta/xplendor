<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarPromotionPriority;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature tests do StockPromotionController — foco MULTI-TENANCY.
 *
 * Cobre os 7 cenários definidos na Etapa 3:
 *   1. admin A faz GET de company B   → 403
 *   2. admin A faz POST priority em B → 403
 *   3. root  faz GET de qualquer     → 200
 *   4. admin faz GET própria         → 200
 *   5. toggle (POST/DELETE) respeita isolamento
 *   6. DELETE inexistente            → idempotente (200, was_active=false)
 *   7. admin não forja companyId no path para ver outro stand (guard backend)
 */
class StockPromotionControllerTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;
    private Company $companyB;
    private User    $adminA;
    private User    $adminB;
    private User    $rootUser;
    private Car     $carA;
    private Car     $carB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = $this->makeCompany('500000100', 'Stand A Lda');
        $this->companyB = $this->makeCompany('500000200', 'Stand B Lda');

        $this->adminA = User::factory()->create([
            'company_id' => $this->companyA->id,
            'role'       => 'admin',
            'email'      => 'admin-a@x.x',
        ]);
        $this->adminB = User::factory()->create([
            'company_id' => $this->companyB->id,
            'role'       => 'admin',
            'email'      => 'admin-b@x.x',
        ]);
        $this->rootUser = User::factory()->create([
            'company_id' => $this->companyA->id, // root pertence formalmente a uma company
            'role'       => 'root',
            'email'      => 'root@x.x',
        ]);

        $this->carA = $this->makeCar($this->companyA->id, 'AAA-1');
        $this->carB = $this->makeCar($this->companyB->id, 'BBB-1');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function makeCompany(string $nipc, string $name): Company
    {
        $planId = DB::table('plans')->insertGetId([
            'name'       => 'Plan ' . $nipc,
            'price'      => 0,
            'car_limit'  => 999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $companyId = DB::table('companies')->insertGetId([
            'fiscal_name'         => $name,
            'nipc'                => $nipc,
            'slug'                => 'stand-' . $nipc,
            'plan_id'             => $planId,
            'subscription_status' => 'active',
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
        return Company::findOrFail($companyId);
    }

    private function makeCar(int $companyId, string $suffix): Car
    {
        $brandId = DB::table('car_brands')->insertGetId([
            'name'         => 'Brand-' . $suffix,
            'slug'         => 'brand-' . strtolower($suffix),
            'vehicle_type' => 'car',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
        $modelId = DB::table('car_models')->insertGetId([
            'name'         => 'Model-' . $suffix,
            'car_brand_id' => $brandId,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
        $carId = DB::table('cars')->insertGetId([
            'company_id'        => $companyId,
            'car_brand_id'      => $brandId,
            'car_model_id'      => $modelId,
            'version'           => 'V',
            'status'            => 'active',
            'vehicle_type'      => 'car',
            'origin'            => 'national',
            'registration_year' => 2022,
            'doors'             => 4,
            'segment'           => 'sedan',
            'seats'             => 5,
            'exterior_color'    => 'white',
            'condition'         => 'used',
            'has_spare_key'     => false,
            'has_manuals'       => false,
            'is_metallic'       => false,
            'hide_price_online' => false,
            'price_gross'       => 15000,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
        return Car::findOrFail($carId);
    }

    private function indexUrl(int $companyId): string
    {
        return "/api/v1/companies/{$companyId}/stock/promotion-candidates";
    }

    private function priorityUrl(int $companyId, int $carId): string
    {
        return "/api/v1/companies/{$companyId}/stock/promotion-candidates/{$carId}";
    }

    // ── 1. Acesso não autenticado ─────────────────────────────────────────

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson($this->indexUrl($this->companyA->id))->assertStatus(401);
        $this->postJson($this->priorityUrl($this->companyA->id, $this->carA->id))->assertStatus(401);
        $this->deleteJson($this->priorityUrl($this->companyA->id, $this->carA->id))->assertStatus(401);
    }

    // ── 2. admin da própria company tem acesso 200 ────────────────────────

    public function test_admin_can_list_own_company(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->getJson($this->indexUrl($this->companyA->id))
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta' => ['thresholds']]);
    }

    public function test_admin_can_view_summary_of_own_company(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->getJson($this->indexUrl($this->companyA->id) . '/summary')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['visible_total', 'marked_total', 'visible_by_type']]);
    }

    // ── 3. CENÁRIO CRÍTICO: admin A tenta aceder a company B → 403 ────────

    public function test_admin_a_listing_company_b_is_forbidden(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->getJson($this->indexUrl($this->companyB->id))
            ->assertStatus(403);
    }

    public function test_admin_a_viewing_summary_of_company_b_is_forbidden(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->getJson($this->indexUrl($this->companyB->id) . '/summary')
            ->assertStatus(403);
    }

    public function test_admin_a_marking_priority_on_company_b_is_forbidden(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson($this->priorityUrl($this->companyB->id, $this->carB->id), ['note' => 'tentativa'])
            ->assertStatus(403);

        $this->assertSame(0, CarPromotionPriority::count(), 'nenhuma row deve ter sido criada');
    }

    public function test_admin_a_unmarking_priority_on_company_b_is_forbidden(): void
    {
        // Cria uma prioridade activa no stand B com o admin B
        $existing = CarPromotionPriority::create([
            'company_id'        => $this->companyB->id,
            'car_id'            => $this->carB->id,
            'marked_by_user_id' => $this->adminB->id,
            'marked_at'         => now(),
            'is_active'         => true,
        ]);

        // admin A tenta desmarcar — 403, e a prioridade fica intacta
        $this->actingAs($this->adminA, 'sanctum')
            ->deleteJson($this->priorityUrl($this->companyB->id, $this->carB->id))
            ->assertStatus(403);

        $this->assertTrue($existing->fresh()->is_active, 'a prioridade não deve ter sido tocada');
    }

    // ── 4. CENÁRIO CRÍTICO: forja de companyId via mismatch path/car ─────

    public function test_admin_a_cannot_forge_companyId_using_own_path_with_other_companys_car(): void
    {
        // Cenário: admin A diz "/companies/{A}/stock/.../{carB}" — i.e. valida
        // como sua company mas tenta agir sobre car do stand B. O 2º guard
        // (car_id pertence a companyId do path) tem de saltar: 404.
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson($this->priorityUrl($this->companyA->id, $this->carB->id), ['note' => 'sneaky'])
            ->assertStatus(404);

        $this->assertSame(0, CarPromotionPriority::count());
    }

    // ── 5. root tem acesso a qualquer company ────────────────────────────

    public function test_root_can_list_any_company(): void
    {
        $this->actingAs($this->rootUser, 'sanctum')
            ->getJson($this->indexUrl($this->companyA->id))
            ->assertStatus(200);

        $this->actingAs($this->rootUser, 'sanctum')
            ->getJson($this->indexUrl($this->companyB->id))
            ->assertStatus(200);
    }

    public function test_root_can_mark_priority_on_any_company(): void
    {
        $this->actingAs($this->rootUser, 'sanctum')
            ->postJson($this->priorityUrl($this->companyB->id, $this->carB->id), ['note' => 'agência mark'])
            ->assertStatus(201)
            ->assertJsonPath('data.marked_by.id', $this->rootUser->id);

        $this->assertSame(1, CarPromotionPriority::active()->where('car_id', $this->carB->id)->count());
    }

    // ── 6. Toggle (POST + DELETE) — happy path ───────────────────────────

    public function test_admin_can_mark_and_unmark_priority_on_own_company(): void
    {
        // POST → 201, cria row activa
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson($this->priorityUrl($this->companyA->id, $this->carA->id), ['note' => 'campanha verão'])
            ->assertStatus(201)
            ->assertJsonPath('data.note', 'campanha verão')
            ->assertJsonPath('data.marked_by.id', $this->adminA->id);

        $this->assertSame(1, CarPromotionPriority::active()->where('car_id', $this->carA->id)->count());

        // DELETE → 200, was_active=true
        $this->actingAs($this->adminA, 'sanctum')
            ->deleteJson($this->priorityUrl($this->companyA->id, $this->carA->id))
            ->assertStatus(200)
            ->assertJsonPath('data.was_active', true);

        $this->assertSame(0, CarPromotionPriority::active()->where('car_id', $this->carA->id)->count());
    }

    // ── 7. DELETE idempotente quando não há nada activo ─────────────────

    public function test_delete_when_nothing_active_is_idempotent(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->deleteJson($this->priorityUrl($this->companyA->id, $this->carA->id))
            ->assertStatus(200)
            ->assertJsonPath('data.was_active', false);
    }

    // ── 8. Validação 422 do note (campo opcional, mas com max:500) ───────

    public function test_post_rejects_note_above_500_chars_with_422(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson($this->priorityUrl($this->companyA->id, $this->carA->id), [
                'note' => str_repeat('x', 501),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['note']);
    }

    // ── 9. Filtro `only_marked=true` ─────────────────────────────────────

    public function test_only_marked_filter_returns_only_priorities(): void
    {
        $car2 = $this->makeCar($this->companyA->id, 'AAA-2');
        // marca o car2, não marca o carA
        CarPromotionPriority::create([
            'company_id'        => $this->companyA->id,
            'car_id'            => $car2->id,
            'marked_by_user_id' => $this->adminA->id,
            'marked_at'         => now(),
            'is_active'         => true,
        ]);

        $response = $this->actingAs($this->adminA, 'sanctum')
            ->getJson($this->indexUrl($this->companyA->id) . '?only_marked=1')
            ->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($car2->id, $data[0]['id']);
        $this->assertNotNull($data[0]['promotion']);
    }

    // ── 10. Status do car_id que não existe → 404 ───────────────────────

    public function test_post_on_nonexistent_car_returns_404(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson($this->priorityUrl($this->companyA->id, 999999), ['note' => 'fantasma'])
            ->assertStatus(404);
    }

    public function test_delete_on_nonexistent_car_returns_404(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->deleteJson($this->priorityUrl($this->companyA->id, 999999))
            ->assertStatus(404);
    }
}
