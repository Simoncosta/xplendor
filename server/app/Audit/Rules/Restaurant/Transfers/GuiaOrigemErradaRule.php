<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Transfers;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Services\Restaurant\StockUnits;

/**
 * Fatia 7c · T1 · Guia de envio que saiu do armazém errado: as linhas da Guia (1102/1007) tiram o stock
 * de um armazém que não é a origem de nenhum circuito (o Economato). Acontece quando a Guia fica com o
 * armazém por omissão da loja (por exemplo, o Armazém Yuko): o restaurante perde stock que nunca saiu do
 * Economato. Valor: as unidades × o PCM no Economato.
 */
final class GuiaOrigemErradaRule extends TransferRule
{
    public function code(): string
    {
        return 'restauracao.transfer_origem_errada';
    }

    public function entityType(): string
    {
        return 'documento';
    }

    public function title(): string
    {
        return 'Guias de envio que saíram do armazém errado';
    }

    public function description(): string
    {
        return 'Guias de envio em que o stock saiu de um armazém que não é o Economato.';
    }

    public function severity(): string
    {
        return 'alta';
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $d = $this->transferData($companyId, $scope);
        if (! $d['origins']) {
            return;
        }
        $units = new StockUnits($companyId);
        $originName = $this->wh($companyId, $d['origins'][0]);
        foreach ($d['guides'] as $doc) {
            $bad = $doc->lines->filter(fn ($l) => (float) $l->qnt != 0.0 && $l->warehouse_id && ! in_array((string) $l->warehouse_id, $d['origins'], true));
            if ($bad->isEmpty()) {
                continue;
            }
            $rows = [];
            $eur = 0.0;
            foreach ($bad as $l) {
                $base = self::base($units, $l);
                $pcm = self::originPcm($d, (string) $l->product_id);
                $eur += $pcm !== null ? $base * $pcm : 0;
                $rows[] = ['article' => trim(($l->code ? $l->code . ' ' : '') . $l->description), 'qty' => Fmt::qty($l->qnt) . ' ' . $l->unit_desc,
                    'warehouse' => $this->wh($companyId, $l->warehouse_id), 'value' => $pcm !== null ? round($base * $pcm, 2) : null];
            }
            $from = $this->wh($companyId, (string) $bad->first()->warehouse_id);

            yield new AuditFindingData(
                entityType: 'documento', entityId: (string) $doc->pingwin_doc_id, entityLabel: (string) $doc->document,
                title: Fmt::title(Fmt::doc($doc->document), "tirou o stock do {$from}"),
                why: sprintf('Uma Guia de envio tira o stock do armazém do cabeçalho. Esta tirou do %s em vez do %s: o restaurante perdeu stock que nunca saiu do Economato e o Economato ficou com stock a mais.', $from, $originName),
                suggestion: sprintf('Anule esta Guia no PingWin e faça o envio em Restauração › Transferências, que põe sempre o %s como origem.', $originName),
                evidence: ['table' => ['title' => 'Linhas da Guia', 'columns' => [
                    ['key' => 'article', 'label' => 'Artigo'], ['key' => 'qty', 'label' => 'Quantidade', 'align' => 'end'],
                    ['key' => 'warehouse', 'label' => 'Saiu de'], ['key' => 'value', 'label' => 'Valor ao PCM', 'align' => 'end', 'format' => 'eur'],
                ], 'rows' => $rows]],
                signature: (string) $doc->pingwin_doc_id,
                impactCents: $eur > 0 ? (int) round($eur * 100) : null,
                severity: self::severityFor($eur),
                fixUrl: self::transfersUrl('envios'), fixLabel: 'Abrir as transferências',
                refs: [['armazem', (string) $bad->first()->warehouse_id]],
                seen: sprintf('A %s, de %s, tem %d %s a sair do %s. As Guias de envio devem sair do %s.', $doc->document, Fmt::date((string) $doc->doc_date),
                    $bad->count(), $bad->count() === 1 ? 'linha' : 'linhas', $from, $originName),
                stake: $eur > 0 ? sprintf('%s: as unidades enviadas ao PCM do %s.', Fmt::eur($eur), $originName) : 'Sem PCM no Economato para estes artigos: o valor fica por calcular.',
            );
        }
    }
}
