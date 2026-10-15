<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Transfers;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;

/**
 * Fatia 7c · T7 · O mesmo artigo com o PCM muito diferente entre armazéns (mais de 30%): com
 * transferências ao PCM do Economato, o custo devia ser parecido em todos. Uma diferença grande vem de
 * Entradas com o preço errado ou de fatores de embalagem errados. Valor: o stock de cada armazém × a
 * diferença para o PCM do Economato (ou o mais baixo).
 */
final class PcmEntreArmazensRule extends TransferRule
{
    public function code(): string
    {
        return 'restauracao.transfer_pcm_armazens';
    }

    public function entityType(): string
    {
        return 'artigo';
    }

    public function title(): string
    {
        return 'Artigos com o custo médio muito diferente entre armazéns';
    }

    public function description(): string
    {
        return 'Artigos com o PCM muito diferente de armazém para armazém.';
    }

    public function severity(): string
    {
        return 'media';
    }

    public function screens(): array
    {
        return ['transferencias', 'stock'];
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $d = $this->transferData($companyId, $scope);
        $gap = (float) config('auditoria.transfers_pcm_gap_pct', 30) / 100;
        $data = self::data($scope);
        foreach ($d['stock']->filter(fn ($s) => (float) $s->qnt > 0 && (float) $s->pcm > 0)->groupBy('product_pingwin_id') as $pid => $rows) {
            if ($rows->count() < 2) {
                continue;
            }
            $min = (float) $rows->min('pcm');
            $max = (float) $rows->max('pcm');
            if ($min <= 0 || $max / $min - 1 <= $gap) {
                continue;
            }
            $ref = self::originPcm($d, (string) $pid);
            $refWh = $ref !== null ? $d['origins'][0] : (string) $rows->sortBy('pcm')->first()->warehouse_pingwin_id;
            $ref ??= $min;
            $eur = $rows->sum(fn ($s) => (float) $s->qnt * abs((float) $s->pcm - $ref));
            $unit = self::unitOf($rows->all());
            $label = $data->item((string) $pid)?->description ?? (string) $pid;
            $top = $rows->sortByDesc(fn ($s) => abs((float) $s->pcm - $ref))->first();

            yield new AuditFindingData(
                entityType: 'artigo', entityId: (string) $pid, entityLabel: (string) $label,
                title: Fmt::title((string) $label, 'com o custo muito diferente entre armazéns'),
                why: 'Com as transferências feitas ao PCM do Economato, o custo de um artigo devia ser parecido em todos os armazéns. Uma diferença grande vem de Entradas com o preço errado ou de embalagens com o fator errado, e passa para o custo das fichas técnicas de cada restaurante.',
                suggestion: 'Veja as Entradas deste artigo no restaurante com o custo mais afastado e corrija o preço no PingWin. Daqui para a frente, envie em Restauração › Transferências.',
                evidence: $this->stockTable($companyId, $rows->sortBy(fn ($s) => (string) $s->warehouse_pingwin_id)->values()->all()),
                signature: sprintf('%s:%s', number_format($min, 2, '.', ''), number_format($max, 2, '.', '')),
                impactCents: (int) round($eur * 100),
                severity: self::severityFor($eur),
                fixUrl: self::stockUrl((string) $pid), fixLabel: 'Abrir o stock do artigo',
                refs: [['artigo', (string) $pid]],
                seen: sprintf('O PCM vai de %s a %s por %s (%s de diferença). No %s está a %s; no %s, a %s.', Fmt::unitPrice($min), Fmt::unitPrice($max), Fmt::lower($unit ?: 'unidade'),
                    Fmt::pct(($max / $min - 1) * 100), $this->wh($companyId, $refWh), Fmt::unitPrice($ref), $this->wh($companyId, (string) $top->warehouse_pingwin_id), Fmt::unitPrice((float) $top->pcm)),
                stake: sprintf('%s: o stock de cada armazém × a diferença para o PCM do %s.', Fmt::eur($eur), $this->wh($companyId, $refWh)),
            );
        }
    }
}
