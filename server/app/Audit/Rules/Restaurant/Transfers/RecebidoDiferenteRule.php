<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Transfers;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;

/**
 * Fatia 7c · T5 · Recebido diferente do enviado: a Entrada de um artigo não bate com as Guias com que
 * se emparelha (em unidades de stock). A diferença de stock aparece num lado e não no outro. Valor: a
 * diferença × o PCM no Economato.
 */
final class RecebidoDiferenteRule extends TransferRule
{
    public function code(): string
    {
        return 'restauracao.transfer_diferenca';
    }

    public function entityType(): string
    {
        return 'documento';
    }

    public function title(): string
    {
        return 'Receções diferentes do que foi enviado';
    }

    public function description(): string
    {
        return 'Entradas em que a quantidade recebida não é a que saiu na Guia.';
    }

    public function severity(): string
    {
        return 'media';
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $d = $this->transferData($companyId, $scope);
        $diff = collect($this->pairs($companyId, $d)['pairs'])->filter(fn ($p) => abs($p['received'] - $p['sent']) > 1e-4)->groupBy(fn ($p) => $p['entry']->id);
        foreach ($diff as $pairs) {
            $doc = $pairs->first()['entry'];
            $eur = 0.0;
            $rows = [];
            foreach ($pairs as $p) {
                $pcm = self::originPcm($d, (string) $p['line']->product_id);
                $delta = $p['received'] - $p['sent'];
                $eur += $pcm !== null ? abs($delta) * $pcm : 0;
                $unit = $this->stockUnit($companyId, (string) $p['line']->product_id);
                $rows[] = ['article' => trim(($p['line']->code ? $p['line']->code . ' ' : '') . $p['line']->description),
                    'guides' => implode(', ', array_unique(array_map(fn ($g) => (string) $g['doc']->document, $p['guides']))),
                    'sent' => self::qty($p['sent'], $unit), 'received' => self::qty($p['received'], $unit), 'diff' => ($delta > 0 ? '+' : '') . Fmt::qty($delta),
                    'value' => $pcm !== null ? round(abs($delta) * $pcm, 2) : null];
            }
            $r = $rows[0];

            yield new AuditFindingData(
                entityType: 'documento', entityId: (string) $doc->pingwin_doc_id, entityLabel: (string) $doc->document,
                title: Fmt::title(Fmt::doc($doc->document), 'recebeu diferente do enviado'),
                why: 'O que entra no restaurante tem de ser o que saiu do Economato. Se a Entrada tem mais, o stock da empresa sobe sem compra; se tem menos, a diferença desaparece sem registo.',
                suggestion: 'Confirme a quantidade com o restaurante. Corrija a Entrada (ou a Guia) no PingWin; nos envios feitos em Restauração › Transferências a receção regista a diferença.',
                evidence: ['table' => ['title' => 'Enviado e recebido', 'columns' => [
                    ['key' => 'article', 'label' => 'Artigo'], ['key' => 'guides', 'label' => 'Guias'], ['key' => 'sent', 'label' => 'Enviado', 'align' => 'end'],
                    ['key' => 'received', 'label' => 'Recebido', 'align' => 'end'], ['key' => 'diff', 'label' => 'Diferença', 'align' => 'end'],
                    ['key' => 'value', 'label' => 'Valor ao PCM', 'align' => 'end', 'format' => 'eur'],
                ], 'rows' => $rows]],
                signature: (string) $doc->pingwin_doc_id . ':' . md5(json_encode(array_column($rows, 'diff'))),
                impactCents: $eur > 0 ? (int) round($eur * 100) : null,
                severity: self::severityFor($eur),
                fixUrl: self::transfersUrl('envios'), fixLabel: 'Abrir as transferências',
                seen: sprintf('Na %s, de %s, entraram %s de %s; a %s enviou %s.', $doc->document, Fmt::date((string) $doc->doc_date), $r['received'], $r['article'], $r['guides'], $r['sent'])
                    . (count($rows) > 1 ? sprintf(' Mais %d %s.', count($rows) - 1, count($rows) === 2 ? 'artigo' : 'artigos') : ''),
                stake: $eur > 0 ? sprintf('%s: a diferença ao PCM do Economato.', Fmt::eur($eur)) : 'Sem PCM no Economato: o valor fica por calcular.',
            );
        }
    }

    private function stockUnit(int $companyId, string $pid): string
    {
        return (new \App\Services\Restaurant\StockUnits($companyId))->for($pid)['stock_unit'];
    }
}
