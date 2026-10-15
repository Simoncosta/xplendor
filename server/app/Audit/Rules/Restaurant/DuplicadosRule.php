<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;
use App\Models\ArticleGroup;
use Illuminate\Support\Facades\DB;

/**
 * Regra 5 · Prováveis duplicados com compras (média): grupos pelo nome normalizado (sem acentos,
 * sem os prefixos de fornecedor MC/PE, sem calibres) com 2 ou mais artigos comprados nos últimos 6
 * meses. Peso: o gasto do grupo. Ação local "Escolher artigo principal" (article_groups; não
 * escreve no PingWin).
 */
final class DuplicadosRule extends RestaurantRule
{
    private const MONTHS_DAYS = 183;

    public function code(): string
    {
        return 'restauracao.duplicados';
    }

    public function entityType(): string
    {
        return 'grupo_artigos';
    }

    public function title(): string
    {
        return 'Artigos que parecem o mesmo produto';
    }

    public function description(): string
    {
        return 'Artigos com o mesmo nome (sem contar acentos, prefixos de fornecedor nem calibres) comprados nos últimos 6 meses.';
    }

    public function screens(): array
    {
        return ['artigos'];
    }

    public static function groupKey(?string $description): string
    {
        $tokens = RestaurantAuditData::nameKeyTokens($description);
        sort($tokens);

        return implode(' ', $tokens);
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $data = RestaurantAuditData::for($scope);
        $since = $data->daysAgo(self::MONTHS_DAYS);
        $units = $data->cost()->units();
        $bought = [];
        foreach ($data->purchases() as $pid => $lines) {
            $recent = array_values(array_filter($lines, fn ($l) => $l['date'] >= $since));
            // Despesas genéricas (sempre "1" de cada vez: avenças, despesas, serviços) não são produtos.
            if (! $recent || max(array_column($recent, 'qnt')) <= 1) {
                continue;
            }
            $bought[(string) $pid] = $recent;
        }
        $groups = [];
        foreach (array_keys($bought) as $pid) {
            $pid = (string) $pid;
            $item = $data->item($pid);
            $key = self::groupKey($item?->description ?? $bought[$pid][0]['description']);
            if ($key === '' || (! str_contains($key, ' ') && mb_strlen($key) < 4)) {
                continue;
            }
            $groups[$key][] = $pid;
        }
        $inBoms = array_fill_keys(DB::table('pingwin_product_bom_lines as l')
            ->join('pingwin_product_boms as b', fn ($j) => $j->on('b.bom_pingwin_id', '=', 'l.bom_pingwin_id')->on('b.company_id', '=', 'l.company_id'))
            ->where('l.company_id', $companyId)->where('b.deleted', false)->distinct()->pluck('l.component_pingwin_id')
            ->map(fn ($v) => (string) $v)->all(), true);
        $mains = ArticleGroup::where('company_id', $companyId)->pluck('main_product_pingwin_id', 'key')->all();
        $sales = $data->sales90();

        foreach ($groups as $key => $pids) {
            if (count($pids) < 2) {
                continue;
            }
            $pids = array_map('strval', $pids);
            sort($pids);
            $rows = [];
            $spend = 0;
            foreach ($pids as $pid) {
                $item = $data->item($pid);
                $lines = $bought[$pid];
                $total = array_sum(array_column($lines, 'total'));
                $qb = array_sum(array_column($lines, 'qnt_base'));
                $root = $data->cost()->componentBaseRoot($pid);
                $spend += (int) round($total * 100);
                $rows[] = [
                    'product_id' => $pid,
                    'main' => ($mains[$key] ?? null) === $pid,
                    'code' => $item?->code ? ltrim((string) $item->code, "\u{FEFF}") : null,
                    'description' => $item?->description ?? $lines[0]['description'],
                    'family' => RestaurantAuditData::familyPath($item?->family),
                    'unit' => $item?->purchaseunit ?: ($units[$root]->description ?? null),
                    'suppliers' => implode(', ', array_values(array_unique(array_filter(array_column($lines, 'supplier'))))),
                    'base_price' => $qb > 0 ? round($total / $qb, 4) : null,
                    'base_unit' => $units[$root]->description ?? null,
                    'purchases_cents' => (int) round($total * 100),
                    'sales_qty' => round($sales[$pid]['qty'] ?? 0, 3),
                    'in_boms' => isset($inBoms[$pid]) ? 'sim' : 'não',
                ];
            }
            usort($rows, fn ($a, $b) => $b['purchases_cents'] <=> $a['purchases_cents']);
            // O rótulo: o nome mais curto do grupo (ex.: "MACA GOLDEN"), que é o mais legível.
            $label = (string) collect($rows)->pluck('description')->sortBy(fn ($d) => mb_strlen((string) $d))->first();
            $names = implode(', ', array_map(fn ($r) => trim(($r['code'] ? "{$r['code']} " : '') . $r['description']), $rows));

            yield new AuditFindingData(
                entityType: 'grupo_artigos', entityId: $key, entityLabel: $label,
                title: Fmt::title($label, sprintf('%d artigos que parecem o mesmo produto', count($rows))),
                why: 'Se são o mesmo produto, as compras e o stock ficam divididos por vários artigos, e as fichas técnicas podem usar o artigo errado.',
                suggestion: 'Escolha o artigo principal: passa a aparecer com uma estrela quando procura ingredientes nas fichas. Se forem o mesmo produto, compre sempre pelo principal no PingWin. Se forem produtos diferentes, ignore com esse motivo.',
                evidence: [
                    'table' => [
                        'columns' => [
                            ['key' => 'code', 'label' => 'Código'], ['key' => 'description', 'label' => 'Artigo'], ['key' => 'family', 'label' => 'Família'],
                            ['key' => 'unit', 'label' => 'Unidade'], ['key' => 'suppliers', 'label' => 'Fornecedores'],
                            ['key' => 'base_price', 'label' => 'Preço por unidade de stock', 'align' => 'end', 'format' => 'eur4'],
                            ['key' => 'purchases_cents', 'label' => 'Compras (6 meses)', 'align' => 'end', 'format' => 'cents'],
                            ['key' => 'sales_qty', 'label' => 'Vendas (90 dias)', 'align' => 'end', 'format' => 'qty'],
                            ['key' => 'in_boms', 'label' => 'Em fichas'],
                        ],
                        'rows' => $rows,
                    ],
                    'group' => ['key' => $key, 'main' => $mains[$key] ?? null],
                ],
                evidenceKey: '',
                signature: implode(',', $pids),
                weightCents: $spend ?: null,
                fixLabel: 'Escolher artigo principal',
                fixAction: ['type' => 'escolher_principal', 'group_key' => $key, 'label' => $label, 'members' => $pids],
                refs: array_map(fn ($pid) => ['artigo', $pid], $pids),
                seen: sprintf('Nos últimos 6 meses comprou %d artigos com o mesmo nome, sem contar prefixos de fornecedor nem calibres. São estes: %s.', count($rows), $names),
                stake: sprintf('Não há um valor direto em jogo. A relevância é o que comprou destes artigos em 6 meses: %s.', Fmt::cents($spend)),
            );
        }
    }
}
