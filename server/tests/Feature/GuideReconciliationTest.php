<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditScope;
use App\Audit\Rules\Restaurant\Money\FaturaGuiasEmFaltaRule;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\OcrInvoice;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Restaurant\GuideReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fatia 5 · Conferência fatura × guias. O caso de referência: a FT FAR.2026/379 do Forno refere 14
 * guias; no PingWin estão 12 (uma "fatura" por entrega, sem referência); faltam a GT 3233 (06/05) e a
 * GT 3732 (26/05): 189,95 €. Também: a guia pelo número, a guia a mais e a diferença de quantidade.
 */
class GuideReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private int $c;
    private int $seq = 0;
    private const NIF = '516182609';

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->c = Company::create(['nipc' => '500009930', 'fiscal_name' => 'Yuko Lda', 'plan_id' => $planId, 'subscription_status' => 'active'])->id;
        app(CompanyModuleService::class)->applyPreset($this->c, 'restaurant');
        CompanyIntegration::create(['company_id' => $this->c, 'platform' => 'pingwin', 'status' => 'active', 'access_token' => 'x', 'config' => ['username' => 'op', 'database' => 'yuko']]);
    }

    /** Um documento do Forno no PingWin com as linhas [qnt, preço]. */
    private function pw(string $date, array $lines, string $ref = '', string $docconfig = '1209'): string
    {
        $id = 'D' . (++$this->seq);
        $total = (int) round(array_sum(array_map(fn ($l) => $l[0] * $l[1] * 100, $lines)) * 1.06);
        DB::table('pingwin_supplier_documents')->insert(['company_id' => $this->c, 'docheader_id' => $id, 'docconfig_id' => $docconfig, 'doctype' => 'Fatura de fornecedor',
            'document' => "VFT 0VFT/{$this->seq}", 'doc_date' => $date, 'docstatus_id' => '8002', 'entity_name' => 'FORNO TRADICIONAL', 'entity_pingwin_id' => 'F', 'tax_number' => self::NIF,
            'docreference_number' => $ref, 'total_cents' => $total, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($lines as $i => [$q, $p]) {
            DB::table('pingwin_supplier_document_lines')->insert(['company_id' => $this->c, 'docheader_id' => $id, 'line_number' => $i + 1, 'product_pingwin_id' => 'P', 'description' => 'FORMA',
                'qnt' => $q, 'price' => $p, 'total_cents' => (int) round($q * $p * 100), 'created_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }

    /** A fatura: as guias [ref, data] e as linhas [qnt, preço] pela ordem das guias. */
    private function invoice(array $guides, array $lines): OcrInvoice
    {
        $inv = OcrInvoice::create(['company_id' => $this->c, 'supplier_name' => 'FORNO TRADICIONAL, Lda.', 'supplier_nif' => self::NIF, 'number' => 'FT FAR.2026/379', 'doc_type' => 'FT',
            'issue_date' => '2026-05-31', 'status' => 'por_validar', 'image_path' => 'x.jpg', 'guide_refs' => array_map(fn ($g) => ['ref' => $g[0], 'date' => $g[1]], $guides)]);
        foreach ($lines as $i => [$q, $p]) {
            DB::table('ocr_invoice_lines')->insert(['ocr_invoice_id' => $inv->id, 'company_id' => $this->c, 'position' => $i, 'item' => $p > 1.5 ? 'Forma Redonda - Fatiada' : 'Forma Redonda - Inteira',
                'quantity' => $q, 'unit' => 'UN', 'unit_price' => $p, 'unit_price_cents' => (int) round($p * 100), 'discount_pct' => 0, 'line_total_cents' => (int) round($q * $p * 100), 'vat_rate' => 6,
                'created_at' => now(), 'updated_at' => now()]);
        }

        return $inv;
    }

    /** O caso real: 14 guias, as linhas pela ordem e 12 documentos no PingWin (faltam 06/05 e 26/05). */
    private function forno(): OcrInvoice
    {
        $F = 1.51; $I = 1.41;
        $g = [['GT 3105/2026', '2026-05-02', [[40, $F]]], ['GT 3159/2026', '2026-05-04', [[20, $I], [40, $F]]], ['GT 3233/2026', '2026-05-06', [[40, $F]]],
            ['GT 3247/2026', '2026-05-07', [[60, $F]]], ['GT 3273/2026', '2026-05-08', [[80, $F]]], ['GT 3375/2026', '2026-05-12', [[60, $F]]], ['GT 3401/2026', '2026-05-13', [[20, $I]]],
            ['GT 3427/2026', '2026-05-14', [[20, $I], [60, $F]]], ['GT 3480/2026', '2026-05-16', [[80, $F]]], ['GT 3550/2026', '2026-05-19', [[20, $I], [40, $F]]],
            ['GT 3628/2026', '2026-05-22', [[40, $F]]], ['GT 3732/2026', '2026-05-26', [[20, $I], [60, $F]]], ['GT 3786/2026', '2026-05-28', [[40, $F]]], ['GT 3846/2026', '2026-05-30', [[12, $I], [60, $F]]]];
        foreach ($g as [$ref, $date, $lines]) {
            if (! in_array($ref, ['GT 3233/2026', 'GT 3732/2026'], true)) {
                $this->pw($date, $lines);
            }
        }
        $this->pw('2026-05-28', [[1, 1]], '', '1200');   // uma encomenda: nunca conta

        return $this->invoice(array_map(fn ($x) => [$x[0], $x[1]], $g), array_merge(...array_map(fn ($x) => $x[2], $g)));
    }

    public function test_far_2026_379_shows_the_two_missing_guides_and_189_95(): void
    {
        $r = app(GuideReconciliationService::class)->for($this->forno());
        $this->assertSame(['referred' => 14, 'launched' => 12, 'missing' => 2, 'missing_eur_cents' => 18995], array_intersect_key($r['summary'], array_flip(['referred', 'launched', 'missing', 'missing_eur_cents'])));
        $missing = array_values(array_filter($r['guides'], fn ($g) => $g['status'] === 'em_falta'));
        $this->assertSame(['GT 3233/2026', 'GT 3732/2026'], array_column($missing, 'ref'));
        $this->assertSame([6402, 12593], array_column($missing, 'eur_cents'));   // 40 × 1,51 e 20 × 1,41 + 60 × 1,51, com IVA a 6 %
        $this->assertSame('2026-05-07', $r['guides'][3]['doc']['date']);           // a GT 3247 fica com o documento do dia dela, não a 3233
        $this->assertSame([], $r['extra']);
        $this->assertSame(0, $r['summary']['line_diff_eur_cents']);
    }

    public function test_guide_by_number_extra_guide_and_quantity_difference(): void
    {
        $this->pw('2026-05-10', [[10, 2.0]], 'GT 500/2026');          // pelo número (a data não bate)
        $this->pw('2026-05-12', [[8, 3.0]]);                          // pela data, mas faturadas 10 (diferença de quantidade)
        $extra = $this->pw('2026-05-20', [[5, 1.0]]);                 // lançada e não referida
        $inv = $this->invoice([['GT 500/2026', '2026-05-03'], ['GT 501/2026', '2026-05-12']], [[10, 2.0], [10, 3.0]]);
        $r = app(GuideReconciliationService::class)->for($inv);
        $this->assertSame(['numero', 'data'], array_column($r['guides'], 'method'));
        $this->assertSame('com_diferencas', $r['guides'][1]['status']);
        $this->assertSame([['kind' => 'quantidade', 'eur_cents' => 636]], $r['guides'][1]['lines'][0]['diffs']);   // 2 × 3,00 € × 1,06
        $this->assertSame([$extra], array_column($r['extra'], 'docheader_id'));
        $this->assertSame(530, $r['summary']['extra_eur_cents']);
        $this->assertSame(0, $r['summary']['missing']);
    }

    public function test_audit_rule_and_endpoint(): void
    {
        $inv = $this->forno();
        $f = iterator_to_array(app(FaturaGuiasEmFaltaRule::class)->run($this->c, new AuditScope($this->c, 'restauracao', 'central')), false);
        $this->assertCount(1, $f);
        $this->assertSame([(string) $inv->id, 18995, 'FT FAR.2026/379: 2 guias não lançadas'], [$f[0]->entityId, $f[0]->impactCents, $f[0]->title]);
        $this->assertStringContainsString('GT 3233/2026 (06/05/2026), GT 3732/2026 (26/05/2026)', $f[0]->seen);

        $admin = User::factory()->create(['company_id' => $this->c, 'role' => 'admin']);
        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/companies/{$this->c}/ocr/invoices/{$inv->id}/guides")->assertOk()
            ->assertJsonPath('data.reconciliation.summary.missing_eur_cents', 18995);
        $plain = OcrInvoice::create(['company_id' => $this->c, 'supplier_nif' => self::NIF, 'number' => 'FT 1', 'issue_date' => '2026-05-31', 'status' => 'por_validar', 'image_path' => 'y.jpg']);
        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/companies/{$this->c}/ocr/invoices/{$plain->id}/guides")->assertOk()->assertJsonPath('data.reconciliation', null);
    }
}
