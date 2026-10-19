<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Models\Company;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinDocumentConfig;
use App\Models\PingwinLocation;
use App\Models\PingwinSupplier;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\SupplierCcService;
use Illuminate\Support\Facades\DB;

/**
 * Os dados das Finanças › Mês nos testes (setembro de 2026, hoje 09/10/2026): famílias, artigos,
 * fornecedores, documentos de agosto e setembro, notas de liquidação, vendas e conta corrente.
 */
trait FinanceFixtures
{
    protected const FV = '1209';
    protected const FR = '584955579139752236';
    protected const NC = '1205';
    protected const NL = '1204';

    protected Company $company;
    protected User $user;
    protected array $sup = [];

    protected function seedFinance(): void
    {
        $this->travelTo('2026-10-09 12:00:00');
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->company = Company::create(['nipc' => '500013400', 'fiscal_name' => 'Resto', 'plan_id' => $planId, 'subscription_status' => 'active']);
        app(CompanyModuleService::class)->applyPreset($this->company->id, 'restaurant');
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $c = $this->company->id;

        foreach ([[self::FV, false], [self::FR, true], [self::NL, true], [self::NC, false]] as [$id, $settled]) {
            PingwinDocumentConfig::create(['company_id' => $c, 'external_id' => $id, 'code' => $id, 'description' => $id, 'deleted' => false, 'settled' => $settled, 'synced_at' => now()]);
        }
        // A árvore de famílias do PingWin (a raiz é "Família").
        foreach ([['F0', 'Família', null], ['F1', 'COMPRAS', 'F0'], ['F2', 'COMIDAS', 'F1'], ['F3', 'BEBIDAS ', 'F1'], ['F4', 'DIVERSOS', 'F1'],
            ['F5', 'Cauções de embalagens - fornecedores', 'F4'], ['F6', 'FORNECIMENTOS E SERV EXTERNOS', 'F0'], ['F7', 'RECURSOS HUMANOS', 'F0'],
            ['F8', 'OUTROS DEVEDORES/CREDORES', 'F0'], ['F9', 'PREDIAIS', 'F8'], ['F10', 'Rendas e alugueres', 'F9']] as [$id, $d, $p]) {
            DB::table('pingwin_families')->insert(['company_id' => $c, 'pingwin_id' => $id, 'description' => $d, 'parent_pingwin_id' => $p, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([['A-CARNE', 'Lombo de porco', 'F2'], ['A-CERV', 'Cerveja barril', 'F3'], ['A-CAU', 'Caução barril', 'F5'], ['A-LUZ', 'Energias 23% iva', 'F6'],
            ['A-REN', 'Renda loja', 'F10'], ['A-DEP', 'VALOR DO DEPOSITO', 'F4']] as [$id, $d, $f]) {
            PingwinCatalogItem::create(['company_id' => $c, 'pingwin_id' => $id, 'code' => $id, 'description' => $d, 'family_pingwin_id' => $f, 'is_active' => true]);
        }
        foreach ([['E-CA', 'Carnes'], ['E-BE', 'Bebidas'], ['E-EDP', 'EDP'], ['E-REN', 'Senhorio'], ['E-NOVO', 'Fornecedor novo'], ['E-HAB', 'Talho habitual']] as [$pid, $name]) {
            $this->sup[$pid] = PingwinSupplier::create(['company_id' => $c, 'pingwin_id' => $pid, 'code' => $pid, 'name' => $name, 'is_active' => true, 'synced_at' => now()]);
        }

        // Agosto: o talho habitual vende carne (família habitual: Comida).
        $this->doc('D0', self::FV, 'E-HAB', '2026-08-15', 4920, 920, [['A-CARNE', 4000]]);
        // Setembro.
        $this->doc('D1', self::FV, 'E-CA', '2026-09-03', 12300, 2300, [['A-CARNE', 10000]], '2026-09-30');
        $this->doc('D2', self::FV, 'E-BE', '2026-09-05', 7150, 1150, [['A-CERV', 5000], ['A-CAU', 1000]], '2026-09-05');
        $this->doc('D3', self::NC, 'E-CA', '2026-09-10', 2460, 460, [['A-CARNE', 2000]], '2026-09-10');
        $this->doc('D4', self::FV, 'E-EDP', '2026-09-12', 24600, 4600, [['A-LUZ', 19999]], '2026-09-15');   // 1 cêntimo de acerto
        $this->doc('D5', self::FV, 'E-REN', '2026-09-04', 100000, 0, [['A-REN', 100000]], '2026-10-20');
        $this->doc('D6', self::FV, 'E-HAB', '2026-09-20', 5000, null, null);                                // sem linhas lidas
        $this->doc('D7', self::FV, 'E-NOVO', '2026-09-21', 3690, 690, [[null, 3000]], '2026-09-30');       // linha sem artigo
        $this->doc('D8', self::FR, 'E-BE', '2026-09-22', 2345, 345, [['A-DEP', 500], ['A-CERV', 1500]]);   // fatura-recibo: paga no ato
        $this->doc('D9', self::FV, 'E-BE', '2026-09-25', 6150, null, null, null, '8001', 'Aberto');         // em Aberto
        $this->doc('D10', self::FV, 'E-CA', '2026-09-26', 99900, 18680, [['A-CARNE', 81220]], null, '8003', 'Anulado');
        // Pagamentos (notas de liquidação) no espelho dos documentos.
        $this->doc('N1', self::NL, 'E-CA', '2026-09-28', 12300, null, null);
        $this->doc('N2', self::NL, 'E-BE', '2026-09-15', 4000, null, null);
        $this->doc('N3', self::NL, 'E-BE', '2026-10-02', 3150, null, null);

        // Vendas por artigo: setembro 5 000,00 € (2 dias), agosto 4 000,00 €.
        $loc = PingwinLocation::create(['company_id' => $c, 'winrest_store_id' => 'L1', 'display_name' => 'Baixa', 'is_active' => true]);
        foreach ([['2026-09-01', 300000, 39000], ['2026-09-02', 200000, 26000], ['2026-08-10', 400000, 52000]] as [$d, $net, $tax]) {
            DB::table('pingwin_item_sales_daily')->insert(['company_id' => $c, 'location_id' => $loc->id, 'business_date' => $d, 'product_pingwin_id' => 'P1',
                'product_name' => 'Prato', 'quantity' => 1, 'net_cents' => $net, 'tax_cents' => $tax, 'gross_cents' => $net + $tax, 'synced_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        // Conta corrente (S1).
        $cc = app(SupplierCcService::class);
        $cc->persistSupplier($c, $this->sup['E-CA'], ['ok' => true, 'balance' => ['balance' => -24.60], 'documents' => [
            $this->cc('D1', self::FV, 123.00, 0, 1, '2026-09-03', '2026-09-30'),
            $this->cc('N1', self::NL, 123.00, 0, -1, '2026-09-28', '2026-09-28'),
            $this->cc('D3', self::NC, 24.60, 24.60, -1, '2026-09-10', '2026-09-10'),
        ]]);
        $cc->persistSupplier($c, $this->sup['E-BE'], ['ok' => true, 'balance' => ['balance' => 23.45], 'documents' => [
            $this->cc('D2', self::FV, 71.50, 0, 1, '2026-09-05', '2026-09-05'),
            $this->cc('D8', self::FR, 23.45, 23.45, 1, '2026-09-22', '2026-09-22'),
            $this->cc('N2', self::NL, 40.00, 0, -1, '2026-09-15', '2026-09-15'),
            $this->cc('N3', self::NL, 31.50, 0, -1, '2026-10-02', '2026-10-02'),
        ]]);
        $cc->persistSupplier($c, $this->sup['E-EDP'], ['ok' => true, 'balance' => ['balance' => 246.00], 'documents' => [$this->cc('D4', self::FV, 246.00, 246.00, 1, '2026-09-12', '2026-09-15')]]);
        $cc->persistSupplier($c, $this->sup['E-REN'], ['ok' => true, 'balance' => ['balance' => 1000.00], 'documents' => [$this->cc('D5', self::FV, 1000.00, 1000.00, 1, '2026-09-04', '2026-10-20')]]);
        $cc->persistSupplier($c, $this->sup['E-NOVO'], ['ok' => true, 'balance' => ['balance' => 36.90], 'documents' => [$this->cc('D7', self::FV, 36.90, 36.90, 1, '2026-09-21', '2026-09-30')]]);
    }

    /** Um documento de fornecedor (F1) e as suas linhas (F4): [produto ou null, cêntimos]. */
    protected function doc(string $id, string $cfg, string $entity, string $date, int $total, ?int $tax, ?array $lines, ?string $due = null, string $st = '8002', string $stLabel = 'Fechado'): void
    {
        DB::table('pingwin_supplier_documents')->insert(['company_id' => $this->company->id, 'docheader_id' => $id, 'docconfig_id' => $cfg, 'doctype' => $cfg,
            'document' => "DOC/{$id}", 'entity_pingwin_id' => $entity, 'entity_name' => $this->sup[$entity]->name ?? $entity, 'doc_date' => $date, 'due_date' => $due,
            'total_cents' => $total, 'total_tax_cents' => $tax, 'paid' => false, 'docstatus_id' => $st, 'docstatus_description' => $stLabel,
            'lines_status' => $lines === null ? null : 'ok', 'created_at' => now(), 'updated_at' => now()]);
        foreach ((array) $lines as $i => [$product, $cents]) {
            DB::table('pingwin_supplier_document_lines')->insert(['company_id' => $this->company->id, 'docheader_id' => $id, 'line_number' => $i + 1,
                'product_pingwin_id' => $product, 'description' => $product ? null : 'Serviço sem artigo', 'total_cents' => $cents, 'tax_value_cents' => 0,
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function cc(string $id, string $cfg, float $total, float $topay, int $sig, string $date, string $due): array
    {
        $pw = static fn (string $d) => str_replace('-', '', $d) . 'T00:00:00';

        return ['docheader_id' => $id, 'docconfig_id' => $cfg, 'doctype' => $cfg, 'document' => "DOC/{$id}", 'doc_date' => $pw($date), 'due_date' => $pw($due),
            'fiscal_date' => $pw($date), 'total' => $total, 'total_paid' => round($total - $topay, 2), 'topay' => $topay, 'paid' => $topay == 0.0 ? 1 : 0,
            'ca_signal' => $sig, 'docreference_number' => '', 'docreference_date' => '', 'docheader_store_id' => 'S', 'store' => 'Loja', 'docstatus_description' => 'Fechado'];
    }
}
