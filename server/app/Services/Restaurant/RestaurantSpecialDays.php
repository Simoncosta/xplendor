<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Models\Company;
use App\Models\ContentAnchor;
use App\Models\ContentSector;
use App\Models\EditorialOwnAnchor;
use App\Services\EditorialAnchorResolver;
use Carbon\CarbonImmutable;

/**
 * XPLENDOR — F3: dias especiais de uma empresa num intervalo (feriados nacionais e datas de
 * âncora), para os sinais não os tratarem como dias normais da semana (ajuste 1 da F3:
 * ficam fora da média das 8 semanas dos períodos fracos).
 *  · feriados nacionais de Portugal, calculados aqui (não dependem da Linha Editorial);
 *  · âncoras do ramo da empresa (incluindo as universais) e as próprias: as de um dia, e as
 *    de período só quando duram até 7 dias (uma época inteira, como os Santos Populares em
 *    todo o mês de junho, não é uma data especial).
 */
class RestaurantSpecialDays
{
    /** Períodos de âncora mais longos do que isto são épocas, não datas especiais. */
    public const MAX_PERIOD_DAYS = 7;

    /** @var array<int, array> âncoras por empresa (só durante este cálculo) */
    private array $anchorCache = [];

    public function __construct(private readonly EditorialAnchorResolver $resolver) {}

    /** @return array<string, string> data (Y-m-d) => nome, entre $from e $to (inclusive) */
    public function between(Company $company, string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();
        $out = [];
        foreach (range((int) $start->year, (int) $end->year) as $year) {
            foreach ($this->nationalHolidays($year) as $date => $name) {
                $out[$date] = $name;
            }
            foreach ($this->anchors($company) as $anchor) {
                $r = $this->resolver->resolve($anchor, $year);
                if ($r['type'] === 'day') {
                    $out[$r['date']] ??= $anchor->title;
                    continue;
                }
                $rs = CarbonImmutable::parse($r['start']);
                $re = CarbonImmutable::parse($r['end']);
                if ($rs->diffInDays($re) + 1 > self::MAX_PERIOD_DAYS) {
                    continue;
                }
                for ($d = $rs; $d->lte($re); $d = $d->addDay()) {
                    $out[$d->toDateString()] ??= $anchor->title;
                }
            }
        }
        $out = array_filter($out, fn ($date) => $date >= $start->toDateString() && $date <= $end->toDateString(), ARRAY_FILTER_USE_KEY);
        ksort($out);

        return $out;
    }

    /** Feriados nacionais obrigatórios em Portugal. @return array<string, string> */
    public function nationalHolidays(int $year): array
    {
        $easter = $this->resolver->easter($year);

        return [
            "{$year}-01-01" => 'Ano Novo',
            $easter->subDays(2)->toDateString() => 'Sexta-feira Santa',
            $easter->toDateString() => 'Páscoa',
            "{$year}-04-25" => 'Dia da Liberdade',
            "{$year}-05-01" => 'Dia do Trabalhador',
            $easter->addDays(60)->toDateString() => 'Corpo de Deus',
            "{$year}-06-10" => 'Dia de Portugal',
            "{$year}-08-15" => 'Assunção de Nossa Senhora',
            "{$year}-10-05" => 'Implantação da República',
            "{$year}-11-01" => 'Todos os Santos',
            "{$year}-12-01" => 'Restauração da Independência',
            "{$year}-12-08" => 'Imaculada Conceição',
            "{$year}-12-25" => 'Natal',
        ];
    }

    /** Âncoras herdadas do ramo (do caminho raiz até à folha) e as próprias da empresa. */
    private function anchors(Company $company): array
    {
        if (isset($this->anchorCache[$company->id])) {
            return $this->anchorCache[$company->id];
        }
        $anchors = [];
        $sector = $company->contentSector;
        if ($sector instanceof ContentSector) {
            $pathIds = array_map(static fn (ContentSector $s) => $s->id, $sector->pathFromRoot());
            $anchors = ContentAnchor::whereIn('sector_id', $pathIds)
                ->where(fn ($q) => $q->whereNull('country')->orWhere('country', 'PT'))
                ->get()->all();
        }
        $own = EditorialOwnAnchor::where('company_id', $company->id)->get()->all();

        return $this->anchorCache[$company->id] = [...$anchors, ...$own];
    }
}
