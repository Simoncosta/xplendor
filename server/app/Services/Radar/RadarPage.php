<?php

declare(strict_types=1);

namespace App\Services\Radar;

use App\Models\Company;
use App\Models\EditorialPost;
use App\Models\RadarRun;
use App\Models\RadarSetting;
use App\Models\RadarSignal;
use App\Models\RadarTopic;
use App\Models\User;
use App\Services\Compass\CompassAccess;
use App\Services\Compass\CompassWhen;
use App\Services\Radar\Calendar\OfficialDates;
use Carbon\CarbonImmutable;

/**
 * Radar: o que o cartão "Radar" da Bússola e o painel "Agora" da Linha Editorial recebem. Só os
 * sinais por tratar e ainda na janela; a fonte e a hora sempre visíveis; sem valores em euros;
 * nunca uma causa. Do Google Trends mostra-se a consulta, o volume e as fontes (nome e ligação),
 * não os títulos das notícias (conteúdo de terceiros).
 */
class RadarPage
{
    public const NOTICES = [
        'nome_pessoa' => 'Nome de pessoa: não use a imagem nem o nome da pessoa para promover.',
        'marca_protegida' => 'Marca protegida: não use os nomes nem os logótipos oficiais.',
    ];

    public function __construct(private readonly CompassAccess $access) {}

    public function payload(int $companyId, ?User $user, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $today = $now->setTimezone('Europe/Lisbon')->startOfDay();
        $company = Company::with('contentSector')->findOrFail($companyId);
        $sector = $company->contentSector;
        [$canAct, $reason] = $user ? $this->access->canAct($user, $companyId) : [false, null];
        $settings = RadarSetting::forCompany($companyId);

        $signals = RadarSignal::with('topic')->where('company_id', $companyId)->where('status', RadarSignal::NEW)
            ->where('window_until', '>', $now)->orderByDesc('score')->orderByDesc('created_at')->limit(12)->get()
            ->map(fn (RadarSignal $s) => $this->present($s, $now, $today))->values()->all();
        $lastRun = RadarRun::where('status', 'ok')->where('dry_run', false)->orderByDesc('finished_at')->value('finished_at');

        return [
            'enabled' => RadarCollector::enabled(),
            'company' => (string) ($company->trade_name ?: $company->fiscal_name),
            'sector' => $sector ? ['slug' => $sector->slug, 'name' => $sector->name] : null,
            'sector_missing' => $sector === null,
            'signals' => $signals,
            'calendar' => OfficialDates::upcomingFor($companyId, $now),
            'settings' => [
                'sports_enabled' => $settings['sports_enabled'], 'sports_clubs' => $settings['sports_clubs'],
                'muted' => array_map(fn ($m, $i) => ['index' => $i, 'title' => $m['title'] ?? null, 'theme' => $m['theme'] ?? null, 'muted_at' => $m['muted_at'] ?? null],
                    $settings['muted'], array_keys($settings['muted'])),
            ],
            'can_act' => $canAct, 'can_act_reason' => $reason,
            'formats' => EditorialPost::FORMATS,
            'refreshed_at' => $lastRun ? CarbonImmutable::parse($lastRun)->toIso8601String() : null,
        ];
    }

    public function present(RadarSignal $s, CarbonImmutable $now, CarbonImmutable $today): array
    {
        $t = $s->topic;
        $flags = (array) $s->flags;
        $until = CarbonImmutable::parse($s->window_until)->setTimezone('Europe/Lisbon');

        return [
            'id' => $s->id, 'title' => $t->title, 'kind' => $t->kind,
            'since' => optional($t->first_seen_at)->toIso8601String(),
            'since_label' => $t->first_seen_at ? CarbonImmutable::parse($t->first_seen_at)->locale('pt_PT')->diffForHumans($now) : null,
            'volume_label' => self::volumeLabel($t),
            'why' => $s->why, 'why_source' => $s->why_source,
            'valid_until' => $until->toIso8601String(),
            'when' => CompassWhen::present($until->startOfDay(), $today),
            'sources' => (array) $s->sources,
            'flags' => $flags,
            'notices' => array_values(array_intersect_key(self::NOTICES, array_flip($flags))),
            'status' => $s->status, 'editorial_post_id' => $s->editorial_post_id,
            'attribution' => self::attribution($t),
        ];
    }

    /** As fontes que se podem mostrar: no Trends, só o nome e a ligação das notícias (sem o título delas). */
    public static function publicSources(RadarTopic $t): array
    {
        return array_values(array_map(function ($s) use ($t) {
            $isTrendNews = $t->kind === 'trend' && ($s['source_name'] ?? null) !== 'Google Trends';

            return ['title' => $isTrendNews ? null : ($s['title'] ?? null), 'source_name' => (string) ($s['source_name'] ?? ''),
                'url' => $s['url'] ?? null, 'published_at' => $s['published_at'] ?? null];
        }, array_slice((array) $t->sources, 0, 6)));
    }

    public static function volumeLabel(RadarTopic $t): ?string
    {
        if ($t->kind === 'trend' && $t->approx_volume_label) {
            $n = (int) preg_replace('/\D/', '', (string) $t->approx_volume_label);

            return $n > 0 ? 'mais de ' . number_format($n, 0, ',', ' ') . ' pesquisas' : null;
        }
        if ($t->kind === 'news') {
            $n = count(array_unique(array_filter(array_map(fn ($s) => $s['source_name'] ?? null, (array) $t->sources))));

            return "em {$n} jornais";
        }
        if ($t->kind === 'bce') {
            return 'comunicado oficial';
        }
        if ($t->kind === 'weather' && $t->districts) {
            return 'aviso no distrito de ' . implode(', ', (array) $t->districts);
        }

        return null;
    }

    public static function attribution(RadarTopic $t): string
    {
        return match ($t->kind) {
            'trend' => 'Fonte: Google Trends',
            'bce' => (string) config('radar.sources.bce_press.attribution', 'Fonte: Banco Central Europeu'),
            'weather' => (string) config('radar.sources.ipma_avisos.attribution', 'Fonte: IPMA'),
            default => 'Fontes: ' . implode(', ', array_values(array_unique(array_filter(array_map(fn ($s) => $s['source_name'] ?? null, (array) $t->sources))))),
        };
    }
}
