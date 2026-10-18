<?php

declare(strict_types=1);

namespace App\Services\Restaurant\Units;

use Illuminate\Support\Str;

/**
 * XPLENDOR — Auditoria v2: o tipo de um artigo (e a sua unidade base), pela ordem das evidências.
 * NUNCA pelo nome do artigo ("café" não é sempre peso):
 *  a) o uso: a quantidade por porção nas fichas (1 por porção = contável; frações pequenas como
 *     0,007 = peso ou volume) e a venda 1 para 1;
 *  b) a descrição do fornecedor: "CÁPSULA", "CAPS", "PASTILHA", "MONODOSE", "DOSE", "SAQUETA",
 *     "PACOTINHO", "INDIVIDUAL" = contável com caixa de N; "GRÃO", "MOÍDO", "KG", "G" = peso;
 *     "L", "LT", "CL", "ML" = volume;
 *  c) a unidade e o preço faturados.
 * Se as evidências se contradizem, o tipo fica por decidir (null): o artigo é "Por identificar" e
 * leva a pergunta certa ("O café é em grão ou moído, ou em cápsulas?"). Um "Saco" ou uma "Caixa"
 * sem quantidade nunca passa a contável por omissão: pergunta-se quanto tem.
 */
final class UnitClassifier
{
    private const WEIGHT_WORDS = '/\b(GRAO|MOIDO|MOIDA|KG|KGS|KILO|KILOS|QUILO|QUILOS|GR|GRS|G)\b|\d\s?(KG|GR?)\b/';
    private const VOLUME_WORDS = '/\b(LT|LTS|CL|ML|LITRO|LITROS)\b|\d\s?(L|LT|CL|ML)\b/';

    /**
     * @return array{type:?string, base:?string, base_name:string, dose:?string, signals:array, conflicts:list<string>, questions:list<array>, pack:?array}
     */
    public static function classify(array $e, array $answers = []): array
    {
        $desc = self::descriptionSignals($e);
        $usage = self::usageSignal($e);
        $invoice = self::invoiceSignal($e);
        $pack = $desc['pack'];
        $conflicts = [];
        $questions = [];
        $name = self::shortName($e);
        $type = null;
        $dose = $desc['dose'];

        // As respostas mandam (são de uma pessoa).
        $form = $answers['forma'] ?? null;
        if ($form === 'doses') {
            $desc['kind'] = 'dose';
            $dose ??= 'Dose';
        } elseif ($form === 'granel') {
            $desc['kind'] = $desc['granel'] ?? ($invoice === 'volume' ? 'volume' : 'peso');
            $dose = null;
        }
        $use = $answers['uso'] ?? null;
        if ($use) {
            $usage = ['dose_peso' => 'peso', 'dose_volume' => 'volume', 'inteiro' => 'peca', 'peca' => 'peca'][$use] ?? $usage;
        }
        $packQty = self::packFromAnswer($answers, $pack);
        if ($packQty) {
            $pack = $packQty;
        }

        // a) O uso decide, com a descrição a dizer de quê.
        if ($usage === 'peca') {
            if ($desc['kind'] === 'dose') {
                $type = 'contavel';
            } elseif (in_array($desc['kind'], ['peso', 'volume'], true) && ! ($pack && $pack['count'] > 1)) {
                // Usado à peça mas descrito a granel: comprado ao kg e usado às peças é peso variável
                // (frango a 1/3); faturado à unidade, é uma contradição.
                if ($invoice === $desc['kind']) {
                    $type = $desc['kind'];
                } else {
                    $conflicts[] = 'As fichas usam-no à peça, mas a descrição do fornecedor diz ' . ($desc['kind'] === 'peso' ? 'peso (kg, grão, moído)' : 'volume (litros)') . '.';
                }
            } elseif ($desc['kind'] === null && in_array($invoice, ['peso', 'volume'], true) && self::fractionalPieces($e)) {
                // Comprado ao kg e usado em frações de Unidade (o frango a 1/3): peso, com o peso da peça a confirmar.
                $type = $invoice;
            } else {
                // Garrafas e latas (peças em litros) usadas inteiras; o resto é contável (ovos, pão, fatias).
                $type = $pack && $pack['count'] > 1 && $pack['family'] === 'L' ? 'embalado_inteiro' : 'contavel';
            }
        } elseif (in_array($usage, ['medida', 'peso', 'volume'], true)) {
            if ($desc['kind'] === 'dose') {
                $conflicts[] = sprintf('As fichas usam frações pequenas (como %s), mas a descrição do fornecedor diz %s.',
                    self::num($e['_usage_median'] ?? null), mb_strtolower(PackagingParser::plural($dose ?? 'Dose')));
            } else {
                $measure = $usage !== 'medida' ? $usage : (in_array($desc['kind'], ['peso', 'volume'], true) ? $desc['kind'] : ($invoice ?? null));
                $measureFamily = ['peso' => 'kg', 'volume' => 'L'][$measure] ?? null;
                if ($measure === null) {
                    $questions[] = self::q('uso', "{$name}: como se mede nas fichas?", [
                        ['dose_peso', 'Em gramas'], ['dose_volume', 'Em mililitros'], ['peca', 'À peça (unidade)'],
                    ]);
                } else {
                    $type = $pack && $pack['quantity'] > 0 && $pack['family'] === $measureFamily ? 'embalado_peso' : $measure;
                }
            }
        } else {
            // b) Sem uso nas fichas: a descrição do fornecedor; c) senão, a unidade faturada.
            $type = match ($desc['kind']) {
                'dose' => 'contavel',
                'pecas' => $pack && $pack['family'] === 'L' ? 'embalado_inteiro' : 'contavel',
                'peso', 'volume' => $pack && $pack['quantity'] > 0 && $pack['count'] > 1 ? 'embalado_inteiro' : $desc['kind'],
                default => $invoice,
            };
        }

        // Descrições que se contradizem entre fornecedores (açúcar em saquetas e açúcar ao kg).
        if ($desc['mixed'] && ! $form) {
            $conflicts[] = 'Há fornecedores que o descrevem em ' . mb_strtolower(PackagingParser::plural($dose ?? 'Dose')) . ' e outros a granel (' . ($desc['granel'] === 'volume' ? 'litros' : 'kg') . ').';
        }
        if ($conflicts) {
            $type = null;
            $granel = ($desc['granel'] ?? 'peso') === 'volume' ? 'a granel (em litros)' : 'em grão, moído ou ao kg';
            $questions[] = self::q('forma', sprintf('%s: é %s, ou em %s?', $name, $granel, mb_strtolower(PackagingParser::plural($dose ?? 'Dose'))), [
                ['granel', $desc['granel'] === 'volume' ? 'A granel (litros)' : 'A granel (grão, moído ou kg)'],
                ['doses', 'Em doses individuais (' . mb_strtolower(PackagingParser::plural($dose ?? 'Dose')) . ')'],
            ]);
        }
        // Um "Saco" ou uma "Caixa" sem quantidade: nunca contável por omissão (nas doses pergunta-se quantas).
        if ($desc['container_only'] && ! $dose && ! $packQty && ! ($pack && $pack['quantity'] > 0)) {
            $c = PackagingParser::containerLabel($desc['container_only']);
            $questions[] = ['id' => 'qtd_embalagem', 'kind' => 'quantity', 'text' => sprintf('Quanto tem o %s? (kg ou unidades)', mb_strtolower($c)),
                'units' => [['kg', 'kg'], ['L', 'litros'], ['un', 'unidades']], 'options' => [], 'answer' => $answers['qtd_embalagem'] ?? null];
            if ($type === 'contavel' && $usage === null && $desc['kind'] === null) {
                $type = null;
            }
        }
        // Doses sem o N: pergunta-se quantas leva cada caixa; nunca se assume.
        if ($type === 'contavel' && $dose && ! ($pack && $pack['count'] > 1) && ! isset($answers['doses_por_caixa'])) {
            $questions[] = ['id' => 'doses_por_caixa', 'kind' => 'number', 'text' => sprintf('Quantas %s tem cada caixa?', PackagingParser::plural($dose)),
                'options' => [], 'answer' => null];
        }

        $base = match ($type) {
            'peso' => 'kg',
            'volume' => 'L',
            'embalado_peso' => $pack['family'] ?? 'kg',
            'contavel', 'embalado_inteiro' => 'un',
            default => null,
        };

        return [
            'type' => $type, 'base' => $base, 'base_name' => self::baseName($type, $dose, $pack, $desc),
            'dose' => $type === 'contavel' ? $dose : null, 'pack' => $pack,
            'signals' => ['uso' => $usage, 'descricao' => $desc['kind'], 'faturado' => $invoice],
            'conflicts' => $conflicts, 'questions' => $questions,
        ];
    }

    /** a) O uso: "peca" (1 por porção, ou uma peça da embalagem), "medida" (frações pequenas), "peso"/"volume" (fichas em kg/L). */
    private static function usageSignal(array &$e): ?string
    {
        $q = [];
        $families = [];
        foreach ($e['recipes'] as $r) {
            if ($r['qnt_base'] > 0) {
                $q[] = $r['qnt_base'];
                $families[$r['unit_family'] ?? '?'] = true;
            }
        }
        if (! $q) {
            return ($e['sold_one_to_one'] ?? false) ? 'peca' : null;
        }
        sort($q);
        $median = $q[intdiv(count($q), 2)];
        $e['_usage_median'] = $median;
        // Fichas em kg ou em litros: medem-se assim (15 g de café, 9 ml de molho).
        if (count($families) === 1 && isset($families['kg'])) {
            return 'peso';
        }
        if (count($families) === 1 && isset($families['L']) && ! self::wholePieces($q, self::bestPack($e))) {
            return 'volume';
        }
        // Peças inteiras da embalagem por porção (0,0055 e 0,022 da caixa de 180 ovos = 1 e 4 ovos;
        // 0,042 da grade de 24 = 1 garrafa).
        if (self::wholePieces($q, self::bestPack($e))) {
            return 'peca';
        }
        if ($median >= 0.5 && abs($median - round($median)) <= 0.1 && isset($families['un'])) {
            return 'peca';
        }
        if ($median < UnitProposal::SMALL_FRACTION) {
            return 'medida';
        }
        // Frações de uma unidade (frango a 1/3): peça, com o peso a confirmar.
        return isset($families['un']) ? 'peca' : null;
    }

    /** b) A descrição do fornecedor (todas as linhas de compra, nunca o nome do artigo). */
    private static function descriptionSignals(array $e): array
    {
        $kinds = [];
        $dose = null;
        $pack = null;
        $container = null;
        $sources = array_map(fn ($p) => array_merge($p['descriptions'], [$p['unit_label']]), $e['purchases']);
        $sources[] = $e['extra_descriptions'] ?? [];
        foreach ($sources as $texts) {
            foreach ($texts as $d) {
                $t = Str::upper(Str::ascii((string) $d));
                $parsed = PackagingParser::parse($d);
                if ($parsed && ($parsed['dose'] ?? null)) {
                    $kinds['dose'] = true;
                    $dose ??= $parsed['dose'];
                    if ($parsed['count'] > 1) {
                        $pack ??= $parsed;
                    }
                    continue;
                }
                if ($parsed && $parsed['quantity'] > 0) {
                    $pack ??= $parsed;
                    $kinds[$parsed['count'] > 1 ? 'pecas' : ($parsed['family'] === 'kg' ? 'peso' : ($parsed['family'] === 'L' ? 'volume' : 'pecas'))] = true;
                    continue;
                }
                if ($parsed && $parsed['quantity'] == 0 && $parsed['container']) {
                    $container ??= $parsed['container'];
                }
                if (preg_match(self::WEIGHT_WORDS, $t)) {
                    $kinds['peso'] = true;
                } elseif (preg_match(self::VOLUME_WORDS, $t)) {
                    $kinds['volume'] = true;
                }
            }
        }
        $granel = isset($kinds['peso']) ? 'peso' : (isset($kinds['volume']) ? 'volume' : null);
        $kind = isset($kinds['dose']) ? 'dose' : (isset($kinds['pecas']) ? 'pecas' : $granel);

        return ['kind' => $kind, 'dose' => $dose, 'pack' => $pack, 'granel' => $granel,
            'mixed' => isset($kinds['dose']) && $granel !== null && ! ($pack && ($pack['dose'] ?? null)),
            'container_only' => $kind === null || $kind === 'dose' ? $container : ($pack ? null : $container)];
    }

    /** c) A unidade faturada: tudo em kg = peso; tudo em litros = volume. */
    private static function invoiceSignal(array $e): ?string
    {
        $f = array_values(array_unique(array_filter(array_map(fn ($p) => $p['unit_family'], $e['purchases']))));

        return $f === ['kg'] ? 'peso' : ($f === ['L'] ? 'volume' : null);
    }

    /** As fichas usam frações de Unidade que não são peças inteiras (1/3, 0,5). */
    private static function fractionalPieces(array $e): bool
    {
        foreach ($e['recipes'] as $r) {
            if (($r['unit_family'] ?? null) === 'un' && $r['qnt_base'] > 0 && abs($r['qnt_base'] - round($r['qnt_base'])) > 1e-6) {
                return true;
            }
        }

        return false;
    }

    /** Cada linha da ficha é um punhado de peças inteiras da embalagem (entre 0,75 e 12). */
    private static function wholePieces(array $q, ?array $pack): bool
    {
        if (! $pack || $pack['count'] <= 1 || ! $q) {
            return false;
        }
        foreach ($q as $v) {
            $pieces = $v * $pack['count'];
            if ($pieces < 0.75 || $pieces > 12) {
                return false;
            }
        }

        return true;
    }

    private static function bestPack(array $e): ?array
    {
        foreach ($e['purchases'] as $p) {
            if (($p['packaging']['count'] ?? 0) > 1) {
                return $p['packaging'];
            }
        }

        return null;
    }

    /** O nome da unidade base: a dose, a garrafa, a lata, ou a unidade da família. */
    private static function baseName(?string $type, ?string $dose, ?array $pack, array $desc): string
    {
        if ($type === 'contavel' && $dose) {
            return $dose;
        }
        if ($type === 'embalado_inteiro') {
            return ($pack['container'] ?? null) === 'lata' ? 'Lata' : (($pack['family'] ?? null) === 'L' ? 'Garrafa' : 'Unidade');
        }

        return UnitProposal::baseLabel(match ($type) { 'peso' => 'kg', 'volume' => 'L', 'embalado_peso' => $pack['family'] ?? 'kg', default => 'un' });
    }

    /** "5|kg" (resposta à quantidade da embalagem) → a embalagem com essa quantidade. */
    private static function packFromAnswer(array $answers, ?array $pack): ?array
    {
        $v = (string) ($answers['qtd_embalagem'] ?? '');
        if (! preg_match('/^\s*([\d.,]+)\s*\|\s*(kg|L|un)\s*$/', $v, $m)) {
            return null;
        }
        $q = (float) str_replace(',', '.', $m[1]);
        if ($q <= 0) {
            return null;
        }
        $container = $pack['container'] ?? 'embalagem';

        return ['family' => $m[2], 'each' => $m[2] === 'un' ? 1.0 : $q, 'count' => $m[2] === 'un' ? (int) $q : 1, 'quantity' => $q,
            'container' => $container, 'text' => "{$q} {$m[2]}", 'dose' => null,
            'label' => PackagingParser::label($m[2], $m[2] === 'un' ? 1.0 : $q, $m[2] === 'un' ? (int) $q : 1, $container === 'embalagem' ? null : $container)];
    }

    /** O nome curto do artigo para as perguntas (a primeira palavra, só para o texto; nunca decide nada). */
    private static function shortName(array $e): string
    {
        $w = preg_split('/[\s\-\/(]+/', trim((string) $e['description']))[0] ?? '';

        return $w !== '' ? mb_convert_case(mb_strtolower($w), MB_CASE_TITLE) : 'Este artigo';
    }

    private static function q(string $id, string $text, array $options): array
    {
        return ['id' => $id, 'kind' => 'choice', 'text' => $text, 'options' => array_map(fn ($o) => ['value' => $o[0], 'label' => $o[1]], $options), 'answer' => null];
    }

    private static function num(?float $v): string
    {
        return $v === null ? '' : rtrim(rtrim(number_format($v, 4, ',', ''), '0'), ',');
    }
}
