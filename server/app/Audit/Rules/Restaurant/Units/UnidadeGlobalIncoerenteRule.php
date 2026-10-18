<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant\Units;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Audit\Rules\Restaurant\RestaurantAuditData;
use App\Services\Restaurant\RecipeHints;
use App\Services\Restaurant\Units\PackagingParser;
use App\Services\Restaurant\Units\UnitCatalog;
use App\Services\Restaurant\Units\UnitEvidence;
use App\Services\Restaurant\Units\UnitProposal;
use Illuminate\Support\Facades\DB;

/**
 * U9 · Unidade global com quantidade incoerente com o nome ("Caixa 24 Unidades" com quantidade 1,
 * "Saco 5kg" com 1, "Pacote 600g" com 1,67). As unidades do PingWin são GLOBAIS (de todos os
 * artigos) e a quantidade não se pode mudar: mostra todos os artigos que a usam (compras, fichas,
 * unidade por defeito) e o € total. Correção guiada no Guião: criar ou reutilizar a unidade certa uma
 * vez (UnitCatalog) e passar os artigos um a um ("1 de N"), pondo-a como unidade de compra; a antiga
 * deixa de se usar, nunca se anula. Substitui a Regra 3 (mantém o código, para o histórico dos achados)
 * e junta a U1 e a T6 das linhas nesta unidade, sem duplicar.
 */
final class UnidadeGlobalIncoerenteRule extends UnitRule
{
    public function code(): string
    {
        return 'restauracao.unidade_fator';
    }

    public function entityType(): string
    {
        return 'unidade';
    }

    public function severity(): string
    {
        return 'alta';
    }

    public function title(): string
    {
        return 'Unidade global com quantidade incoerente com o nome';
    }

    public function description(): string
    {
        return 'Unidades do PingWin (de todos os artigos) cuja quantidade não bate com o nome (Caixa 24 com quantidade 1, Saco 5kg com 1).';
    }

    public function screens(): array
    {
        return ['unidades', 'artigos', 'faturas', 'stock'];
    }

    /**
     * As unidades globais incoerentes da empresa (uma vez por corrida): id → nome, quantidade hoje, a
     * que o nome diz, a base (id e família) e a embalagem.
     *
     * @return array<string, array{id:string, name:string, now:float, says:float, base_id:string, family:string, container:?string}>
     */
    public static function incoherent(AuditScope $scope): array
    {
        return $scope->memo('restauracao.units.incoherent', function () use ($scope) {
            return self::detect(RestaurantAuditData::for($scope)->cost()->units());
        });
    }

    /** @param array<string, \App\Models\PingwinUnit> $units */
    public static function detect(array $units): array
    {
        $catalog = new UnitCatalog($units);
        // As unidades base por família (as de topo chamadas Unidade, Quilograma, Litro).
        $roots = [];
        foreach ($units as $id => $u) {
            if (! $u->parent_pingwin_id && ($f = UnitEvidence::fam(RecipeHints::family((string) $u->description))) && in_array(mb_strtolower((string) $u->description), ['unidade', 'quilograma', 'litro'], true)) {
                $roots[$f] ??= (string) $id;
            }
        }
        $out = [];
        foreach ($units as $id => $u) {
            $id = (string) $id;
            if (! UnitCatalog::isGlobal($u) || isset(array_flip($roots)[$id])) {
                continue;
            }
            $pack = PackagingParser::parse((string) $u->description);
            if (! $pack || $pack['quantity'] <= 0) {
                continue;
            }
            $root = $u->parent_pingwin_id ? $catalog->root($id) : null;
            $family = $root ? UnitEvidence::fam(RecipeHints::family((string) ($units[$root]->description ?? ''))) : ($pack['family'] ?? null);
            $says = UnitCatalog::nameQuantity((string) $u->description, $family);
            $now = $u->parent_pingwin_id ? $catalog->factor($id) : 1.0;
            $baseId = $root ?? ($roots[$family] ?? null);
            if ($says === null || $now === null || $baseId === null || abs($now - $says) <= 1e-6 * max(1, $says)) {
                continue;
            }
            $out[$id] = ['id' => $id, 'name' => (string) $u->description, 'now' => (float) $now, 'says' => (float) $says, 'base_id' => $baseId,
                'family' => (string) $family, 'container' => $pack['container'], 'active' => (bool) $u->is_active, 'no_parent' => ! $u->parent_pingwin_id];
        }

        return $out;
    }

    /**
     * Os artigos que usam uma unidade (compras de 12 meses, fichas, unidade por defeito), com o € em
     * jogo de cada um: as compras de 90 dias nesta unidade, sem fator confirmado, × |1 − hoje ÷ nome|.
     */
    public static function articlesOf(RestaurantAuditData $data, array $unit, ?array $bomUses = null): array
    {
        $since = $data->daysAgo(UnitEvidence::WINDOW_RECENT);
        $error = abs(1 - $unit['now'] / $unit['says']);
        $out = [];
        foreach ($data->purchases() as $pid => $lines) {
            foreach ($lines as $l) {
                if ($l['unit_id'] !== $unit['id']) {
                    continue;
                }
                $a = &$out[(string) $pid];
                $a ??= ['product_id' => (string) $pid, 'label' => $data->label((string) $pid), 'purchases_cents' => 0, 'impact_cents' => 0, 'lines' => 0, 'uses' => [], 'confirmed' => false];
                $a['purchases_cents'] += (int) round($l['total'] * 100);
                $a['lines']++;
                $a['uses']['compras'] = true;
                if ($l['qty_source'] === 'confirmado') {
                    $a['confirmed'] = true;
                } elseif ($l['date'] >= $since) {
                    $a['impact_cents'] += (int) round($l['total'] * $error * 100);
                }
                unset($a);
            }
        }
        foreach ($bomUses[$unit['id']] ?? [] as $pid) {
            $out[$pid] ??= ['product_id' => $pid, 'label' => $data->label($pid), 'purchases_cents' => 0, 'impact_cents' => 0, 'lines' => 0, 'uses' => [], 'confirmed' => false];
            $out[$pid]['uses']['fichas'] = true;
        }
        foreach ($data->catalog() as $pid => $it) {
            if (in_array($unit['id'], [(string) $it->default_purchase_unit_id, (string) $it->default_stock_unit_id, (string) $it->default_sale_unit_id], true)) {
                $out[(string) $pid] ??= ['product_id' => (string) $pid, 'label' => $data->label((string) $pid), 'purchases_cents' => 0, 'impact_cents' => 0, 'lines' => 0, 'uses' => [], 'confirmed' => false];
                $out[(string) $pid]['uses']['defeito'] = true;
            }
        }
        uasort($out, fn ($a, $b) => [$b['impact_cents'], $b['purchases_cents']] <=> [$a['impact_cents'], $a['purchases_cents']]);

        return array_values(array_map(fn ($a) => $a + ['uses' => array_keys($a['uses'])], $out));
    }

    /** As linhas de fichas por unidade: unidade → artigos (componentes). */
    public static function bomUses(int $companyId): array
    {
        $out = [];
        foreach (DB::table('pingwin_product_bom_lines')->where('company_id', $companyId)->get(['unit_pingwin_id', 'component_pingwin_id']) as $r) {
            $out[(string) $r->unit_pingwin_id][(string) $r->component_pingwin_id] = (string) $r->component_pingwin_id;
        }

        return array_map('array_values', $out);
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $data = RestaurantAuditData::for($scope);
        $catalog = new UnitCatalog($data->cost()->units());
        $bom = self::bomUses($companyId);
        foreach (self::incoherent($scope) as $u) {
            $articles = self::articlesOf($data, $u, $bom);
            if (! $u['active'] && ! $articles) {
                continue;
            }
            $impact = (int) array_sum(array_column($articles, 'impact_cents'));
            $weight = (int) array_sum(array_column($articles, 'purchases_cents'));
            $right = $catalog->resolve($u['base_id'], $u['says'], $u['family'], $u['container']);
            $base = UnitProposal::baseLabel($u['family']);
            $says = UnitProposal::qty($u['says'], $u['family'] ?: 'un');
            $now = UnitProposal::qty($u['now'], $u['family'] ?: 'un');
            $todo = isset($right['reuse'])
                ? sprintf('Já existe a unidade certa, «%s» (1 = %s). No Guião, passe os artigos um a um para ela (unidade de compra) e deixe de usar «%s».', $right['name'], $says, $u['name'])
                : sprintf('No Guião, crie uma vez a unidade certa, «%s» (1 = %s), e passe os artigos um a um para ela (unidade de compra). «%s» deixa de se usar: é de todos os artigos, não se anula nem se altera.', $right['name'], $says, $u['name']);
            $n = count($articles);

            yield new AuditFindingData(
                entityType: 'unidade', entityId: $u['id'], entityLabel: $u['name'],
                title: Fmt::title($u['name'], "conta {$now} em vez de {$says}"),
                why: sprintf('Cada «%s» que entra conta %s em vez de %s. Como a unidade é de todos os artigos, o erro repete-se em cada artigo que a usa: o stock e o custo por %s ficam errados.',
                    $u['name'], $now, $says, mb_strtolower($base)),
                suggestion: $todo,
                evidence: [
                    'facts' => [
                        ['label' => 'Conta hoje no PingWin', 'value' => $now],
                        ['label' => 'O nome diz', 'value' => $says],
                        ['label' => 'A unidade certa', 'value' => $right['name'] . (isset($right['reuse']) ? ' (já existe)' : ' (a criar)')],
                        ['label' => 'Artigos', 'value' => $n],
                        ['label' => 'Compras nesta unidade (12 meses)', 'value' => $weight / 100, 'format' => 'eur'],
                    ],
                    'table' => $articles ? [
                        'title' => 'Os artigos que usam esta unidade',
                        'columns' => [
                            ['key' => 'label', 'label' => 'Artigo'], ['key' => 'uses', 'label' => 'Onde'],
                            ['key' => 'purchases_cents', 'label' => 'Compras (12 meses)', 'align' => 'end', 'format' => 'cents'],
                            ['key' => 'impact_cents', 'label' => 'Em jogo (90 dias)', 'align' => 'end', 'format' => 'cents'],
                        ],
                        'rows' => array_map(fn ($a) => ['label' => $a['label'], 'uses' => implode(', ', $a['uses']) . ($a['confirmed'] ? ' (fator confirmado)' : ''),
                            'purchases_cents' => $a['purchases_cents'], 'impact_cents' => $a['impact_cents']], array_slice($articles, 0, 30)),
                    ] : null,
                ],
                signature: "{$u['now']}|{$u['says']}|" . implode(',', array_column($articles, 'product_id')),
                impactCents: $impact ?: null, weightCents: $weight ?: null,
                severity: $articles ? ($impact ? self::severityFor($impact / 100) : 'media') : 'baixa',
                fixUrl: '/restauracao/auditoria/guiao?unidade=' . rawurlencode($u['id']), fixLabel: 'Corrigir artigo a artigo',
                fixAction: ['type' => 'unidade_global', 'unit_id' => $u['id']],
                refs: array_map(fn ($a) => ['artigo', $a['product_id']], $articles),
                seen: sprintf('A unidade «%s» conta %s no PingWin, mas o nome diz %s. %s', $u['name'], $now, $says,
                    $n ? sprintf('Usam-na %d %s (%s).', $n, $n === 1 ? 'artigo' : 'artigos', implode('; ', array_map(fn ($a) => $a['label'], array_slice($articles, 0, 5))) . ($n > 5 ? '…' : ''))
                        : 'Nenhum artigo a usa: está ativa sem uso.'),
                stake: $impact
                    ? sprintf('%s em 90 dias: as compras nesta unidade sem fator confirmado × %s %% de erro, somadas por artigo.', Fmt::cents($impact), Fmt::qty(abs(1 - $u['now'] / $u['says']) * 100, 0))
                    : ($weight ? sprintf('Sem erro por confirmar em 90 dias; a relevância são as compras: %s em 12 meses.', Fmt::cents($weight)) : 'Sem compras nesta unidade: a relevância são os artigos que a têm.'),
            );
        }
    }
}
