<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Transfers;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Services\Restaurant\RecipeCostService;
use App\Services\Restaurant\StockUnits;
use App\Services\SupplierDocumentLinesService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Fatia 7c · T6 · Fator da embalagem diferente do confirmado: nas encomendas, Guias, Entradas e compras
 * dos últimos 90 dias, o PingWin conta uma embalagem (unit_value da linha) com um fator diferente do
 * confirmado na XPLENDOR (por exemplo, uma "Caixa 6 Unidades" que o PingWin conta como 1). Um por
 * artigo e embalagem. Valor: as unidades a mais ou a menos × o PCM.
 */
final class FatorEmbalagemRule extends TransferRule
{
    public const DAYS = 90;

    public function code(): string
    {
        return 'restauracao.transfer_fator';
    }

    public function entityType(): string
    {
        return 'artigo_unidade';
    }

    public function title(): string
    {
        return 'Embalagens com o fator diferente do confirmado';
    }

    public function description(): string
    {
        return 'Embalagens que o PingWin conta com um número de unidades diferente do confirmado.';
    }

    public function severity(): string
    {
        return 'alta';
    }

    public function screens(): array
    {
        return ['transferencias', 'faturas'];
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $d = $this->transferData($companyId, $scope);
        $units = new StockUnits($companyId);
        $since = CarbonImmutable::parse($d['today'])->subDays(self::DAYS)->toDateString();
        $lines = [];
        foreach ($d['docs'] as $doc) {
            if ((string) $doc->doc_date < $since) {
                continue;
            }
            foreach ($doc->lines as $l) {
                $lines[] = ['pid' => (string) $l->product_id, 'code' => $l->code, 'desc' => $l->description, 'unit' => (string) $l->unit_desc, 'uv' => $l->unit_value,
                    'qnt' => (float) $l->qnt, 'doc' => (string) $doc->document];
            }
        }
        $f4 = DB::table('pingwin_supplier_document_lines as l')
            ->join('pingwin_supplier_documents as d', fn ($j) => $j->on('d.docheader_id', '=', 'l.docheader_id')->on('d.company_id', '=', 'l.company_id'))
            ->where('l.company_id', $companyId)->whereIn('d.docconfig_id', RecipeCostService::PURCHASE_DOCCONFIGS)
            ->where('d.docstatus_id', SupplierDocumentLinesService::STATUS_CLOSED)->where('d.doc_date', '>=', $since)->whereNotNull('l.unit_value')
            ->get(['l.product_pingwin_id', 'l.product_code', 'l.description', 'l.unit_desc', 'l.unit_value', 'l.qnt', 'd.document']);
        foreach ($f4 as $l) {
            $lines[] = ['pid' => (string) $l->product_pingwin_id, 'code' => $l->product_code, 'desc' => $l->description, 'unit' => (string) $l->unit_desc,
                'uv' => $l->unit_value, 'qnt' => (float) $l->qnt, 'doc' => (string) $l->document];
        }

        // Auditoria v2.1: as linhas numa unidade global incoerente com o nome ficam na U9 (sem duplicar).
        $incoherent = array_flip(array_map(fn ($u) => mb_strtolower($u['name']), \App\Audit\Rules\Restaurant\Units\UnidadeGlobalIncoerenteRule::incoherent($scope)));
        $groups = [];
        // As unidades de todos os artigos de uma vez (antes eram 3 consultas por artigo).
        $units->preload(array_values(array_filter(array_unique(array_column($lines, 'pid')))));
        foreach ($lines as $l) {
            if ($l['pid'] === '' || $l['unit'] === '' || $l['uv'] === null || isset($incoherent[mb_strtolower($l['unit'])])) {
                continue;
            }
            $u = $units->for($l['pid']);
            if (mb_strtolower($l['unit']) === mb_strtolower($u['stock_unit'])) {
                continue;
            }
            $factor = $units->factor($l['pid'], $l['unit']);
            if ($factor === null || abs($factor - (float) $l['uv']) < 1e-6) {
                continue;
            }
            $k = $l['pid'] . '|' . mb_strtolower($l['unit']);
            $groups[$k] ??= ['l' => $l, 'factor' => $factor, 'stock_unit' => $u['stock_unit'], 'lines' => 0, 'delta' => 0.0, 'docs' => []];
            $groups[$k]['lines']++;
            $groups[$k]['delta'] += $l['qnt'] * ($factor - (float) $l['uv']);
            $groups[$k]['docs'][$l['doc']] = true;
        }
        foreach ($groups as $k => $g) {
            $l = $g['l'];
            $pcm = self::originPcm($d, $l['pid']) ?? (collect($d['pcm'][$l['pid']] ?? [])->filter()->first());
            $eur = $pcm ? abs($g['delta']) * $pcm : 0.0;
            $label = trim(($l['code'] ? $l['code'] . ' ' : '') . $l['desc']);
            $docs = array_keys($g['docs']);

            yield new AuditFindingData(
                entityType: 'artigo_unidade', entityId: $l['pid'] . ':' . mb_strtolower($l['unit']), entityLabel: "{$label} · {$l['unit']}",
                title: Fmt::title($label, "«{$l['unit']}» com o fator errado"),
                why: sprintf('O PingWin conta cada «%s» como %s; a XPLENDOR tem confirmado %s. Cada documento nesta embalagem mexe no stock pela conta do PingWin: o stock e o custo ficam errados.',
                    $l['unit'], self::qty((float) $l['uv'], $g['stock_unit']), self::qty($g['factor'], $g['stock_unit'])),
                suggestion: 'Corrija o fator desta embalagem na ficha do artigo no PingWin (com o suporte, porque mexe no stock). Até lá, os envios feitos em Restauração › Transferências vão sempre em unidades de stock.',
                evidence: ['facts' => [
                    ['label' => 'Embalagem', 'value' => $l['unit']], ['label' => 'Fator no PingWin', 'value' => Fmt::qty((float) $l['uv'])],
                    ['label' => 'Fator confirmado', 'value' => Fmt::qty($g['factor'])], ['label' => 'Linhas afetadas', 'value' => $g['lines']],
                    ['label' => 'Diferença no stock', 'value' => self::qty($g['delta'], $g['stock_unit'])],
                ]],
                signature: sprintf('%s:%s', Fmt::qty((float) $l['uv']), Fmt::qty($g['factor'])),
                impactCents: $eur > 0 ? (int) round($eur * 100) : null,
                severity: self::severityFor($eur),
                fixUrl: \App\Audit\Rules\Restaurant\Units\UnitRule::guiaoUrl($l['pid']), fixLabel: 'Abrir no Guião de unidades',
                fixAction: ['type' => 'guiao', 'product_id' => $l['pid']],
                refs: [['artigo', $l['pid']]],
                seen: sprintf('Nos últimos %d dias, %d %s em «%s» (por exemplo, %s) contaram %s por embalagem em vez de %s.', self::DAYS, $g['lines'], $g['lines'] === 1 ? 'linha' : 'linhas',
                    $l['unit'], self::join(array_slice($docs, 0, 2)), Fmt::qty((float) $l['uv']), Fmt::qty($g['factor'])),
                stake: $eur > 0 ? sprintf('%s: %s a %s no stock, ao PCM.', Fmt::eur($eur), self::qty(abs($g['delta']), $g['stock_unit']), $g['delta'] > 0 ? 'menos' : 'mais')
                    : 'Sem PCM para este artigo: o valor fica por calcular.',
            );
        }
    }
}
