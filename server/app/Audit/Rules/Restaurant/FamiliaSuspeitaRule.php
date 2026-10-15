<?php

declare(strict_types=1);

namespace App\Audit\Rules\Restaurant;

use App\Audit\AuditFindingData;
use App\Audit\AuditScope;
use App\Audit\Fmt;
use Illuminate\Support\Str;

/**
 * Regra 6 · Família suspeita ou vasilhame fora das cauções (média):
 *  · um artigo de compra numa família diferente da maioria dos artigos parecidos (a mesma primeira
 *    palavra do nome normalizado; ex.: TOMATE CUBOS GULOSOS em VINHO, ALHO SECO em SUMOS);
 *  · vasilhame, barris vazios, grades e garrafas de vidro (tara) fora de "Cauções de vasilhames".
 */
final class FamiliaSuspeitaRule extends RestaurantRule
{
    private const MIN_GROUP = 4;
    private const MIN_MAJORITY = 3;
    private const MAJORITY_SHARE = 0.6;
    /** Vasilhame (a caução, não o produto): VASILHAME, BARRIL INOX/VAZIO, GRADE, GARRAFA VIDRO / TR, TARA. */
    private const DEPOSIT = '/\bVASILHAMES?\b|\bBARRIL\s+(INOX|VAZIO)\b|^\s*GRADES?\b|^\s*GARRAFAS?\b.*(\bVIDRO\b|\bTR\b)|\bTARA\b/';
    private const DEPOSIT_FAMILY = 'CAUCOES DE VASILHAMES';

    public function code(): string
    {
        return 'restauracao.familia_suspeita';
    }

    public function entityType(): string
    {
        return 'artigo';
    }

    public function title(): string
    {
        return 'Família suspeita ou vasilhame fora das cauções';
    }

    public function description(): string
    {
        return 'Artigos numa família diferente dos parecidos, e vasilhame fora de "Cauções de vasilhames".';
    }

    public function screens(): array
    {
        return ['artigos', 'familias'];
    }

    /** Os dois primeiros níveis da família: "COMPRAS › COMIDAS". */
    private static function level2(string $path): string
    {
        return implode(' › ', array_slice(explode(' › ', $path), 0, 2));
    }

    public static function isDeposit(?string $description, ?string $code = null): bool
    {
        $t = Str::upper(Str::ascii((string) $description));

        return preg_match(self::DEPOSIT, $t) === 1 || Str::upper(trim((string) $code)) === 'VASILHAME';
    }

    public function run(int $companyId, AuditScope $scope): iterable
    {
        $data = RestaurantAuditData::for($scope);
        $families = $data->families();
        $familyById = [];
        foreach ($families as $pw => $f) {
            $familyById[(int) $f->id] = (string) $pw;
        }
        $spend = [];
        foreach ($data->purchases() as $pid => $lines) {
            $spend[(string) $pid] = (int) round(array_sum(array_column($lines, 'total')) * 100);
        }
        $items = array_filter($data->catalog(), fn ($i) => $i->is_active && $i->forpurchase
            && ! in_array(mb_strtolower((string) $i->product_status), ['inativo', 'descontinuado'], true));
        $familyRef = fn ($i) => $i->family_id && isset($familyById[(int) $i->family_id]) ? ['familia', $familyById[(int) $i->family_id]] : null;

        // 1. Vasilhame fora das cauções de vasilhames.
        $deposit = [];
        foreach ($items as $pid => $i) {
            $pid = (string) $pid;
            if (! self::isDeposit($i->description, $i->code)) {
                continue;
            }
            $path = RestaurantAuditData::familyPath($i->family);
            if (str_contains(Str::upper(Str::ascii($path)), self::DEPOSIT_FAMILY)) {
                continue;
            }
            $deposit[(string) $pid] = true;

            yield new AuditFindingData(
                entityType: 'artigo', entityId: (string) $pid, entityLabel: $data->label((string) $pid),
                title: Fmt::title((string) $i->description, 'vasilhame fora das cauções'),
                why: 'O vasilhame paga-se e devolve-se. Fora das cauções, entra nos custos e no food cost como se fosse consumo.',
                suggestion: 'Mude o artigo para a família «Cauções de vasilhames» no PingWin. Se for mesmo um produto e não uma caução, ignore com esse motivo.',
                evidence: ['facts' => [
                    ['label' => 'Família de agora', 'value' => $path ?: '—'],
                    ['label' => 'Família certa', 'value' => 'Cauções de vasilhames'],
                    ['label' => 'Compras (12 meses)', 'value' => ($spend[(string) $pid] ?? 0) / 100, 'format' => 'eur'],
                ]],
                evidenceKey: 'vasilhame',
                signature: $path,
                weightCents: ($spend[(string) $pid] ?? 0) ?: null,
                fixUrl: self::articleUrl((string) $pid), fixLabel: 'Abrir o artigo',
                refs: array_values(array_filter([['artigo', (string) $pid], $familyRef($i)])),
                seen: sprintf('«%s» parece vasilhame: a caução da garrafa, da grade ou do barril. Está em %s, e não nas cauções de vasilhame.', $i->description, $path ?: 'nenhuma família'),
                stake: ($spend[(string) $pid] ?? 0) ? sprintf('Não há um valor direto em jogo. A relevância são as compras deste artigo em 12 meses: %s.', Fmt::cents($spend[(string) $pid])) : 'Não há um valor direto em jogo: sem compras deste artigo em 12 meses.',
            );
        }

        // 2. Família diferente da maioria dos artigos parecidos (a mesma primeira palavra).
        $groups = [];
        foreach ($items as $pid => $i) {
            // Só os artigos só de compra: os de venda têm outra árvore de famílias (a da carta).
            if (isset($deposit[(string) $pid]) || $i->forsale || RestaurantAuditData::familyPath($i->family) === '') {
                continue;
            }
            $tokens = RestaurantAuditData::nameKeyTokens($i->description);
            $head = $tokens[0] ?? '';
            if (mb_strlen($head) < 3) {
                continue;
            }
            $groups[$head][(string) $pid] = RestaurantAuditData::familyPath($i->family);
        }
        foreach ($groups as $head => $members) {
            if (count($members) < self::MIN_GROUP) {
                continue;
            }
            // A maioria conta-se no 2.º nível (ex.: COMPRAS › COMIDAS vs COMPRAS › BEBIDAS).
            $counts = array_count_values(array_map(fn ($p) => self::level2($p), $members));
            arsort($counts);
            $major = (string) array_key_first($counts);
            $n = (int) ($counts[$major] ?? 0);
            if ($major === '' || $n < self::MIN_MAJORITY || $n / count($members) < self::MAJORITY_SHARE) {
                continue;
            }
            $majorTop = explode(' › ', $major)[0];
            foreach ($members as $pid => $path) {
                $pid = (string) $pid;
                $level2 = self::level2($path);
                // Na mesma secção que a maioria, partilhada por outros parecidos, ou noutra raiz
                // (ex.: RECURSOS HUMANOS › STAFF MEAL é uma escolha de centro de custo, não um erro).
                if ($level2 === $major || ($counts[$level2] ?? 0) > 1 || explode(' › ', $level2)[0] !== $majorTop) {
                    continue;
                }
                $i = $data->catalog()[$pid];
                $neighbours = [];
                foreach ($members as $other => $op) {
                    if ((string) $other !== $pid) {
                        $o = $data->catalog()[$other];
                        $neighbours[] = ['code' => $o->code ? ltrim((string) $o->code, "\u{FEFF}") : null, 'description' => $o->description, 'family' => $op];
                    }
                }

                yield new AuditFindingData(
                    entityType: 'artigo', entityId: (string) $pid, entityLabel: $data->label((string) $pid),
                    title: Fmt::title((string) $i->description, 'família diferente da dos artigos parecidos'),
                    why: 'Com a família errada, os custos e os relatórios por família ficam misturados (por exemplo, um ingrediente a contar como bebida).',
                    suggestion: sprintf('Mude o artigo para %s no PingWin. Se a família de agora estiver certa, ignore com esse motivo.', $major),
                    evidence: [
                        'facts' => [
                            ['label' => 'Família de agora', 'value' => $path ?: '—'],
                            ['label' => 'Família dos parecidos', 'value' => "{$major} ({$n} de " . (count($members) - 1) . ')'],
                        ],
                        'table' => [
                            'title' => "Artigos parecidos («{$head}…»)",
                            'columns' => [['key' => 'code', 'label' => 'Código'], ['key' => 'description', 'label' => 'Artigo'], ['key' => 'family', 'label' => 'Família']],
                            'rows' => array_slice($neighbours, 0, 20),
                        ],
                    ],
                    evidenceKey: 'familia',
                    signature: "{$path}|{$major}",
                    weightCents: ($spend[$pid] ?? 0) ?: null,
                    fixUrl: self::articleUrl($pid), fixLabel: 'Abrir o artigo',
                    refs: array_values(array_filter([['artigo', $pid], $familyRef($i)])),
                    seen: sprintf('Está em %s. %d dos outros %d artigos começados por «%s» estão em %s.', $path, $n, count($members) - 1, $head, $major),
                    stake: ($spend[$pid] ?? 0) ? sprintf('Não há um valor direto em jogo. A relevância são as compras deste artigo em 12 meses: %s.', Fmt::cents($spend[$pid])) : 'Não há um valor direto em jogo: sem compras deste artigo em 12 meses.',
                );
            }
        }
    }
}
