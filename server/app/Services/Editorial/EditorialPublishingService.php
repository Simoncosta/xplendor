<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Models\EditorialPost;
use App\Models\EditorialPostMetric;
use App\Models\EditorialPostNetwork;
use App\Models\MediaAsset;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * F3d: Publicado e Análise (à mão).
 *
 *  · "Para publicar hoje" e as atrasadas (Programadas cuja data e hora passaram);
 *  · marcar como publicada EM CADA REDE, com o link (instagram.com ou facebook.com) e a hora
 *    real; quem marcou fica registado, com a pessoa real. "Publicado" só quando todas as
 *    redes escolhidas estão publicadas ou dispensadas ("Não publicar nesta rede", com o
 *    motivo; não pede nova aprovação);
 *  · números à mão por rede, com a ORIGEM e a data da medição; taxa de envolvimento
 *    (interações ÷ alcance × 100), só quando há alcance;
 *  · notas de aprendizagem e resultados do mês.
 * As publicações do Site continuam a seguir o estado do artigo do blog: ficam de fora.
 */
class EditorialPublishingService
{
    public const ANALYSIS_AFTER_DAYS = 7;

    private const HOSTS = ['instagram' => 'instagram.com', 'facebook' => 'facebook.com'];

    public function __construct(private readonly EditorialWorkflowService $workflow) {}

    // ── Hoje e atrasadas ─────────────────────────────────────────────────────

    /** Programadas para hoje (Lisboa) e as atrasadas de qualquer dia. */
    public function today(int $companyId, User $user): array
    {
        $today = CarbonImmutable::now(EditorialPost::TIMEZONE)->toDateString();
        $posts = EditorialPost::where('company_id', $companyId)->where('stage', EditorialPost::STAGE_SCHEDULED)
            ->where('channel', '!=', 'site')->whereDate('publish_date', '<=', $today)->with('networks')
            ->get()->sortBy(fn (EditorialPost $p) => self::sortKey($p))->values();
        $canMark = EditorialWorkflowService::isProducer($user, $companyId);
        $row = fn (EditorialPost $p) => self::summary($p) + ['can_mark' => $canMark];

        return [
            'date' => $today,
            'today' => $posts->filter(fn ($p) => $p->publish_date->toDateString() === $today)->map($row)->values()->all(),
            'overdue' => $posts->filter(fn ($p) => $p->isOverdue())->map($row)->values()->all(),
        ];
    }

    /** Por data e hora prevista; sem hora, no fim do dia. */
    public static function sortKey(EditorialPost $p): string
    {
        return $p->publish_date->toDateString() . ' ' . ($p->publish_time ?? '99:99') . ' ' . sprintf('%010d', $p->id);
    }

    public static function summary(EditorialPost $p): array
    {
        return [
            'id' => $p->id, 'title' => $p->title, 'channel' => $p->channel,
            'networks' => $p->networks->map(fn (EditorialPostNetwork $n) => ['network' => $n->network, 'media_format' => $n->media_format, 'state' => $n->state()])->values()->all(),
            'publish_date' => $p->publish_date->toDateString(), 'publish_time' => $p->publish_time,
            'overdue' => $p->isOverdue(),
        ];
    }

    // ── Marcar como publicada ────────────────────────────────────────────────

    /**
     * Marcar como publicada numa rede, com o link e a hora real (ou corrigir depois). Quando
     * todas as redes a publicar estão publicadas, a publicação passa a Publicado. No modo
     * "Produção pela equipa", só a equipa.
     */
    public function markPublished(EditorialPost $post, User $user, array $data): EditorialPost
    {
        $network = $this->network($post, $user, $data['network'] ?? null);
        $url = trim((string) ($data['url'] ?? ''));
        $at = $data['published_at'] ?? null;
        $errors = [];
        if (! self::validUrl($network->network, $url)) {
            $errors['url'] = [$network->network === 'facebook'
                ? 'Cole o link da publicação no Facebook (https://www.facebook.com/…).'
                : 'Cole o link da publicação no Instagram (https://www.instagram.com/…).'];
        }
        try {
            $when = $at ? CarbonImmutable::parse((string) $at, EditorialPost::TIMEZONE) : null;
        } catch (\Throwable) {
            $when = null;
        }
        if (! $when) {
            $errors['published_at'] = ['Indique a data e a hora em que foi publicada.'];
        } elseif ($when->gt(now()->addMinutes(5))) {
            $errors['published_at'] = ['A hora de publicação não pode ser no futuro.'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($post, $user, $network, $url, $when) {
            $post = $this->lockPublishable($post);
            $row = EditorialPostNetwork::lockForUpdate()->findOrFail($network->id);
            $fix = $row->published_at !== null;
            $row->forceFill([
                'published_url' => mb_substr($url, 0, 500), 'published_at' => $when->utc(),
                'published_by_user_id' => $user->id, 'published_by_impersonator_id' => EditorialWorkflowService::impersonatorId($user),
                'skipped_at' => null, 'skip_reason' => null, 'skipped_by_user_id' => null, 'skipped_by_impersonator_id' => null,
            ])->save();
            $label = NetworkFormats::NETWORK_LABELS[$row->network];
            $local = $when->setTimezone(EditorialPost::TIMEZONE)->format('d/m/Y H:i');
            $this->workflow->event($post, $user, 'published', null, null, null,
                ($fix ? "{$label}: link ou hora corrigidos, " : "{$label}: publicada a ") . "{$local}, {$url}");

            return $this->settle($post, $user);
        });
    }

    /**
     * "Não publicar nesta rede": fica registado o motivo e quem decidiu; não pede nova
     * aprovação. Tem de ficar pelo menos uma rede publicada ou por publicar.
     */
    public function skipNetwork(EditorialPost $post, User $user, array $data): EditorialPost
    {
        $network = $this->network($post, $user, $data['network'] ?? null);
        $reason = trim((string) ($data['reason'] ?? ''));
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => ['Indique o motivo para não publicar nesta rede.']]);
        }

        return DB::transaction(function () use ($post, $user, $network, $reason) {
            $post = $this->lockPublishable($post);
            $row = EditorialPostNetwork::lockForUpdate()->findOrFail($network->id);
            if ($row->published_at) {
                throw new HttpException(409, 'Esta rede já está publicada.');
            }
            $others = EditorialPostNetwork::where('editorial_post_id', $post->id)->where('id', '!=', $row->id)->whereNull('skipped_at')->count();
            if ($others === 0) {
                throw new HttpException(422, 'É a única rede que falta: para não publicar em nenhuma, devolva a publicação a Planeamento.');
            }
            $row->forceFill(['skipped_at' => now(), 'skip_reason' => mb_substr($reason, 0, 500),
                'skipped_by_user_id' => $user->id, 'skipped_by_impersonator_id' => EditorialWorkflowService::impersonatorId($user)])->save();
            $this->workflow->event($post, $user, 'published', null, null, null,
                'Não publicar no ' . NetworkFormats::NETWORK_LABELS[$row->network] . ': ' . $reason);

            return $this->settle($post, $user);
        });
    }

    /** Quando nenhuma rede está por publicar e há pelo menos uma publicada: Programado → Publicado. */
    private function settle(EditorialPost $post, User $user): EditorialPost
    {
        $rows = EditorialPostNetwork::where('editorial_post_id', $post->id)->get();
        $pending = $rows->contains(fn ($n) => $n->state() === EditorialPostNetwork::STATE_PENDING);
        $published = $rows->filter(fn ($n) => $n->published_at)->count();
        if ($post->stage === EditorialPost::STAGE_SCHEDULED && ! $pending && $published > 0) {
            $post->forceFill(['stage' => EditorialPost::STAGE_PUBLISHED, 'stage_changed_at' => now()])->save();
            $this->workflow->event($post, $user, 'stage', EditorialPost::STAGE_SCHEDULED, EditorialPost::STAGE_PUBLISHED, $post->approved_version_id ?? $post->current_version_id,
                "Publicada em {$published} " . ($published === 1 ? 'rede' : 'redes') . '.');
        }

        return $post->fresh();
    }

    private function network(EditorialPost $post, User $user, mixed $network): EditorialPostNetwork
    {
        EditorialWorkflowService::assertProducer($user, (int) $post->company_id);
        if ($post->channel === 'site') {
            throw new HttpException(422, 'As publicações do Site seguem o estado do artigo do blog.');
        }
        $rows = $post->networks()->get();
        $row = $network ? $rows->firstWhere('network', $network) : ($rows->count() === 1 ? $rows->first() : null);
        if (! $row) {
            throw ValidationException::withMessages(['network' => [$network ? 'Esta publicação não vai para essa rede.' : 'Indique a rede.']]);
        }

        return $row;
    }

    private function lockPublishable(EditorialPost $post): EditorialPost
    {
        $post = EditorialPost::lockForUpdate()->findOrFail($post->id);
        if (! in_array($post->stage, [EditorialPost::STAGE_SCHEDULED, EditorialPost::STAGE_PUBLISHED, EditorialPost::STAGE_ANALYSIS], true)) {
            throw new HttpException(422, 'Só se marca como publicada uma publicação Programada.');
        }

        return $post;
    }

    /** O link tem de ser da rede da publicação (https, instagram.com ou facebook.com e subdomínios). */
    public static function validUrl(string $channel, string $url): bool
    {
        $host = self::HOSTS[$channel] ?? null;
        if (! $host || strlen($url) > 500 || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);
        $h = strtolower((string) ($parts['host'] ?? ''));

        return ($parts['scheme'] ?? '') === 'https' && ($h === $host || str_ends_with($h, '.' . $host))
            && trim((string) ($parts['path'] ?? ''), '/') !== '';
    }

    // ── Análise ──────────────────────────────────────────────────────────────

    /** Publicadas há 7 dias (ou mais) passam a Análise. Devolve quantas. */
    public function autoAnalysis(): int
    {
        $n = 0;
        // A contar da ÚLTIMA rede publicada.
        $last = EditorialPostNetwork::selectRaw('editorial_post_id, MAX(published_at) as last_at')->whereNotNull('published_at')->groupBy('editorial_post_id');
        EditorialPost::where('stage', EditorialPost::STAGE_PUBLISHED)->where('channel', '!=', 'site')
            ->joinSub($last, 'pn', 'pn.editorial_post_id', '=', 'editorial_posts.id')
            ->where('pn.last_at', '<=', now()->subDays(self::ANALYSIS_AFTER_DAYS))
            ->select('editorial_posts.*')->orderBy('editorial_posts.id')->chunkById(200, function ($posts) use (&$n) {
                foreach ($posts as $p) {
                    $updated = EditorialPost::whereKey($p->id)->where('stage', EditorialPost::STAGE_PUBLISHED)
                        ->update(['stage' => EditorialPost::STAGE_ANALYSIS, 'status' => EditorialPost::STAGE_TO_STATUS[EditorialPost::STAGE_ANALYSIS], 'stage_changed_at' => now()]);
                    if ($updated) {
                        $this->workflow->event($p, null, 'stage', EditorialPost::STAGE_PUBLISHED, EditorialPost::STAGE_ANALYSIS, null,
                            'Passou a Análise ao fim de ' . self::ANALYSIS_AFTER_DAYS . ' dias de publicada.');
                        $n++;
                    }
                }
            }, 'editorial_posts.id', 'id');

        return $n;
    }

    /**
     * Números à mão (origem "manual") com a data da medição e as notas de aprendizagem.
     * Um campo vazio apaga o número manual dessa métrica. Só em Publicada ou Análise.
     */
    public function saveMetrics(EditorialPost $post, User $user, array $data): EditorialPost
    {
        $network = $this->network($post, $user, $data['network'] ?? null);
        if (! $network->published_at) {
            throw new HttpException(422, 'Os resultados registam-se depois de publicada nesta rede.');
        }
        $net = $network->network;
        // Só as notas de aprendizagem (sem números): a data da medição não é precisa.
        $hasNumbers = (bool) array_intersect(array_keys($data), EditorialPostMetric::METRICS);
        $rules = ['measured_on' => [$hasNumbers ? 'required' : 'nullable', 'date', 'before_or_equal:' . CarbonImmutable::now(EditorialPost::TIMEZONE)->toDateString()],
            'worked' => ['nullable', 'string', 'max:2000'], 'change' => ['nullable', 'string', 'max:2000']];
        foreach (EditorialPostMetric::METRICS as $m) {
            $rules[$m] = ['nullable', 'integer', 'min:0', 'max:9999999999'];
        }
        $v = validator($data, $rules, [
            'measured_on.required' => 'Indique a data da medição.',
            'measured_on.before_or_equal' => 'A data da medição não pode ser no futuro.',
            'integer' => 'Use números inteiros.', 'min' => 'Os números não podem ser negativos.',
        ])->validate();
        $video = self::isVideo($post, $net);

        DB::transaction(function () use ($post, $user, $v, $data, $video, $net) {
            foreach (EditorialPostMetric::METRICS as $m) {
                if (! array_key_exists($m, $data) || ($m === 'video_views' && ! $video)) {
                    continue;
                }
                $value = $v[$m] ?? null;
                if ($value === null) {
                    EditorialPostMetric::where('editorial_post_id', $post->id)->where('network', $net)->where('metric', $m)->where('source', EditorialPostMetric::SOURCE_MANUAL)->delete();
                    continue;
                }
                EditorialPostMetric::updateOrCreate(
                    ['editorial_post_id' => $post->id, 'network' => $net, 'metric' => $m, 'source' => EditorialPostMetric::SOURCE_MANUAL],
                    ['company_id' => $post->company_id, 'value' => (int) $value, 'measured_on' => $v['measured_on'],
                        'recorded_by_user_id' => $user->id, 'impersonator_user_id' => EditorialWorkflowService::impersonatorId($user)],
                );
            }
            $notes = [];
            foreach (['worked' => 'analysis_worked', 'change' => 'analysis_change'] as $in => $col) {
                if (array_key_exists($in, $data)) {
                    $notes[$col] = isset($v[$in]) && trim((string) $v[$in]) !== '' ? trim((string) $v[$in]) : null;
                }
            }
            if ($notes) {
                $post->forceFill($notes)->save();
            }
            if (! empty($v['measured_on']) && array_intersect(array_keys($data), EditorialPostMetric::METRICS)) {
                $this->workflow->event($post, $user, 'metrics', null, null, null, NetworkFormats::NETWORK_LABELS[$net] . ': resultados registados (medição de ' . CarbonImmutable::parse($v['measured_on'])->format('d/m/Y') . ').');
            }
        });

        return $post->fresh();
    }

    /**
     * Os números de uma publicação: por métrica, o da medição mais recente (com a origem);
     * e a taxa de envolvimento calculada a partir do alcance e das interações mostrados.
     *
     * @param  Collection<int, EditorialPostMetric>|null  $rows
     */
    public static function metrics(EditorialPost $post, ?Collection $rows = null, ?string $network = null): array
    {
        $network ??= $post->primaryNetwork();
        $rows = ($rows ?? EditorialPostMetric::where('editorial_post_id', $post->id)->get())->where('network', $network);
        $out = [];
        foreach (EditorialPostMetric::METRICS as $m) {
            $best = $rows->where('metric', $m)
                ->sortByDesc(fn ($r) => $r->measured_on->toDateString() . '|' . ($r->source === EditorialPostMetric::SOURCE_MANUAL ? '1' : '0'))->first();
            $out[$m] = $best ? ['value' => (int) $best->value, 'source' => $best->source, 'measured_on' => $best->measured_on->toDateString()] : null;
        }
        $measured = collect($out)->filter()->max('measured_on');

        return [
            'values' => $out,
            'measured_on' => $measured,
            'engagement_rate' => self::engagementRate($out['interactions']['value'] ?? null, $out['reach']['value'] ?? null),
            'is_video' => self::isVideo($post, $network),
        ];
    }

    /** Interações ÷ alcance × 100; sem alcance (vazio ou zero), não há taxa. */
    public static function engagementRate(?int $interactions, ?int $reach): ?float
    {
        if ($interactions === null || ! $reach) {
            return null;
        }

        return round($interactions / $reach * 100, 4);
    }

    public static function isVideo(EditorialPost $post, ?string $network = null): bool
    {
        $format = $post->networks->firstWhere('network', $network ?? $post->primaryNetwork())?->media_format;
        if (in_array($format, EditorialPostMetric::VIDEO_FORMATS, true)) {
            return true;
        }
        $versionId = $post->approved_version_id ?? $post->current_version_id;

        return $versionId !== null && DB::table('editorial_post_version_media')
            ->join('media_assets', 'media_assets.id', '=', 'editorial_post_version_media.media_asset_id')
            ->where('version_id', $versionId)->where('role', 'item')->where('media_assets.kind', MediaAsset::VIDEO)->exists();
    }

    // ── Resultados do mês ────────────────────────────────────────────────────

    /** Uma linha por publicação das redes publicada ou em Análise no mês. */
    public function results(int $companyId, string $month): array
    {
        $start = CarbonImmutable::parse("{$month}-01");
        $posts = EditorialPost::where('company_id', $companyId)->where('channel', '!=', 'site')->with('networks')
            ->whereHas('networks', fn ($q) => $q->whereNotNull('published_at'))
            ->whereDate('publish_date', '>=', $start->toDateString())->whereDate('publish_date', '<=', $start->endOfMonth()->toDateString())
            ->orderBy('publish_date')->orderBy('id')->get();
        $rows = EditorialPostMetric::whereIn('editorial_post_id', $posts->pluck('id'))->get()->groupBy('editorial_post_id');

        // Uma linha por publicação e por rede publicada.
        return $posts->flatMap(fn (EditorialPost $p) => $p->networks->filter(fn ($n) => $n->published_at)->map(function (EditorialPostNetwork $n) use ($p, $rows) {
            $m = self::metrics($p, $rows[$p->id] ?? collect(), $n->network);

            return [
                'id' => $p->id, 'key' => "{$p->id}-{$n->network}", 'title' => $p->title, 'network' => $n->network, 'stage' => $p->stage,
                'date' => $n->published_at->setTimezone(EditorialPost::TIMEZONE)->toDateString(),
                'media_format' => $n->media_format, 'format' => $p->format, 'pillar' => $p->pillar,
                'reach' => $m['values']['reach']['value'] ?? null,
                'interactions' => $m['values']['interactions']['value'] ?? null,
                'engagement_rate' => $m['engagement_rate'],
                'published_url' => $n->published_url,
            ];
        }))->values()->all();
    }
}
