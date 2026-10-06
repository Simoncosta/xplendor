<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EditorialPost;
use App\Models\EditorialPostVersion;
use App\Models\MediaAsset;
use App\Models\MediaUpload;
use App\Services\AlertService;
use App\Services\Media\DiskUsage;
use App\Services\Media\MediaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Diário: retenção dos media da Linha Editorial e aviso de disco.
 *  · versões substituídas há mais de 30 dias: os media saem da versão (o texto fica);
 *  · originais das publicações publicadas há mais de 12 meses: sai o original, ficam as
 *    miniaturas (biblioteca da marca);
 *  · media enviados e nunca usados há mais de 7 dias, e envios por concluir expirados;
 *  · um media só é apagado quando nenhuma versão o usa;
 *  · aviso à equipa XPLENDOR quando o disco passa dos 70% (uma vez por dia).
 */
class MediaRetentionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 900;

    public function handle(MediaService $media, DiskUsage $disk, AlertService $alerts): array
    {
        $r = config('media.retention');
        $stats = ['detached' => 0, 'purged' => 0, 'originals' => 0, 'uploads' => 0];

        // 1. Versões substituídas há mais de 30 dias: largam os media.
        $old = EditorialPostVersion::where('status', EditorialPostVersion::SUPERSEDED)
            ->where('updated_at', '<', now()->subDays((int) $r['superseded_days']))->pluck('id');
        if ($old->isNotEmpty()) {
            $stats['detached'] = DB::table('editorial_post_version_media')->whereIn('version_id', $old)->delete();
        }

        // 2. Media sem nenhuma versão: apagados (os acabados de enviar têm 7 dias para serem usados).
        $orphans = MediaAsset::whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('editorial_post_version_media')->whereColumn('media_asset_id', 'media_assets.id'))
            ->where(fn ($q) => $q->where('created_at', '<', now()->subDays((int) $r['orphan_days']))->orWhere('status', MediaAsset::REJECTED))
            ->get();
        foreach ($orphans as $asset) {
            $media->purge($asset);
            $stats['purged']++;
        }

        // 3. Originais das publicações publicadas há mais de 12 meses (ficam as miniaturas).
        $cut = now()->subMonths((int) $r['published_original_months'])->toDateString();
        $published = MediaAsset::whereNull('original_deleted_at')
            ->whereIn('id', DB::table('editorial_post_version_media as vm')
                ->join('editorial_posts as p', 'p.approved_version_id', '=', 'vm.version_id')
                ->whereIn('p.stage', [EditorialPost::STAGE_PUBLISHED, EditorialPost::STAGE_ANALYSIS])
                ->where('p.publish_date', '<', $cut)->select('vm.media_asset_id'))
            // ...e que nenhuma versão por publicar continua a usar.
            ->whereNotIn('id', DB::table('editorial_post_version_media as vm')
                ->join('editorial_post_versions as v', 'v.id', '=', 'vm.version_id')
                ->join('editorial_posts as p', 'p.id', '=', 'v.editorial_post_id')
                ->whereNotIn('p.stage', [EditorialPost::STAGE_PUBLISHED, EditorialPost::STAGE_ANALYSIS])->select('vm.media_asset_id'))
            ->get();
        foreach ($published as $asset) {
            $media->purgeOriginal($asset);
            $stats['originals']++;
        }

        // 4. Envios por concluir e expirados.
        foreach (MediaUpload::whereNull('completed_at')->where('expires_at', '<', now())->get() as $u) {
            MediaService::disk()->delete($u->tempPath());
            $u->delete();
            $stats['uploads']++;
        }

        // 5. Aviso de disco (equipa XPLENDOR), no máximo um por dia.
        $percent = $disk->percentUsed(MediaService::disk()->path(''));
        $limit = (float) config('media.disk_alert_percent', 70);
        $teamCompany = (int) config('quotes.team_company_id', 0);
        if ($percent !== null && $percent >= $limit) {
            Log::warning('[Media] Disco acima do limite', ['percent' => $percent]);
            if ($teamCompany > 0 && ! \App\Models\Alert::where('company_id', $teamCompany)->where('title', 'Disco do servidor acima de ' . (int) $limit . '%')->whereDate('created_at', today())->exists()) {
                $alerts->createSystemAlert($teamCompany, 'warning', 'Disco do servidor acima de ' . (int) $limit . '%',
                    "O disco dos media está {$percent}% ocupado. Rever a retenção, as quotas ou passar os media para S3.", 'high');
            }
        }
        $stats['disk_percent'] = $percent;

        Log::info('[Media] Retenção', $stats);

        return $stats;
    }
}
