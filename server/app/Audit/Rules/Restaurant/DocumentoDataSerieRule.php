<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Models\PingwinSupplierDocument;

/**
 * Regra 9a · Documento com data fora do ano da série (baixa): numa série (ex.: BOVFT), um documento
 * cujo ano é diferente do ano dos documentos vizinhos (o número antes e o número depois, do mesmo
 * ano). Ex.: BOVFT/522 com 01/08/2025 entre documentos de 2026.
 */
final class DocumentoDataSerieRule extends RestaurantRule
{
    public function code(): string
    {
        return 'restauracao.documento_data_serie';
    }

    public function entityType(): string
    {
        return 'documento';
    }

    public function severity(): string
    {
        return 'baixa';
    }

    public function title(): string
    {
        return 'Documentos com a data no ano errado';
    }

    public function description(): string
    {
        return 'Faturas de fornecedor com um ano diferente do das faturas com os números ao lado.';
    }

    public function screens(): array
    {
        return ['faturas', 'fornecedores'];
    }

    /** @return array{0:string,1:int}|null [série, número] de "VFT BOVFT/522" */
    public static function parse(?string $document): ?array
    {
        return preg_match('~(\S+)/(\d+)\s*$~', (string) $document, $m) ? [$m[1], (int) $m[2]] : null;
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $series = [];
        foreach (PingwinSupplierDocument::where('company_id', $companyId)->where('docstatus_id', '!=', PingwinSupplierDocument::STATUS_ANULADO)
            ->whereNotNull('doc_date')->get(['docheader_id', 'document', 'doc_date', 'entity_name', 'entity_pingwin_id', 'total_cents']) as $d) {
            $p = self::parse($d->document);
            if ($p) {
                $series[$p[0]][$p[1]] = $d;
            }
        }
        foreach ($series as $name => $docs) {
            ksort($docs);
            $numbers = array_keys($docs);
            foreach ($numbers as $k => $num) {
                $d = $docs[$num];
                $prev = $k > 0 ? $docs[$numbers[$k - 1]] : null;
                $next = $k < count($numbers) - 1 ? $docs[$numbers[$k + 1]] : null;
                if (! $prev || ! $next) {
                    continue;
                }
                $y = substr((string) $d->doc_date, 0, 4);
                $py = substr((string) $prev->doc_date, 0, 4);
                $ny = substr((string) $next->doc_date, 0, 4);
                if ($py !== $ny || $y === $py) {
                    continue;
                }
                $date = substr((string) $d->doc_date, 0, 10);

                yield new AuditFindingData(
                    entityType: 'documento', entityId: (string) $d->docheader_id, entityLabel: (string) $d->document,
                    title: Fmt::title(Fmt::doc($d->document), "data de {$y} no meio de documentos de {$py}"),
                    why: 'Uma data no ano errado põe a compra no período errado: IVA, custos do mês e conta corrente do fornecedor.',
                    suggestion: 'Confirme a data na fatura em papel e corrija-a no PingWin. Se a data estiver certa (uma fatura antiga lançada tarde), ignore com esse motivo.',
                    evidence: ['facts' => [
                        ['label' => 'Documento', 'value' => Fmt::doc($d->document)],
                        ['label' => 'Data', 'value' => Fmt::date($date)],
                        ['label' => 'Fornecedor', 'value' => $d->entity_name],
                        ['label' => 'Documento anterior', 'value' => Fmt::doc($prev->document) . ', ' . Fmt::date((string) $prev->doc_date)],
                        ['label' => 'Documento seguinte', 'value' => Fmt::doc($next->document) . ', ' . Fmt::date((string) $next->doc_date)],
                        ['label' => 'Total', 'value' => ((int) $d->total_cents) / 100, 'format' => 'eur'],
                    ]],
                    signature: $date,
                    weightCents: (int) $d->total_cents ?: null,
                    fixUrl: self::documentUrl((string) $d->docheader_id), fixLabel: 'Abrir o documento',
                    refs: array_values(array_filter([['documento', (string) $d->docheader_id], $d->entity_pingwin_id ? ['fornecedor', (string) $d->entity_pingwin_id] : null])),
                    seen: sprintf('A fatura %s de %s tem a data %s. A anterior da mesma série (%s) é de %s e a seguinte (%s) de %s.',
                        Fmt::doc($d->document), $d->entity_name, Fmt::date($date), Fmt::doc($prev->document), Fmt::date((string) $prev->doc_date),
                        Fmt::doc($next->document), Fmt::date((string) $next->doc_date)),
                    stake: sprintf('Não há um valor direto em jogo. A relevância é o total da fatura: %s.', Fmt::cents((int) $d->total_cents)),
                );
            }
        }
    }
}
