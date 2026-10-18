<?php

declare(strict_types=1);

namespace App\Services\Restaurant\Units;

/**
 * XPLENDOR — Auditoria v2: "O que muda" com a proposta do Guião, com números reais.
 *  · fichas afetadas: a quantidade antes e depois (0,007 Unidade → 7 g) e o custo por porção;
 *  · stock e PCM convertidos para a unidade nova;
 *  · como lançar as próximas faturas e como contar;
 *  · € por mês que a decisão corrige (os achados de Unidades deste artigo, a 90 dias, ÷ 3).
 * Função pura: recebe as evidências, a proposta, o custo atual por unidade base e o € dos achados.
 */
final class UnitImpact
{
    /**
     * @param  float|null  $oldPricePerBase  o custo atual por unidade base (o das fichas hoje)
     * @param  int  $findingsCents  o € em jogo nos achados abertos de Unidades deste artigo (90 dias)
     */
    public static function build(array $e, array $proposal, ?float $oldPricePerBase, int $findingsCents = 0): array
    {
        $new = $proposal['base'] ?? null;
        if ($new === null) {
            return ['recipes' => [], 'stock' => [], 'invoices' => [], 'count' => null, 'conversion' => null, 'price_before_eur' => $oldPricePerBase,
                'price_after_eur' => null, 'recipes_monthly_delta_eur' => 0.0, 'monthly_eur' => round($findingsCents / 100 / 3, 2), 'pending' => true];
        }
        $old = $e['base_family'] ?: $new;
        $k = ($proposal['old_unit_is_pack'] ?? false) && ($proposal['pack'] ?? null)
            ? self::packConversion($proposal['pack'], $new)
            : self::conversion($old, $new, ($proposal['pack']['quantity'] ?? 0) > 0 ? $proposal['pack'] : (($e['packaging']['quantity'] ?? 0) > 0 ? $e['packaging'] : null));
        // As fichas e o stock podem converter-se de forma diferente (fichas que contam a caixa).
        $kRecipes = $proposal['recipe_conversion'] ?? $k;
        $kStock = $proposal['stock_conversion'] ?? $k;
        $newPrice = self::newPricePerBase($e, $proposal);

        $recipes = [];
        $monthly = 0.0;
        foreach ($e['recipes'] as $r) {
            // Uma linha já na família da base nova (azeite em litros numa base nova em litros) fica igual.
            $kLine = ($r['unit_family'] ?? null) === $new && ! ($proposal['recipes_use_pack'] ?? false) && ! ($proposal['old_unit_is_pack'] ?? false) ? 1.0 : $kRecipes;
            $after = $kLine !== null ? round($r['qnt_base'] * $kLine, 4) : null;
            $costBefore = $oldPricePerBase !== null ? round($r['qnt_base'] * $oldPricePerBase, 4) : null;
            $costAfter = $after !== null && $newPrice !== null ? round($after * $newPrice, 4) : null;
            if ($costBefore !== null && $costAfter !== null) {
                $monthly += ($costAfter - $costBefore) * $r['sold_qty_90d'] / 3;
            }
            $recipes[] = [
                'product_id' => $r['product_id'], 'label' => $r['label'], 'bom_id' => $r['bom_id'],
                'before' => self::fmt($r['qnt']) . ' ' . ($r['unit'] ?: UnitProposal::baseLabel($old)),
                'after' => $after !== null ? UnitProposal::qty($after, $new, $proposal['base_name'] ?? null) : null,
                'after_qnt' => $after, 'before_qnt' => $r['qnt_base'],
                'cost_before_eur' => $costBefore, 'cost_after_eur' => $costAfter, 'sold_qty_90d' => $r['sold_qty_90d'],
            ];
        }

        $stock = [];
        foreach ($e['stock'] ?? [] as $s) {
            $stock[] = [
                'warehouse' => $s['warehouse'], 'before' => self::fmt($s['qnt']) . ' ' . ($s['unit'] ?: UnitProposal::baseLabel($old)),
                'after' => $kStock !== null ? UnitProposal::qty($s['qnt'] * $kStock, $new, $proposal['base_name'] ?? null) : null,
                'pcm_before_eur' => $s['pcm'] ?: null, 'pcm_after_eur' => $s['pcm'] && $kStock ? round($s['pcm'] / $kStock, 4) : null,
            ];
        }

        $invoices = [];
        foreach ($proposal['purchase_units'] as $p) {
            $invoices[] = $p['factor']
                ? sprintf('Faturas de %s em «%s»: lançar como %s (1 = %s).', $p['supplier'] ?: 'qualquer fornecedor', $p['unit_label'], $p['proposed_unit'] ?? $p['unit_label'], UnitProposal::qty($p['factor'], $new, $proposal['base_name'] ?? null))
                : sprintf('Faturas de %s em «%s»: falta saber quanto leva.', $p['supplier'] ?: 'qualquer fornecedor', $p['unit_label']);
        }
        $count = $proposal['count_unit'];

        return [
            'recipes' => $recipes,
            'stock' => $stock,
            'invoices' => $invoices,
            'count' => sprintf('Contar em %s%s.', mb_strtolower(mb_substr($count['label'], 0, 1)) . mb_substr($count['label'], 1), $count['factor'] && $new !== 'un' && $count['label'] !== UnitProposal::baseLabel($new)
                ? ' (1 = ' . UnitProposal::qty($count['factor'], $new) . ')' : ''),
            'conversion' => $k,
            'recipe_conversion' => $kRecipes,
            'stock_conversion' => $kStock,
            'recipes_note' => $proposal['recipes_note'] ?? null,
            'price_before_eur' => $oldPricePerBase,
            'price_after_eur' => $newPrice,
            'recipes_monthly_delta_eur' => round($monthly, 2),
            'monthly_eur' => round($findingsCents / 100 / 3, 2),
        ];
    }

    /** Quantas unidades novas vale uma unidade base antiga (null se não der para saber). */
    public static function conversion(?string $old, string $new, ?array $pack): ?float
    {
        if ($old === null || $old === $new) {
            return 1.0;
        }
        if ($old === 'un' && in_array($new, ['kg', 'L'], true) && $pack && $pack['family'] === $new && $pack['each'] > 0) {
            return $pack['count'] > 1 ? $pack['each'] : $pack['quantity'];
        }
        if (in_array($old, ['kg', 'L'], true) && $new === 'un' && $pack && $pack['family'] === $old && $pack['each'] > 0) {
            return 1 / $pack['each'];
        }

        return null;
    }

    /** A unidade antiga é a embalagem inteira: quantas unidades novas leva (180 ovos, 24 garrafas, 30 L). */
    public static function packConversion(array $pack, string $new): ?float
    {
        if ($new === 'un') {
            return (float) max(1, $pack['count']);
        }

        return $pack['family'] === $new ? (float) $pack['quantity'] : null;
    }

    /** O custo por unidade base nova, com os fatores da proposta (Σ € ÷ Σ quantidade × fator). */
    public static function newPricePerBase(array $e, array $proposal): ?float
    {
        $q = 0.0;
        $t = 0.0;
        foreach ($e['purchases'] as $i => $p) {
            $f = $proposal['purchase_units'][$i]['factor'] ?? null;
            if ($f && $p['qnt'] > 0) {
                $q += $p['qnt'] * $f;
                $t += $p['total_eur'];
            }
        }

        return $q > 0 && $t > 0 ? round($t / $q, 4) : null;
    }

    private static function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 4, ',', ' '), '0'), ',');
    }
}
