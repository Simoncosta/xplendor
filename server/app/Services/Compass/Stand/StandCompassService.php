<?php

declare(strict_types=1);

namespace App\Services\Compass\Stand;

use App\Constants\StockThresholds;
use App\Models\Company;
use App\Models\CompassExcludedSubject;
use App\Models\CompassSignal;
use App\Models\CompassSignalAction;
use App\Models\CompassText;
use App\Models\User;
use App\Services\Compass\CompassPlayChooser;
use App\Services\Compass\CompassWhen;
use App\Services\Compass\CompassWhere;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Bússola do stand: a página (o topo, as 3 jogadas da semana e os blocos) e o resumo do
 * dashboard do stand (só as jogadas).
 *
 *  · As jogadas, por ordem de prioridade (primeiro o dinheiro a perder, depois a força do
 *    sinal): parar o anúncio, rever o preço, renovar o stock parado, aproveitar o interesse e
 *    dar palco. Os veículos com o mesmo sinal juntam-se numa só jogada; no máximo uma por tipo
 *    e 3 no total. Nunca se sugere um preço. O "Quando" nunca é uma data passada.
 *  · Sem Finanças, o servidor não envia o valor do stock, o gasto em anúncios nem o custo por
 *    contacto; o preço de cada veículo e a mediana dos comparáveis ficam (são públicos).
 *  · Nunca há dados pessoais: só contagens de vistas, contactos e leads.
 * Os números descrevem o que aconteceu; não dizem porquê.
 */
final class StandCompassService
{
    public const PROVIDER = StandSignalService::PROVIDER;
    public const TYPES = ['all' => 'Todos', 'car' => 'Carros', 'motorhome' => 'Autocaravanas'];
    /** Do sinal para a jogada, pela ordem de prioridade. */
    public const PLAY_ORDER = ['stop_ad' => 'stop_ad', 'review_price' => 'review_price', 'stale' => 'renew_stock', 'interest' => 'use_interest', 'stage' => 'give_stage'];
    public const TYPE_META = [
        'stop_ad' => ['label' => 'Anúncio a rever', 'icon' => 'ri-pause-circle-line', 'color' => 'danger'],
        'review_price' => ['label' => 'Preço acima do mercado', 'icon' => 'ri-price-tag-3-line', 'color' => 'warning'],
        'renew_stock' => ['label' => 'Stock parado', 'icon' => 'ri-time-line', 'color' => 'info'],
        'use_interest' => ['label' => 'Interesse sem contacto', 'icon' => 'ri-eye-line', 'color' => 'primary'],
        'give_stage' => ['label' => 'Para dar palco', 'icon' => 'ri-star-line', 'color' => 'success'],
    ];
    public const TEMPLATES = [
        'stop_ad' => 'Pause o anúncio no Gestor de Anúncios da Meta.',
        'review_price' => 'Reveja o preço face aos veículos comparáveis no mercado.',
        'renew_stock' => 'Mostre os veículos que estão há mais tempo no stand, com fotografias novas.',
        'use_interest' => 'Publique o veículo com as melhores fotografias e um contacto direto bem visível.',
        'give_stage' => 'Dê palco ao veículo nas redes sociais, com as melhores fotografias.',
    ];
    public const MAX_ITEMS = 6;

    public function __construct(
        private readonly StandSignalService $signals,
        private readonly StandFacts $factsService,
        private readonly CompassWhere $where,
    ) {}

    /**
     * @param string $type "all", "car" ou "motorhome"
     * @param bool $fin a pessoa vê os valores financeiros
     */
    public function payload(int $companyId, ?User $user, string $type = 'all', bool $playsOnly = false, bool $fin = true): array
    {
        $type = array_key_exists($type, self::TYPES) ? $type : 'all';
        $this->signals->ensureFresh($companyId);
        $company = Company::findOrFail($companyId);
        $base = [
            'enabled' => StandSignalService::isEnabled($companyId),
            'provider' => self::PROVIDER,
            'company' => (string) ($company->trade_name ?: $company->fiscal_name),
            'type' => $type,
            'types' => array_map(fn ($k, $l) => ['key' => $k, 'label' => $l], array_keys(self::TYPES), self::TYPES),
        ];
        if (! $base['enabled']) {
            return $base + ['computed_at' => null, 'top' => null, 'plays' => []];
        }
        $today = CarbonImmutable::now('Europe/Lisbon')->startOfDay();
        $allFacts = $this->factsService->forCompany($companyId, $today);
        $facts = StandFacts::filter($allFacts, $type);
        $signals = $this->visibleSignals($companyId, $type, $today, $allFacts);
        $computedAt = StandSignalService::computedAt($companyId);

        $out = $base + [
            'computed_at' => $computedAt?->toIso8601String(),
            'data_until' => $today->toDateString(),
            'window' => ['from' => $today->subDays(StandFacts::WINDOW_DAYS - 1)->toDateString(), 'to' => $today->toDateString()],
            'top' => $playsOnly ? null : $this->top($company, $facts, $this->adRows($companyId, $type, $today), $fin),
            'plays' => $this->plays($companyId, $user, $signals, $type, $today, $fin),
        ];
        if ($playsOnly) {
            return $out;
        }

        return $out + ['blocks' => $this->blocks($facts, $this->adRows($companyId, $type, $today), $fin)];
    }

    /** O gasto por veículo das 4 semanas (também os vendidos), do tipo escolhido. */
    private function adRows(int $companyId, string $type, CarbonImmutable $today): array
    {
        return array_values(array_filter($this->factsService->adRows($companyId, $today), fn ($r) => $type === 'all' || $r['group'] === $type));
    }

    /** Os sinais visíveis: sem os ignorados, sem os veículos excluídos de vez, do tipo escolhido. */
    public function visibleSignals(int $companyId, string $type, CarbonImmutable $today, array $facts = []): Collection
    {
        $hidden = array_flip(CompassSignalAction::hiddenKeys(self::PROVIDER, $companyId, $today->toDateString()));
        $excluded = CompassExcludedSubject::activeIds(self::PROVIDER, $companyId, 'car');

        return CompassSignal::where('provider', self::PROVIDER)->where('company_id', $companyId)->orderBy('id')->get()
            ->reject(fn (CompassSignal $s) => isset($hidden[$s->signal_key]) || isset($excluded[(int) $s->subject_id]))
            ->filter(fn (CompassSignal $s) => $type === 'all' || StandFacts::typeGroup($s->vehicle_type) === $type)
            ->values();
    }

    // ── Jogadas ──────────────────────────────────────────────────────────────

    /**
     * Os grupos candidatos: um por tipo de sinal (os veículos com o mesmo sinal juntam-se);
     * "Renovar o stock parado" só com dois ou mais veículos.
     *
     * @return array<string, list<array{key: string, type: string, members: Collection}>>
     */
    public function candidateGroups(Collection $signals, string $scope = 'all'): array
    {
        $out = [];
        foreach (self::PLAY_ORDER as $signalType => $playType) {
            $members = $signals->where('type', $signalType)->sortByDesc(fn (CompassSignal $s) => $this->memberStrength($s))->values();
            if ($members->isEmpty() || ($playType === 'renew_stock' && $members->count() < 2)) {
                continue;
            }
            $out[$playType][] = ['key' => "{$playType}:{$scope}", 'type' => $playType, 'members' => $members];
        }

        return $out;
    }

    /** A força de um veículo dentro do seu tipo (o dinheiro em risco, o desvio, os dias, as vistas). */
    private function memberStrength(CompassSignal $s): array
    {
        $n = $s->numbers;

        return match ($s->type) {
            'stop_ad' => [($n['reason'] ?? '') === 'sold_active' ? 1 : 0, (float) ($n['spend'] ?? 0)],
            'review_price' => [(float) ($n['diff_pct'] ?? 0), (int) ($n['days'] ?? 0)],
            'stale' => [(int) ($n['days'] ?? 0) - (int) ($n['threshold'] ?? 0)],
            'interest' => [(int) ($n['views'] ?? 0)],
            'stage' => [($n['position'] ?? '') === 'below_market' ? 1 : 0, -(float) ($n['diff_pct'] ?? 0)],
            default => [0],
        };
    }

    /** A força de uma jogada: primeiro a prioridade do tipo (o dinheiro a perder), depois o sinal mais forte. */
    public function strength(array $group): array
    {
        $rank = array_search($group['type'], array_values(self::PLAY_ORDER), true);

        return [10 - (int) $rank, $this->memberStrength($group['members']->first())];
    }

    /**
     * A chave da frase da IA de uma jogada: a chave da jogada e os veículos do grupo. Se o grupo
     * mudar (um veículo ignorado ou excluído), a frase antiga deixa de servir (falaria de outro
     * veículo) e fica o modelo de frase até ao próximo recálculo.
     */
    public static function textKey(array $group): string
    {
        $keys = $group['members']->pluck('signal_key')->sort()->values()->all();

        return $group['key'] . '#' . substr(md5(implode('|', $keys)), 0, 12);
    }

    public function choose(array $groups): array
    {
        return CompassPlayChooser::choose($groups, fn (array $g) => $this->strength($g));
    }

    private function plays(int $companyId, ?User $user, Collection $signals, string $type, CarbonImmutable $today, bool $fin): array
    {
        $texts = CompassText::where('provider', self::PROVIDER)->where('company_id', $companyId)->pluck('text', 'play_key')->all();
        $where = $this->where->networks($companyId);
        $photos = $user ? app(StandCompassActions::class)->photosAvailable($user, $companyId) : false;

        return array_map(fn ($g) => $this->presentPlay($g, $today, $texts, $where, $fin, $photos), $this->choose($this->candidateGroups($signals, $type)));
    }

    private function presentPlay(array $g, CarbonImmutable $today, array $texts, array $where, bool $fin, bool $photos): array
    {
        $type = $g['type'];
        /** @var Collection $m */
        $m = $g['members'];
        $first = $m->first();
        $single = $m->count() === 1;
        $label = $single ? (string) $first->numbers['label'] : $m->count() . ' veículos';
        $title = match ($type) {
            'stop_ad' => "Parar o anúncio de {$label}",
            'review_price' => "Rever o preço de {$label}",
            'renew_stock' => 'Renovar o stock parado',
            'use_interest' => "Aproveitar o interesse em {$label}",
            default => "Dar palco a {$label}",
        };
        $publish = in_array($type, ['renew_stock', 'use_interest', 'give_stage'], true);
        $ai = $texts[self::textKey($g)] ?? null;
        $whenPlay = match ($type) {
            'stop_ad' => CompassWhen::present($today, $today, null, 'Fazer'),
            'review_price' => CompassWhen::present($today->addDay(), $today, null, 'Rever'),
            default => CompassWhen::present($today->addDay(), $today),
        };
        $actions = match ($type) {
            'stop_ad' => ['view_car'],
            'review_price' => ['review_price', 'view_car'],
            'renew_stock' => ['create', 'suggest'],
            default => ['create', 'suggest', 'view_car'],
        };

        return [
            'key' => $g['key'],
            'type' => $type,
            'type_label' => self::TYPE_META[$type]['label'],
            'icon' => self::TYPE_META[$type]['icon'],
            'color' => self::TYPE_META[$type]['color'],
            'locations' => [],
            'location_id' => 0,
            'title' => $title,
            'confidence' => $m->contains(fn ($s) => $s->confidence === 'alta') ? 'alta' : 'media',
            'number' => $this->bigNumber($g),
            'bars' => [],
            'items' => $m->take(self::MAX_ITEMS)->map(fn (CompassSignal $s) => ['car_id' => (int) $s->subject_id, 'label' => (string) $s->numbers['label'],
                'detail' => $this->itemDetail($s, $fin)])->values()->all(),
            'what' => ['text' => $ai ?: self::TEMPLATES[$type], 'source' => $ai ? 'ai' : 'template'],
            'where' => match ($type) {
                'stop_ad' => ['networks' => [], 'connected' => true, 'ad' => 'Anúncio: no Gestor de Anúncios da Meta (pausar é à mão; a XPLENDOR só tem leitura)'],
                'review_price' => ['networks' => [], 'connected' => true, 'ad' => 'Na ficha do veículo'],
                default => $where + ['ad' => null],
            },
            'when' => $whenPlay,
            'detail' => [
                'sentences' => array_merge($m->map(fn (CompassSignal $s) => $this->sentence($s, $fin))->values()->all(),
                    $m->count() > self::MAX_ITEMS ? ['E mais ' . ($m->count() - self::MAX_ITEMS) . ' veículos com o mesmo sinal.'] : []),
                'sample' => $this->sampleText($type, $first),
                'confidence' => $m->map(fn (CompassSignal $s) => ['title' => (string) $s->numbers['label'], 'confidence' => $s->confidence])->values()->all(),
            ],
            'signal_keys' => $m->pluck('signal_key')->values()->all(),
            'theme' => match ($type) {
                'renew_stock' => 'Veículos à espera de novo dono',
                'use_interest' => "Em destaque: {$label}",
                'give_stage' => "Em destaque: {$label}",
                default => $title,
            },
            'format' => 'Carrossel',
            'car_id' => $single ? (int) $first->subject_id : null,
            'actions' => $actions,
            'post_note' => $publish ? $this->postNote($single, $photos) : null,
        ];
    }

    private function postNote(bool $single, bool $photos): string
    {
        if (! $single) {
            return 'Escolha as fotografias dos veículos na Linha Editorial, depois de criar a ideia.';
        }

        return $photos
            ? 'Leva até 4 fotografias do veículo, a partir dos Ficheiros (a mesma fotografia, sem cópia).'
            : 'Sem os Ficheiros ligados (ou sem acesso à pasta dos veículos), a ideia abre sem fotografias: escolha-as na Linha Editorial.';
    }

    /** @return array{value: string, caption: string} */
    private function bigNumber(array $g): array
    {
        $m = $g['members'];
        $first = $m->first()->numbers;
        $n = $m->count();

        return match ($g['type']) {
            'stop_ad' => ['value' => (string) $n,
                'caption' => ($n === 1 ? 'veículo ' : 'veículos ') . ($m->contains(fn ($s) => ($s->numbers['reason'] ?? '') === 'sold_active') ? 'vendidos com anúncio ativo, ou com gasto sem contactos' : 'com gasto e sem contactos')],
            'review_price' => ['value' => '+' . $this->num((float) $first['diff_pct']) . '%',
                'caption' => $n === 1 ? 'acima da mediana dos comparáveis' : "o maior desvio, entre {$n} veículos"],
            'renew_stock' => ['value' => (string) $n, 'caption' => 'veículos à venda no limiar de dias do seu tipo ou acima'],
            'use_interest' => ['value' => $this->num((int) $first['views']) . ' vistas', 'caption' => 'em ' . (int) $first['window_days'] . ' dias, sem contactos'],
            default => ['value' => ($first['position'] ?? '') === 'below_market' ? $this->signedPct((float) $first['diff_pct']) : 'Alinhado',
                'caption' => ($first['position'] ?? '') === 'below_market' ? 'abaixo da mediana dos comparáveis' : 'com a mediana dos comparáveis'],
        };
    }

    private function itemDetail(CompassSignal $s, bool $fin): ?string
    {
        $n = $s->numbers;

        return match ($s->type) {
            'stop_ad' => ($n['reason'] === 'sold_active'
                ? 'vendido' . ($n['sold_at'] ? ' a ' . CarbonImmutable::parse($n['sold_at'])->format('d/m') : '') . ($n['active_ads'] > 0 ? ', anúncio ativo' : '')
                : 'sem contactos em ' . (int) $n['window_days'] . ' dias') . ($fin && (float) $n['spend'] > 0 ? ', gasto de ' . $this->eur((float) $n['spend']) : ''),
            'review_price' => (int) $n['days'] . ' dias, +' . $this->num((float) $n['diff_pct']) . '%, mediana de ' . $this->eur((float) $n['median']) . ' (' . (int) $n['comparables'] . ' comparáveis)',
            'stale' => (int) $n['days'] . ' dias (limiar de ' . (int) $n['threshold'] . ')',
            'interest' => $this->num((int) $n['views']) . ' vistas, 0 contactos',
            'stage' => ($n['position'] === 'below_market' ? 'abaixo do mercado, ' . $this->signedPct((float) $n['diff_pct']) : 'alinhado com o mercado')
                . ' (' . (int) $n['comparables'] . ' comparáveis)',
            default => null,
        };
    }

    /** Uma frase descritiva (o que aconteceu, nunca porquê). Sem Finanças, sem o gasto. */
    private function sentence(CompassSignal $s, bool $fin): string
    {
        $n = $s->numbers;
        $label = (string) $n['label'];

        return match ($s->type) {
            'stop_ad' => $n['reason'] === 'sold_active'
                ? sprintf('O veículo %s está vendido%s e %s.', $label, $n['sold_at'] ? ' desde ' . CarbonImmutable::parse($n['sold_at'])->format('d/m') : '',
                    $n['active_ads'] > 0 ? 'tem ' . ($n['active_ads'] === 1 ? 'um anúncio ativo' : $n['active_ads'] . ' anúncios ativos') . ' com a etiqueta'
                    : 'registou gasto em anúncios depois da venda') . ($fin && (float) $n['spend'] > 0 ? ' (' . $this->eur((float) $n['spend']) . ' depois da venda).' : '')
                : sprintf('Os anúncios ativos com a etiqueta do veículo %s registaram gasto nos últimos %d dias e nenhum contacto (nem leads, nem cliques no WhatsApp, chamadas ou telefone visto)%s.', $label, (int) $n['window_days'],
                    $fin ? ' (' . $this->eur((float) $n['spend']) . ')' : ''),
            'review_price' => sprintf('O veículo %s está há %d dias em stock (o limiar do seu tipo é de %d dias) e o preço está %s%% acima da mediana de %d veículos comparáveis (%s)%s.',
                $label, (int) $n['days'], (int) $n['threshold'], $this->num((float) $n['diff_pct']), (int) $n['comparables'], $this->eur((float) $n['median']),
                ! empty($n['criteria_widened']) ? ', com critérios alargados' : ''),
            'stale' => sprintf('O veículo %s está há %d dias em stock; o limiar do seu tipo é de %d dias.', $label, (int) $n['days'], (int) $n['threshold']),
            'interest' => sprintf('O veículo %s teve %s vistas nos últimos %d dias e nenhum contacto por WhatsApp, chamada ou telefone.', $label, $this->num((int) $n['views']), (int) $n['window_days']),
            'stage' => sprintf('O veículo %s tem o preço %s (%d comparáveis), sem anúncio ativo nem publicação da Bússola nos últimos 30 dias.', $label,
                $n['position'] === 'below_market' ? $this->num(abs((float) $n['diff_pct'])) . '% abaixo da mediana do mercado' : 'alinhado com a mediana do mercado', (int) $n['comparables']),
            default => '',
        };
    }

    private function sampleText(string $type, CompassSignal $first): string
    {
        return match ($type) {
            'stop_ad' => 'O gasto vem da fonte única do gasto por veículo (a etiqueta [id:N] nos anúncios); a regra é a das Recomendações.',
            'review_price', 'give_stage' => 'Só com comparação exata e confiança média ou alta; comparáveis do mercado já guardados.',
            'renew_stock' => 'Os dias contam desde a entrada no stock (a data do DMS ou, sem ela, a entrada na XPLENDOR).',
            'use_interest' => 'As vistas e os contactos dos últimos ' . (int) ($first->numbers['window_days'] ?? 14) . ' dias, no site do stand.',
            default => '',
        };
    }

    // ── Topo ─────────────────────────────────────────────────────────────────

    private function top(Company $company, array $facts, array $adRows, bool $fin): array
    {
        $count = count($facts);
        $value = array_sum(array_map(fn ($f) => (float) ($f['price'] ?? 0), $facts));
        $days = array_filter(array_map(fn ($f) => $f['days'], $facts), fn ($d) => $d !== null);
        $eligible = array_filter($facts, fn ($f) => $f['price_eligible']);
        $above = array_filter($eligible, fn ($f) => $f['position'] === 'above_market');
        $spend = array_sum(array_column($adRows, 'spend'));
        $contacts = array_sum(array_column($adRows, 'contacts'));
        $paidLeads = array_sum(array_column($adRows, 'paid_leads'));
        $estimated = count(array_filter($facts, fn ($f) => $f['entry_estimated']));
        $outside = $count - count($eligible);

        $numbers = [
            ['kind' => 'stock_count', 'label' => 'Veículos em stock', 'value' => $count, 'format' => 'int', 'caption' => 'stock próprio, sem retomas', 'icon' => 'ri-car-line'],
            $this->money(['kind' => 'stock_value', 'label' => 'Valor do stock', 'value' => (int) round($value * 100), 'format' => 'eur', 'caption' => 'a soma dos preços em stock', 'icon' => 'ri-money-euro-circle-line'], $fin),
            ['kind' => 'avg_days', 'label' => 'Dias médios em stock', 'value' => $days ? (int) round(array_sum($days) / count($days)) : null, 'format' => 'int', 'caption' => 'desde a entrada no stock', 'icon' => 'ri-time-line'],
            ['kind' => 'above_market', 'label' => 'Acima do mercado', 'value' => $eligible ? round(count($above) / count($eligible) * 100, 1) : null, 'format' => 'pct',
                'caption' => 'de ' . count($eligible) . ' ' . (count($eligible) === 1 ? 'veículo' : 'veículos') . ' com comparação exata', 'icon' => 'ri-scales-3-line'],
            $this->money(['kind' => 'ad_spend', 'label' => 'Gasto em anúncios', 'value' => (int) round($spend * 100), 'format' => 'eur', 'caption' => 'últimas 4 semanas', 'icon' => 'ri-megaphone-line'], $fin),
            // Custo por lead paga (as leads guardam a origem); ao lado, os contactos de todas as origens, sem divisão.
            $this->money(['kind' => 'cost_per_paid_lead', 'label' => 'Custo por lead paga', 'value' => $paidLeads > 0 ? (int) round($spend / $paidLeads * 100) : null, 'format' => 'eur',
                'caption' => ($paidLeads === 1 ? '1 lead paga' : "{$paidLeads} leads pagas") . '; ' . ($contacts === 1 ? '1 contacto' : "{$contacts} contactos") . ' de todas as origens nos veículos com gasto',
                'icon' => 'ri-chat-1-line', 'paid_leads' => $paidLeads, 'contacts' => $contacts], $fin),
        ];
        $notes = [];
        if ($spend > 0) {
            $notes[] = 'As interações (WhatsApp, chamadas, telefone) não guardam a origem: o custo é por lead paga, e os contactos de todas as origens mostram-se ao lado, sem divisão.';
        }
        if ($estimated > 0) {
            $notes[] = $estimated === 1 ? 'Num veículo, a data de entrada é estimada (a data em que entrou na XPLENDOR).'
                : "Em {$estimated} veículos, a data de entrada é estimada (a data em que entraram na XPLENDOR).";
        }
        if ($outside > 0) {
            $notes[] = $outside === 1 ? 'Um veículo fica fora das comparações de preço (sem tipologia, sem comparáveis ou com comparação aproximada).'
                : "{$outside} veículos ficam fora das comparações de preço (sem tipologia, sem comparáveis ou com comparação aproximada).";
        }

        return [
            'title' => 'Esta semana ' . \App\Support\CompanyName::with('em', $company),
            'context' => 'Stock de hoje; anúncios, vistas e contactos das últimas 4 semanas.',
            'numbers' => $numbers,
            'notes' => $notes,
        ];
    }

    /** Sem Finanças: o valor não vem (null) e fica marcado como escondido. */
    private function money(array $number, bool $fin): array
    {
        return $fin ? $number : ['value' => null, 'hidden' => true] + $number;
    }

    // ── Blocos ───────────────────────────────────────────────────────────────

    private function blocks(array $facts, array $adRows, bool $fin): array
    {
        $forSale = array_filter($facts, fn ($f) => $f['for_sale']);

        return [
            'aging' => $this->agingBlock($forSale),
            'price' => $this->priceBlock($forSale),
            'ads' => $this->adsBlock($adRows, $fin),
            'demand' => $this->demandBlock($facts),
            'forgotten' => $this->forgottenBlock($forSale),
            'footer' => [
                'Os veículos em stock são o stock próprio (sem retomas); "à venda" são os ativos.',
                'O preço face ao mercado só conta com comparação exata e confiança média ou alta: os veículos sem tipologia, sem comparáveis ou com comparação aproximada ficam de fora.',
                'O gasto vem dos anúncios com a etiqueta [id:N] (e dos mapeamentos antigos); os contactos são os cliques no WhatsApp, as chamadas e o telefone visto ou copiado no site do stand.',
                'A Bússola nunca mostra nomes, telefones nem e-mails dos contactos, só contagens.',
            ],
        ];
    }

    private function agingBlock(array $forSale): array
    {
        $groups = [];
        foreach (['car' => 'Carros', 'motorhome' => 'Autocaravanas'] as $group => $name) {
            $list = array_filter($forSale, fn ($f) => $f['group'] === $group);
            if ($list === []) {
                continue;
            }
            $type = $group === 'car' ? 'car' : 'motorhome';
            [$a, $b, $c] = StockThresholds::ageBandsFor($type);
            $bands = [["Até {$a} dias", 0, $a], ["De " . ($a + 1) . " a {$b} dias", $a + 1, $b], ["De " . ($b + 1) . " a {$c} dias", $b + 1, $c], ["Mais de {$c} dias", $c + 1, PHP_INT_MAX]];
            $groups[] = ['group' => $group, 'label' => $name, 'threshold' => StockThresholds::ageThresholdFor($type), 'total' => count($list),
                'bands' => array_map(fn ($band) => ['label' => $band[0], 'count' => count(array_filter($list, fn ($f) => $f['days'] !== null && $f['days'] >= $band[1] && $f['days'] <= $band[2]))], $bands)];
        }
        $stale = array_filter($forSale, fn ($f) => $f['stale']);
        usort($forSale, fn ($x, $y) => ($y['days'] ?? 0) <=> ($x['days'] ?? 0));
        $n = count($stale);

        return [
            'headline' => $n === 0 ? 'Nenhum veículo à venda passou o limiar de dias do seu tipo.'
                : ($n === 1 ? 'Um veículo à venda está no limiar de dias do seu tipo ou acima.' : "{$n} veículos à venda estão no limiar de dias do seu tipo ou acima."),
            'groups' => $groups,
            'oldest' => array_map(fn ($f) => ['car_id' => $f['car_id'], 'label' => $f['label'], 'days' => $f['days'], 'threshold' => $f['threshold'], 'stale' => $f['stale']], array_slice($forSale, 0, 5)),
        ];
    }

    private function priceBlock(array $forSale): array
    {
        $eligible = array_filter($forSale, fn ($f) => $f['price_eligible']);
        $counts = ['below' => 0, 'aligned' => 0, 'above' => 0];
        foreach ($eligible as $f) {
            $counts[match ($f['position']) { 'below_market' => 'below', 'above_market' => 'above', default => 'aligned' }]++;
        }
        $above = array_values(array_filter($eligible, fn ($f) => $f['position'] === 'above_market'));
        usort($above, fn ($x, $y) => $y['diff_pct'] <=> $x['diff_pct']);
        $outside = ['sem_tipologia' => 0, 'sem_mercado' => 0, 'sem_comparaveis' => 0, 'aproximada' => 0, 'confianca_baixa' => 0];
        foreach ($forSale as $f) {
            if (! $f['price_eligible']) {
                $outside[$f['price_reason']]++;
            }
        }
        $e = count($eligible);

        return [
            'headline' => $e === 0 ? 'Nenhum veículo à venda tem comparação exata com o mercado.'
                : sprintf('%d de %d %s com comparação exata %s acima do mercado.', $counts['above'], $e, $e === 1 ? 'veículo' : 'veículos', $counts['above'] === 1 ? 'está' : 'estão'),
            'eligible' => $e,
            'counts' => $counts,
            'top_above' => array_map(fn ($f) => ['car_id' => $f['car_id'], 'label' => $f['label'], 'diff_pct' => $f['diff_pct'], 'price' => $f['price'],
                'median' => $f['median'], 'comparables' => $f['comparables'], 'days' => $f['days']], array_slice($above, 0, 5)),
            'outside' => array_filter($outside),
        ];
    }

    private function adsBlock(array $rows, bool $fin): array
    {
        $noContacts = array_filter($rows, fn ($f) => $f['contacts'] === 0);
        $n = count($rows);
        $row = fn ($f) => ['car_id' => $f['car_id'], 'label' => $f['label'], 'sold' => $f['sold'], 'contacts' => $f['contacts'], 'paid_leads' => $f['paid_leads']]
            + ($fin ? ['spend' => $f['spend'], 'cost_per_paid_lead' => $f['paid_leads'] > 0 ? round($f['spend'] / $f['paid_leads'], 2) : null]
                : ['spend' => null, 'cost_per_paid_lead' => null, 'hidden' => true]);

        return [
            'headline' => $n === 0 ? 'Sem gasto em anúncios atribuído a veículos nas últimas 4 semanas.'
                : sprintf('Nas últimas 4 semanas, %d %s gasto em anúncios; %d sem nenhum contacto.', $n, $n === 1 ? 'veículo teve' : 'veículos tiveram', count($noContacts)),
            'note' => 'As interações (WhatsApp, chamadas, telefone) não guardam a origem: o custo é por lead paga; os contactos são de todas as origens, sem divisão.',
            'rows' => array_map($row, array_slice($rows, 0, 8)),
            'without_contacts' => array_map($row, array_values($noContacts)),
        ];
    }

    private function demandBlock(array $facts): array
    {
        $rows = array_values(array_filter($facts, fn ($f) => $f['views_28'] > 0 || $f['contacts_28'] > 0));
        usort($rows, fn ($x, $y) => [$y['views_28'], $y['contacts_28']] <=> [$x['views_28'], $x['contacts_28']]);

        return [
            'headline' => $rows === [] ? 'Sem vistas nem contactos registados nas últimas 4 semanas.' : 'Os veículos mais vistos nas últimas 4 semanas, com os contactos de cada um.',
            'rows' => array_map(fn ($f) => ['car_id' => $f['car_id'], 'label' => $f['label'], 'views' => $f['views_28'], 'contacts' => $f['contacts_28']], array_slice($rows, 0, 5)),
        ];
    }

    private function forgottenBlock(array $forSale): array
    {
        $items = array_values(array_filter($forSale, fn ($f) => ($f['days'] ?? 0) > StandFacts::FORGOTTEN_DAYS && $f['active_ads'] === 0 && $f['spend_30'] <= 0 && ! $f['compass_post_30']));
        usort($items, fn ($x, $y) => $y['days'] <=> $x['days']);
        $n = count($items);

        return [
            'headline' => $n === 0 ? 'Nenhum veículo à venda há mais de 30 dias ficou sem anúncio nem publicação.'
                : ($n === 1 ? 'Um veículo à venda há mais de 30 dias está sem anúncio nem publicação.' : "{$n} veículos à venda há mais de 30 dias estão sem anúncio nem publicação."),
            'items' => array_map(fn ($f) => ['car_id' => $f['car_id'], 'label' => $f['label'], 'days' => $f['days']], array_slice($items, 0, 8)),
            'total' => $n,
        ];
    }

    // ── Formatos ─────────────────────────────────────────────────────────────

    private function num(int|float $v): string
    {
        $v = is_float($v) ? round($v, 1) : $v;

        return number_format($v, is_float($v) && fmod($v, 1.0) !== 0.0 ? 1 : 0, ',', ' ');
    }

    private function signedPct(float $v): string
    {
        return ($v > 0 ? '+' : ($v < 0 ? '−' : '')) . $this->num(abs($v)) . '%';
    }

    private function eur(float $v): string
    {
        return number_format($v, 0, ',', ' ') . ' €';
    }
}
