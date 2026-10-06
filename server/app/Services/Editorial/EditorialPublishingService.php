<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Models\EditorialPost;
use App\Models\EditorialPostMetric;
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
 *  · marcar como publicada, com o link (instagram.com ou facebook.com, conforme a rede) e
 *    a hora real; quem marcou fica registado, com a pessoa real;
 *  · números à mão com a ORIGEM e a data da medição; taxa de envolvimento calculada
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
            ->where('channel', '!=', 'site')->whereDate('publish_date', '<=', $today)
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
            'id' => $p->id, 'title' => $p->title, 'channel' => $p->channel, 'media_format' => $p->media_format,
            'publish_date' => $p->publish_date->toDateString(), 'publish_time' => $p->publish_time,
            'overdue' => $p->isOverdue(),
        ];
    }

    // ── Marcar como publicada ────────────────────────────────────────────────

    /**
     * Programada → Publicada, com o link e a hora real. Numa publicada, corrige o link
     * ou a hora (sem mudar a etapa). No modo "Produção pela equipa", só a equipa.
     */
    public function markPublished(EditorialPost $post, User $user, array $data): EditorialPost
    {
        EditorialWorkflowService::assertProducer($user, (int) $post->company_id);
        if ($post->channel === 'site') {
            throw new HttpException(422, 'As publicações do Site seguem o estado do artigo do blog.');
        }
        $url = trim((string) ($data['url'] ?? ''));
        $at = $data['published_at'] ?? null;
        $errors = [];
        if (! self::validUrl($post->channel, $url)) {
            $errors['url'] = [$post->channel === 'facebook'
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

        return DB::transaction(function () use ($post, $user, $url, $when) {
            $post = EditorialPost::lockForUpdate()->findOrFail($post->id);
            if (! in_array($post->stage, [EditorialPost::STAGE_SCHEDULED, EditorialPost::STAGE_PUBLISHED, EditorialPost::STAGE_ANALYSIS], true)) {
                throw new HttpException(422, 'Só se marca como publicada uma publicação Programada.');
            }
            $from = $post->stage;
            $post->forceFill([
                'published_url' => mb_substr($url, 0, 500), 'published_at' => $when->utc(),
                'published_by_user_id' => $user->id, 'published_by_impersonator_id' => EditorialWorkflowService::impersonatorId($user),
            ]);
            $local = $when->setTimezone(EditorialPost::TIMEZONE)->format('d/m/Y H:i');
            if ($from === EditorialPost::STAGE_SCHEDULED) {
                $post->forceFill(['stage' => EditorialPost::STAGE_PUBLISHED, 'stage_changed_at' => now()])->save();
                $this->workflow->event($post, $user, 'stage', $from, EditorialPost::STAGE_PUBLISHED, $post->approved_version_id ?? $post->current_version_id, "Publicada a {$local}: {$url}");
            } else {
                $post->save();
                $this->workflow->event($post, $user, 'published', null, null, null, "Link ou hora de publicação corrigidos: {$local}, {$url}");
            }

            return $post->fresh();
        });
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
        EditorialPost::where('stage', EditorialPost::STAGE_PUBLISHED)->where('channel', '!=', 'site')
            ->whereNotNull('published_at')->where('published_at', '<=', now()->subDays(self::ANALYSIS_AFTER_DAYS))
            ->orderBy('id')->chunkById(200, function ($posts) use (&$n) {
                foreach ($posts as $p) {
                    $updated = EditorialPost::whereKey($p->id)->where('stage', EditorialPost::STAGE_PUBLISHED)
                        ->update(['stage' => EditorialPost::STAGE_ANALYSIS, 'status' => EditorialPost::STAGE_TO_STATUS[EditorialPost::STAGE_ANALYSIS], 'stage_changed_at' => now()]);
                    if ($updated) {
                        $this->workflow->event($p, null, 'stage', EditorialPost::STAGE_PUBLISHED, EditorialPost::STAGE_ANALYSIS, null,
                            'Passou a Análise ao fim de ' . self::ANALYSIS_AFTER_DAYS . ' dias de publicada.');
                        $n++;
                    }
                }
            });

        return $n;
    }

    /**
     * Números à mão (origem "manual") com a data da medição e as notas de aprendizagem.
     * Um campo vazio apaga o número manual dessa métrica. Só em Publicada ou Análise.
     */
    public function saveMetrics(EditorialPost $post, User $user, array $data): EditorialPost
    {
        EditorialWorkflowService::assertProducer($user, (int) $post->company_id);
        if ($post->channel === 'site') {
            throw new HttpException(422, 'As publicações do Site seguem o estado do artigo do blog.');
        }
        if (! in_array($post->stage, [EditorialPost::STAGE_PUBLISHED, EditorialPost::STAGE_ANALYSIS], true)) {
            throw new HttpException(422, 'Os resultados registam-se depois de publicada.');
        }
        $rules = ['measured_on' => ['required', 'date', 'before_or_equal:' . CarbonImmutable::now(EditorialPost::TIMEZONE)->toDateString()],
            'worked' => ['nullable', 'string', 'max:2000'], 'change' => ['nullable', 'string', 'max:2000']];
        foreach (EditorialPostMetric::METRICS as $m) {
            $rules[$m] = ['nullable', 'integer', 'min:0', 'max:9999999999'];
        }
        $v = validator($data, $rules, [
            'measured_on.required' => 'Indique a data da medição.',
            'measured_on.before_or_equal' => 'A data da medição não pode ser no futuro.',
            'integer' => 'Use números inteiros.', 'min' => 'Os números não podem ser negativos.',
        ])->validate();
        $video = self::isVideo($post);

        DB::transaction(function () use ($post, $user, $v, $data, $video) {
            foreach (EditorialPostMetric::METRICS as $m) {
                if (! array_key_exists($m, $data) || ($m === 'video_views' && ! $video)) {
                    continue;
                }
                $value = $v[$m] ?? null;
                if ($value === null) {
                    EditorialPostMetric::where('editorial_post_id', $post->id)->where('metric', $m)->where('source', EditorialPostMetric::SOURCE_MANUAL)->delete();
                    continue;
                }
                EditorialPostMetric::updateOrCreate(
                    ['editorial_post_id' => $post->id, 'metric' => $m, 'source' => EditorialPostMetric::SOURCE_MANUAL],
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
            $this->workflow->event($post, $user, 'metrics', null, null, null, 'Resultados registados (medição de ' . CarbonImmutable::parse($v['measured_on'])->format('d/m/Y') . ').');
        });

        return $post->fresh();
    }

    /**
     * Os números de uma publicação: por métrica, o da medição mais recente (com a origem);
     * e a taxa de envolvimento calculada a partir do alcance e das interações mostrados.
     *
     * @param  Collection<int, EditorialPostMetric>|null  $rows
     */
    public static function metrics(EditorialPost $post, ?Collection $rows = null): array
    {
        $rows ??= EditorialPostMetric::where('editorial_post_id', $post->id)->get();
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
            'is_video' => self::isVideo($post),
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

    public static function isVideo(EditorialPost $post): bool
    {
        if (in_array($post->media_format, EditorialPostMetric::VIDEO_FORMATS, true)) {
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
        $posts = EditorialPost::where('company_id', $companyId)->where('channel', '!=', 'site')
            ->whereIn('stage', [EditorialPost::STAGE_PUBLISHED, EditorialPost::STAGE_ANALYSIS])
            ->whereDate('publish_date', '>=', $start->toDateString())->whereDate('publish_date', '<=', $start->endOfMonth()->toDateString())
            ->orderBy('publish_date')->orderBy('id')->get();
        $rows = EditorialPostMetric::whereIn('editorial_post_id', $posts->pluck('id'))->get()->groupBy('editorial_post_id');

        return $posts->map(function (EditorialPost $p) use ($rows) {
            $m = self::metrics($p, $rows[$p->id] ?? collect());

            return [
                'id' => $p->id, 'title' => $p->title, 'channel' => $p->channel, 'stage' => $p->stage,
                'date' => optional($p->published_at)->setTimezone(EditorialPost::TIMEZONE)?->toDateString() ?? $p->publish_date->toDateString(),
                'media_format' => $p->media_format, 'format' => $p->format, 'pillar' => $p->pillar,
                'reach' => $m['values']['reach']['value'] ?? null,
                'interactions' => $m['values']['interactions']['value'] ?? null,
                'engagement_rate' => $m['engagement_rate'],
                'published_url' => $p->published_url,
            ];
        })->values()->all();
    }
}
