<?php

declare(strict_types=1);

namespace App\Services\Compass\Stand;

use App\Models\Car;
use App\Models\Company;
use App\Models\CompassExcludedSubject;
use App\Models\CompassPostSubject;
use App\Models\CompassSignal;
use App\Models\CompassSignalAction;
use App\Models\EditorialMonth;
use App\Models\EditorialPost;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\EditorialPostService;
use App\Jobs\RecomputeStandCompassJob;
use App\Services\Editorial\EditorialWorkflowService;
use App\Services\Files\FileAccess;
use App\Services\Files\FilesService;
use App\Services\Files\SystemFileCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Bússola do stand: o que se faz a partir de uma jogada.
 *  · Criar publicação: uma ideia na Linha Editorial (mês aberto, nunca uma data passada), com
 *    o texto e, numa jogada de um só veículo, até 4 fotografias do veículo a partir dos
 *    Ficheiros (a mesma media, sem cópia); sem os Ficheiros ligados, ou sem acesso à pasta
 *    "Veículos", abre sem fotografias. A publicação fica ligada ao veículo (compass_post_subjects).
 *  · Ignorar durante 4 semanas (todas as chaves da jogada), restaurar, e "Não voltar a sugerir
 *    este veículo" (exclusão do veículo, que pode voltar a incluir-se).
 */
final class StandCompassActions
{
    public const PROVIDER = StandSignalService::PROVIDER;
    public const IGNORE_DAYS = 28;
    public const MAX_PHOTOS = 4;

    public function __construct(
        private readonly EditorialPostService $posts,
        private readonly CompanyModuleService $modules,
        private readonly FileAccess $files,
    ) {}

    /** A pessoa pode receber as fotografias dos Ficheiros (o módulo ligado e a pasta "Veículos" visível)? */
    public function photosAvailable(User $user, int $companyId): bool
    {
        return $this->modules->isEnabled($companyId, 'ficheiros') && $this->files->canSeeSystem($user, $companyId, 'viaturas');
    }

    /** As fotografias do veículo nos Ficheiros, pela ordem do veículo (a principal primeiro). @return list<int> ids dos ficheiros */
    public function photoFiles(int $companyId, int $carId): array
    {
        $ordered = DB::table('car_images')->where('car_id', $carId)->orderByDesc('is_primary')->orderBy('order')->orderBy('id')->pluck('id')->all();
        $files = StoredFile::query()->join('file_folders as f', 'f.id', '=', 'stored_files.folder_id')
            ->where('stored_files.company_id', $companyId)->where('f.system_key', "viaturas:{$carId}")
            ->where('stored_files.source', 'viatura')->whereNull('stored_files.deleted_at')->whereNull('stored_files.missing_at')
            ->pluck('stored_files.id', 'stored_files.source_id')->all();
        $out = [];
        foreach ($ordered as $imageId) {
            if (isset($files[$imageId])) {
                $out[] = (int) $files[$imageId];
            }
        }

        return array_slice($out, 0, self::MAX_PHOTOS);
    }

    /** @return array{post: EditorialPost, photos: int} */
    public function createPost(Company $company, User $user, array $play, array $data, array $extra = []): array
    {
        $date = CarbonImmutable::parse($data['publish_date']);
        if ($date->lt(CarbonImmutable::now('Europe/Lisbon')->startOfDay())) {
            throw ValidationException::withMessages(['publish_date' => ['A data de publicação não pode ser anterior a hoje.']]);
        }
        $open = EditorialMonth::where('company_id', $company->id)->where('year', (int) $date->year)->where('month', (int) $date->month)
            ->where('state', EditorialMonth::OPEN)->exists();
        if (! $open) {
            $month = mb_strtolower($date->locale('pt_PT')->translatedFormat('F \d\e Y'));
            throw ValidationException::withMessages(['publish_date' => ["O mês de {$month} não está aberto na Linha Editorial. Abra-o lá primeiro."]]);
        }
        $exists = EditorialPost::where('company_id', $company->id)
            ->whereBetween('publish_date', [$date->startOfMonth()->toDateString(), $date->endOfMonth()->toDateString()])
            ->whereRaw('LOWER(title) = ?', [mb_strtolower(trim($data['title']))])->exists();
        if ($exists) {
            throw ValidationException::withMessages(['title' => ['Já existe uma publicação com este título nesse mês.']]);
        }

        $post = DB::transaction(function () use ($company, $user, $play, $data, $extra) {
            $post = $this->posts->createPostRecord($company, [
                'title' => trim($data['title']),
                'publish_date' => $data['publish_date'],
                'channel' => EditorialPost::CHANNEL_SOCIAL,
                'networks' => array_values(array_unique($data['networks'])),
                'format' => $data['format'],
                'stage' => EditorialPost::STAGE_IDEA,
            ]);
            $workflow = app(EditorialWorkflowService::class);
            if (! empty($extra['media_formats'])) {
                $networks = [];
                foreach (array_values(array_unique($data['networks'])) as $n) {
                    $networks[$n] = $extra['media_formats'][$n] ?? null;
                }
                $workflow->setNetworks($post->fresh('networks'), $user, $networks);
            }
            if (! empty($extra['content'])) {
                $workflow->saveContent($post->fresh(), $user, $extra['content']);
            }
            foreach ($play['signal_keys'] as $key) {
                CompassSignalAction::create(['provider' => self::PROVIDER, 'company_id' => $company->id, 'signal_key' => $key,
                    'action' => CompassSignalAction::POST_CREATED, 'editorial_post_id' => $post->id, 'user_id' => $user->id]);
            }
            foreach ($play['items'] as $item) {
                CompassPostSubject::create(['company_id' => $company->id, 'editorial_post_id' => $post->id, 'subject_type' => 'car', 'subject_id' => (int) $item['car_id']]);
            }

            return $post;
        });

        // As fotografias do veículo (jogada de um só veículo), a partir dos Ficheiros, sem cópia.
        $photos = 0;
        if (! empty($play['car_id']) && $this->photosAvailable($user, $company->id)) {
            try {
                if ($this->photoFiles($company->id, (int) $play['car_id']) === []) {
                    app(SystemFileCatalog::class)->syncIfStale($company->id); // regista as fotografias que ainda não estejam nos Ficheiros
                }
                $assets = array_map(fn (int $fileId) => app(FilesService::class)->mediaFor($user, $company->id, $fileId)->id,
                    $this->photoFiles($company->id, (int) $play['car_id']));
                if ($assets !== []) {
                    app(EditorialWorkflowService::class)->setMedia($post->fresh(), $user, $assets, null);
                    $photos = count($assets);
                }
            } catch (\Throwable $e) {
                Log::warning('[Bússola do stand] fotografias do veículo não juntas à publicação', ['post_id' => $post->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            }
        }
        RecomputeStandCompassJob::dispatch($company->id)->afterCommit(); // o veículo passa a ter uma publicação

        return ['post' => $post->fresh(), 'photos' => $photos];
    }

    /** Ignorar durante 4 semanas todas as chaves da jogada. */
    public function ignore(int $companyId, User $user, array $keys): void
    {
        $until = CarbonImmutable::now('Europe/Lisbon')->addDays(self::IGNORE_DAYS)->toDateString();
        foreach (array_unique($keys) as $key) {
            $this->assertSignal($companyId, $key);
            CompassSignalAction::create(['provider' => self::PROVIDER, 'company_id' => $companyId, 'signal_key' => $key,
                'action' => CompassSignalAction::IGNORED, 'hidden_until' => $until, 'user_id' => $user->id]);
        }
    }

    public function restore(int $companyId, User $user, string $key): void
    {
        CompassSignalAction::create(['provider' => self::PROVIDER, 'company_id' => $companyId, 'signal_key' => $key,
            'action' => CompassSignalAction::RESTORED, 'user_id' => $user->id]);
    }

    /** "Não voltar a sugerir este veículo": o veículo do sinal sai das jogadas (pode voltar a incluir-se). */
    public function exclude(int $companyId, User $user, string $key): int
    {
        $signal = $this->assertSignal($companyId, $key);
        $carId = (int) $signal->subject_id;
        if (! CompassExcludedSubject::where('provider', self::PROVIDER)->where('company_id', $companyId)->where('subject_type', 'car')
            ->where('subject_id', $carId)->whereNull('included_at')->exists()) {
            CompassExcludedSubject::create(['provider' => self::PROVIDER, 'company_id' => $companyId, 'subject_type' => 'car', 'subject_id' => $carId,
                'excluded_by_user_id' => $user->id, 'excluded_at' => now()]);
        }

        return $carId;
    }

    public function include(int $companyId, User $user, int $carId): void
    {
        CompassExcludedSubject::where('provider', self::PROVIDER)->where('company_id', $companyId)->where('subject_type', 'car')
            ->where('subject_id', $carId)->whereNull('included_at')->update(['included_at' => now(), 'included_by_user_id' => $user->id]);
    }

    private function assertSignal(int $companyId, string $key): CompassSignal
    {
        $signal = CompassSignal::where('provider', self::PROVIDER)->where('company_id', $companyId)->where('signal_key', $key)->first();
        if (! $signal || ! Car::where('company_id', $companyId)->whereKey($signal->subject_id)->exists()) {
            throw ValidationException::withMessages(['key' => ['Esta sugestão já não existe (os sinais foram recalculados). Atualize a página.']]);
        }

        return $signal;
    }
}
