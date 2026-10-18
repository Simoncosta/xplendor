<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Menu;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;

/**
 * Fatia 3 · M2 · A ficha de um prato vendido usa um artigo sem compras nos últimos 90 dias (o caso da
 * Coca Cola 0, cuja ficha usa o 100366, que não se compra). O custo do prato fica incompleto (sem preço)
 * ou antigo (o último preço conhecido). € = a receita do prato no período.
 */
final class FichaSemComprasRule extends MenuRule
{
    public function code(): string
    {
        return 'restauracao.menu_ficha_sem_compras';
    }

    public function entityType(): string
    {
        return 'ficha';
    }

    public function title(): string
    {
        return 'Fichas de pratos vendidos com ingredientes sem compras recentes';
    }

    public function description(): string
    {
        return 'Fichas que usam um artigo sem compras nos últimos 90 dias.';
    }

    public function severity(): string
    {
        return 'media';
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        [$snap, $items] = $this->snapshot($companyId, $scope);
        if (! $snap) {
            return;
        }
        foreach ($items as $i) {
            if ($i->revenue_cents <= 0) {
                continue;
            }
            foreach ((array) (($i->detail ?? [])['stale'] ?? []) as $c) {
                $never = ($c['status'] ?? '') === 'sem_custo';
                $comp = trim(($c['code'] ? $c['code'] . ' ' : '') . (string) $c['description']);

                yield new AuditFindingData(
                    entityType: 'ficha', entityId: (string) $i->product_id, entityLabel: 'Ficha técnica: ' . trim(($i->code ? $i->code . ' · ' : '') . $i->description),
                    title: Fmt::title((string) $i->description, sprintf('usa %s, sem compras recentes', (string) $c['code'] ?: $comp)),
                    why: $never
                        ? 'O ingrediente da ficha não tem compras: o custo do prato fica incompleto (Parcial) e o food cost parece mais baixo do que é. Muitas vezes a ficha usa um artigo antigo e compra-se outro.'
                        : 'O ingrediente da ficha não é comprado há mais de 90 dias: o custo do prato usa um preço antigo. Pode ser que se compre agora outro artigo.',
                    suggestion: 'Confirme que artigo se usa de facto no prato. Se se compra outro, troque-o na ficha (editor das fichas). Se este ainda se compra, registe a compra.',
                    evidence: ['facts' => array_values(array_filter([
                        ['label' => 'Ficha técnica', 'value' => (string) $i->description],
                        ['label' => 'Ingrediente', 'value' => $comp],
                        ['label' => 'Última compra', 'value' => $never ? 'Nenhuma' : Fmt::date($c['last_purchase'] ?? null)],
                        ['label' => 'Receita sem IVA no período', 'value' => $i->revenue_cents / 100, 'format' => 'eur'],
                    ]))],
                    evidenceKey: (string) $c['component_id'],
                    signature: $never ? 'nunca' : 'antigo',
                    impactCents: (int) $i->revenue_cents,
                    fixUrl: self::recipeUrl((string) $i->product_id), fixLabel: 'Abrir a ficha',
                    refs: [['artigo', (string) $i->product_id], ['artigo', (string) $c['component_id']]],
                    seen: sprintf('%s, %s vendeu %s sem IVA. A ficha usa %s, %s.', self::periodText($snap), $i->description, Fmt::cents((int) $i->revenue_cents), $comp,
                        $never ? 'que não tem nenhuma compra registada' : 'cuja última compra foi em ' . Fmt::date($c['last_purchase'] ?? null)),
                    stake: sprintf('%s: a receita do prato no período, com o custo %s.', Fmt::cents((int) $i->revenue_cents), $never ? 'incompleto' : 'baseado num preço antigo'),
                );
            }
        }
    }
}
