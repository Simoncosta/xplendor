<?php

declare(strict_types=1);

namespace App\Services\Restaurant\Units;

/**
 * XPLENDOR — Guião de unidades (apresentação): a conclusão antes das evidências. Só lê a proposta,
 * o "O que muda" e o plano; não decide nada de novo.
 *  · "certo": a proposta é igual ao que já existe no PingWin (nada muda nas fichas, no stock nem nas
 *    faturas): "Está certo: nada a mudar.", com o porquê numa frase;
 *  · "mudar": "Precisa de mudança.", com o que muda numa frase;
 *  · "ajuda": os "Por identificar": "Precisamos da sua ajuda."
 * E a confiança em palavras (Alta, Média, Baixa) com o porquê.
 */
final class UnitVerdict
{
    public const ORDER = ['mudar' => 0, 'ajuda' => 1, 'certo' => 2];

    /**
     * @param  list<array>  $steps  os passos do "Aplicar no PingWin"
     * @return array{key:string, title:string, text:string, tone:string}
     */
    public static function of(array $e, array $proposal, array $impact, array $steps, bool $unknown, ?string $currentPurchase = null): array
    {
        if ($unknown || ! $proposal['identified']) {
            $q = $proposal['questions'][0]['text'] ?? null;
            $why = $proposal['conflicts'][0] ?? ($e['confidence']['contradictions'][0] ?? null);

            return ['key' => 'ajuda', 'title' => 'Precisamos da sua ajuda.', 'tone' => 'secondary',
                'text' => trim(($why ? rtrim($why, '.') . '. ' : '') . ($q ? "Responda: {$q}" : 'Responda às perguntas ou dê mais informação.'))];
        }
        $changes = self::changes($e, $proposal, $impact, $steps, $currentPurchase);
        if (! $changes) {
            return ['key' => 'certo', 'title' => 'Está certo: nada a mudar.', 'tone' => 'success', 'text' => self::why($e, $proposal)];
        }

        return ['key' => 'mudar', 'title' => 'Precisa de mudança.', 'tone' => 'warning', 'text' => $changes[0] . (count($changes) > 1 ? ' E mais ' . (count($changes) - 1) . ' ' . (count($changes) === 2 ? 'mudança' : 'mudanças') . ' em baixo.' : '')];
    }

    /** O que muda, em frases curtas (a primeira é a mais importante). */
    public static function changes(array $e, array $proposal, array $impact, array $steps, ?string $currentPurchase = null): array
    {
        $out = [];
        $base = $proposal['base'] ?? 'un';
        if ($proposal['base_change'] && $proposal['base_change_warning']) {
            $out[] = $proposal['base_change_warning']['title'] . ' (à mão, pelo caminho seguro).';
        }
        foreach ($proposal['purchase_units'] as $u) {
            if (! $u['factor'] || $u['confirmed']) {
                continue;
            }
            $now = $u['pingwin_factor'];
            if ($now === null || abs($now - $u['factor']) > 1e-6 * max(1, $u['factor'])) {
                $out[] = sprintf('Lançar a %s com fator %s em vez de %s.', $u['proposed_unit'] ?: $u['unit_label'], self::n($u['factor']), $now === null ? 'nenhum' : self::n($now));
            }
        }
        if ($proposal['recipes_use_pack'] ?? false) {
            $out[] = 'Corrigir as fichas: contam a embalagem inteira em vez da peça.';
        } else {
            foreach ($impact['recipes'] ?? [] as $r) {
                // "0,1 Quilograma" e "100 g" são a mesma quantidade: só conta se o número mudar.
                $b = (float) ($r['before_qnt'] ?? 0);
                if ($r['after_qnt'] !== null && abs($r['after_qnt'] - $b) > 1e-6 * max(1, abs($b))) {
                    $out[] = sprintf('Converter as fichas para %s (%s passa a %s).', mb_strtolower(UnitProposal::baseLabel($base)), $r['before'], $r['after']);
                    break;
                }
            }
        }
        foreach ($steps as $s) {
            // Uma unidade de compra por defeito em falta no espelho não é uma mudança; trocar a que existe é.
            if ($s['op'] === 'create' || ($s['op'] === 'set_purchase' && ($currentPurchase ?? '') !== '')) {
                $out[] = $s['label'] . '.';
                break;
            }
        }

        return array_values(array_unique($out));
    }

    /** O porquê de "Está certo", numa frase: como se compra, como se usa e o preço. */
    public static function why(array $e, array $proposal): string
    {
        $unit = ['kg' => 'ao kg', 'L' => 'ao litro', 'un' => 'à unidade'];
        $families = array_values(array_unique(array_filter(array_map(fn ($p) => $p['unit_family'], $e['purchases']))));
        $parts = [];
        if (count($families) === 1) {
            $parts[] = 'Compra-se ' . ($unit[$families[0]] ?? 'sempre na mesma unidade');
        } elseif ($e['purchases']) {
            $parts[] = 'As unidades de compra têm o fator certo';
        }
        $rf = array_values(array_unique(array_filter(array_map(fn ($r) => $r['unit_family'] ?? null, $e['recipes']))));
        $parts[] = $e['recipes'] ? (count($rf) === 1 ? 'usa-se ' . ($unit[$rf[0]] ?? 'na mesma unidade') . ' nas fichas' : 'as fichas usam a unidade certa') : 'ainda não entra em fichas';
        $suppliers = array_unique(array_map(fn ($p) => $p['supplier'], $e['purchases']));
        if (count($suppliers) > 1) {
            $prices = array_filter(array_map(fn ($p) => $p['price_per_base'] ?? null, $e['purchases']));
            $parts[] = $prices && max($prices) <= 2 * min($prices) ? 'os fornecedores batem no preço' : 'os fornecedores faturam na mesma unidade';
        } elseif (($e['price_ratio'] ?? null) !== null && $e['price_ratio'] >= 0.25 && $e['price_ratio'] <= 4) {
            $parts[] = 'o preço está dentro do da família';
        }
        $last = array_pop($parts);

        return ucfirst($parts ? implode(', ', $parts) . ' e ' . $last : $last) . '.';
    }

    /** A confiança em palavras, com o porquê. */
    public static function confidence(float $score, array $e, array $proposal): array
    {
        $label = $score >= 0.8 ? 'Alta' : ($score >= \App\Models\ProductUnitProfile::MIN_CONFIDENCE ? 'Média' : 'Baixa');
        $why = array_merge($e['confidence']['why'] ?? [], array_map(fn ($c) => 'A rever: ' . lcfirst($c), array_unique(array_merge($proposal['conflicts'], $e['confidence']['contradictions'] ?? []))));
        if (($proposal['signals']['uso'] ?? null) !== null) {
            $why[] = 'O uso nas fichas confirma o tipo.';
        }

        return ['label' => $label, 'score' => $score, 'why' => $why ? implode(' ', array_unique($why)) : 'Poucas evidências: sem compras recentes, sem fichas e sem embalagem na descrição.'];
    }

    private static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, ',', ' '), '0'), ',');
    }

    private static function norm(string $s): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim($s))) ?? $s;
    }
}

