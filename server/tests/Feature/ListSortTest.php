<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\CarBrand;
use App\Models\CarInteraction;
use App\Models\CarLead;
use App\Models\CarModel;
use App\Models\CarView;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PingwinCatalogItem;
use App\Models\Supplier;
use App\Models\User;
use App\Repositories\CarRepository;
use App\Services\CompanyModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Ordenação no servidor (?sort=&dir=) de Carros, Leads, Artigos e Despesas: só pelas chaves
 * permitidas (App\Support\ListSort), nos dois sentidos, com o id como desempate. Uma chave
 * desconhecida ou uma tentativa de injeção dá 422; paginação + ordenação + filtros juntos; tenancy.
 */
class ListSortTest extends TestCase
{
    use RefreshDatabase;

    private const INJECTIONS = ['price; drop table cars', 'id desc, (select 1)', 'price_gross`', '(select password from users limit 1)', 'cars.id', 'code desc'];

    private Company $company;
    private Company $other;
    private Company $resto;
    private User $user;
    private User $stranger;
    private User $restoUser;
    /** @var array<string, int> */
    private array $cars = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500020100', 'fiscal_name' => 'Stand A', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500020101', 'fiscal_name' => 'Stand B', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->resto = Company::create(['nipc' => '500020102', 'fiscal_name' => 'Resto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->resto->id, 'restaurant');
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $this->stranger = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);
        $this->restoUser = User::factory()->create(['company_id' => $this->resto->id, 'role' => 'admin']);

        // 3 viaturas com valores distintos em cada chave (ordem ascendente esperada nos testes).
        //          marca      modelo     versão preço  views leads interações → conversão
        $spec = [
            'audi'    => ['Audi', 'A3', 'X', 30000, 5, 1, 2],  // 0.2
            'bmw'     => ['BMW', 'Serie 1', 'Y', 10000, 0, 0, 7], // 0 (sem views)
            'citroen' => ['Citroen', 'C3', 'Z', 20000, 2, 2, 0], // 1.0
        ];
        foreach ($spec as $key => [$brand, $model, $version, $price, $views, $leads, $interactions]) {
            $b = CarBrand::create(['name' => $brand, 'slug' => strtolower($brand), 'vehicle_type' => 'car']);
            $m = CarModel::create(['name' => $model, 'car_brand_id' => $b->id]);
            $car = Car::factory()->create(['company_id' => $this->company->id, 'car_brand_id' => $b->id, 'car_model_id' => $m->id, 'version' => $version, 'price_gross' => $price]);
            $this->cars[$key] = $car->id;
            CarView::factory()->count($views)->create(['company_id' => $this->company->id, 'car_id' => $car->id]);
            CarInteraction::factory()->count($interactions)->create(['company_id' => $this->company->id, 'car_id' => $car->id]);
            for ($i = 0; $i < $leads; $i++) {
                CarLead::factory()->create(['company_id' => $this->company->id, 'car_id' => $car->id]);
            }
        }
        // Viatura de outra empresa: nunca aparece.
        $this->cars['outra'] = Car::factory()->create(['company_id' => $this->other->id, 'price_gross' => 1])->id;
    }

    /** @return list<int> */
    private function ids(string $url, ?User $as = null, string $path = 'data.data'): array
    {
        $res = $this->actingAs($as ?? $this->user, 'sanctum')->getJson($url)->assertOk();

        return array_map(fn ($r) => (int) $r['id'], $res->json($path));
    }

    /** Ordem ascendente esperada; a descendente é exatamente a inversa (o id desempata no mesmo sentido). */
    private function assertBothWays(string $base, string $key, array $ascIds, ?User $as = null, string $path = 'data.data'): void
    {
        $sep = str_contains($base, '?') ? '&' : '?';
        $this->assertSame($ascIds, $this->ids("{$base}{$sep}sort={$key}&dir=asc", $as, $path), "{$key} asc");
        $this->assertSame(array_reverse($ascIds), $this->ids("{$base}{$sep}sort={$key}&dir=desc", $as, $path), "{$key} desc");
    }

    private function assertRejected(string $base, ?User $as = null): void
    {
        $sep = str_contains($base, '?') ? '&' : '?';
        foreach (self::INJECTIONS as $bad) {
            $this->actingAs($as ?? $this->user, 'sanctum')->getJson("{$base}{$sep}sort=" . urlencode($bad))
                ->assertStatus(422)->assertJsonValidationErrors('sort');
        }
        $this->actingAs($as ?? $this->user, 'sanctum')->getJson("{$base}{$sep}sort=" . urlencode(array_key_first($this->allowedFor($base))) . '&dir=' . urlencode('asc; drop table x'))
            ->assertStatus(422)->assertJsonValidationErrors('dir');
    }

    private function allowedFor(string $base): array
    {
        return match (true) {
            str_contains($base, '/cars') => CarRepository::sorts(),
            str_contains($base, '/leads') => \App\Http\Controllers\Api\V1\CarLeadController::sorts(),
            str_contains($base, '/expenses') => \App\Http\Controllers\Api\V1\ExpenseController::sorts(),
            default => ['code' => 'code'],
        };
    }

    // ── Carros ─────────────────────────────────────────────────────────────────────

    private function carsUrl(string $extra = ''): string
    {
        return "/api/v1/companies/{$this->company->id}/cars?perPage=50{$extra}";
    }

    public function test_cars_sort_by_every_allowed_key_both_ways(): void
    {
        ['audi' => $a, 'bmw' => $b, 'citroen' => $c] = $this->cars;
        $expected = [
            'car' => [$a, $b, $c], 'price' => [$b, $c, $a], 'views' => [$b, $c, $a],
            'leads' => [$b, $a, $c], 'interactions' => [$c, $a, $b], 'conversion' => [$b, $a, $c],
        ];
        $this->assertSame(array_keys(CarRepository::sorts()), array_keys($expected), 'cada chave permitida tem um teste');
        foreach ($expected as $key => $asc) {
            $this->assertBothWays($this->carsUrl(), $key, $asc);
        }
    }

    public function test_cars_default_order_is_by_id_and_legacy_sort_by_still_works(): void
    {
        ['audi' => $a, 'bmw' => $b, 'citroen' => $c] = $this->cars;
        $this->assertSame([$a, $b, $c], $this->ids($this->carsUrl()));
        // frontend anterior: sort_by=price_gross&sort_direction=desc
        $this->assertSame([$a, $c, $b], $this->ids($this->carsUrl('&sort_by=price_gross&sort_direction=desc')));
    }

    public function test_cars_unknown_key_and_injection_are_rejected(): void
    {
        $this->assertRejected($this->carsUrl());
        // também pelo nome antigo (antes ia direto ao orderBy)
        $this->actingAs($this->user, 'sanctum')->getJson($this->carsUrl('&sort_by=' . urlencode('price; drop table cars')))->assertStatus(422);
        $this->actingAs($this->user, 'sanctum')->getJson($this->carsUrl('&sort_by=year'))->assertStatus(422);
        $this->assertSame(4, Car::count(), 'nada foi apagado');
    }

    public function test_cars_pagination_sort_and_filters_together(): void
    {
        ['audi' => $a, 'bmw' => $b, 'citroen' => $c] = $this->cars;
        Car::whereKey($a)->update(['status' => 'sold']);
        $base = "/api/v1/companies/{$this->company->id}/cars?perPage=1&status=active,sold&sort=price&dir=desc";
        $this->assertSame([$a], $this->ids("{$base}&page=1"));
        $this->assertSame([$c], $this->ids("{$base}&page=2"));
        $this->assertSame([$b], $this->ids("{$base}&page=3"));
        // só ativas: a vendida sai, a ordem mantém-se
        $this->assertSame([$c, $b], $this->ids("/api/v1/companies/{$this->company->id}/cars?perPage=10&status=active&sort=price&dir=desc"));
    }

    public function test_cars_tenancy(): void
    {
        $this->assertNotContains($this->cars['outra'], $this->ids($this->carsUrl('&sort=price&dir=asc')));
        $this->actingAs($this->stranger, 'sanctum')->getJson($this->carsUrl('&sort=price'))->assertStatus(403);
    }

    // ── Leads ──────────────────────────────────────────────────────────────────────

    /** @return array<string, int> 3 leads com nome, estado, carro e data distintos */
    private function leads(): array
    {
        CarLead::query()->delete();
        $mk = fn (string $name, string $status, string $car, string $ago) => CarLead::forceCreate([
            'name' => $name, 'email' => uniqid() . '@x.pt', 'phone' => '91' . random_int(1000000, 9999999), 'status' => $status, 'source' => 'website_form',
            'car_id' => $this->cars[$car], 'company_id' => $this->company->id, 'created_at' => now()->sub($ago), 'updated_at' => now(),
        ])->id;
        $ids = [
            'ana'   => $mk('Ana', 'won', 'citroen', '1 day'),
            'bruno' => $mk('Bruno', 'contacted', 'audi', '3 days'),
            'carla' => $mk('Carla', 'new', 'bmw', '2 days'),
        ];
        CarLead::create(['name' => 'Aaa Outra', 'email' => 'o@x.pt', 'phone' => '1', 'status' => 'new', 'source' => 'website_form',
            'car_id' => $this->cars['outra'], 'company_id' => $this->other->id]);

        return $ids;
    }

    private function leadsUrl(string $extra = ''): string
    {
        return "/api/v1/companies/{$this->company->id}/leads?perPage=50{$extra}";
    }

    public function test_leads_sort_by_every_allowed_key_both_ways(): void
    {
        ['ana' => $ana, 'bruno' => $bruno, 'carla' => $carla] = $this->leads();
        $expected = [
            'created_at' => [$bruno, $carla, $ana],
            'name'       => [$ana, $bruno, $carla],
            'status'     => [$bruno, $carla, $ana], // contacted, new, won
            'car'        => [$bruno, $carla, $ana], // Audi, BMW, Citroen
        ];
        $this->assertSame(array_keys(\App\Http\Controllers\Api\V1\CarLeadController::sorts()), array_keys($expected));
        foreach ($expected as $key => $asc) {
            $this->assertBothWays($this->leadsUrl(), $key, $asc);
        }
    }

    public function test_leads_default_is_newest_first_and_funnel_without_pages_keeps_id_order(): void
    {
        ['ana' => $ana, 'bruno' => $bruno, 'carla' => $carla] = $this->leads();
        $this->assertSame([$ana, $carla, $bruno], $this->ids($this->leadsUrl()));
        // sem perPage (o funil lê todas): pelo id, como antes
        $this->assertSame([$ana, $bruno, $carla], $this->ids("/api/v1/companies/{$this->company->id}/leads", null, 'data'));
    }

    public function test_leads_paginate_sort_and_search_together(): void
    {
        ['ana' => $ana, 'bruno' => $bruno, 'carla' => $carla] = $this->leads();
        $res = $this->actingAs($this->user, 'sanctum')->getJson($this->leadsUrl('&perPage=2&page=1&sort=name&dir=desc'))->assertOk();
        $this->assertSame([$carla, $bruno], array_column($res->json('data.data'), 'id'));
        $this->assertSame(3, $res->json('data.total'));
        $this->assertSame([$ana], $this->ids("/api/v1/companies/{$this->company->id}/leads?perPage=2&page=2&sort=name&dir=desc"));
        // pesquisa no servidor: pelo carro (marca + modelo, várias palavras) e pelo nome
        $this->assertSame([$carla], $this->ids($this->leadsUrl('&search=' . urlencode('bmw serie'))));
        $this->assertSame([$bruno], $this->ids($this->leadsUrl('&search=brun&sort=car')));
        $this->assertSame([$carla], $this->ids($this->leadsUrl('&status=new&sort=name')));
    }

    public function test_leads_injection_rejected_and_tenancy(): void
    {
        $this->leads();
        $this->assertRejected($this->leadsUrl());
        $this->assertNotContains('Aaa Outra', array_column($this->actingAs($this->user, 'sanctum')->getJson($this->leadsUrl('&sort=name'))->json('data.data'), 'name'));
        $this->actingAs($this->stranger, 'sanctum')->getJson($this->leadsUrl('&sort=name'))->assertStatus(403);
    }

    // ── Artigos (catálogo PingWin) ─────────────────────────────────────────────────

    /** @return array<string, int> */
    private function articles(): array
    {
        $mk = fn (array $a) => PingwinCatalogItem::create(array_merge(['company_id' => $this->resto->id, 'synced_at' => now()], $a))->id;
        $ids = [
            'a' => $mk(['pingwin_id' => '1', 'code' => 'A1', 'description' => 'Café', 'family' => 'Bebidas', 'saleunit' => 'KG', 'saleprice_cents' => 300, 'purchaseprice_cents' => 50, 'is_active' => false, 'forsale' => false, 'forpurchase' => true, 'has_bom' => false]),
            'b' => $mk(['pingwin_id' => '2', 'code' => 'B2', 'description' => 'Arroz', 'family' => 'Mercearia', 'saleunit' => 'LT', 'saleprice_cents' => 100, 'purchaseprice_cents' => 90, 'is_active' => true, 'forsale' => true, 'forpurchase' => false, 'has_bom' => true]),
            'c' => $mk(['pingwin_id' => '3', 'code' => 'C3', 'description' => 'Prego', 'family' => 'Pratos', 'saleunit' => 'UN', 'saleprice_cents' => 200, 'purchaseprice_cents' => 10, 'is_active' => true, 'forsale' => true, 'forpurchase' => false, 'has_bom' => false]),
        ];
        PingwinCatalogItem::create(['company_id' => $this->company->id, 'pingwin_id' => '9', 'code' => '0', 'description' => 'Outra empresa', 'synced_at' => now()]);

        return $ids;
    }

    private function articlesUrl(string $extra = ''): string
    {
        return "/api/v1/companies/{$this->resto->id}/integrations/pingwin/catalog?perPage=50{$extra}";
    }

    public function test_articles_sort_by_every_allowed_key_both_ways(): void
    {
        ['a' => $a, 'b' => $b, 'c' => $c] = $this->articles();
        $expected = [
            'code' => [$a, $b, $c], 'description' => [$b, $a, $c], 'family' => [$a, $b, $c], 'unit' => [$a, $b, $c],
            'sale_price' => [$b, $c, $a], 'purchase_price' => [$c, $a, $b], 'active' => [$a, $b, $c],
            'forsale' => [$a, $b, $c], 'forpurchase' => [$b, $c, $a], 'bom' => [$a, $c, $b],
        ];
        foreach ($expected as $key => $asc) {
            $this->assertBothWays($this->articlesUrl(), $key, $asc, $this->restoUser, 'data.articles.data');
        }
        // por omissão: código (como antes)
        $this->assertSame([$a, $b, $c], $this->ids($this->articlesUrl(), $this->restoUser, 'data.articles.data'));
    }

    public function test_articles_pagination_filters_injection_and_tenancy(): void
    {
        ['a' => $a, 'b' => $b, 'c' => $c] = $this->articles();
        $base = "/api/v1/companies/{$this->resto->id}/integrations/pingwin/catalog?perPage=1&forsale=1&sort=sale_price&dir=desc";
        $this->assertSame([$c], $this->ids("{$base}&page=1", $this->restoUser, 'data.articles.data'));
        $this->assertSame([$b], $this->ids("{$base}&page=2", $this->restoUser, 'data.articles.data'));
        $this->assertRejected($this->articlesUrl(), $this->restoUser);
        $this->actingAs($this->stranger, 'sanctum')->getJson($this->articlesUrl('&sort=code'))->assertStatus(403);
    }

    // ── Despesas ───────────────────────────────────────────────────────────────────

    /** @return array<string, int> */
    private function expenses(): array
    {
        $cat = fn (string $n) => ExpenseCategory::create(['company_id' => $this->company->id, 'name' => $n])->id;
        $sup = fn (string $n) => Supplier::create(['company_id' => $this->company->id, 'name' => $n])->id;
        $mk = fn (array $e) => Expense::create(array_merge(['company_id' => $this->company->id], $e))->id;
        $ids = [
            'x' => $mk(['description' => 'Pneus', 'amount' => 300, 'date' => '2026-09-03', 'expense_category_id' => $cat('Oficina'), 'supplier_id' => $sup('Zeta'), 'car_id' => $this->cars['bmw'], 'is_paid' => false]),
            'y' => $mk(['description' => 'Anúncio', 'amount' => 50, 'date' => '2026-09-01', 'expense_category_id' => $cat('Marketing'), 'supplier_id' => $sup('Alfa'), 'car_id' => $this->cars['citroen'], 'is_paid' => true, 'paid_at' => '2026-09-02']),
            'z' => $mk(['description' => 'Limpeza', 'amount' => 120, 'date' => '2026-09-02', 'expense_category_id' => $cat('Lavagem'), 'supplier_id' => $sup('Mega'), 'car_id' => $this->cars['audi'], 'is_paid' => false]),
        ];
        Expense::create(['company_id' => $this->other->id, 'description' => 'Outra', 'amount' => 1, 'date' => '2026-09-04']);

        return $ids;
    }

    private function expensesUrl(string $extra = ''): string
    {
        return "/api/v1/companies/{$this->company->id}/expenses?perPage=50{$extra}";
    }

    public function test_expenses_sort_by_every_allowed_key_both_ways(): void
    {
        ['x' => $x, 'y' => $y, 'z' => $z] = $this->expenses();
        $expected = [
            'date' => [$y, $z, $x], 'description' => [$y, $z, $x], 'category' => [$z, $y, $x],
            'supplier' => [$y, $z, $x], 'car' => [$z, $x, $y], 'amount' => [$y, $z, $x], 'status' => [$x, $z, $y],
        ];
        $this->assertSame(array_keys(\App\Http\Controllers\Api\V1\ExpenseController::sorts()), array_keys($expected));
        foreach ($expected as $key => $asc) {
            $this->assertBothWays($this->expensesUrl(), $key, $asc);
        }
        // por omissão, como antes: a mais recente primeiro
        $this->assertSame([$x, $z, $y], $this->ids($this->expensesUrl()));
    }

    public function test_expenses_pagination_filters_injection_and_tenancy(): void
    {
        ['x' => $x, 'y' => $y, 'z' => $z] = $this->expenses();
        $base = "/api/v1/companies/{$this->company->id}/expenses?perPage=1&is_paid=0&sort=amount&dir=asc";
        $this->assertSame([$z], $this->ids("{$base}&page=1"));
        $this->assertSame([$x], $this->ids("{$base}&page=2"));
        $this->assertRejected($this->expensesUrl());
        $this->assertNotContains('Outra', array_column($this->actingAs($this->user, 'sanctum')->getJson($this->expensesUrl('&sort=amount'))->json('data.data'), 'description'));
        $this->actingAs($this->stranger, 'sanctum')->getJson($this->expensesUrl('&sort=amount'))->assertStatus(403);
    }

    // ── Defesa extra no BaseRepository ────────────────────────────────────────────

    public function test_base_repository_refuses_non_identifier_columns(): void
    {
        $repo = app(\App\Repositories\CarModelRepository::class);
        foreach (['name; drop table cars', 'id desc, (select 1)', 'name`'] as $bad) {
            try {
                $repo->getAll(['*'], [], null, [], [$bad => 'asc']);
                $this->fail("aceitou {$bad}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertNotEmpty($repo->getAll(['*'], [], null, [], ['car_models.name' => 'desc']));
    }
}
