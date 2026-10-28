<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Mail\AccountantDocumentsMail;
use App\Models\Company;
use App\Models\FinanceDocumentMark;
use App\Models\PingwinSupplierDocument;
use App\Services\OcrPingwinLinkService;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ZipArchive;

/**
 * XPLENDOR — Finanças: o envio à contabilidade (substitui a folha manual "Documentos enviados para a
 * contabilidade"). Só na XPLENDOR: marcar como enviado, a Situação e a nota; a folha em XLSX com o
 * layout antigo; o ZIP com os PDFs que estão na XPLENDOR (as faturas do OCR) e a folha; o email ao
 * contabilista (pré-visualização primeiro, envio só com o clique); importar a folha antiga.
 */
class AccountingService
{
    /** As colunas da folha antiga, pela mesma ordem. */
    public const COLUMNS = ['NIF', 'Titular', 'Data do documento', 'Tipo de documento', 'Nº do documento', 'Valor', 'Situação', 'Enviado em', 'Forma de envio', 'Nota'];
    public const TITLE = 'Documentos enviados para a contabilidade';
    /** Os anexos de um email não passam disto (senão, "Preparar envio" e descarregar o ZIP). */
    public const MAX_EMAIL_BYTES = 20 * 1024 * 1024;

    // ─────────────────────────────────────────────────────────────────────────
    // Marcas
    // ─────────────────────────────────────────────────────────────────────────

    /** "Marcar como enviado" num ou em vários documentos. */
    public function markSent(int $companyId, array $docheaderIds, string $sentOn, string $method, ?int $userId, string $source = 'manual'): int
    {
        if (! array_key_exists($method, FinanceDocumentMark::SEND_METHODS)) {
            throw ValidationException::withMessages(['send_method' => ['Escolha a forma de envio.']]);
        }
        $ids = $this->existing($companyId, $docheaderIds);
        DB::transaction(function () use ($companyId, $ids, $sentOn, $method, $userId, $source) {
            foreach ($ids as $id) {
                FinanceDocumentMark::updateOrCreate(['company_id' => $companyId, 'docheader_id' => $id],
                    ['sent_on' => $sentOn, 'send_method' => $method, 'sent_by' => $userId, 'updated_by' => $userId, 'source' => $source]);
            }
        });

        return count($ids);
    }

    /** Desmarcar o envio (engano). */
    public function unmarkSent(int $companyId, array $docheaderIds, ?int $userId): int
    {
        return FinanceDocumentMark::where('company_id', $companyId)->whereIn('docheader_id', array_map('strval', $docheaderIds))
            ->update(['sent_on' => null, 'send_method' => null, 'sent_by' => null, 'updated_by' => $userId]);
    }

    /** A nota e a Situação de um documento (editáveis). */
    public function edit(int $companyId, string $docheaderId, array $data, ?int $userId): FinanceDocumentMark
    {
        $this->existing($companyId, [$docheaderId]);
        $values = ['updated_by' => $userId];
        foreach (['note' => 500, 'situation' => 60] as $k => $max) {
            if (array_key_exists($k, $data)) {
                $v = trim((string) ($data[$k] ?? ''));
                $values[$k] = $v === '' ? null : mb_substr($v, 0, $max);
            }
        }

        return FinanceDocumentMark::updateOrCreate(['company_id' => $companyId, 'docheader_id' => $docheaderId], $values);
    }

    private function existing(int $companyId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
        if ($ids === []) {
            throw ValidationException::withMessages(['docheader_ids' => ['Escolha pelo menos um documento.']]);
        }
        $found = PingwinSupplierDocument::where('company_id', $companyId)->whereIn('docheader_id', $ids)->pluck('docheader_id')->all();
        if (count($found) !== count($ids)) {
            throw ValidationException::withMessages(['docheader_ids' => ['Documento não encontrado.']]);
        }

        return $ids;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // A folha (XLSX)
    // ─────────────────────────────────────────────────────────────────────────

    /** As linhas da folha: os documentos do mês (ou só os escolhidos), pela ordem da data. */
    public function rows(int $companyId, string $month, ?array $docheaderIds = null): array
    {
        $rows = (new MonthFinanceService($companyId))->month($month)['documents']['rows'];
        if ($docheaderIds !== null) {
            $want = array_flip(array_map('strval', $docheaderIds));
            $rows = array_values(array_filter($rows, fn ($r) => isset($want[$r['docheader_id']])));
        }
        usort($rows, fn ($a, $b) => [$a['doc_date'], $a['document']] <=> [$b['doc_date'], $b['document']]);

        return $rows;
    }

    /** A folha em XLSX, com o título, a empresa, o NIF e o mês, e as colunas da folha antiga. */
    public function sheet(int $companyId, string $month, array $rows): string
    {
        $company = Company::find($companyId);
        $book = new Spreadsheet();
        $ws = $book->getActiveSheet();
        $ws->setTitle('Contabilidade');
        $ws->setCellValue('A1', self::TITLE);
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $ws->setCellValue('A2', 'Empresa');
        $ws->setCellValue('B2', (string) ($company?->fiscal_name ?? ''));
        $ws->setCellValue('A3', 'NIF');
        $ws->setCellValueExplicit('B3', (string) ($company?->nipc ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $ws->setCellValue('A4', 'Mês');
        $ws->setCellValue('B4', ucfirst(MonthFinanceService::monthLabel($month)));
        foreach (self::COLUMNS as $i => $h) {
            $ws->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '6', $h);
        }
        $ws->getStyle('A6:J6')->getFont()->setBold(true);
        $r = 7;
        foreach ($rows as $row) {
            $a = $row['accounting'];
            $ws->setCellValueExplicit("A{$r}", (string) ($row['tax_number'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $ws->setCellValue("B{$r}", (string) ($row['supplier'] ?? ''));
            $ws->setCellValue("C{$r}", ExcelDate::PHPToExcel(CarbonImmutable::parse($row['doc_date'])->startOfDay()->toDateTime()));
            $ws->setCellValue("D{$r}", (string) ($row['doctype'] ?? ''));
            $ws->setCellValueExplicit("E{$r}", (string) (($row['supplier_doc'] ?? '') ?: ($row['document'] ?? '')), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $ws->setCellValue("F{$r}", round($row['total_cents'] / 100, 2));
            $ws->setCellValue("G{$r}", (string) ($a['situation'] ?? ''));
            if ($a['sent_on']) {
                $ws->setCellValue("H{$r}", ExcelDate::PHPToExcel(CarbonImmutable::parse($a['sent_on'])->toDateTime()));
            }
            $ws->setCellValue("I{$r}", (string) ($a['send_method_label'] ?? ''));
            $ws->setCellValue("J{$r}", (string) ($a['note'] ?? ''));
            $r++;
        }
        $last = max(7, $r - 1);
        $ws->getStyle("C7:C{$last}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $ws->getStyle("H7:H{$last}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $ws->getStyle("F7:F{$last}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
        $ws->getStyle("F6:F{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        foreach (['A' => 12, 'B' => 36, 'C' => 14, 'D' => 26, 'E' => 22, 'F' => 12, 'G' => 18, 'H' => 14, 'I' => 16, 'J' => 40] as $col => $w) {
            $ws->getColumnDimension($col)->setWidth($w);
        }
        $ws->freezePane('A7');
        ob_start();
        (new Xlsx($book))->save('php://output');

        return (string) ob_get_clean();
    }

    public static function sheetName(string $month): string
    {
        return "contabilidade-{$month}.xlsx";
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ficheiros: ZIP e email
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Os PDFs que estão na XPLENDOR (as faturas carregadas no OCR ligadas ao documento) e os que não
     * têm ficheiro ("Sem ficheiro na XPLENDOR").
     *
     * @return array{files: list<array{docheader_id:string, name:string, path:string, mime:string, bytes:int}>, missing: list<array{docheader_id:string, document:?string, supplier:?string}>}
     */
    public function files(int $companyId, array $rows): array
    {
        $disk = Storage::disk((string) config('services.openai.ocr_disk', 'local'));
        $ocr = DB::table('ocr_invoice_pingwin_links as k')->join('ocr_invoices as o', 'o.id', '=', 'k.ocr_invoice_id')
            ->where('k.company_id', $companyId)->whereIn('k.docheader_id', array_column($rows, 'docheader_id'))->whereNull('o.deleted_at')->whereNull('o.file_purged_at')
            ->get(['k.docheader_id', 'o.image_path', 'o.image_mime'])->keyBy('docheader_id');
        $files = [];
        $missing = [];
        $used = [];
        foreach ($rows as $row) {
            $o = $ocr[$row['docheader_id']] ?? null;
            if (! $o || ! $o->image_path || ! $disk->exists($o->image_path)) {
                $missing[] = ['docheader_id' => $row['docheader_id'], 'document' => $row['document'], 'supplier' => $row['supplier']];
                continue;
            }
            $ext = strtolower(pathinfo($o->image_path, PATHINFO_EXTENSION)) ?: 'pdf';
            $base = Str::slug(substr((string) $row['doc_date'], 0, 10) . ' ' . ($row['supplier'] ?? '') . ' ' . (($row['supplier_doc'] ?? '') ?: ($row['document'] ?? '')));
            $name = $base . '.' . $ext;
            for ($i = 2; isset($used[$name]); $i++) {
                $name = "{$base}-{$i}.{$ext}";
            }
            $used[$name] = true;
            $files[] = ['docheader_id' => $row['docheader_id'], 'name' => $name, 'path' => $o->image_path, 'mime' => $o->image_mime ?: 'application/pdf', 'bytes' => (int) $disk->size($o->image_path)];
        }

        return ['files' => $files, 'missing' => $missing];
    }

    /** "Preparar envio": um ZIP com os PDFs e a folha. Devolve o caminho de um ficheiro temporário. */
    public function zip(int $companyId, string $month, array $rows): array
    {
        $f = $this->files($companyId, $rows);
        $disk = Storage::disk((string) config('services.openai.ocr_disk', 'local'));
        $path = tempnam(sys_get_temp_dir(), 'xp-contab-') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Não foi possível preparar o ZIP.');
        }
        $zip->addFromString(self::sheetName($month), $this->sheet($companyId, $month, $rows));
        foreach ($f['files'] as $file) {
            $zip->addFromString($file['name'], (string) $disk->get($file['path']));
        }
        $zip->close();

        return ['path' => $path, 'name' => "contabilidade-{$month}.zip", 'files' => $f['files'], 'missing' => $f['missing']];
    }

    /** A pré-visualização do email ao contabilista: o destinatário, o assunto, a lista e os anexos. */
    public function emailPreview(int $companyId, string $month, array $rows): array
    {
        $to = trim((string) Settings::company($companyId, 'finance.accountant_email'));
        $company = Company::find($companyId);
        $f = $this->files($companyId, $rows);
        $sheet = ['name' => self::sheetName($month), 'bytes' => strlen($this->sheet($companyId, $month, $rows))];
        $bytes = $sheet['bytes'] + array_sum(array_column($f['files'], 'bytes'));
        $problems = [];
        if ($to === '') {
            $problems[] = 'Falta o email do contabilista (Configurações da empresa › Finanças).';
        }
        if ($rows === []) {
            $problems[] = 'Escolha pelo menos um documento.';
        }
        if ($bytes > self::MAX_EMAIL_BYTES) {
            $problems[] = 'Os anexos passam de 20 MB: use "Preparar envio" e envie o ZIP de outra forma.';
        }

        return [
            'to' => $to ?: null,
            'subject' => sprintf('%s: documentos de %s', $company?->fiscal_name ?? 'Documentos', MonthFinanceService::monthLabel($month)),
            'lines' => [sprintf('Seguem os documentos de %s (%d %s).', MonthFinanceService::monthLabel($month), count($rows), count($rows) === 1 ? 'documento' : 'documentos'),
                'Em anexo, a folha "' . self::TITLE . '" e as faturas que estão na XPLENDOR.'],
            'documents' => array_map(fn ($r) => ['docheader_id' => $r['docheader_id'], 'number' => ($r['supplier_doc'] ?? '') ?: (string) $r['document'],
                'supplier' => (string) $r['supplier'], 'date' => CarbonImmutable::parse($r['doc_date'])->format('d/m/Y'),
                'value' => MonthFinanceService::eur((int) $r['total_cents'])], $rows),
            'attachments' => array_merge([$sheet], array_map(fn ($x) => ['name' => $x['name'], 'bytes' => $x['bytes']], $f['files'])),
            'missing' => $f['missing'],
            'bytes' => $bytes,
            'can_send' => $problems === [],
            'problems' => $problems,
        ];
    }

    /** Envia ao contabilista (só depois da pré-visualização) e marca os documentos como enviados. */
    public function emailSend(int $companyId, string $month, array $rows, ?int $userId, ?string $replyTo = null): array
    {
        $p = $this->emailPreview($companyId, $month, $rows);
        if (! $p['can_send']) {
            throw ValidationException::withMessages(['email' => $p['problems']]);
        }
        $disk = Storage::disk((string) config('services.openai.ocr_disk', 'local'));
        $files = [['name' => self::sheetName($month), 'data' => $this->sheet($companyId, $month, $rows), 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']];
        foreach ($this->files($companyId, $rows)['files'] as $f) {
            $files[] = ['name' => $f['name'], 'data' => (string) $disk->get($f['path']), 'mime' => $f['mime']];
        }
        Mail::to($p['to'])->send(new AccountantDocumentsMail($p['subject'], $p['subject'], $p['lines'], $p['documents'],
            'Os documentos sem ficheiro na XPLENDOR seguem pelo meio habitual.', $files, $replyTo));
        $n = $this->markSent($companyId, array_column($rows, 'docheader_id'), now('Europe/Lisbon')->toDateString(), 'email', $userId, 'email');

        return ['sent_to' => $p['to'], 'documents' => $n, 'attachments' => count($files)];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Importar a folha antiga
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * "Importar folha antiga (XLSX)": emparelha cada linha por NIF e nº do documento com os documentos
     * de fornecedor e preenche a Situação, o Enviado em, a Forma de envio e a Nota. Devolve o que
     * emparelhou e o que não.
     */
    public function import(int $companyId, string $path, ?int $userId): array
    {
        // Só os leitores de Excel (o ficheiro já foi validado como xlsx ou xls): sem a deteção
        // automática, uma folha nunca é lida como ODS, XML, HTML ou outro formato.
        $ws = IOFactory::load($path, 0, [IOFactory::READER_XLSX, IOFactory::READER_XLS])->getActiveSheet();
        $data = $ws->toArray(null, true, false, false);
        $head = null;
        $col = [];
        foreach ($data as $i => $row) {
            $norm = array_map(fn ($v) => mb_strtolower(trim((string) $v)), $row);
            if (in_array('nif', $norm, true) && collect($norm)->contains(fn ($v) => str_starts_with($v, 'nº do documento') || str_starts_with($v, 'n.º do documento') || $v === 'número do documento')) {
                $head = $i;
                foreach ($norm as $k => $v) {
                    $col[$v] = $k;
                }
                break;
            }
        }
        if ($head === null) {
            throw ValidationException::withMessages(['file' => ['Não encontrei o cabeçalho da folha (as colunas NIF e Nº do documento).']]);
        }
        $find = fn (array $names) => collect($names)->map(fn ($n) => $col[$n] ?? null)->first(fn ($v) => $v !== null);
        $c = ['nif' => $find(['nif']), 'titular' => $find(['titular']), 'numero' => $find(['nº do documento', 'n.º do documento', 'número do documento']),
            'situacao' => $find(['situação', 'situacao']), 'enviado' => $find(['enviado em']), 'forma' => $find(['forma de envio']), 'nota' => $find(['nota'])];

        $docs = PingwinSupplierDocument::where('company_id', $companyId)->get(['docheader_id', 'document', 'docreference_number', 'tax_number', 'entity_pingwin_id']);
        $supNif = DB::table('suppliers')->where('company_id', $companyId)->whereNotNull('pingwin_id')->pluck('tax_number', 'pingwin_id');
        $digits = fn ($v) => preg_replace('/\D+/', '', (string) $v);
        $index = [];
        foreach ($docs as $d) {
            $nif = $digits($d->tax_number ?: ($supNif[$d->entity_pingwin_id] ?? ''));
            foreach (array_unique(array_filter([$d->docreference_number, $d->document])) as $num) {
                $index[$nif][OcrPingwinLinkService::numberKey($num)][] = ['id' => $d->docheader_id, 'full' => self::norm($num)];
            }
        }

        $matched = 0;
        $unmatched = [];
        DB::transaction(function () use ($data, $head, $c, $index, $digits, $companyId, $userId, &$matched, &$unmatched) {
            foreach (array_slice($data, $head + 1, null, true) as $i => $row) {
                $nif = $digits($row[$c['nif']] ?? '');
                $num = trim((string) ($row[$c['numero']] ?? ''));
                if ($nif === '' && $num === '') {
                    continue;
                }
                $cands = $index[$nif][OcrPingwinLinkService::numberKey($num)] ?? [];
                $exact = array_values(array_filter($cands, fn ($x) => $x['full'] === self::norm($num)));
                $hit = count($exact) === 1 ? $exact[0] : (count($cands) === 1 ? $cands[0] : null);
                if (! $hit) {
                    $unmatched[] = ['row' => $i + 1, 'nif' => $nif ?: null, 'titular' => $c['titular'] !== null ? (string) ($row[$c['titular']] ?? '') : null, 'number' => $num,
                        'reason' => count($cands) > 1 ? 'Há mais do que um documento com este NIF e este número.' : 'Sem documento com este NIF e este número.'];
                    continue;
                }
                $values = ['updated_by' => $userId, 'source' => 'importado'];
                if ($c['situacao'] !== null && trim((string) ($row[$c['situacao']] ?? '')) !== '') {
                    $values['situation'] = mb_substr(trim((string) $row[$c['situacao']]), 0, 60);
                }
                if ($c['enviado'] !== null && ($d = self::date($row[$c['enviado']] ?? null))) {
                    $values['sent_on'] = $d;
                    $values['send_method'] = self::sendMethod($c['forma'] !== null ? (string) ($row[$c['forma']] ?? '') : '');
                }
                if ($c['nota'] !== null && trim((string) ($row[$c['nota']] ?? '')) !== '') {
                    $values['note'] = mb_substr(trim((string) $row[$c['nota']]), 0, 500);
                }
                FinanceDocumentMark::updateOrCreate(['company_id' => $companyId, 'docheader_id' => $hit['id']], $values);
                $matched++;
            }
        });

        return ['matched' => $matched, 'unmatched' => $unmatched];
    }

    private static function norm(?string $v): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $v) ?? '');
    }

    /** Uma data da folha: número do Excel, "dd/mm/aaaa" ou "aaaa-mm-dd". */
    private static function date($v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $v))->toDateString();
        }
        $s = trim((string) $v);
        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})$#', $s, $m)) {
            $y = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];

            return checkdate((int) $m[2], (int) $m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}/', $s) ? substr($s, 0, 10) : null;
    }

    private static function sendMethod(string $v): string
    {
        $v = mb_strtolower(trim($v));

        return match (true) {
            str_contains($v, 'mail') => 'email',
            str_contains($v, 'mão') || str_contains($v, 'entreg') => 'entregue',
            str_contains($v, 'plataforma') => 'plataforma',
            default => 'outro',
        };
    }
}
