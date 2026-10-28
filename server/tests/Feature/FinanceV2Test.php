<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\AccountantDocumentsMail;
use App\Models\FinancePayment;
use App\Models\FinanceRuleEvent;
use App\Models\OcrInvoice;
use App\Models\OcrInvoicePingwinLink;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinDocumentConfig;
use App\Models\PingwinSupplier;
use App\Services\Finance\AccountingService;
use App\Services\Finance\MonthFinanceService;
use App\Services\SupplierCcService;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Concerns\FinanceFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * Finanças v2 (só na XPLENDOR): as regras de classificação com prioridade sobre a família do PingWin
 * (e o histórico); um tipo de documento novo em "Por classificar"; o mês provisório (documentos
 * habituais em falta e resultado estimado); o pagamento registado, desfeito e substituído pela nota
 * do PingWin; "Regularizar"; o envio à contabilidade (marcar, XLSX com o layout da folha, importar com
 * e sem par, ZIP e o email com a pré-visualização, sem envio real).
 */
class FinanceV2Test extends TestCase
{
    use RefreshDatabase;
    use FinanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFinance();
        $c = $this->company->id;
        // A folha de obrigações: julho e agosto (no PingWin, as linhas estão em "IMPOSTO DO SELO"); setembro ainda não chegou.
        foreach ([['F11', 'ESTADO', 'F8'], ['F12', 'IMPOSTO DO SELO', 'F11']] as [$id, $d, $p]) {
            DB::table('pingwin_families')->insert(['company_id' => $c, 'pingwin_id' => $id, 'description' => $d, 'parent_pingwin_id' => $p, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([['A-SS', '100835', 'SEGURANÇA SOCIAL'], ['A-RET', '100834', 'RETENÇÕES NA FONTE -T.DEPENDENTE']] as [$id, $code, $d]) {
            PingwinCatalogItem::create(['company_id' => $c, 'pingwin_id' => $id, 'code' => $code, 'description' => $d, 'family_pingwin_id' => 'F12', 'is_active' => true]);
        }
        $this->sup['E-FOLHA'] = PingwinSupplier::create(['company_id' => $c, 'pingwin_id' => 'E-FOLHA', 'code' => 'FO', 'name' => 'FOLHA DE OBRIGAÇÕES', 'is_active' => true, 'synced_at' => now()]);
        $this->doc('FO7', self::FV, 'E-FOLHA', '2026-07-01', 100000, 0, [['A-SS', 90000], ['A-RET', 10000]], '2026-07-31');
        $this->doc('FO8', self::FV, 'E-FOLHA', '2026-08-01', 100000, 0, [['A-SS', 90000], ['A-RET', 10000]], '2026-08-31');
        DB::table('pingwin_supplier_documents')->where('docheader_id', 'D1')->update(['tax_number' => '501111111', 'docreference_number' => 'FT A/77']);
    }

    private function api(string $method, string $path, array $data = [])
    {
        return $this->actingAs($this->user, 'sanctum')->json($method, "/api/v1/companies/{$this->company->id}/finance{$path}", $data);
    }

    private function month(string $m = '2026-09'): array
    {
        return (new MonthFinanceService($this->company->id))->month($m);
    }

    private function row(array $m, string $doc): array
    {
        return collect($m['documents']['rows'])->firstWhere('docheader_id', $doc) ?? $this->fail("documento {$doc}");
    }

    private function groupOf(array $m, string $g): int
    {
        return (int) collect($m['result']['rows'])->where('kind', 'grupo')->firstWhere('group', $g)['cents'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Regras

    public function test_a_rule_wins_over_the_pingwin_family_shows_both_and_keeps_history(): void
    {
        $aug = fn () => $this->month('2026-08');
        $this->assertSame(100000, $this->groupOf($aug(), 'outros'), 'sem a regra, a folha cai em Outros (família "IMPOSTO DO SELO")');

        $id = $this->api('POST', '/rules', ['match_type' => 'artigo', 'match_values' => ['100833', '100834', '100835'], 'match_label' => 'Artigos 100833 a 100835',
            'entity_pingwin_id' => 'E-FOLHA', 'entity_name' => 'FOLHA DE OBRIGAÇÕES', 'group' => 'pessoal'])->assertCreated()->json('data.rule.id');
        $m = $aug();
        $this->assertSame([0, 100000], [$this->groupOf($m, 'outros'), $this->groupOf($m, 'pessoal')]);
        $part = $this->row($m, 'FO8')['parts'][0];
        $this->assertSame(['regra', 'OUTROS DEVEDORES/CREDORES › ESTADO › IMPOSTO DO SELO', 'Artigos 100833 a 100835 de FOLHA DE OBRIGAÇÕES → Pessoal'],
            [$part['source'], $part['pingwin_family'], $part['rule']['label']]);
        // A regra da entidade não apanha outra entidade com o mesmo artigo.
        $this->assertSame('comida', $this->row($m, 'D0')['group']);

        $this->api('PUT', "/rules/{$id}", ['active' => false])->assertOk()->assertJsonPath('message', 'Regra desativada.');
        $this->assertSame(100000, $this->groupOf($aug(), 'outros'));
        $this->assertSame(['criar', 'desativar'], FinanceRuleEvent::orderBy('id')->pluck('action')->all());
        $this->api('GET', '/rules')->assertOk()->assertJsonPath('data.events.0.action', 'desativar')->assertJsonPath('data.rules.0.label', 'Artigos 100833 a 100835 de FOLHA DE OBRIGAÇÕES → Pessoal');
        // Uma regra por família e por entidade: a de artigo ganha à de entidade, que ganha à de família.
        $this->api('POST', '/rules', ['match_type' => 'familia', 'match_values' => ['COMPRAS › COMIDAS'], 'group' => 'outros'])->assertCreated();
        $this->api('POST', '/rules', ['match_type' => 'entidade', 'match_values' => ['E-CA'], 'entity_name' => 'Carnes', 'group' => 'fse'])->assertCreated();
        $m = $this->month();
        $this->assertSame(['fse', 'outros'], [$this->row($m, 'D1')['group'], $this->row($m, 'D6')['group']]);
        $this->api('POST', '/rules', ['match_type' => 'xpto', 'match_values' => ['1'], 'group' => 'fse'])->assertStatus(422);
    }

    public function test_a_new_document_type_is_unclassified_with_create_rule_until_a_rule_exists(): void
    {
        PingwinDocumentConfig::create(['company_id' => $this->company->id, 'external_id' => '777001', 'code' => 'SAL', 'description' => 'Despesa de salários',
            'entitytype' => 'Fornecedor', 'doctype_id' => '17001', 'deleted' => false, 'settled' => true, 'synced_at' => now()]);
        $this->doc('S9', '777001', 'E-NOVO', '2026-09-30', 500000, 0, null);
        $s9 = $this->row($this->month(), 'S9');
        $this->assertSame([500000, 'Despesa de salários'], [$s9['unclassified_cents'], $s9['new_type']['label']]);
        $this->assertStringContainsString('crie uma regra', $s9['unclassified_reason']);

        $this->api('POST', '/rules', ['match_type' => 'tipo_documento', 'match_values' => ['777001'], 'group' => 'pessoal'])->assertCreated();
        $m = $this->month();
        $this->assertSame(['pessoal', 0], [$this->row($m, 'S9')['group'], $this->row($m, 'S9')['unclassified_cents']]);
        $this->assertSame(500000, $this->groupOf($m, 'pessoal'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Mês provisório

    public function test_habitual_documents_missing_make_the_month_provisional_with_an_estimated_result(): void
    {
        $m = $this->month();
        $this->assertSame('Provisório: faltam 1 documento habitual (cerca de 1 000,00 €).', $m['provisional']['text']);
        $miss = $m['provisional']['missing'][0];
        $this->assertSame(['FOLHA DE OBRIGAÇÕES', 2, 100000, 'DOC/FO8'], [$miss['supplier'], $miss['months'], $miss['avg_cents'], $miss['last_document']]);
        $this->assertSame([351350, 251350, 50.3], [$m['cards']['result']['cents'], $m['cards']['result']['estimated_cents'], $m['cards']['result']['estimated_pct']]);
        // O aviso antigo de "Outros abaixo da média" passou a ser esta lista.
        $this->assertSame(['Vendas: há vendas por artigo em 2 dos 30 dias do mês.'], $m['warnings']);

        // Quando a folha de setembro chega, o mês deixa de ser provisório.
        $this->doc('FO9', self::FV, 'E-FOLHA', '2026-09-01', 100000, 0, [['A-SS', 90000], ['A-RET', 10000]], '2026-09-30');
        $m = $this->month();
        $this->assertNull($m['provisional']);
        $this->assertNull($m['cards']['result']['estimated_cents']);
    }

    public function test_the_six_month_chart_marks_the_months_still_provisional(): void
    {
        // Em outubro: setembro e outubro sem a folha (veio em julho e agosto); agosto olha para maio a julho e não está provisório.
        $chart = array_column($this->month('2026-10')['result']['chart'], 'provisional', 'month');
        $this->assertSame(['2026-05' => false, '2026-06' => false, '2026-07' => false, '2026-08' => false, '2026-09' => true, '2026-10' => true], $chart);
        // "Não vem este mês" em setembro: setembro deixa de estar provisório no gráfico de outubro.
        $this->api('POST', '/habitual-exclusions', ['entity_pingwin_id' => 'E-FOLHA', 'scope' => 'mes', 'month' => '2026-09'])->assertCreated();
        $chart = array_column($this->month('2026-10')['result']['chart'], 'provisional', 'month');
        $this->assertSame([false, true], [$chart['2026-09'], $chart['2026-10']]);
    }

    public function test_not_coming_this_month_removes_it_only_from_this_month_and_the_month_stops_being_provisional(): void
    {
        $this->api('POST', '/habitual-exclusions', ['entity_pingwin_id' => 'E-FOLHA', 'scope' => 'mes', 'month' => '2026-09', 'avg_cents' => 100000, 'reason' => 'Vem em outubro'])
            ->assertCreated()->assertJsonPath('message', 'FOLHA DE OBRIGAÇÕES: não vem este mês. O resultado estimado foi recalculado.');
        $m = $this->month();
        $this->assertNull($m['provisional'], 'sem documentos habituais em falta, o mês deixa de ser provisório');
        $this->assertNull($m['cards']['result']['estimated_cents']);
        $this->assertSame([['FOLHA DE OBRIGAÇÕES', 'mes', 'Vem em outubro', $this->user->name]],
            array_map(fn ($e) => [$e['supplier'], $e['scope'], $e['reason'], $e['by']], $m['habitual_excluded']));
        // Só este mês: em outubro volta a faltar (julho e agosto continuam a contar).
        $this->assertSame(['FOLHA DE OBRIGAÇÕES'], array_column($this->month('2026-10')['provisional']['missing'], 'supplier'));
        // Duas vezes no mesmo mês, não.
        $this->api('POST', '/habitual-exclusions', ['entity_pingwin_id' => 'E-FOLHA', 'scope' => 'sempre', 'month' => '2026-09'])->assertStatus(422);
    }

    public function test_not_habitual_removes_it_from_this_month_on_and_restoring_brings_it_back_with_history(): void
    {
        $id = $this->api('POST', '/habitual-exclusions', ['entity_pingwin_id' => 'E-FOLHA', 'scope' => 'sempre', 'month' => '2026-09'])->assertCreated()->json('data.exclusion.id');
        $this->assertNull($this->month()['provisional']);
        $this->assertNull($this->month('2026-10')['provisional'], 'a partir deste mês');
        $list = $this->api('GET', '/habitual-exclusions')->assertOk()->json('data.exclusions');
        $this->assertSame([[true, 'sempre', '2026-09', $this->user->name]], array_map(fn ($e) => [$e['active'], $e['scope'], $e['month'], $e['by']], $list));

        $this->api('DELETE', "/habitual-exclusions/{$id}")->assertOk()->assertJsonPath('message', 'FOLHA DE OBRIGAÇÕES volta a contar como habitual.');
        $this->assertSame('Provisório: faltam 1 documento habitual (cerca de 1 000,00 €).', $this->month()['provisional']['text']);
        $hist = $this->api('GET', '/habitual-exclusions')->json('data.exclusions');
        $this->assertSame([false, $this->user->name], [$hist[0]['active'], $hist[0]['removed_by']]);
        $this->api('DELETE', "/habitual-exclusions/{$id}")->assertNotFound();
        $this->api('POST', '/habitual-exclusions', ['entity_pingwin_id' => 'E-NADA', 'scope' => 'mes', 'month' => '2026-09'])->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pagamentos

    public function test_a_registered_payment_counts_as_paid_can_be_undone_and_stays_in_the_history(): void
    {
        $before = $this->month();
        $this->assertSame(['vencido', 28290], [$this->row($before, 'D4')['payment']['state'], $before['cards']['cash']['overdue_cents']]);

        $this->api('POST', '/payments', ['docheader_ids' => ['D4'], 'paid_on' => '2026-09-20', 'method' => 'transferencia', 'reference' => 'TRF 123'])
            ->assertCreated()->assertJsonPath('message', 'Pagamento registado na XPLENDOR.');
        $m = $this->month();
        $p = $this->row($m, 'D4')['payment'];
        $this->assertSame(['pago_xplendor', 'Pago em 20/09 (registado na XPLENDOR)', 'TF'], [$p['state'], $p['label'], $this->row($m, 'D4')['accounting']['situation']]);
        // A tesouraria e o vencido contam-no como pago: saiu em setembro, já não vence.
        $this->assertSame([18645 + 24600, 25830 - 24600, 28290 - 24600], [$m['cards']['cash']['paid_cents'], $m['cards']['cash']['due_by_month_end_cents'], $m['cards']['cash']['overdue_cents']]);
        $rec = $m['cash']['reconciliation'];
        $this->assertTrue($rec['ok'], 'a conferência com a conta corrente continua a ser a do PingWin');
        $this->assertSame([24600, 125830 - 24600], [$rec['registered_cents'], $rec['after_registers_cents']]);
        // Duas vezes o mesmo documento, não.
        $this->api('POST', '/payments', ['docheader_ids' => ['D4'], 'paid_on' => '2026-09-21', 'method' => 'numerario'])->assertStatus(422);

        $pid = FinancePayment::first()->id;
        $this->api('DELETE', "/payments/{$pid}")->assertOk();
        $this->assertSame('vencido', $this->row($this->month(), 'D4')['payment']['state']);
        $hist = $this->api('GET', '/payments/history/D4')->assertOk()->json('data.history');
        $this->assertCount(1, $hist);
        $this->assertNotNull($hist[0]['undone_at']);
    }

    public function test_the_pingwin_settlement_wins_over_the_registered_payment_and_warns_when_it_differs(): void
    {
        $this->api('POST', '/payments', ['docheader_ids' => ['D7'], 'paid_on' => '2026-09-30', 'method' => 'cartao_debito'])->assertCreated();
        $this->assertSame('pago_xplendor', $this->row($this->month(), 'D7')['payment']['state']);
        // A nota de liquidação aparece na conta corrente, com outra data.
        app(SupplierCcService::class)->persistSupplier($this->company->id, $this->sup['E-NOVO'], ['ok' => true, 'balance' => ['balance' => 0], 'documents' => [
            $this->cc('D7', self::FV, 36.90, 0, 1, '2026-09-21', '2026-09-30'),
            $this->cc('N7', self::NL, 36.90, 0, -1, '2026-10-01', '2026-10-01'),
        ]]);
        $p = $this->row($this->month(), 'D7')['payment'];
        $this->assertSame(['pago', '2026-10-01', true], [$p['state'], $p['paid_on'], $p['xplendor_superseded']]);
        $this->assertSame('O pagamento registado na XPLENDOR não bate com o do PingWin: a data (30/09 na XPLENDOR, 01/10 no PingWin).', $p['warning']);
        $this->assertSame('Nota de liquidação', $this->row($this->month(), 'D7')['accounting']['situation']);
    }

    public function test_regularize_several_documents_with_one_payment(): void
    {
        $sup = collect($this->api('GET', '/payables')->assertOk()->json('data.suppliers'))->keyBy('supplier');
        $this->assertSame([24600, 24600], [$sup['EDP']['total_cents'], $sup['EDP']['overdue_cents']]);
        $this->api('POST', '/payments', ['docheader_ids' => ['D4', 'D5'], 'paid_on' => '2026-10-08', 'method' => 'transferencia'])
            ->assertCreated()->assertJsonPath('message', '2 pagamentos registados na XPLENDOR.');
        $this->assertSame(1, FinancePayment::distinct('batch')->count('batch'));
        $this->assertSame([24600, 100000], FinancePayment::orderBy('docheader_id')->pluck('amount_cents')->all());
        $left = collect($this->api('GET', '/payables')->json('data.suppliers'))->pluck('supplier')->all();
        $this->assertNotContains('EDP', $left);
        $this->assertNotContains('Senhorio', $left);
        $this->api('POST', '/payments', ['docheader_ids' => ['D8'], 'paid_on' => '2026-10-08', 'method' => 'transferencia'])
            ->assertStatus(422)->assertJsonPath('errors.docheader_ids.0', 'DOC/D8: é uma fatura-recibo, paga no ato.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Envio à contabilidade

    public function test_mark_as_sent_note_and_the_unsent_filter_field(): void
    {
        $this->api('POST', '/accounting/sent', ['docheader_ids' => ['D1', 'D2'], 'sent_on' => '2026-10-05', 'send_method' => 'email'])
            ->assertOk()->assertJsonPath('message', '2 documentos marcados como enviados à contabilidade.');
        $this->api('PUT', '/accounting/documents/D1', ['note' => 'Original em papel'])->assertOk();
        $m = $this->month();
        $a = $this->row($m, 'D1')['accounting'];
        $this->assertSame([true, '2026-10-05', 'Email', 'Original em papel'], [$a['sent'], $a['sent_on'], $a['send_method_label'], $a['note']]);
        $this->assertFalse($this->row($m, 'D3')['accounting']['sent']);
        $this->api('POST', '/accounting/unsent', ['docheader_ids' => ['D2']])->assertOk();
        $this->assertFalse($this->row($this->month(), 'D2')['accounting']['sent']);
    }

    public function test_the_sheet_has_the_old_layout(): void
    {
        $this->api('POST', '/accounting/sent', ['docheader_ids' => ['D1'], 'sent_on' => '2026-10-05', 'send_method' => 'email']);
        $res = $this->api('POST', '/accounting/sheet', ['month' => '2026-09'])->assertOk();
        $this->assertStringContainsString('spreadsheetml', $res->headers->get('Content-Type'));
        $path = tempnam(sys_get_temp_dir(), 'xls');
        file_put_contents($path, $res->getContent());
        $ws = IOFactory::load($path)->getActiveSheet();
        $this->assertSame(['Documentos enviados para a contabilidade', 'Empresa', 'Resto', 'NIF', '500013400', 'Mês', 'Setembro de 2026'],
            [$ws->getCell('A1')->getValue(), $ws->getCell('A2')->getValue(), $ws->getCell('B2')->getValue(), $ws->getCell('A3')->getValue(),
                $ws->getCell('B3')->getValue(), $ws->getCell('A4')->getValue(), $ws->getCell('B4')->getValue()]);
        $this->assertSame(AccountingService::COLUMNS, $ws->rangeToArray('A6:J6')[0]);
        $rows = collect($ws->rangeToArray('A7:J20', null, true, false))->filter(fn ($r) => $r[1] !== null);
        $d1 = $rows->first(fn ($r) => $r[4] === 'FT A/77');
        $this->assertSame(['501111111', 'Carnes', '03/09/2026', 123.0, 'Nota de liquidação', '05/10/2026', 'Email'],
            [$d1[0], $d1[1], $ws->getCell('C' . (7 + $rows->search($d1)))->getFormattedValue(), (float) $d1[5], $d1[6],
                $ws->getCell('H' . (7 + $rows->search($d1)))->getFormattedValue(), $d1[8]]);
        @unlink($path);
    }

    public function test_import_the_old_sheet_with_and_without_a_match(): void
    {
        $book = new Spreadsheet();
        $ws = $book->getActiveSheet();
        $ws->fromArray([['Documentos enviados para a contabilidade'], [], [], [], [], AccountingService::COLUMNS,
            ['501111111', 'Carnes', '03/09/2026', 'Fatura', 'FT A/77', 123, 'TF', '06/10/2026', 'Email', 'Pago pelo banco'],
            ['999999990', 'Desconhecido', '03/09/2026', 'Fatura', 'FT 9/9', 10, 'Recibo', '06/10/2026', 'Email', ''],
        ], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'imp') . '.xlsx';
        (new Xlsx($book))->save($path);

        $res = $this->actingAs($this->user, 'sanctum')->post("/api/v1/companies/{$this->company->id}/finance/accounting/import",
            ['file' => new UploadedFile($path, 'folha.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true)])->assertOk();
        $this->assertSame(1, $res->json('data.matched'));
        $this->assertSame([['row' => 8, 'nif' => '999999990', 'titular' => 'Desconhecido', 'number' => 'FT 9/9', 'reason' => 'Sem documento com este NIF e este número.']], $res->json('data.unmatched'));
        $a = $this->row($this->month(), 'D1')['accounting'];
        $this->assertSame(['TF', true, '2026-10-06', 'Email', 'Pago pelo banco', true], [$a['situation'], $a['sent'], $a['sent_on'], $a['send_method_label'], $a['note'], $a['situation_manual']]);
    }

    /**
     * phpspreadsheet: a importação só lê Excel. Um ODS (o formato do aviso de esgotar a memória) é
     * recusado na validação; e o serviço, mesmo com outro formato à frente, nunca o lê como ODS ou
     * XML (sem a deteção automática do formato).
     */
    public function test_import_reads_only_excel(): void
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([AccountingService::COLUMNS], null, 'A1');
        $ods = tempnam(sys_get_temp_dir(), 'imp') . '.ods';
        (new \PhpOffice\PhpSpreadsheet\Writer\Ods($book))->save($ods);

        $this->actingAs($this->user, 'sanctum')->post("/api/v1/companies/{$this->company->id}/finance/accounting/import",
            ['file' => new UploadedFile($ods, 'folha.xlsx', 'application/vnd.oasis.opendocument.spreadsheet', null, true)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $this->expectException(\PhpOffice\PhpSpreadsheet\Reader\Exception::class);
        app(AccountingService::class)->import($this->company->id, $ods, $this->user->id);
    }

    public function test_prepare_the_zip_with_the_pdfs_and_the_sheet_and_list_what_has_no_file(): void
    {
        Storage::fake('local');
        $inv = OcrInvoice::create(['company_id' => $this->company->id, 'image_path' => 'ocr/f1.pdf', 'image_mime' => 'application/pdf', 'status' => 'validada']);
        Storage::disk('local')->put('ocr/f1.pdf', '%PDF-1.4 fatura');
        OcrInvoicePingwinLink::create(['company_id' => $this->company->id, 'ocr_invoice_id' => $inv->id, 'docheader_id' => 'D1', 'method' => 'numero']);

        $files = $this->api('POST', '/accounting/files', ['month' => '2026-09', 'docheader_ids' => ['D1', 'D2']])->assertOk()->json('data');
        $this->assertSame(['D1'], array_column($files['files'], 'docheader_id'));
        $this->assertSame(['D2'], array_column($files['missing'], 'docheader_id'));
        $this->assertTrue($this->row($this->month(), 'D1')['has_file']);

        $res = $this->api('POST', '/accounting/zip', ['month' => '2026-09', 'docheader_ids' => ['D1', 'D2']])->assertOk();
        $this->assertSame('1', $res->headers->get('X-Sem-Ficheiro'));
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($res->baseResponse->getFile()->getPathname()));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $this->assertSame(['contabilidade-2026-09.xlsx', '2026-09-03-carnes-ft-a77.pdf'], $names);
        $this->assertSame('%PDF-1.4 fatura', $zip->getFromName('2026-09-03-carnes-ft-a77.pdf'));
    }

    public function test_email_to_the_accountant_shows_the_preview_first_and_only_sends_on_click(): void
    {
        Mail::fake();
        $p = $this->api('POST', '/accounting/email-preview', ['month' => '2026-09', 'docheader_ids' => ['D1']])->assertOk()->json('data');
        $this->assertFalse($p['can_send']);
        $this->assertSame(['Falta o email do contabilista (Configurações da empresa › Finanças).'], $p['problems']);
        $this->api('POST', '/accounting/email-send', ['month' => '2026-09', 'docheader_ids' => ['D1']])->assertStatus(422);
        Mail::assertNothingSent();

        Settings::setCompany($this->company->id, ['finance.accountant_email' => 'contas@example.com'], $this->user);
        $p = $this->api('POST', '/accounting/email-preview', ['month' => '2026-09', 'docheader_ids' => ['D1', 'D2']])->assertOk()->json('data');
        $this->assertSame(['contas@example.com', 'Resto: documentos de setembro de 2026', true], [$p['to'], $p['subject'], $p['can_send']]);
        $this->assertSame(['contabilidade-2026-09.xlsx'], array_column($p['attachments'], 'name'));
        $this->assertCount(2, $p['missing']);
        Mail::assertNothingSent();

        $this->api('POST', '/accounting/email-send', ['month' => '2026-09', 'docheader_ids' => ['D1', 'D2']])->assertOk()
            ->assertJsonPath('message', 'Enviado a contas@example.com: 2 documentos marcados como enviados.');
        Mail::assertSent(AccountantDocumentsMail::class, fn ($m) => $m->hasTo('contas@example.com') && count($m->attachments()) === 1 && count($m->documents) === 2);
        $a = $this->row($this->month(), 'D2')['accounting'];
        $this->assertSame([true, 'Email'], [$a['sent'], $a['send_method_label']]);
        $this->api('PUT', '/accounting/documents/D2', ['note' => str_repeat('x', 501)])->assertStatus(422);
    }
}
