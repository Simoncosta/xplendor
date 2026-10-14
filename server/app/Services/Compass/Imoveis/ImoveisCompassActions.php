<?php

declare(strict_types=1);

namespace App\Services\Compass\Imoveis;

use App\Jobs\RecomputeImoveisCompassJob;
use App\Models\Company;
use App\Models\CompassExcludedSubject;
use App\Models\CompassPostSubject;
use App\Models\CompassSignal;
use App\Models\CompassSignalAction;
use App\Models\EditorialMonth;
use App\Models\EditorialPost;
use App\Models\Property;
use App\Models\User;
use App\Services\Editorial\EditorialWorkflowService;
use App\Services\EditorialPostService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bússola do imobiliário: as ações das jogadas, como no stand (sem as fotografias, que se
 * escolhem na Linha Editorial). Criar a ideia liga a publicação ao imóvel (ou à freguesia, na
 * jogada da zona), para o imóvel não voltar a ser sugerido nos 30 dias seguintes e para o aviso
 * da classe energética.
 */
final class ImoveisCompassActions
{
    public const PROVIDER = ImoveisSignalService::PROVIDER;
    public const IGNORE_DAYS = 28;

    public function __construct(private readonly EditorialPostService $posts) {}

    public function createPost(Company $company, User $user, array $play, array $data, array $extra = []): EditorialPost
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
        if (EditorialPost::where('company_id', $company->id)->whereBetween('publish_date', [$date->startOfMonth()->toDateString(), $date->endOfMonth()->toDateString()])
            ->whereRaw('LOWER(title) = ?', [mb_strtolower(trim($data['title']))])->exists()) {
            throw ValidationException::withMessages(['title' => ['Já existe uma publicação com este título nesse mês.']]);
        }

        $post = DB::transaction(function () use ($company, $user, $play, $data, $extra) {
            $networks = array_values(array_unique($data['networks']));
            $post = $this->posts->createPostRecord($company, ['title' => trim($data['title']), 'publish_date' => $data['publish_date'],
                'channel' => EditorialPost::CHANNEL_SOCIAL, 'networks' => $networks, 'format' => $data['format'], 'stage' => EditorialPost::STAGE_IDEA]);
            $workflow = app(EditorialWorkflowService::class);
            if (! empty($extra['media_formats'])) {
                $workflow->setNetworks($post->fresh('networks'), $user, array_combine($networks, array_map(fn ($n) => $extra['media_formats'][$n] ?? null, $networks)));
            }
            if (! empty($extra['content'])) {
                $workflow->saveContent($post->fresh(), $user, $extra['content']);
            }
            foreach ((array) $play['signal_keys'] as $key) {
                CompassSignalAction::create(['provider' => self::PROVIDER, 'company_id' => $company->id, 'signal_key' => $key, 'action' => 'post_created',
                    'editorial_post_id' => $post->id, 'user_id' => $user->id]);
            }
            foreach ((array) $play['items'] as $it) {
                CompassPostSubject::create(['company_id' => $company->id, 'editorial_post_id' => $post->id, 'subject_type' => 'property', 'subject_id' => (int) $it['property_id'], 'source' => 'bussola']);
            }
            if (! empty($play['parish_id'])) {
                CompassPostSubject::create(['company_id' => $company->id, 'editorial_post_id' => $post->id, 'subject_type' => 'parish', 'subject_id' => (int) $play['parish_id'], 'source' => 'bussola']);
            }

            return $post;
        });
        RecomputeImoveisCompassJob::dispatch($company->id)->afterCommit();

        return $post;
    }

    public function ignore(int $companyId, User $user, array $keys): void
    {
        foreach ($keys as $key) {
            $this->assertSignal($companyId, $key);
            CompassSignalAction::create(['provider' => self::PROVIDER, 'company_id' => $companyId, 'signal_key' => $key, 'action' => 'ignored',
                'hidden_until' => CarbonImmutable::now('Europe/Lisbon')->addDays(self::IGNORE_DAYS)->toDateString(), 'user_id' => $user->id]);
        }
    }

    public function restore(int $companyId, User $user, string $key): void
    {
        CompassSignalAction::create(['provider' => self::PROVIDER, 'company_id' => $companyId, 'signal_key' => $key, 'action' => 'restored', 'user_id' => $user->id]);
    }

    /** "Não voltar a sugerir este imóvel": devolve o id do imóvel. */
    public function exclude(int $companyId, User $user, string $key): int
    {
        $signal = $this->assertSignal($companyId, $key);
        if ($signal->subject_type !== 'property') {
            throw ValidationException::withMessages(['key' => ['Só um imóvel pode deixar de ser sugerido.']]);
        }
        $id = (int) $signal->subject_id;
        if (! CompassExcludedSubject::where('provider', self::PROVIDER)->where('company_id', $companyId)->where('subject_type', 'property')->where('subject_id', $id)->whereNull('included_at')->exists()) {
            CompassExcludedSubject::create(['provider' => self::PROVIDER, 'company_id' => $companyId, 'subject_type' => 'property', 'subject_id' => $id,
                'excluded_by_user_id' => $user->id, 'excluded_at' => now()]);
        }

        return $id;
    }

    private function assertSignal(int $companyId, string $key): CompassSignal
    {
        $s = CompassSignal::where('provider', self::PROVIDER)->where('company_id', $companyId)->where('signal_key', $key)->first();
        $owned = $s && ($s->subject_type !== 'property' || Property::where('company_id', $companyId)->whereKey($s->subject_id)->exists());
        if (! $owned) {
            throw ValidationException::withMessages(['key' => ['Esta sugestão já não existe (os sinais foram recalculados). Atualize a página.']]);
        }

        return $s;
    }
}
