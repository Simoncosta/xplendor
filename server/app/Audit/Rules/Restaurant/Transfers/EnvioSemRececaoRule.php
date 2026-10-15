<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Transfers;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;
use Carbon\CarbonImmutable;

/**
 * Fatia 7c · T3 · Envio sem receção: uma Guia de envio com linhas que o PingWin não deu como entregues
 * (qnt_delivered 0: nenhuma Entrada foi feita a partir dela) há mais de um dia. O stock saiu do Economato
 * e não entrou em nenhum restaurante. Valor: as unidades × o PCM no Economato.
 */
final class EnvioSemRececaoRule extends TransferRule
{
    public function code(): string
    {
        return 'restauracao.transfer_sem_rececao';
    }

    public function entityType(): string
    {
        return 'documento';
    }

    public function title(): string
    {
        return 'Envios sem receção no restaurante';
    }

    public function description(): string
    {
        return 'Guias de envio sem a Entrada correspondente no restaurante.';
    }

    public function severity(): string
    {
        return 'alta';
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $d = $this->transferData($companyId, $scope);
        $limit = CarbonImmutable::parse($d['today'])->subDays((int) config('auditoria.transfers_reception_days', 1))->toDateString();
        $open = collect($this->pairs($companyId, $d)['open_guides'])->filter(fn ($x) => $x['date'] <= $limit)->groupBy(fn ($x) => $x['doc']->id);
        foreach ($open as $lines) {
            $doc = $lines->first()['doc'];
            $eur = 0.0;
            $rows = [];
            foreach ($lines as $x) {
                $pcm = self::originPcm($d, (string) $x['line']->product_id);
                $eur += $pcm !== null ? $x['base'] * $pcm : 0;
                $rows[] = ['article' => trim(($x['line']->code ? $x['line']->code . ' ' : '') . $x['line']->description), 'qty' => Fmt::qty($x['line']->qnt) . ' ' . $x['line']->unit_desc,
                    'value' => $pcm !== null ? round($x['base'] * $pcm, 2) : null];
            }
            $days = CarbonImmutable::parse((string) $doc->doc_date)->diffInDays(CarbonImmutable::parse($d['today']));

            yield new AuditFindingData(
                entityType: 'documento', entityId: (string) $doc->pingwin_doc_id, entityLabel: (string) $doc->document,
                title: Fmt::title(Fmt::doc($doc->document), 'sem receção no restaurante'),
                why: 'A Guia tirou o stock do Economato, mas nenhuma Entrada o pôs no restaurante. Enquanto não houver Entrada, o stock do restaurante está abaixo do real e o consumo dele fica por explicar.',
                suggestion: 'Confirme com o restaurante se a mercadoria chegou. Se chegou, registe a receção em Restauração › Transferências (ou faça a Entrada a partir desta Guia no PingWin). Se não seguiu, anule a Guia.',
                evidence: ['table' => ['title' => 'Linhas por receber', 'columns' => [
                    ['key' => 'article', 'label' => 'Artigo'], ['key' => 'qty', 'label' => 'Quantidade', 'align' => 'end'], ['key' => 'value', 'label' => 'Valor ao PCM', 'align' => 'end', 'format' => 'eur'],
                ], 'rows' => $rows]],
                signature: (string) $doc->pingwin_doc_id,
                impactCents: $eur > 0 ? (int) round($eur * 100) : null,
                severity: self::severityFor($eur),
                fixUrl: self::transfersUrl('envios'), fixLabel: 'Abrir as transferências',
                seen: sprintf('A %s é de %s (há %d %s) e o PingWin não tem nenhuma Entrada feita a partir dela.', $doc->document, Fmt::date((string) $doc->doc_date), $days, $days === 1 ? 'dia' : 'dias'),
                stake: $eur > 0 ? sprintf('%s: o que saiu do Economato, ao PCM.', Fmt::eur($eur)) : 'Sem PCM no Economato para estes artigos: o valor fica por calcular.',
            );
        }
    }
}
