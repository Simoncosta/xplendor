<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Models\SocialFollowerSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Registo diário de seguidores (Instagram e Página de Facebook).
 *  · Uma entrada por empresa, plataforma e dia (data de Lisboa).
 *  · A leitura automática ganha à manual: um registo manual nunca substitui uma
 *    leitura automática do mesmo dia; uma leitura automática substitui o manual.
 *  · A série devolve null nos dias sem registo (falhas ficam falhas, sem inventar).
 */
class FollowerSnapshotService
{
    public const TIMEZONE = 'Europe/Lisbon';
    public const DEFAULT_DAYS = 90;
    public const MAX_DAYS = 365;

    public static function today(): string
    {
        return CarbonImmutable::now(self::TIMEZONE)->toDateString();
    }

    /** Registo manual de hoje. Recusa (409) se hoje já houver leitura automática. */
    public function recordManual(int $companyId, string $platform, int $followers, User $actor): SocialFollowerSnapshot
    {
        $today = self::today();
        $existing = SocialFollowerSnapshot::where('company_id', $companyId)
            ->where('platform', $platform)
            ->whereDate('snapshot_date', $today)
            ->first();

        if ($existing && $existing->isAutomatic()) {
            throw new HttpException(409, 'Já existe uma leitura automática de hoje para esta rede; essa leitura prevalece sobre o registo manual.');
        }

        if ($existing) {
            $existing->update(['followers_count' => $followers, 'recorded_by_user_id' => $actor->id]);

            return $existing->refresh();
        }

        return SocialFollowerSnapshot::create([
            'company_id'          => $companyId,
            'platform'            => $platform,
            'snapshot_date'       => $today,
            'followers_count'     => $followers,
            'source'              => SocialFollowerSnapshot::SOURCE_MANUAL,
            'recorded_by_user_id' => $actor->id,
        ]);
    }

    /** Leitura automática (job diário): substitui o registo manual do mesmo dia, se existir. */
    public function recordAutomatic(int $companyId, string $platform, int $followers, ?int $follows = null, ?int $media = null, string $source = SocialFollowerSnapshot::SOURCE_API, ?string $date = null): SocialFollowerSnapshot
    {
        $date ??= self::today();
        $row = SocialFollowerSnapshot::where('company_id', $companyId)
            ->where('platform', $platform)
            ->whereDate('snapshot_date', $date)
            ->first() ?? new SocialFollowerSnapshot(['company_id' => $companyId, 'platform' => $platform, 'snapshot_date' => $date]);

        $row->fill([
            'followers_count'     => $followers,
            'follows_count'       => $follows,
            'media_count'         => $media,
            'source'              => $source,
            'recorded_by_user_id' => null,
        ])->save();

        return $row->refresh();
    }

    /**
     * Estado atual e série diária por plataforma, de (hoje - dias + 1) até hoje.
     * Dias sem registo ficam com count = null.
     */
    public function overview(int $companyId, int $days = self::DEFAULT_DAYS): array
    {
        $days = max(7, min(self::MAX_DAYS, $days));
        $to = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $from = $to->subDays($days - 1);

        $rows = SocialFollowerSnapshot::where('company_id', $companyId)
            ->whereDate('snapshot_date', '>=', $from->toDateString())
            ->whereDate('snapshot_date', '<=', $to->toDateString())
            ->orderBy('snapshot_date')
            ->get()
            ->groupBy('platform');

        $platforms = [];
        foreach (SocialFollowerSnapshot::PLATFORMS as $platform) {
            $byDate = ($rows[$platform] ?? collect())->keyBy(fn (SocialFollowerSnapshot $s) => $s->snapshot_date->toDateString());
            $series = [];
            for ($d = $from; $d->lte($to); $d = $d->addDay()) {
                $s = $byDate->get($d->toDateString());
                $series[] = [
                    'date'   => $d->toDateString(),
                    'count'  => $s?->followers_count,
                    'source' => $s?->source,
                ];
            }

            $latest = SocialFollowerSnapshot::where('company_id', $companyId)
                ->where('platform', $platform)
                ->orderByDesc('snapshot_date')
                ->first();

            $platforms[$platform] = [
                'current' => $latest ? [
                    'count'  => $latest->followers_count,
                    'date'   => $latest->snapshot_date->toDateString(),
                    'source' => $latest->source,
                ] : null,
                'series'  => $series,
            ];
        }

        return [
            'from'      => $from->toDateString(),
            'to'        => $to->toDateString(),
            'today'     => $to->toDateString(),
            'platforms' => $platforms,
        ];
    }
}
