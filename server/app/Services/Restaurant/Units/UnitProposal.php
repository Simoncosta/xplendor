<?php

declare(strict_types=1);

namespace App\Services\Restaurant\Units;

/**
 * XPLENDOR — Auditoria v2: a proposta do Guião para um artigo, a partir das evidências, das
 * respostas às perguntas e do que já se confirmou em artigos parecidos (a mesma família). Funções
 * puras: não lê nem grava nada.
 *
 * O tipo e a base vêm do UnitClassifier (uso → descrição do fornecedor → unidade faturada; nunca o
 * nome do artigo). A proposta junta as unidades de compra com o fator por fornecedor (lido da
 * descrição ou da unidade faturada; sem número, pergunta-se), a unidade de contagem, as unidades a
 * inativar, as perguntas (uma a três) e o aviso quando a base muda (a XPLENDOR não faz essa mudança).
 */
final class UnitProposal
{
    /** Abaixo desta fração da unidade base por porção, a base é demasiado grande para as fichas (U2). */
    public const SMALL_FRACTION = 0.05;

    private const BASE_LABEL = ['kg' => 'Quilograma', 'L' => 'Litro', 'un' => 'Unidade'];

    private const TYPE_LABEL = [
        'peso' => 'Ao peso', 'volume' => 'Ao volume', 'contavel' => 'Contável (à peça ou à dose)',
        'embalado_peso' => 'Embalado, usado ao peso ou ao volume', 'embalado_inteiro' => 'Embalado, usado inteiro',
    ];

    public static function baseLabel(?string $base): string
    {
        return self::BASE_LABEL[$base] ?? 'Unidade';
    }

    public static function typeLabel(?string $type): string
    {
        return self::TYPE_LABEL[$type] ?? 'Por identificar';
    }

    /**
     * @param  array<string, string>  $answers  respostas às perguntas (id → opção)
     * @param  array{base?:string, type?:string, family?:string}|null  $learned  o habitual na família (só sugestão)
     */
    public static function build(array $e, array $answers = [], ?array $learned = null): array
    {
        $c = UnitClassifier::classify($e, $answers);
        $usage = self::usage($e);
        $type = $c['type'];
        $base = $c['base'];
        if (($answers['base'] ?? null) && isset(self::BASE_LABEL[$answers['base']])) {
            $base = $answers['base'];
        }
        $pack = $c['pack'];
        $piecesPerPortion = self::recipesCountPack($e, $pack);
        $priceSaysPack = $pack ? self::priceSupportsPack($e, $pack) : null;
        // As fichas contam a embalagem inteira (0,0055 Unidade = 1 ovo da caixa de 180). Se as compras
        // também (o preço por «Unidade» é o de uma caixa), a base de hoje é a embalagem; se o preço é o
        // de uma peça, são as fichas que estão erradas e a base fica.
        $packIsBase = $piecesPerPortion && $priceSaysPack !== false && self::oldUnitIsPack($e, $pack, $usage);
        $recipesUsePack = $piecesPerPortion && ! $packIsBase;

        $purchases = [];
        foreach ($e['purchases'] as $p) {
            $purchases[] = self::purchaseUnit($p, $base, $c, $answers, $packIsBase, $e);
        }
        $deactivate = [];
        $limit = (new \DateTimeImmutable($e['today'] ?? 'now'))->modify('-365 days')->format('Y-m-d');
        foreach ($e['units'] as $u) {
            if (($u['last_use'] ?? null) && $u['last_use'] < $limit) {
                $deactivate[] = $u['label'];
            }
        }
        $questions = $c['questions'];
        $boughtKg = (bool) array_filter($e['purchases'], fn ($p) => $p['unit_family'] === 'kg');
        if ($type === 'peso' && $boughtKg && in_array('un', $usage['families'], true)) {
            $questions[] = ['id' => 'peso_variavel', 'kind' => 'choice', 'text' => 'Cada peça tem sempre o mesmo peso?', 'options' => [
                ['value' => 'nao', 'label' => 'Sim, o peso é sempre o mesmo'], ['value' => 'sim', 'label' => 'Não, o peso varia de peça para peça'],
            ], 'answer' => $answers['peso_variavel'] ?? null];
        }
        foreach ($purchases as $p) {
            if ($p['factor'] === null && $base && ! array_filter($questions, fn ($q) => in_array($q['id'], ['qtd_embalagem', 'doses_por_caixa', $p['answer_key']], true))) {
                $questions[] = ['id' => $p['answer_key'], 'kind' => 'number', 'options' => [], 'answer' => $answers[$p['answer_key']] ?? null,
                    'text' => sprintf('Quanto leva cada «%s» de %s? (em %s)', $p['unit_label'], $p['supplier'] ?: 'fornecedor', self::unitWord($base, $c['base_name']))];
            }
        }
        if ($type === null && ! $questions) {
            $questions[] = ['id' => 'uso', 'kind' => 'choice', 'text' => 'Como se gasta este artigo?', 'answer' => $answers['uso'] ?? null, 'options' => [
                ['value' => 'dose_peso', 'label' => 'Em gramas'], ['value' => 'dose_volume', 'label' => 'Em mililitros'],
                ['value' => 'peca', 'label' => 'À peça ou à dose'], ['value' => 'inteiro', 'label' => 'A embalagem inteira'],
            ]];
        }
        foreach ($questions as $i => $q) {
            $questions[$i]['answer'] ??= $answers[$q['id']] ?? null;
        }

        $baseChange = $base !== null && (($e['base_family'] && $e['base_family'] !== $base) || $packIsBase);
        $hasStock = array_sum(array_map(fn ($s) => abs((float) $s['qnt']), $e['stock'] ?? [])) > 0;
        $identified = $type !== null && ! $c['conflicts'];

        return [
            'identified' => $identified,
            'type' => $type,
            'type_label' => self::typeLabel($type),
            'base' => $base,
            'base_name' => $c['base_name'],
            'base_label' => $base ? ($c['base_name'] !== self::baseLabel($base) ? "{$c['base_name']} (" . mb_strtolower(self::baseLabel($base)) . ')' : self::baseLabel($base)) : null,
            'dose' => $c['dose'],
            'current_base' => $e['base_family'],
            'current_base_label' => $e['base_unit'] ?: ($e['base_family'] ? self::baseLabel($e['base_family']) : null),
            'old_unit_is_pack' => $packIsBase,
            'recipes_use_pack' => $recipesUsePack,
            // Quanto se multiplica: as quantidades das fichas e as do stock, da base de hoje para a nova.
            'recipe_conversion' => ($packIsBase || $recipesUsePack) && $pack && $base === 'un' ? (float) $pack['count'] : null,
            'stock_conversion' => $packIsBase && $pack && $base === 'un' ? (float) $pack['count'] : ($recipesUsePack ? 1.0 : null),
            'recipes_note' => $recipesUsePack ? sprintf('As fichas contam «%s» como %s, mas as compras mostram que cada «%s» é uma peça: são as fichas que estão erradas.',
                $e['base_unit'] ?: 'Unidade', self::lcfirst($pack['label'] ?? 'a embalagem'), $e['base_unit'] ?: 'Unidade') : null,
            'pack' => $pack,
            'signals' => $c['signals'],
            'conflicts' => $c['conflicts'],
            'purchase_units' => $purchases,
            'count_unit' => $base ? self::countUnit($type, $base, $c, $pack, $answers) : null,
            'deactivate' => $deactivate,
            'base_change' => $baseChange,
            'base_change_warning' => $baseChange ? self::baseChangeWarning($e, $base, $c['base_name'], $hasStock) : null,
            'questions' => array_slice($questions, 0, 3),
            'learned' => $learned && ($learned['base'] ?? null) ? sprintf('Nos artigos de %s já confirmados, a base costuma ser %s (só sugestão).',
                mb_strtolower($learned['family'] ?? 'mesma família'), mb_strtolower(self::baseLabel($learned['base']))) : null,
            'complete' => $identified && ! array_filter($purchases, fn ($p) => $p['factor'] === null),
        ];
    }

    /** Como as fichas usam o artigo: a fração da base por porção e a família das unidades. */
    public static function usage(array $e): array
    {
        $fractions = [];
        $families = [];
        foreach ($e['recipes'] as $r) {
            if ($r['qnt_base'] > 0) {
                $fractions[] = $r['qnt_base'];
            }
            if ($r['unit_family'] ?? null) {
                $families[$r['unit_family']] = true;
            }
        }
        sort($fractions);
        $median = $fractions ? $fractions[intdiv(count($fractions), 2)] : null;

        return [
            'min' => $fractions[0] ?? null, 'median' => $median,
            'small' => $median !== null && $median < self::SMALL_FRACTION,
            'fractional' => (bool) array_filter($fractions, fn ($f) => $f < 1 && abs($f - round($f)) > 1e-6),
            'families' => array_keys($families), 'recipes' => count($e['recipes']),
        ];
    }

    /**
     * A unidade base de hoje é afinal a embalagem inteira: entra na unidade base com fator 1 (ou sem
     * compras), a descrição diz uma embalagem com várias peças, e as fichas usam frações pequenas que
     * batem com uma peça da embalagem (0,0055 Unidade = 1/180 da caixa de ovos). A base nova vem do
     * uso (o ovo, a garrafa), nunca da embalagem.
     */
    public static function oldUnitIsPack(array $e, ?array $pack, array $usage): bool
    {
        if (! $pack || ! $usage['median'] || ($e['has_recipe'] ?? false)) {
            return false;
        }
        $inBase = ! $e['purchases'] || array_filter($e['purchases'], fn ($p) => $p['base_source'] === 'base');
        $pieces = $pack['count'] > 1 ? $pack['count'] : 0;
        if (! $inBase || $pieces <= 1) {
            return false;
        }
        $share = $usage['median'] * $pieces;

        return $share >= 0.25 && $share <= 12;
    }

    /** As fichas usam peças inteiras da embalagem (entre 0,75 e 12 por linha). */
    public static function recipesCountPack(array $e, ?array $pack): bool
    {
        if (! $pack || $pack['count'] <= 1 || ! $e['recipes'] || ($e['has_recipe'] ?? false)) {
            return false;
        }
        foreach ($e['recipes'] as $r) {
            $pieces = $r['qnt_base'] * $pack['count'];
            if ($r['qnt_base'] <= 0 || $pieces < 0.75 || $pieces > 12) {
                return false;
            }
        }

        return true;
    }

    /**
     * O preço diz que a unidade faturada é a embalagem inteira: o preço por unidade faturada está perto
     * de N × o preço de uma peça na família (entre N/3 e 3N, e pelo menos o dobro). Null sem termo de
     * comparação (a família tem menos de 3 artigos com preço).
     */
    public static function priceSupportsPack(array $e, array $pack, ?array $p = null): ?bool
    {
        $median = $e['family_median'] ?? null;
        // Quantas unidades base de hoje leva a embalagem: o conteúdo (7,92 L) numa base em litros ou
        // quilos; as peças (180 ovos) numa base em unidades.
        $n = ($e['base_family'] ?? null) === $pack['family'] && $pack['family'] !== 'un' ? $pack['quantity'] : ($pack['count'] > 1 ? $pack['count'] : $pack['quantity']);
        $groups = $p ? [$p] : array_filter($e['purchases'], fn ($x) => $x['base_source'] === 'base');
        if (! $median || $n <= 1 || ! $groups) {
            return null;
        }
        foreach ($groups as $g) {
            $ratio = $g['price_per_unit'] ? $g['price_per_unit'] / $median : null;
            if ($ratio === null || $ratio < max(2, $n / 3) || $ratio > $n * 3) {
                return false;
            }
        }

        return true;
    }

    /** O fator de uma unidade de compra (quantas unidades base leva), por fornecedor. */
    private static function purchaseUnit(array $p, ?string $base, array $c, array $answers, bool $packIsBase, array $e = []): array
    {
        $own = ($p['packaging']['quantity'] ?? 0) > 0 ? $p['packaging'] : null;
        $unitPack = PackagingParser::parse($p['unit_label']);
        $unitPack = ($unitPack['quantity'] ?? 0) > 0 ? $unitPack : null;
        $factor = null;
        $why = null;
        $name = null;
        $container = null;
        $fromPack = function (?array $pk, string $whyText) use ($base, &$factor, &$why, &$name, &$container) {
            if (! $pk || ! $base) {
                return false;
            }
            if ($base === 'un' && $pk['count'] > 1) {
                $factor = (float) $pk['count'];
            } elseif ($base !== 'un' && $pk['family'] === $base && $pk['quantity'] > 0) {
                $factor = (float) $pk['quantity'];
            } else {
                return false;
            }
            $why = $whyText;
            $name = self::packName($pk, $base);
            $container = $pk['container'] ?? null;

            return true;
        };

        // Faturado na unidade base: a quantidade já é a base (189,87 kg de queijo em barras de 3 kg), a não
        // ser que o preço mostre que cada linha é a embalagem inteira (e a base de hoje seja a embalagem).
        // Só quando a unidade faturada é da família da base nova (kg faturado numa base kg); um pacote de
        // 1 kg faturado como «Unidade» numa base nova em kg é 1 kg. Nas doses, o N da descrição é a caixa
        // (a não ser que o preço diga que se fatura à dose).
        $inBase = $base && $p['base_source'] === 'base' && $p['unit_family'] === $base;
        $price = $own ? self::priceSupportsPack($e, $own, $p) : null;
        if ($inBase && $own && ! $packIsBase && ($c['dose'] ? $price === false : $price !== true)) {
            $own = null;
        }
        if ($p['base_source'] === 'confirmado' && $p['base_factor']) {
            $factor = $p['base_factor'];
            $why = 'Fator já confirmado.';
            // O nome da embalagem, se bate com o fator confirmado ("Lata 28").
            foreach (array_filter([$own, $unitPack]) as $pk) {
                if ($base && abs(($base === 'un' ? $pk['count'] : $pk['quantity']) - $factor) < 1e-6) {
                    $name = self::packName($pk, $base);
                    break;
                }
            }
        } elseif ($fromPack($own, ($p['packaging_from'] ?? 'descricao') === 'unidade'
            ? 'Lido no nome da unidade faturada («' . $p['unit_label'] . '»).' : 'Lido na descrição do fornecedor: «' . ($own['text'] ?? '') . '».')) {
            // A embalagem escrita na descrição (também quando entrou como «Unidade» com fator 1).
        } elseif ($fromPack($unitPack, 'Lido no nome da unidade faturada.')) {
            // "Grade 24", "Barril 50lt".
        } elseif ($base && $p['unit_family'] === $base && $p['base_source'] === 'base' && ! ($c['dose'] && ! (($c['pack']['count'] ?? 0) > 1))) {
            $factor = 1.0;
            $why = 'Faturado na unidade base.';
        }
        // A dose sem número e a quantidade do saco ou da caixa: só com a resposta de uma pessoa.
        if ($factor === null && $c['dose'] && is_numeric($answers['doses_por_caixa'] ?? null) && (float) $answers['doses_por_caixa'] > 1) {
            $factor = (float) $answers['doses_por_caixa'];
            $why = 'Indicado por si.';
            $name = 'Caixa ' . (int) $factor;
        }
        if ($factor === null && $c['pack'] && ($answers['qtd_embalagem'] ?? null)) {
            $fromPack($c['pack'], 'Indicado por si.');
        }
        $key = 'fator:' . ($p['nif'] ?: 'todos') . ':' . $p['unit_key'];
        $typed = str_replace(',', '.', (string) ($answers[$key] ?? ''));
        if (is_numeric($typed) && (float) $typed > 0) {
            $factor = (float) $typed;
            $why = 'Indicado por si.';
        }
        if ($packIsBase && $p['base_source'] === 'base' && $factor) {
            $why .= ' Hoje entra como «' . $p['unit_label'] . '» com fator 1.';
        }

        return [
            'supplier' => $p['supplier'], 'nif' => $p['nif'], 'unit_key' => $p['unit_key'], 'unit_id' => $p['unit_id'], 'unit_label' => $p['unit_label'],
            'proposed_unit' => $name ?? $p['unit_label'], 'container' => $container ?? (($p['packaging']['container'] ?? null) ?: null),
            'factor' => $factor, 'why' => $why, 'answer_key' => $key, 'pingwin_factor' => $p['pingwin_factor'],
            'label' => $factor && $base ? sprintf('1 %s = %s', $name ?? $p['unit_label'], self::qty($factor, $base, $c['base_name']))
                : sprintf('1 %s = ?', $p['unit_label']),
            'confirmed' => $p['base_source'] === 'confirmado',
        ];
    }

    /** "Caixa 180", "Grade 24", "Caixa 100" (doses), "Saco 10 kg", "Barril 50 L". */
    public static function packName(array $pk, string $base): string
    {
        // Várias latas ou garrafas numa embalagem: é uma caixa ("Caixa 28", como o catálogo das unidades).
        $c = $pk['container'] && ! ($pk['count'] > 1 && in_array($pk['container'], ['lata', 'garrafa'], true))
            ? PackagingParser::containerLabel($pk['container']) : ($pk['count'] > 1 ? 'Caixa' : 'Embalagem');
        if ($base === 'un') {
            return $c . ' ' . $pk['count'];
        }

        return $pk['count'] > 1 ? "{$c} {$pk['count']} × " . PackagingParser::size($pk['family'], $pk['each']) : $c . ' ' . PackagingParser::size($pk['family'], $pk['quantity']);
    }

    /** A unidade em que se conta: a dose, a garrafa, a embalagem inteira, ou "≈ X kg" para o peso variável. */
    private static function countUnit(?string $type, string $base, array $c, ?array $pack, array $answers): array
    {
        if ($type === 'peso' && ($answers['peso_variavel'] ?? null) === 'sim') {
            $avg = is_numeric(str_replace(',', '.', (string) ($answers['peso_medio'] ?? ''))) ? (float) str_replace(',', '.', (string) $answers['peso_medio']) : null;

            return ['label' => $avg ? 'Peça ≈ ' . self::qty($avg, 'kg') : 'Peça (peso aproximado)', 'factor' => $avg, 'approximate' => true];
        }
        if ($type === 'embalado_inteiro' && $pack && $pack['family'] !== 'un' && $pack['each'] > 0) {
            return ['label' => $c['base_name'] . ' de ' . PackagingParser::size($pack['family'], $pack['each']), 'factor' => 1.0, 'approximate' => false];
        }
        if ($type === 'embalado_peso' && $pack && $pack['quantity'] > 0) {
            return ['label' => self::packName($pack, $base), 'factor' => (float) ($pack['count'] > 1 ? $pack['each'] : $pack['quantity']), 'approximate' => false];
        }

        return ['label' => $c['base_name'] ?: self::baseLabel($base), 'factor' => 1.0, 'approximate' => false];
    }

    /** O aviso forte de mudar a base de um artigo com stock e histórico, com o caminho seguro. */
    private static function baseChangeWarning(array $e, string $base, string $baseName, bool $hasStock): array
    {
        return [
            'title' => sprintf('Mudar a unidade base de %s para %s', mb_strtolower((string) ($e['base_unit'] ?: self::baseLabel($e['base_family']))), mb_strtolower($baseName ?: self::baseLabel($base))),
            'text' => ($hasStock || $e['purchases'] ? 'Este artigo tem ' . implode(' e ', array_filter([$hasStock ? 'stock' : null, $e['purchases'] ? 'compras' : null])) . '. ' : '')
                . 'Mudar a unidade base no PingWin muda o significado das quantidades que já lá estão (stock, compras, fichas). A XPLENDOR não faz esta mudança.',
            'paths' => [
                'Com o stock a zero: mude a unidade base no PingWin num dia sem movimentos e confirme aqui.',
                'Depois de uma contagem: conte, lance a contagem, mude a base no PingWin e volte a contar na unidade nova.',
                'Criar um artigo novo com a base certa e passar as fichas e as compras para ele (o antigo fica inativo).',
            ],
        ];
    }

    private static function lcfirst(string $s): string
    {
        return mb_strtolower(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    private static function unitWord(string $base, string $baseName): string
    {
        return $base === 'un' ? PackagingParser::plural(mb_strtolower($baseName ?: 'unidade')) : ($base === 'kg' ? 'kg' : 'litros');
    }

    /** "7 g", "0,25 L", "24 garrafas", "100 cápsulas". */
    public static function qty(float $q, string $base, ?string $baseName = null): string
    {
        $fmt = fn (float $v, int $d = 3) => rtrim(rtrim(number_format($v, $d, ',', ' '), '0'), ',');
        if ($base === 'kg') {
            return $q < 1 ? $fmt($q * 1000, 1) . ' g' : $fmt($q) . ' kg';
        }
        if ($base === 'L') {
            return $q < 1 ? $fmt($q * 1000, 1) . ' ml' : $fmt($q) . ' L';
        }
        $name = mb_strtolower($baseName ?: 'unidade');

        return $fmt($q) . ' ' . (abs($q - 1) < 1e-9 ? $name : PackagingParser::plural($name));
    }
}
