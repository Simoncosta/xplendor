<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PingwinSupplier;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — Unificação de fornecedores (tabela `suppliers` + flag `source`).
 * Cobre: os dois models como janelas scoped da MESMA tabela, e ⚠️ a PROTECÇÃO
 * dos manuais contra o sync do PingWin (o upsert por company_id+pingwin_id nunca
 * toca nos manuais, mesmo re-sincronizando).
 */
class SupplierUnificationTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId([
            'name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->companyId = Company::create([
            'nipc' => '500999999', 'fiscal_name' => 'Stand Teste', 'plan_id' => $planId, 'subscription_status' => 'active',
        ])->id;
    }

    /** Reproduz o caminho de escrita do sync (PingwinService::syncSuppliers). */
    private function runSyncUpsert(array $rows): void
    {
        $updateCols = ['code', 'name', 'fiscal_name', 'tax_number', 'address', 'city', 'postal_code', 'phone', 'email', 'is_active', 'synced_at', 'updated_at'];
        PingwinSupplier::upsert($rows, ['company_id', 'pingwin_id'], $updateCols);
    }

    private function pingwinRow(string $pingwinId, string $name): array
    {
        $now = now();
        return [
            'company_id' => $this->companyId, 'source' => 'pingwin', 'pingwin_id' => $pingwinId,
            'code' => $pingwinId, 'name' => $name, 'fiscal_name' => null, 'tax_number' => '500',
            'address' => null, 'city' => null, 'postal_code' => null, 'phone' => null, 'email' => null,
            'is_active' => true, 'synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ];
    }

    public function test_the_two_models_are_scoped_windows_of_the_same_table(): void
    {
        Supplier::create(['company_id' => $this->companyId, 'name' => 'Manual A', 'nif' => '111']);
        $this->runSyncUpsert([$this->pingwinRow('PW-1', 'PingWin A')]);

        // Uma tabela, duas janelas: cada model só vê o seu source.
        $this->assertSame(2, DB::table('suppliers')->where('company_id', $this->companyId)->count());
        $this->assertSame(1, Supplier::where('company_id', $this->companyId)->count());        // só manual
        $this->assertSame(1, PingwinSupplier::where('company_id', $this->companyId)->count());  // só pingwin
        $this->assertSame('manual', DB::table('suppliers')->where('name', 'Manual A')->value('source'));
        $this->assertSame('pingwin', DB::table('suppliers')->where('name', 'PingWin A')->value('source'));
    }

    public function test_manual_supplier_survives_the_pingwin_sync_and_resync(): void
    {
        $manual = Supplier::create(['company_id' => $this->companyId, 'name' => 'Fornecedor Manual', 'nif' => '123']);
        $this->assertDatabaseHas('suppliers', ['id' => $manual->id, 'source' => 'manual']);

        // 1ª sincronização.
        $this->runSyncUpsert([$this->pingwinRow('PW-1', 'PW Um'), $this->pingwinRow('PW-2', 'PW Dois')]);

        // ⚠️ O manual continua LÁ e continua manual; os 2 pingwin entraram.
        $this->assertDatabaseHas('suppliers', ['id' => $manual->id, 'source' => 'manual']);
        $this->assertSame(2, PingwinSupplier::where('company_id', $this->companyId)->count());

        // 2ª sincronização (reenvio de listas): idempotente, o manual sobrevive, sem duplicar.
        $this->runSyncUpsert([$this->pingwinRow('PW-1', 'PW Um (editado)'), $this->pingwinRow('PW-2', 'PW Dois')]);

        $this->assertDatabaseHas('suppliers', ['id' => $manual->id, 'source' => 'manual']);
        $this->assertSame(1, Supplier::where('company_id', $this->companyId)->count());          // manual intacto
        $this->assertSame(2, PingwinSupplier::where('company_id', $this->companyId)->count());    // sem duplicados
        $this->assertSame('PW Um (editado)', PingwinSupplier::where('pingwin_id', 'PW-1')->value('name')); // update aplicado
    }

    public function test_decoupled_company_manages_only_manual_suppliers(): void
    {
        // Empresa SEM PingWin: cria/edita/arquiva manuais normalmente, sem tocar no PingWin.
        $s = Supplier::create(['company_id' => $this->companyId, 'name' => 'Só Manual', 'nif' => '999']);
        $s->update(['phone' => '910000000', 'archived' => true]);

        $this->assertSame(1, Supplier::where('company_id', $this->companyId)->count());
        $this->assertSame(0, PingwinSupplier::where('company_id', $this->companyId)->count());
        $this->assertDatabaseHas('suppliers', ['id' => $s->id, 'source' => 'manual', 'phone' => '910000000', 'archived' => 1]);
    }
}
