<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Car;
use App\Models\CarPromotionPriority;
use App\Models\Company;
use App\Models\User;
use App\Repositories\CarPromotionPriorityRepository;
use App\Repositories\StockPromotionRepository;
use App\Services\StockPromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Testes do StockPromotionService — foco na INVARIANTE atómica:
 *   "Cada viatura tem NO MÁXIMO uma row activa em car_promotion_priorities."
 *
 * Multi-tenancy (admin A não acede B) entra na Etapa 3 com testes feature
 * sobre o Controller — aqui o foco é a regra de unicidade do estado activo.
 */
class StockPromotionServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockPromotionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StockPromotionService(
            new StockPromotionRepository(),
            new CarPromotionPriorityRepository(),
        );
    }

    // ── Helpers (bypass observers — ver CarPromotionPriorityTest) ─────────

    private function makeCompany(string $nipc = '500000001'): Company
    {
        $planId = DB::table('plans')->insertGetId([
            'name'       => 'Test Plan ' . $nipc,
            'price'      => 0,
            'car_limit'  => 999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $companyId = DB::table('companies')->insertGetId([
            'fiscal_name' => 'Tester Lda',
            'nipc'        => $nipc,
            'slug'        => 'tester-' . $nipc,
            'plan_id'     => $planId,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        return Company::findOrFail($companyId);
    }

    private function makeCar(int $companyId, string $vehicleType = 'car'): Car
    {
        $brandId = DB::table('car_brands')->insertGetId([
            'name'         => 'TB-' . uniqid(),
            'slug'         => 'tb-' . uniqid(),
            'vehicle_type' => $vehicleType === 'caravan' ? 'motorhome' : $vehicleType,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
        $modelId = DB::table('car_models')->insertGetId([
            'name'         => 'TM-' . uniqid(),
            'car_brand_id' => $brandId,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
        $carId = DB::table('cars')->insertGetId([
            'company_id'        => $companyId,
            'car_brand_id'      => $brandId,
            'car_model_id'      => $modelId,
            'version'           => 'X',
            'status'            => 'active',
            'vehicle_type'      => $vehicleType,
            'origin'            => 'national',
            'registration_year' => 2020,
            'doors'             => 4,
            'segment'           => $vehicleType,
            'seats'             => 5,
            'exterior_color'    => 'white',
            'condition'         => 'used',
            'has_spare_key'     => false,
            'has_manuals'       => false,
            'is_metallic'       => false,
            'hide_price_online' => false,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
        return Car::findOrFail($carId);
    }

    // ── Testes ────────────────────────────────────────────────────────────

    /** @test */
    public function it_creates_a_single_active_priority_on_first_mark(): void
    {
        $company = $this->makeCompany();
        $user    = User::factory()->create(['company_id' => $company->id]);
        $car     = $this->makeCar($company->id);

        $priority = $this->service->markForPromotion(
            $company->id,
            $car->id,
            $user->id,
            'Verão 2026',
        );

        $this->assertTrue($priority->is_active);
        $this->assertSame('Verão 2026', $priority->note);
        $this->assertSame($user->id, $priority->marked_by_user_id);
        $this->assertSame(1, CarPromotionPriority::active()->where('car_id', $car->id)->count());
    }

    /** @test */
    public function double_mark_leaves_exactly_one_active_row(): void
    {
        // INVARIANTE PRINCIPAL — duas chamadas seguidas de markForPromotion
        // não podem deixar duas rows activas para o mesmo car.
        $company = $this->makeCompany('500000002');
        $user1   = User::factory()->create(['company_id' => $company->id, 'email' => 'u1@x.x']);
        $user2   = User::factory()->create(['company_id' => $company->id, 'email' => 'u2@x.x']);
        $car     = $this->makeCar($company->id);

        $p1 = $this->service->markForPromotion($company->id, $car->id, $user1->id, 'Primeira');
        $p2 = $this->service->markForPromotion($company->id, $car->id, $user2->id, 'Segunda');

        // Há 2 rows no histórico (auditoria — não perdemos a primeira).
        $this->assertSame(2, CarPromotionPriority::where('car_id', $car->id)->count());

        // MAS só UMA está activa.
        $active = CarPromotionPriority::active()->where('car_id', $car->id)->get();
        $this->assertCount(1, $active);
        $this->assertSame($p2->id, $active->first()->id);
        $this->assertSame('Segunda', $active->first()->note);
        $this->assertSame($user2->id, $active->first()->marked_by_user_id);

        // A primeira ficou inactiva com unmarked_at carimbado, unmarked_by NULL
        // (re-mark automático, não acção humana de desmarcar).
        $p1Reloaded = CarPromotionPriority::find($p1->id);
        $this->assertFalse($p1Reloaded->is_active);
        $this->assertNotNull($p1Reloaded->unmarked_at);
        $this->assertNull($p1Reloaded->unmarked_by_user_id);
    }

    /** @test */
    public function unmark_deactivates_active_and_stamps_unmarked_by(): void
    {
        $company = $this->makeCompany('500000003');
        $user    = User::factory()->create(['company_id' => $company->id]);
        $car     = $this->makeCar($company->id);

        $this->service->markForPromotion($company->id, $car->id, $user->id, null);

        $result = $this->service->unmarkPromotion($company->id, $car->id, $user->id);

        $this->assertTrue($result);
        $this->assertSame(0, CarPromotionPriority::active()->where('car_id', $car->id)->count());

        $row = CarPromotionPriority::where('car_id', $car->id)->first();
        $this->assertFalse($row->is_active);
        $this->assertSame($user->id, $row->unmarked_by_user_id);
    }

    /** @test */
    public function unmark_is_idempotent_when_nothing_active(): void
    {
        $company = $this->makeCompany('500000004');
        $car     = $this->makeCar($company->id);

        $result = $this->service->unmarkPromotion($company->id, $car->id, null);

        $this->assertFalse($result, 'unmark sem nada activo deve devolver false (idempotente)');
        $this->assertSame(0, CarPromotionPriority::where('car_id', $car->id)->count());
    }

    /** @test */
    public function mark_after_unmark_creates_brand_new_active_row(): void
    {
        // Toggle ON → OFF → ON. mark INSERE; unmark é UPDATE (não cria row).
        // Total esperado: 2 rows (a 1ª agora is_active=false, a 2ª is_active=true).
        $company = $this->makeCompany('500000005');
        $user    = User::factory()->create(['company_id' => $company->id]);
        $car     = $this->makeCar($company->id);

        $this->service->markForPromotion($company->id, $car->id, $user->id, 'A');
        $this->service->unmarkPromotion($company->id, $car->id, $user->id);
        $third = $this->service->markForPromotion($company->id, $car->id, $user->id, 'C');

        $this->assertSame(2, CarPromotionPriority::where('car_id', $car->id)->count());
        $active = CarPromotionPriority::active()->where('car_id', $car->id)->get();
        $this->assertCount(1, $active);
        $this->assertSame($third->id, $active->first()->id);
        $this->assertSame('C', $active->first()->note);
    }

    /** @test */
    public function mark_isolates_priorities_between_cars_of_same_company(): void
    {
        // Defesa: o deactivateAllActiveForCar tem de filtrar por car_id,
        // não esfregar todas as activas da empresa.
        $company = $this->makeCompany('500000006');
        $user    = User::factory()->create(['company_id' => $company->id]);
        $car1    = $this->makeCar($company->id);
        $car2    = $this->makeCar($company->id);

        $this->service->markForPromotion($company->id, $car1->id, $user->id, null);
        $this->service->markForPromotion($company->id, $car2->id, $user->id, null);

        $this->assertSame(2, CarPromotionPriority::active()->where('company_id', $company->id)->count());
        $this->assertSame(1, CarPromotionPriority::active()->where('car_id', $car1->id)->count());
        $this->assertSame(1, CarPromotionPriority::active()->where('car_id', $car2->id)->count());
    }
}
