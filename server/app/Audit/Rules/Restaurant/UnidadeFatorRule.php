<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;
use Illuminate\Support\Facades\DB;

/**
 * Regra 3 · Unidade com fator incoerente com o nome (alta): unidades do PingWin com unit_value 1
 * cujo nome indica mais (Caixa 24, BARRIL 20LT, Garrafão 5LT, Saco 5kg...), com o nº de linhas de
 * compra e de artigos afetados e o fator sugerido. Corrige-se na configuração de unidades do
 * PingWin, com o suporte, porque mexe no stock.
 */
final class UnidadeFatorRule extends RestaurantRule
{
    /** Palavras de embalagem (como o PACK_NAME do custo das fichas). */
    private const PACK = '/\b(caixa|cx|pack|grade|grd|garraf\w*|grf|barril|bid[oõ]n|bid[aã]o|saco|sco|lata|balde|bld|pacote|pct|fardo|tabuleiro|embal\w*|emb)\b/iu';
    /** A quantidade no nome: "24 Unidades", "12UN", "20LT", "5kg", "250gr", "C24". */
    private const QTY = '/(?<![\dx×])(\d+(?:[.,]\d+)?)\s*(unidades|unidade|und|un|u|kgs|kg|grs|gr|g|lts|lt|l|cl|ml)?\b(?!\s*[x×]\s*\d)/iu';

    private const UNIDADE = '11001';
    private const QUILOGRAMA = '11002';
    private const LITRO = '11003';

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
        return 'Unidades que contam 1 quando o nome diz mais';
    }

    public function description(): string
    {
        return 'Unidades do PingWin que contam como 1 quando o nome indica mais (Caixa 24, Barril 20LT, Saco 5kg...).';
    }

    public function screens(): array
    {
        return ['artigos', 'faturas'];
    }

    /**
     * O fator e a unidade de referência que o nome indica, ou null.
     *
     * @return array{factor: float, parent: string, text: string}|null
     */
    public static function fromName(string $name): ?array
    {
        if (preg_match('/\d+\s*[x×]\s*\d+/iu', $name) || ! preg_match(self::PACK, $name)) {
            return null; // medidas (60X40X32) ou sem palavra de embalagem
        }
        if (! preg_match_all(self::QTY, $name, $m, PREG_SET_ORDER)) {
            return null;
        }
        foreach ($m as $hit) {
            $n = (float) str_replace(',', '.', $hit[1]);
            $u = mb_strtolower($hit[2] ?? '');
            if ($n <= 1) {
                continue;
            }
            [$factor, $parent] = match (true) {
                in_array($u, ['kg', 'kgs'], true) => [$n, self::QUILOGRAMA],
                in_array($u, ['gr', 'grs', 'g'], true) => [$n / 1000, self::QUILOGRAMA],
                in_array($u, ['l', 'lt', 'lts'], true) => [$n, self::LITRO],
                $u === 'cl' => [$n / 100, self::LITRO],
                $u === 'ml' => [$n / 1000, self::LITRO],
                default => [$n, self::UNIDADE],
            };

            return ['factor' => $factor, 'parent' => $parent, 'text' => trim($hit[0])];
        }

        return null;
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $data = RestaurantAuditData::for($scope);
        $cost = $data->cost();
        $units = $cost->units();
        $byUnit = [];
        foreach ($data->purchases() as $pid => $lines) {
            foreach ($lines as $l) {
                if ($l['unit_id'] !== '') {
                    $byUnit[$l['unit_id']][] = $l + ['pid' => (string) $pid];
                }
            }
        }
        $inBoms = DB::table('pingwin_product_bom_lines')->where('company_id', $companyId)
            ->groupBy('unit_pingwin_id')->pluck(DB::raw('COUNT(*) as n'), 'unit_pingwin_id')->all();

        foreach ($units as $id => $u) {
            $id = (string) $id;
            if (abs((float) $u->unit_value - 1.0) > 1e-9 || (string) $u->product_pingwin_id !== '' && $u->product_pingwin_id !== null) {
                continue;
            }
            $hint = self::fromName(trim((string) $u->description));
            if (! $hint) {
                continue;
            }
            $lines = $byUnit[$id] ?? [];
            if (! $u->is_active && ! $lines) {
                continue;
            }
            $parentId = (string) ($u->parent_pingwin_id ?: '');
            $parentRoot = $parentId !== '' ? ($cost->unitRoot($parentId) ?? $parentId) : null;
            $parentLabel = $parentId !== '' ? (string) ($units[$parentId]->description ?? $parentId) : null;
            $suggestedLabel = (string) ($units[$hint['parent']]->description ?? 'Unidade');
            $wrongParent = $parentRoot !== null ? $parentRoot !== $hint['parent'] : true;

            $articles = [];
            foreach ($lines as $l) {
                $a = &$articles[$l['pid']];
                $a ??= ['code' => $data->item($l['pid'])?->code, 'description' => $data->item($l['pid'])?->description ?? $l['description'], 'lines' => 0, 'last' => $l['date'], 'total_cents' => 0];
                $a['lines']++;
                $a['last'] = max($a['last'], $l['date']);
                $a['total_cents'] += (int) round($l['total'] * 100);
                unset($a);
            }
            uasort($articles, fn ($a, $b) => $b['total_cents'] <=> $a['total_cents']);
            $weight = (int) array_sum(array_column($articles, 'total_cents'));
            $nLines = count($lines);
            $nArticles = count($articles);
            $nBoms = (int) ($inBoms[$id] ?? 0);
            $factorText = Fmt::units($hint['factor'], $suggestedLabel);
            $now = $parentLabel ? Fmt::units(1, $parentLabel) : '1 (sem unidade de stock)';

            $times = Fmt::qty($hint['factor'], 3);
            yield new AuditFindingData(
                entityType: 'unidade', entityId: $id, entityLabel: (string) $u->description,
                title: Fmt::title((string) $u->description, "conta como 1 em vez de {$times}"),
                why: sprintf('Cada «%s» que entra conta como 1 em vez de %s. O stock fica abaixo do real e o custo por %s fica %s vezes mais alto.',
                    $u->description, $times, mb_strtolower($suggestedLabel), $times),
                suggestion: sprintf('Peça ao suporte do PingWin para corrigir a unidade: 1 «%s» = %s%s. Não o faça sozinho: mexe no stock dos artigos que a usam. Se a unidade já não se usa, ignore com esse motivo.',
                    $u->description, $factorText, $wrongParent ? ", com «{$suggestedLabel}» como unidade de stock" : ''),
                evidence: [
                    'facts' => array_values(array_filter([
                        ['label' => 'Conta hoje no PingWin como', 'value' => $now],
                        ['label' => 'O nome indica', 'value' => $factorText],
                        $wrongParent ? ['label' => 'Unidade de stock sugerida', 'value' => $suggestedLabel] : null,
                        ['label' => 'Compras nesta unidade (12 meses)', 'value' => $nLines],
                        ['label' => 'Artigos', 'value' => $nArticles],
                        ['label' => 'Linhas de fichas técnicas', 'value' => $nBoms],
                    ])),
                    'table' => $articles ? [
                        'title' => 'Artigos comprados nesta unidade',
                        'columns' => [
                            ['key' => 'code', 'label' => 'Código'], ['key' => 'description', 'label' => 'Artigo'],
                            ['key' => 'lines', 'label' => 'Linhas', 'align' => 'end'], ['key' => 'last', 'label' => 'Última compra', 'format' => 'date'],
                            ['key' => 'total_cents', 'label' => 'Compras', 'align' => 'end', 'format' => 'cents'],
                        ],
                        'rows' => array_values(array_slice($articles, 0, 20)),
                    ] : null,
                ],
                signature: "{$u->unit_value}|{$parentId}",
                weightCents: $weight ?: null,
                severity: $nLines || $nBoms ? 'alta' : 'baixa',
                fixUrl: '/restauracao/unidades', fixLabel: 'Abrir as unidades',
                refs: array_map(fn ($pid) => ['artigo', (string) $pid], array_keys($articles)),
                seen: sprintf('No PingWin, a unidade «%s» conta como %s, mas o nome indica %s. %s',
                    $u->description, $now, $factorText,
                    $nLines ? sprintf('Nos últimos 12 meses foi usada em %d %s de %d %s.', $nLines, $nLines === 1 ? 'compra' : 'compras', $nArticles, $nArticles === 1 ? 'artigo' : 'artigos')
                        : 'Não foi usada em compras nos últimos 12 meses.'),
                stake: $weight
                    ? sprintf('Não há um valor direto em jogo. A relevância são as compras feitas nesta unidade nos últimos 12 meses: %s.', Fmt::cents($weight))
                    : 'Não há um valor direto em jogo: não houve compras nesta unidade nos últimos 12 meses.',
            );
        }
    }
}
