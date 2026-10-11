<?php

declare(strict_types=1);

namespace App\Services\Compass\Stand;

use App\Models\CompassPostSubject;
use App\Models\EditorialPost;
use Illuminate\Support\Facades\DB;

/**
 * Bússola do stand: uma publicação que usa fotografias da pasta de um veículo (pelos Ficheiros)
 * fica ligada a esse veículo, e conta para o "sem publicação nos últimos 30 dias". Refaz-se
 * sempre que os ficheiros da publicação mudam (as ligações da Bússola ficam como estão).
 */
final class StandPostLinks
{
    /** @param list<int> $mediaIds os ficheiros da versão em edição */
    public function syncFromMedia(EditorialPost $post, array $mediaIds): void
    {
        $cars = [];
        if ($mediaIds !== []) {
            // A pasta de cada veículo nos Ficheiros é "viaturas:{id}" (o nome mostrado é "Veículos").
            $ids = DB::table('stored_files as sf')->join('file_folders as ff', 'ff.id', '=', 'sf.folder_id')
                ->where('sf.company_id', $post->company_id)->whereIn('sf.media_asset_id', $mediaIds)->whereNull('sf.deleted_at')
                ->where('ff.system_key', 'like', 'viaturas:%')->pluck('ff.system_key')
                ->map(fn ($key) => (int) substr((string) $key, strlen('viaturas:')))->filter()->unique()->values()->all();
            $cars = $ids === [] ? [] : DB::table('cars')->where('company_id', $post->company_id)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }
        $before = CompassPostSubject::where('editorial_post_id', $post->id)->where('source', CompassPostSubject::SOURCE_FILES)->pluck('subject_id')->map(fn ($id) => (int) $id)->all();
        sort($cars);
        sort($before);
        if ($cars === $before) {
            return;
        }
        DB::transaction(function () use ($post, $cars) {
            CompassPostSubject::where('editorial_post_id', $post->id)->where('source', CompassPostSubject::SOURCE_FILES)->delete();
            foreach ($cars as $carId) {
                CompassPostSubject::create(['company_id' => $post->company_id, 'editorial_post_id' => $post->id, 'subject_type' => 'car',
                    'subject_id' => $carId, 'source' => CompassPostSubject::SOURCE_FILES]);
            }
        });
        StandCompassTriggers::dispatch((int) $post->company_id); // o veículo passa a ter (ou deixa de ter) uma publicação
    }
}
