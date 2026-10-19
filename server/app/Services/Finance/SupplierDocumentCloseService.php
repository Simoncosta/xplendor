<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Jobs\CloseSupplierDocumentsJob;
use App\Models\OcrInvoice;
use App\Models\OcrInvoicePingwinLink;
use App\Models\PingwinSupplierDocument;
use App\Models\PingwinSupplierDocumentLine;
use App\Models\ProductUnitProfile;
use App\Models\SupplierDocumentClose;
use App\Services\PingwinService;
use App\Services\SupplierDocumentLinesService;
use App\Settings\PingwinWriteGate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * XPLENDOR — "Fechar no PingWin" (8002) qualquer documento de fornecedor em Aberto (8001), também os
 * lançados diretamente no PingWin. ⚠️ ESCRITA no PingWin: uma de cada vez, com releitura.
 *
 * Antes de fechar, a XPLENDOR confere o documento com as linhas espelhadas: tem linhas; o total das
 * linhas bate com o total sem IVA (acerto até 0,05 €); as unidades dos artigos das linhas estão
 * confirmadas no Guião de unidades. Se falhar alguma, não deixa fechar e diz porquê.
 * No worker, antes de gravar, relê o documento ao vivo (só leitura) e repete a conferência sobre o
 * que leu: o espelho pode estar atrasado (um documento já anulado nunca é fechado).
 * Um lote ("Fechar os selecionados", no máximo 10) fecha um de cada vez e para no primeiro erro.
 * Cada fecho fica registado: quem, quando, o documento, o estado antes e depois.
 */
class SupplierDocumentCloseService
{
    public const OPEN = '8001';
    public const CLOSED = '8002';

    public function __construct(private readonly PingwinService $pingwin) {}

    private function cfg(string $key, $default = null)
    {
        return config("finance_month.{$key}", $default);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Conferência (pelo espelho)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * O que impede fechar cada documento (lista vazia = pode). Pelo espelho; o worker repete-o
     * sobre a leitura viva.
     *
     * @param  Collection<int, PingwinSupplierDocument>  $docs
     * @return array<string, list<array{key:string, message:string}>>
     */
    public function checks(int $companyId, Collection $docs): array
    {
        $lines = PingwinSupplierDocumentLine::where('company_id', $companyId)->whereIn('docheader_id', $docs->pluck('docheader_id')->all())
            ->get(['docheader_id', 'total_cents', 'tax_value_cents', 'product_pingwin_id', 'description'])->groupBy('docheader_id');
        $pending = SupplierDocumentClose::where('company_id', $companyId)->where('status', SupplierDocumentClose::PENDING)
            ->whereIn('docheader_id', $docs->pluck('docheader_id')->all())->pluck('docheader_id')->flip();
        $gate = PingwinWriteGate::blockOther($companyId);
        $out = [];
        foreach ($docs as $d) {
            $out[$d->docheader_id] = $this->problems($companyId, [
                'docconfig_id' => (string) $d->docconfig_id, 'docstatus_id' => (string) $d->docstatus_id,
                'total_cents' => (int) $d->total_cents, 'tax_cents' => $d->total_tax_cents,
                'lines' => ($lines[$d->docheader_id] ?? collect())->map(fn ($l) => ['total_cents' => (int) $l->total_cents, 'tax_cents' => (int) $l->tax_value_cents,
                    'product' => $l->product_pingwin_id, 'description' => $l->description])->all(),
            ], isset($pending[$d->docheader_id]), $gate);
        }

        return $out;
    }

    /**
     * As regras, sobre um documento normalizado (do espelho ou da leitura viva).
     *
     * @param  array{docconfig_id:string, docstatus_id:string, total_cents:int, tax_cents:?int, lines:list<array{total_cents:int, tax_cents:int, product:?string, description:?string}>}  $doc
     * @return list<array{key:string, message:string}>
     */
    public function problems(int $companyId, array $doc, bool $pending = false, ?array $gate = null): array
    {
        $p = [];
        if ($gate) {
            $p[] = ['key' => 'escrita', 'message' => $gate['reason']];
        }
        if (! in_array($doc['docconfig_id'], (array) $this->cfg('close_docconfigs', []), true)) {
            $p[] = ['key' => 'tipo', 'message' => 'Este tipo de documento não se fecha a partir da XPLENDOR.'];
        }
        if ($doc['docstatus_id'] !== self::OPEN) {
            $p[] = ['key' => 'estado', 'message' => $doc['docstatus_id'] === self::CLOSED ? 'O documento já está fechado.' : 'Só se fecha um documento em Aberto.'];
        }
        if ($pending) {
            $p[] = ['key' => 'em_curso', 'message' => 'Já há um fecho em curso para este documento.'];
        }
        if ($doc['lines'] === []) {
            $p[] = ['key' => 'linhas', 'message' => 'O documento não tem linhas lidas: carregue em "Ler as linhas do PingWin" (só leitura) ou abra-o no PingWin.'];

            return $p;
        }
        $tol = (int) $this->cfg('close_tolerance_cents', 5);
        $linesNet = array_sum(array_column($doc['lines'], 'total_cents'));
        $net = $doc['tax_cents'] !== null ? $doc['total_cents'] - (int) $doc['tax_cents'] : null;
        $linesGross = $linesNet + array_sum(array_column($doc['lines'], 'tax_cents'));
        if ($net === null ? abs($linesGross - $doc['total_cents']) > $tol : abs($linesNet - $net) > $tol) {
            $p[] = ['key' => 'total', 'message' => sprintf('O total das linhas (%s) não bate com o do documento (%s): diferença acima de 0,05 €.',
                self::eur($net === null ? $linesGross : $linesNet), self::eur($net ?? $doc['total_cents']))];
        }
        $products = array_values(array_unique(array_filter(array_column($doc['lines'], 'product'))));
        if (count($products) < count($doc['lines'])) {
            $p[] = ['key' => 'artigos', 'message' => 'Há linhas sem artigo: não se confirmam as unidades.'];
        }
        if ($products !== []) {
            $ok = ProductUnitProfile::where('company_id', $companyId)->whereIn('product_pingwin_id', $products)
                ->whereIn('state', ['confirmado', 'aplicado_no_pingwin'])->pluck('product_pingwin_id')->map(fn ($v) => (string) $v)->flip();
            $missing = array_values(array_filter($products, fn ($id) => ! isset($ok[(string) $id])));
            if ($missing !== []) {
                $names = DB::table('pingwin_catalog_items')->where('company_id', $companyId)->whereIn('pingwin_id', $missing)->pluck('description')->take(5)->all();
                $p[] = ['key' => 'unidades', 'message' => 'Falta confirmar as unidades no Guião de unidades: ' . implode(', ', $names ?: $missing)
                    . (count($missing) > 5 ? sprintf(' e mais %d', count($missing) - 5) : '') . '.'];
            }
        }

        return $p;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pedido e execução
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * "Fechar no PingWin" / "Fechar os selecionados": confere todos e, só se todos puderem, cria o
     * lote (um registo por documento) e manda-o ao worker.
     *
     * @param  list<string>  $docheaderIds
     * @return array{batch:string, closes: Collection}
     */
    public function request(int $companyId, array $docheaderIds, ?int $userId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $docheaderIds))));
        $max = (int) $this->cfg('close_max_batch', 10);
        if ($ids === []) {
            throw new \InvalidArgumentException('Escolha pelo menos um documento.');
        }
        if (count($ids) > $max) {
            throw new \InvalidArgumentException("No máximo {$max} documentos de cada vez.");
        }

        return DB::transaction(function () use ($companyId, $ids, $userId) {
            $docs = PingwinSupplierDocument::where('company_id', $companyId)->whereIn('docheader_id', $ids)->lockForUpdate()->get()->keyBy('docheader_id');
            $missing = array_diff($ids, $docs->keys()->all());
            if ($missing !== []) {
                throw new \InvalidArgumentException('Documento não encontrado.');
            }
            $checks = $this->checks($companyId, $docs->values());
            $blocked = array_filter($checks);
            if ($blocked !== []) {
                $first = array_key_first($blocked);
                throw new \App\Exceptions\LaunchBlocked(array_map(fn ($p) => $p + ['document' => $docs[$first]->document, 'docheader_id' => $first], $blocked[$first]));
            }
            $batch = (string) Str::uuid();
            $closes = collect($ids)->values()->map(fn ($id, $i) => SupplierDocumentClose::create([
                'company_id' => $companyId, 'batch' => $batch, 'position' => $i, 'docheader_id' => $id, 'docconfig_id' => $docs[$id]->docconfig_id,
                'document' => $docs[$id]->document, 'entity_name' => $docs[$id]->entity_name, 'total_cents' => (int) $docs[$id]->total_cents,
                'user_id' => $userId, 'status' => SupplierDocumentClose::PENDING, 'status_before' => $docs[$id]->docstatus_id,
            ]));
            CloseSupplierDocumentsJob::dispatch($companyId, $batch)->afterCommit();

            return ['batch' => $batch, 'closes' => $closes];
        });
    }

    /** No worker: um de cada vez, com a leitura viva antes e a releitura depois; para no primeiro erro. */
    public function execute(int $companyId, string $batch): void
    {
        $closes = SupplierDocumentClose::where('company_id', $companyId)->where('batch', $batch)->orderBy('position')->get();
        $stopped = null;
        foreach ($closes as $c) {
            if ($c->status !== SupplierDocumentClose::PENDING) {
                continue;
            }
            if ($stopped !== null) {
                $c->update(['status' => SupplierDocumentClose::NOT_DONE, 'error' => "Não foi feito: o fecho de {$stopped} falhou.", 'finished_at' => now()]);
                continue;
            }
            if (! $this->closeOne($c)) {
                $stopped = $c->document ?? $c->docheader_id;
            }
        }
    }

    /** Fecha um documento. true = fechado e confirmado pela releitura. */
    private function closeOne(SupplierDocumentClose $c): bool
    {
        $c->update(['started_at' => now()]);
        $doc = PingwinSupplierDocument::where('company_id', $c->company_id)->where('docheader_id', $c->docheader_id)->first();
        if (! $doc) {
            return $this->finish($c, SupplierDocumentClose::ERROR, 'O documento já não existe no espelho.');
        }
        try {
            // 1. Leitura viva (só leitura) e a conferência outra vez, sobre o que se leu.
            $live = $this->pingwin->fetchSupplierDocumentLines($c->company_id, [['docconfig_id' => (string) $doc->docconfig_id, 'docheader_id' => (string) $doc->docheader_id]])[0] ?? null;
            if (! ($live['ok'] ?? false)) {
                return $this->finish($c, SupplierDocumentClose::ERROR, 'Não foi possível ler o documento no PingWin: ' . ($live['error'] ?? 'erro') . ' Nada foi gravado.');
            }
            $h = (array) ($live['header'] ?? []);
            $c->update(['status_before' => (string) ($h['docstatus_id'] ?? $doc->docstatus_id)]);
            $problems = $this->problems($c->company_id, $this->normalize($h, (array) ($live['details'] ?? []), (string) $doc->docconfig_id), false, PingwinWriteGate::blockOther($c->company_id));
            if ((int) round(((float) ($h['total'] ?? 0)) * 100) !== (int) $doc->total_cents) {
                $problems[] = ['key' => 'mudou', 'message' => 'O total no PingWin mudou desde a última sincronização: sincronize os documentos e confira de novo.'];
            }
            if ($problems !== []) {
                return $this->finish($c, SupplierDocumentClose::ERROR, implode(' ', array_column($problems, 'message')) . ' Nada foi gravado.', ['live' => $h]);
            }
            // 2. Fechar (OPEN, EDIT, SAVE, CLOSE com docstatus 8002) e reler.
            $sent = true;
            $res = $this->pingwin->documentStatus($c->company_id, (string) $doc->docheader_id, self::CLOSED, (string) $doc->docconfig_id);
        } catch (\Throwable $e) {
            Log::warning('[Fechar documento] falhou', ['close_id' => $c->id, 'error' => $e->getMessage()]);

            // Depois de pedir o fecho, sem resposta não se sabe se mudou: rever no PingWin.
            return isset($sent)
                ? $this->finish($c, SupplierDocumentClose::CONFIRM_ERROR, $e->getMessage() . ' Reveja o estado no PingWin.')
                : $this->finish($c, SupplierDocumentClose::ERROR, $e->getMessage() . ' Nada foi gravado.');
        }
        $after = (string) ($res['reread']['header']['docstatus_id'] ?? '');
        $c->update(['status_after' => $after !== '' ? $after : null, 'result' => $res]);
        if (! ($res['result']['ok'] ?? false) && $after !== self::CLOSED) {
            return $this->finish($c, SupplierDocumentClose::ERROR, (string) ($res['result']['error'] ?? 'O PingWin recusou a mudança de estado.'));
        }
        if ($after !== self::CLOSED) {
            return $this->finish($c, SupplierDocumentClose::CONFIRM_ERROR, "A releitura mostra o estado {$after} (esperado 8002). Reveja no PingWin.");
        }
        $doc->update(['docstatus_id' => self::CLOSED, 'docstatus_description' => 'Fechado', 'last_seen_at' => now()]);
        // Uma fatura carregada na XPLENDOR ligada a este documento fica com o fecho registado.
        $ocr = OcrInvoicePingwinLink::where('company_id', $c->company_id)->where('docheader_id', $doc->docheader_id)->value('ocr_invoice_id');
        if ($ocr) {
            OcrInvoice::whereKey($ocr)->update(['closed_by' => $c->user_id, 'closed_at' => now()]);
        }

        return $this->finish($c, SupplierDocumentClose::OK, null);
    }

    /** A leitura viva no formato da conferência. */
    private function normalize(array $h, array $details, string $docconfig): array
    {
        $cents = fn ($v) => (int) round(((float) ($v ?? 0)) * 100);

        return [
            'docconfig_id' => $docconfig, 'docstatus_id' => (string) ($h['docstatus_id'] ?? ''),
            'total_cents' => $cents($h['total'] ?? 0), 'tax_cents' => isset($h['total_tax']) ? $cents($h['total_tax']) : null,
            'lines' => array_map(fn ($l) => ['total_cents' => $cents($l['total'] ?? 0), 'tax_cents' => $cents($l['tax_value'] ?? 0),
                'product' => ($l['product_id'] ?? '') !== '' ? (string) $l['product_id'] : null, 'description' => $l['description'] ?? null], $details),
        ];
    }

    /** "Ler as linhas do PingWin" (só leitura) de documentos em Aberto, para a conferência. */
    public function readLines(int $companyId, array $docheaderIds): array
    {
        $docs = PingwinSupplierDocument::where('company_id', $companyId)->whereIn('docheader_id', array_slice(array_map('strval', $docheaderIds), 0, (int) $this->cfg('close_max_batch', 10)))->get();
        $res = $this->pingwin->fetchSupplierDocumentLines($companyId, $docs->map(fn ($d) => ['docconfig_id' => (string) $d->docconfig_id, 'docheader_id' => (string) $d->docheader_id])->values()->all());
        $lines = app(SupplierDocumentLinesService::class);
        $out = [];
        foreach ($res as $r) {
            $d = $docs->firstWhere('docheader_id', (string) ($r['docheader_id'] ?? ''));
            if ($d) {
                $out[$d->docheader_id] = $lines->apply($d, $r);
            }
        }

        return $out;
    }

    public function present(SupplierDocumentClose $c): array
    {
        return ['id' => $c->id, 'batch' => $c->batch, 'position' => $c->position, 'docheader_id' => $c->docheader_id, 'document' => $c->document,
            'supplier' => $c->entity_name, 'total_cents' => $c->total_cents, 'status' => $c->status, 'status_before' => $c->status_before,
            'status_after' => $c->status_after, 'error' => $c->error, 'user' => $c->user?->name,
            'created_at' => $c->created_at?->toIso8601String(), 'finished_at' => $c->finished_at?->toIso8601String()];
    }

    private function finish(SupplierDocumentClose $c, string $status, ?string $error, ?array $result = null): bool
    {
        $c->update(['status' => $status, 'error' => $error ? mb_substr($error, 0, 1000) : null, 'finished_at' => now()] + ($result ? ['result' => $result] : []));

        return $status === SupplierDocumentClose::OK;
    }

    private static function eur(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ') . ' €';
    }
}
