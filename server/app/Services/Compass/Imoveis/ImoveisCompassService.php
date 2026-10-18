<?php

declare(strict_types=1);

namespace App\Services\Compass\Imoveis;

use App\Models\Company;
use App\Models\CompassExcludedSubject;
use App\Models\CompassSignal;
use App\Models\CompassSignalAction;
use App\Models\CompassText;
use App\Models\IneHousingPrice;
use App\Models\User;
use App\Services\Compass\CompassPlayChooser;
use App\Services\Compass\CompassWhen;
use App\Services\Compass\CompassWhere;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Bússola do imobiliário: a página (a mesma forma da do stand: topo, jogadas, blocos). No máximo
 * 3 jogadas, uma por tipo. Os preços dos imóveis e a referência do INE são públicos e ficam
 * visíveis; sem Finanças só o valor da carteira fica escondido. Nunca se sugere um preço; a
 * referência do INE é de toda a freguesia e diz-se sempre com a fonte e o período.
 */
final class ImoveisCompassService
{
    public const PROVIDER = ImoveisSignalService::PROVIDER;
    public const TYPES = ['all' => 'Todos', 'venda' => 'Venda', 'arrendamento' => 'Arrendamento'];
    public const PLAY_ORDER = ['bce' => 'bce_day', 'new' => 'publish_new', 'review_price' => 'review_price', 'closed' => 'ask_testimonial', 'zone' => 'show_zone', 'interest' => 'use_interest'];
    public const MAX_ITEMS = 6;
    public const TYPE_META = [
        'bce_day' => ['label' => 'Decisão do BCE', 'icon' => 'ri-bank-line', 'color' => 'info'],
        'publish_new' => ['label' => 'Imóvel novo', 'icon' => 'ri-home-smile-line', 'color' => 'success'],
        'review_price' => ['label' => 'Imóvel parado', 'icon' => 'ri-price-tag-3-line', 'color' => 'warning'],
        'ask_testimonial' => ['label' => 'Testemunho', 'icon' => 'ri-chat-quote-line', 'color' => 'primary'],
        'show_zone' => ['label' => 'A zona', 'icon' => 'ri-map-pin-line', 'color' => 'info'],
        'use_interest' => ['label' => 'Interesse', 'icon' => 'ri-fire-line', 'color' => 'danger'],
    ];
    public const TEMPLATES = [
        'bce_day' => 'Publique hoje uma reação à decisão do BCE, com a ligação ao comunicado oficial.',
        'publish_new' => 'Publique o imóvel novo com as melhores fotografias e a classe energética.',
        'review_price' => 'Reveja o preço do imóvel parado com o proprietário, com os dias em carteira e a referência do INE quando existe.',
        'ask_testimonial' => 'Peça um testemunho curto a quem acabou de fechar, com autorização para publicar.',
        'show_zone' => 'Mostre a zona: serviços, transportes e o dia a dia perto dos imóveis.',
        'use_interest' => 'Publique de novo o imóvel com mais contactos, com uma visita guiada em vídeo.',
    ];
    public const POST_NOTE = 'Escolha as fotografias do imóvel na Linha Editorial, depois de criar a ideia.';

    public function __construct(private readonly ImoveisSignalService $signals, private readonly CompassWhere $where) {}

    public function payload(int $companyId, ?User $user, string $type = 'all', bool $playsOnly = false, bool $fin = true): array
    {
        $type = array_key_exists($type, self::TYPES) ? $type : 'all';
        $enabled = ImoveisSignalService::isEnabled($companyId);
        $company = Company::find($companyId);
        $base = ['enabled' => $enabled, 'provider' => self::PROVIDER, 'company' => (string) ($company?->trade_name ?: $company?->fiscal_name),
            'type' => $type, 'types' => array_map(fn ($k, $l) => ['key' => $k, 'label' => $l], array_keys(self::TYPES), self::TYPES)];
        if (! $enabled) {
            return $base + ['computed_at' => null, 'top' => null, 'plays' => []];
        }
        $this->signals->ensureFresh($companyId);
        $today = CarbonImmutable::now('Europe/Lisbon')->startOfDay();
        $facts = ImoveisFacts::forCompany($companyId, $today);
        $signals = $this->visibleSignals($companyId, $type, $today);
        $out = $base + [
            'computed_at' => ImoveisSignalService::computedAt($companyId)?->toIso8601String(),
            'data_until' => $today->toDateString(),
            'window' => ['from' => $today->subDays(ImoveisFacts::POST_DAYS - 1)->toDateString(), 'to' => $today->toDateString()],
            'top' => $playsOnly ? null : $this->top(self::filter($facts, $type), $companyId, $today, $fin),
            'plays' => $this->plays($companyId, $signals, $type, $today, $facts),
        ];
        if (! $playsOnly) {
            $out['blocks'] = $this->blocks(self::filter($facts, $type), $companyId, $today);
        }

        return $out;
    }

    public static function filter(array $facts, string $type): array
    {
        return $type === 'all' ? $facts : array_filter($facts, fn ($f) => $f['listing'] === $type);
    }

    public function visibleSignals(int $companyId, string $type, CarbonImmutable $today): Collection
    {
        $hidden = array_flip(CompassSignalAction::hiddenKeys(self::PROVIDER, $companyId, $today->toDateString()));
        $excluded = CompassExcludedSubject::activeIds(self::PROVIDER, $companyId, 'property');

        return CompassSignal::where('provider', self::PROVIDER)->where('company_id', $companyId)->orderBy('id')->get()
            ->reject(fn ($s) => isset($hidden[$s->signal_key]) || ($s->subject_type === 'property' && isset($excluded[(int) $s->subject_id])))
            ->filter(fn ($s) => $type === 'all' || ! isset($s->numbers['listing']) || $s->numbers['listing'] === $type)->values();
    }

    /** @return array<string, list<array>> */
    public function candidateGroups(Collection $signals, string $scope = 'all'): array
    {
        $out = [];
        foreach (self::PLAY_ORDER as $signalType => $playType) {
            $members = $signals->where('type', $signalType)->sortByDesc(fn ($s) => self::memberStrength($s))->values();
            if ($members->isNotEmpty()) {
                $out[$playType][] = ['key' => "{$playType}:{$scope}", 'type' => $playType, 'members' => $members];
            }
        }

        return $out;
    }

    public static function memberStrength(CompassSignal $s): array
    {
        $n = (array) $s->numbers;

        return match ($s->type) {
            'review_price' => [($n['position'] ?? null) === 'above_ref' ? 1 : 0, (float) ($n['diff_pct'] ?? 0), (int) ($n['days'] ?? 0) - (int) ($n['threshold'] ?? 0)],
            'interest' => [(int) ($n['contacts_30'] ?? 0), (int) ($n['visits_30'] ?? 0)],
            'zone' => [(int) ($n['properties'] ?? 0)],
            'closed' => [-(int) ($n['closed_days'] ?? 0)],
            'new' => [-(int) ($n['days'] ?? 0)],
            default => [0],
        };
    }

    public function strength(array $group): array
    {
        $pos = array_search($group['type'], array_values(self::PLAY_ORDER), true);

        return [10 - (int) $pos, self::memberStrength($group['members']->first())];
    }

    public function choose(array $groups): array
    {
        return CompassPlayChooser::choose($groups, fn ($g) => $this->strength($g));
    }

    public static function textKey(array $group): string
    {
        $keys = $group['members']->pluck('signal_key')->sort()->implode('|');

        return "{$group['key']}#" . substr(md5($keys), 0, 12);
    }

    private function plays(int $companyId, Collection $signals, string $type, CarbonImmutable $today, array $facts): array
    {
        $texts = CompassText::where('provider', self::PROVIDER)->where('company_id', $companyId)->pluck('text', 'play_key')->all();
        $where = $this->where->networks($companyId);

        return array_map(fn ($g) => $this->presentPlay($g, $texts, $where, $today, $facts), $this->choose($this->candidateGroups($signals, $type)));
    }

    private function presentPlay(array $g, array $texts, array $where, CarbonImmutable $today, array $facts): array
    {
        $members = $g['members'];
        $first = $members->first();
        $n = (array) $first->numbers;
        $single = $members->count() === 1 && $first->subject_type === 'property';
        $type = $g['type'];
        $title = match ($type) {
            'bce_day' => 'Explicar a decisão do BCE no próprio dia',
            'publish_new' => $single ? "Publicar o imóvel novo: {$n['label']}" : "Publicar os {$members->count()} imóveis novos",
            'review_price' => $single ? "Rever o preço de {$n['label']}" : "Rever o preço de {$members->count()} imóveis parados",
            'ask_testimonial' => $single ? "Pedir o testemunho de quem fechou: {$n['label']}" : "Pedir o testemunho de quem fechou ({$members->count()} imóveis)",
            'show_zone' => "Mostrar a zona: {$n['label']}",
            'use_interest' => $single ? "Aproveitar o interesse em {$n['label']}" : "Aproveitar o interesse em {$members->count()} imóveis",
        };
        $verb = $type === 'review_price' ? 'Rever' : ($type === 'bce_day' ? 'Publicar' : 'Publicar');
        $publish = $type === 'bce_day' || $type === 'review_price' ? $today : $today->addDay();
        $key = self::textKey($g);
        $publishes = $type !== 'review_price';
        $items = $members->filter(fn ($s) => $s->subject_type === 'property')->take(self::MAX_ITEMS)
            ->map(fn ($s) => ['property_id' => (int) $s->subject_id, 'label' => (string) ($s->numbers['label'] ?? ''), 'detail' => self::itemDetail($s)])->values()->all();
        $sentences = $members->map(fn ($s) => self::sentence($s))->all();
        if ($members->count() > self::MAX_ITEMS) {
            $sentences = array_slice($sentences, 0, self::MAX_ITEMS);
            $sentences[] = 'E mais ' . ($members->count() - self::MAX_ITEMS) . ' imóveis na mesma situação.';
        }

        return [
            'key' => $g['key'], 'type' => $type, 'type_label' => self::TYPE_META[$type]['label'], 'icon' => self::TYPE_META[$type]['icon'], 'color' => self::TYPE_META[$type]['color'],
            'locations' => [], 'location_id' => 0, 'title' => $title,
            'confidence' => $members->contains(fn ($s) => $s->confidence === 'alta') ? 'alta' : 'media',
            'number' => self::bigNumber($g), 'bars' => [], 'items' => $items,
            'what' => isset($texts[$key]) ? ['text' => $texts[$key], 'source' => 'ai'] : ['text' => self::TEMPLATES[$type], 'source' => 'template'],
            'where' => $publishes ? $where + ['ad' => null] : ['networks' => [], 'connected' => true, 'ad' => 'Com o proprietário, na ficha do imóvel'],
            'when' => CompassWhen::present($publish, $today, null, $verb),
            'detail' => ['sentences' => $sentences, 'sample' => self::sample($members),
                'confidence' => $members->take(self::MAX_ITEMS)->map(fn ($s) => ['title' => (string) ($s->numbers['label'] ?? ''), 'confidence' => $s->confidence])->values()->all()],
            'signal_keys' => $members->pluck('signal_key')->values()->all(),
            'theme' => match ($type) {
                'bce_day' => 'A decisão do BCE de hoje e o que diz o comunicado oficial',
                'publish_new' => 'Imóvel novo em carteira: ' . ($n['label'] ?? ''),
                'review_price' => 'Rever o preço com o proprietário',
                'ask_testimonial' => 'Testemunho de quem fechou negócio',
                'show_zone' => 'A zona: ' . ($n['label'] ?? ''),
                'use_interest' => 'O imóvel com mais procura: ' . ($n['label'] ?? ''),
            },
            'format' => 'Carrossel',
            'property_id' => $single ? (int) $first->subject_id : null,
            'parish_id' => $type === 'show_zone' ? (int) $first->subject_id : null,
            'link' => $type === 'bce_day' ? ($n['url'] ?? null) : null,
            'actions' => match ($type) {
                'review_price' => ['view_property'],
                'bce_day', 'show_zone' => ['create', 'suggest'],
                default => $single ? ['create', 'suggest', 'view_property'] : ['create', 'suggest'],
            },
            'post_note' => $publishes && $type !== 'bce_day' && $type !== 'show_zone' ? self::POST_NOTE : null,
        ];
    }

    private static function bigNumber(array $g): array
    {
        $members = $g['members'];
        $n = (array) $members->first()->numbers;

        return match ($g['type']) {
            'bce_day' => ['value' => 'Hoje', 'caption' => 'decisão de política monetária'],
            'publish_new' => ['value' => (string) $members->count(), 'caption' => $members->count() === 1 ? 'imóvel novo em carteira' : 'imóveis novos em carteira'],
            'review_price' => ($n['position'] ?? null) === 'above_ref'
                ? ['value' => self::signedPct((float) $n['diff_pct']), 'caption' => 'preço por m² face ao valor mediano da freguesia (INE)']
                : ['value' => "{$n['days']} dias", 'caption' => 'em carteira'],
            'ask_testimonial' => ['value' => (string) $members->count(), 'caption' => $members->count() === 1 ? 'fechado há menos de 30 dias' : 'fechados há menos de 30 dias'],
            'show_zone' => ['value' => (string) ($n['properties'] ?? 0), 'caption' => 'imóveis em carteira na freguesia'],
            'use_interest' => ['value' => (string) ($n['contacts_30'] ?? 0), 'caption' => ((int) ($n['contacts_30_site'] ?? 0) + (int) ($n['visits_30_site'] ?? 0)) > 0
                ? 'contactos em 30 dias (' . (int) $n['contacts_30_site'] . ' do site, ' . (int) ($n['contacts_30_manual'] ?? 0) . ' de outros canais)'
                : 'contactos em 30 dias (contagem manual)'],
        };
    }

    private static function itemDetail(CompassSignal $s): string
    {
        return self::sentence($s);
    }

    /** Uma frase descritiva por sinal, com os números e a fonte; nunca um preço sugerido nem uma causa. */
    public static function sentence(CompassSignal $s): string
    {
        $n = (array) $s->numbers;

        return match ($s->type) {
            'new' => "{$n['label']}: entrou em carteira há {$n['days']} " . ((int) $n['days'] === 1 ? 'dia' : 'dias') . ' e ainda não tem publicação.'
                . (empty($n['energy_class']) || $n['energy_class'] === 'em_processo' ? ' Falta a classe energética, obrigatória no anúncio.' : ''),
            'review_price' => "{$n['label']}: {$n['days']} dias em carteira (limiar de {$n['threshold']} dias para " . ($n['listing'] === 'arrendamento' ? 'arrendamento' : 'venda') . ').'
                . match (true) {
                    ($n['diff_pct'] ?? null) !== null => ' Preço por m² ' . self::signedPct((float) $n['diff_pct']) . " face ao valor mediano das vendas na freguesia ({$n['ine_median_m2']} euros por m², INE, {$n['ine_period']}).",
                    ($n['ine_reason'] ?? null) === 'arrendamento' => ' A referência do INE é de vendas: não se compara no arrendamento.',
                    ! empty($n['muni_median_m2']) => " Sem referência do INE para a freguesia; no concelho de {$n['municipality']}, {$n['muni_median_m2']} euros por m² ({$n['muni_period']}), só como nota, sem comparar.",
                    default => ' Sem referência do INE para a freguesia.',
                },
            'closed' => "{$n['label']}: fechado há {$n['closed_days']} " . ((int) $n['closed_days'] === 1 ? 'dia' : 'dias') . ', sem publicação de testemunho.',
            'zone' => "{$n['label']}: {$n['properties']} " . ((int) $n['properties'] === 1 ? 'imóvel' : 'imóveis') . ' em carteira, sem publicação sobre a zona nos últimos 30 dias.'
                . (! empty($n['ine_median_m2']) ? " O valor mediano das vendas na freguesia foi {$n['ine_median_m2']} euros por m² ({$n['ine_period']}), segundo o INE." : ''),
            'interest' => ((int) ($n['contacts_30_site'] ?? 0) + (int) ($n['visits_30_site'] ?? 0)) > 0
                ? "{$n['label']}: {$n['contacts_30']} contactos e {$n['visits_30']} visitas nos últimos 30 dias ({$n['contacts_30_site']} contactos e {$n['visits_30_site']} pedidos de visita pelo site; "
                    . ($n['contacts_30_manual'] ?? 0) . ' contactos e ' . ($n['visits_30_manual'] ?? 0) . ' visitas de outros canais, contados à mão).'
                : "{$n['label']}: {$n['contacts_30']} contactos e {$n['visits_30']} visitas marcadas nos últimos 30 dias (contagem manual, atualizada há {$n['updated_days']} dias).",
            'bce' => 'Hoje é dia de decisão de política monetária do BCE (anúncio às 13:15, hora de Lisboa).' . (! empty($n['url']) ? ' O comunicado oficial já está publicado.' : ''),
            default => (string) ($n['label'] ?? ''),
        };
    }

    private static function sample(Collection $members): string
    {
        $n = (array) $members->first()->numbers;
        if (! empty($n['ine_period'])) {
            return IneHousingPrice::ATTRIBUTION . ", {$n['ine_period']}.";
        }

        return $members->first()->type === 'bce' ? 'Fonte: Banco Central Europeu, calendário das reuniões de política monetária.' : '';
    }

    private function top(array $facts, int $companyId, CarbonImmutable $today, bool $fin): array
    {
        $sale = array_filter($facts, fn ($f) => $f['listing'] === 'venda');
        $eligible = array_filter($facts, fn ($f) => $f['position'] !== null);
        $above = array_filter($eligible, fn ($f) => $f['position'] === 'above_ref');
        $new30 = count(array_filter($facts, fn ($f) => $f['days'] < ImoveisFacts::POST_DAYS));
        $closed30 = count(ImoveisFacts::closed($companyId, $today));
        $value = array_sum(array_column($sale, 'price'));
        $numbers = [
            ['kind' => 'portfolio_count', 'label' => 'Imóveis em carteira', 'value' => count($facts), 'format' => 'int', 'caption' => 'disponíveis e reservados', 'icon' => 'ri-home-4-line'],
            $this->money(['kind' => 'portfolio_value', 'label' => 'Valor dos imóveis à venda', 'value' => $value * 100, 'format' => 'eur', 'caption' => 'soma dos preços pedidos', 'icon' => 'ri-money-euro-circle-line'], $fin),
            ['kind' => 'avg_days', 'label' => 'Dias em carteira (média)', 'value' => $facts ? (int) round(array_sum(array_column($facts, 'days')) / count($facts)) : 0, 'format' => 'int', 'caption' => 'desde a entrada', 'icon' => 'ri-time-line'],
            ['kind' => 'above_reference', 'label' => 'Acima da referência do INE', 'value' => $eligible ? round(count($above) / count($eligible) * 100, 1) : null, 'format' => 'pct',
                'caption' => 'de ' . count($eligible) . ' ' . (count($eligible) === 1 ? 'imóvel' : 'imóveis') . ' com referência da freguesia', 'icon' => 'ri-scales-3-line'],
            ['kind' => 'new_30', 'label' => 'Entradas em 30 dias', 'value' => $new30, 'format' => 'int', 'caption' => 'imóveis novos em carteira', 'icon' => 'ri-add-circle-line'],
            ['kind' => 'closed_30', 'label' => 'Fechados em 30 dias', 'value' => $closed30, 'format' => 'int', 'caption' => 'vendas e arrendamentos', 'icon' => 'ri-checkbox-circle-line'],
        ];
        $noRef = count(array_filter($facts, fn ($f) => $f['ine_reason'] === 'sem_referencia'));
        $notes = array_values(array_filter([
            $noRef > 0 ? "{$noRef} " . ($noRef === 1 ? 'imóvel à venda está' : 'imóveis à venda estão') . ' numa freguesia sem referência publicada pelo INE: não se compara o preço.' : null,
            'Os contactos e as visitas são contagens manuais, atualizadas na ficha de cada imóvel.',
        ]));

        return ['title' => 'A carteira de hoje', 'context' => 'Carteira de hoje; referência do INE do último trimestre publicado; contactos e visitas dos últimos 30 dias (contagens manuais).',
            'numbers' => $numbers, 'notes' => $notes];
    }

    private function money(array $number, bool $fin): array
    {
        return $fin ? $number : ['value' => null, 'hidden' => true] + $number;
    }

    private function blocks(array $facts, int $companyId, CarbonImmutable $today): array
    {
        $groups = [];
        foreach (['venda' => 'Venda', 'arrendamento' => 'Arrendamento'] as $listing => $label) {
            $list = array_filter($facts, fn ($f) => $f['listing'] === $listing);
            $t = ImoveisFacts::STALE_DAYS[$listing];
            $groups[] = ['group' => $listing, 'label' => $label, 'threshold' => $t, 'total' => count($list), 'bands' => [
                ['label' => 'Até 30 dias', 'count' => count(array_filter($list, fn ($f) => $f['days'] <= 30))],
                ['label' => "31 a {$t} dias", 'count' => count(array_filter($list, fn ($f) => $f['days'] > 30 && $f['days'] < $t))],
                ['label' => "{$t} dias ou mais", 'count' => count(array_filter($list, fn ($f) => $f['days'] >= $t))],
            ]];
        }
        $byDays = $facts;
        usort($byDays, fn ($a, $b) => $b['days'] <=> $a['days']);
        $eligible = array_filter($facts, fn ($f) => $f['position'] !== null);
        $counts = ['below' => 0, 'aligned' => 0, 'above' => 0];
        foreach ($eligible as $f) {
            $counts[match ($f['position']) { 'below_ref' => 'below', 'above_ref' => 'above', default => 'aligned' }]++;
        }
        $topAbove = array_values(array_filter($eligible, fn ($f) => $f['position'] === 'above_ref'));
        usort($topAbove, fn ($a, $b) => $b['diff_pct'] <=> $a['diff_pct']);
        $outside = array_count_values(array_filter(array_map(fn ($f) => $f['ine_reason'], $facts)));
        $zones = array_values(ImoveisFacts::zones($facts, $companyId, $today));
        $interest = array_values(array_filter($facts, fn ($f) => $f['interest_days'] !== null));
        usort($interest, fn ($a, $b) => [$b['contacts_30'], $b['visits_30']] <=> [$a['contacts_30'], $a['visits_30']]);
        $forgotten = array_values(array_filter($facts, fn ($f) => $f['days'] > ImoveisFacts::POST_DAYS && ! $f['posted_30']));
        usort($forgotten, fn ($a, $b) => $b['days'] <=> $a['days']);
        $period = collect($facts)->pluck('ine_period')->filter()->first();

        return [
            'aging' => ['headline' => 'Há quanto tempo estão em carteira', 'groups' => $groups,
                'oldest' => array_map(fn ($f) => ['property_id' => $f['property_id'], 'label' => $f['label'], 'days' => $f['days'], 'threshold' => $f['threshold'], 'stale' => $f['stale']], array_slice($byDays, 0, 5))],
            'price' => ['headline' => 'Preço por m² face à referência do INE (só venda, só a freguesia)', 'eligible' => count($eligible), 'counts' => $counts,
                'top_above' => array_map(fn ($f) => ['property_id' => $f['property_id'], 'label' => $f['label'], 'diff_pct' => $f['diff_pct'], 'price_m2' => $f['price_m2'],
                    'median' => $f['ine_median_m2'], 'period' => $f['ine_period'], 'days' => $f['days']], array_slice($topAbove, 0, 5)),
                'outside' => $outside, 'attribution' => $period ? IneHousingPrice::ATTRIBUTION . ", {$period}." : null],
            'zones' => ['headline' => 'As zonas da carteira', 'rows' => array_map(fn ($z) => ['parish_id' => $z['parish_id'], 'label' => $z['label'], 'municipality' => $z['municipality'],
                'properties' => $z['properties'], 'median' => $z['ine_median_m2'], 'period' => $z['ine_period'], 'posted_30' => $z['posted_30']], $zones)],
            'interest' => collect($facts)->contains(fn ($f) => ((int) ($f['contacts_30_site'] ?? 0) + (int) ($f['visits_30_site'] ?? 0)) > 0)
                ? ['headline' => 'Contactos e visitas (do site e de outros canais)', 'note' => 'O site conta sozinho os contactos e os cliques no WhatsApp de cada imóvel; os outros canais (o portal da rede, o telefone, as indicações) contam-se à mão na ficha do imóvel. Os números são o total dos últimos 30 dias.',
                'rows' => array_map(fn ($f) => ['property_id' => $f['property_id'], 'label' => $f['label'], 'contacts' => $f['contacts_30'], 'visits' => $f['visits_30'], 'updated_days' => $f['interest_days']], array_slice($interest, 0, 8))]
                : ['headline' => 'Contactos e visitas (contagens manuais)', 'note' => 'Os números são atualizados à mão na ficha de cada imóvel.',
                'rows' => array_map(fn ($f) => ['property_id' => $f['property_id'], 'label' => $f['label'], 'contacts' => $f['contacts_30'], 'visits' => $f['visits_30'], 'updated_days' => $f['interest_days']], array_slice($interest, 0, 8))],
            'closed' => ['headline' => 'Fechados nos últimos 30 dias', 'rows' => array_values(array_map(fn ($c) => $c, ImoveisFacts::closed($companyId, $today)))],
            'forgotten' => ['headline' => 'Há mais de 30 dias sem publicação', 'items' => array_map(fn ($f) => ['property_id' => $f['property_id'], 'label' => $f['label'], 'days' => $f['days']], array_slice($forgotten, 0, 8)),
                'total' => count($forgotten)],
            'footer' => [
                'A referência do INE é o valor mediano das vendas de toda a freguesia nos últimos 12 meses, não de imóveis comparáveis.',
                'A Bússola nunca sugere um preço: mostra os dias em carteira e a referência oficial, com a fonte e o período.',
                'A classe energética é obrigatória em qualquer anúncio de venda ou arrendamento (Decreto-Lei 101-D/2020, artigo 18.º).',
                'A carteira não guarda dados de proprietários, compradores nem inquilinos.',
            ],
        ];
    }

    private static function signedPct(float $v): string
    {
        return ($v > 0 ? '+' : ($v < 0 ? '−' : '')) . number_format(abs($v), 1, ',', ' ') . '%';
    }
}
