<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Company;
use App\Services\PingwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * XPLENDOR — PingWin › tab Compras (C2): tradução das mudanças de fornecedor (formato
 * frontend/cêntimos/supplier LOCAL → formato PingWin decimal/supplier pingwin) + o guard
 * "há mudanças?". Sem scraper (testa só a fronteira PHP). A escrita real prova-se em dev.
 */
class PingwinSupplierChangesTest extends TestCase
{
    use RefreshDatabase;

    private PingwinService $service;
    private int $companyId;
    private int $supplierLocalId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PingwinService::class);

        $planId = DB::table('plans')->insertGetId([
            'name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $company = Company::create(['nipc' => '500000999', 'fiscal_name' => 'C Lda', 'plan_id' => $planId]);
        $this->companyId = $company->id;

        $this->supplierLocalId = (int) DB::table('suppliers')->insertGetId([
            'company_id' => $this->companyId, 'source' => 'pingwin', 'pingwin_id' => 'SUP1',
            'name' => 'Makro', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function build(array $changes): array
    {
        $m = new \ReflectionMethod(PingwinService::class, 'buildSupplierChangesPayload');
        $m->setAccessible(true);
        return $m->invoke($this->service, $this->companyId, $changes);
    }

    private function has(?array $changes): bool
    {
        $m = new \ReflectionMethod(PingwinService::class, 'hasSupplierChanges');
        $m->setAccessible(true);
        return (bool) $m->invoke($this->service, $changes);
    }

    public function test_traduz_create_centimos_e_supplier(): void
    {
        $out = $this->build(['create' => [[
            'supplier_id' => $this->supplierLocalId, 'supprice_header_id' => 'H1',
            'price_cents' => 280, 'unit_id' => 'KG', 'discount1' => '0', 'discount2_mul' => '1',
        ]]]);

        $this->assertCount(1, $out['create']);
        $c = $out['create'][0];
        $this->assertSame('H1', $c['supprice_header_id']);
        $this->assertSame('SUP1', $c['line']['supplier_id']);   // local → pingwin
        $this->assertSame('2.80', $c['line']['price']);          // 280 cêntimos → "2.80"
        $this->assertSame('KG', $c['line']['unit_id']);
    }

    public function test_traduz_update_e_delete(): void
    {
        $out = $this->build([
            'update' => [['line_pingwin_id' => '9001', 'price_cents' => 135]],
            'delete' => ['9002'],
        ]);

        $this->assertSame('9001', $out['update'][0]['id']);
        $this->assertSame('1.35', $out['update'][0]['fields']['price']);
        $this->assertSame(['9002'], $out['delete']);
    }

    public function test_create_sem_header_recusa(): void
    {
        $this->expectException(ValidationException::class);
        $this->build(['create' => [['supplier_id' => $this->supplierLocalId, 'price_cents' => 100]]]);
    }

    public function test_supplier_invalido_recusa(): void
    {
        $this->expectException(ValidationException::class);
        $this->build(['create' => [['supplier_id' => 999999, 'supprice_header_id' => 'H1', 'price_cents' => 100]]]);
    }

    public function test_guard_has_supplier_changes(): void
    {
        $this->assertFalse($this->has(null));
        $this->assertFalse($this->has([]));
        $this->assertFalse($this->has(['create' => [], 'update' => [], 'delete' => []]));
        $this->assertTrue($this->has(['create' => [['x' => 1]]]));
        $this->assertTrue($this->has(['delete' => ['9002']]));
    }
}
