<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Units;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Rules\Restaurant\RestaurantAuditData;
use App\Models\PingwinCatalogItem;
use Illuminate\Support\Facades\DB;

/**
 * U6 · Unidades sem uso há 12 meses (Informação): unidades ativas do PingWin que não aparecem em
 * nenhuma compra, ficha, contagem nem transferência dos últimos 12 meses, e que não são a unidade
 * por defeito de nenhum artigo. Sugere inativá-las (no PingWin, à mão ou no Guião).
 */
final class UnidadesSemUsoRule extends UnitRule
{
    public function code(): string
    {
        return 'restauracao.unidade_sem_uso';
    }

    public function entityType(): string
    {
        return 'unidade';
    }

    public function severity(): string
    {
        return 'info';
    }

    public function title(): string
    {
        return 'Unidade sem uso há 12 meses';
    }

    public function description(): string
    {
        return 'Unidades ativas que não aparecem em compras, fichas, contagens nem transferências há 12 meses.';
    }

    public function screens(): array
    {
        return ['unidades', 'artigos'];
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $data = RestaurantAuditData::for($scope);
        $used = [];
        foreach ($data->purchases() as $rows) {
            foreach ($rows as $r) {
                $used[$r['unit_id']] = true;
                $used[mb_strtolower($r['unit_label'])] = true;
            }
        }
        foreach (DB::table('pingwin_product_bom_lines')->where('company_id', $companyId)->distinct()->pluck('unit_pingwin_id') as $u) {
            $used[(string) $u] = true;
        }
        foreach (['default_purchase_unit_id', 'default_stock_unit_id', 'default_sale_unit_id', 'base_unit_id'] as $col) {
            foreach (PingwinCatalogItem::where('company_id', $companyId)->whereNotNull($col)->distinct()->pluck($col) as $u) {
                $used[(string) $u] = true;
            }
        }
        foreach ($this->evidence($scope)->all() as $e) {
            foreach ($e['units'] as $u) {
                $used[mb_strtolower($u['label'])] = true;
            }
        }
        foreach ($data->cost()->units() as $id => $u) {
            $id = (string) $id;
            if (! $u->is_active || ! $u->parent_pingwin_id || isset($used[$id]) || isset($used[mb_strtolower((string) $u->description)])) {
                continue;
            }
            yield new AuditFindingData(
                entityType: 'unidade', entityId: $id, entityLabel: (string) $u->description,
                title: "{$u->description}: sem uso há 12 meses",
                why: 'Unidades a mais na lista do PingWin levam a escolher a errada nas faturas e nas fichas.',
                suggestion: 'Se já não se usa, inative-a no PingWin (Unidades). Se ainda faz falta, ignore com esse motivo.',
                evidence: ['facts' => [['label' => 'Unidade', 'value' => (string) $u->description], ['label' => 'Fator no PingWin', 'value' => (string) $u->unit_value]]],
                severity: 'info', fixUrl: '/restauracao/unidades', fixLabel: 'Ver as unidades',
                refs: [['unidade', $id]],
                seen: sprintf('A unidade «%s» está ativa no PingWin, mas não aparece em nenhuma compra, ficha, contagem nem transferência dos últimos 12 meses, nem é a unidade por defeito de nenhum artigo.', $u->description),
                stake: 'Informação: não há um valor em jogo.',
            );
        }
    }
}
