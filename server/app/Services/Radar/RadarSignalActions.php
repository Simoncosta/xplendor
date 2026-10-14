<?php

declare(strict_types=1);

namespace App\Services\Radar;

use App\Models\Company;
use App\Models\EditorialMonth;
use App\Models\EditorialPost;
use App\Models\RadarSetting;
use App\Models\RadarSignal;
use App\Models\User;
use App\Services\Editorial\EditorialWorkflowService;
use App\Services\EditorialPostService;
use App\Services\Radar\Calendar\OfficialDates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Radar: as ações do cartão. "Reagir" cria uma ideia (etapa Ideia) na Linha Editorial pelo mesmo
 * caminho da Bússola do stand, com o gancho, as fontes e o prazo, e SEM texto (o "Sugerir texto"
 * da Linha Editorial continua disponível, só a pedido). "Ignorar" tira o cartão. "Não mostrar
 * temas como este" tira-o e guarda o tema nas definições (reversível).
 */
class RadarSignalActions
{
    public function __construct(private readonly EditorialPostService $posts) {}

    public function signal(int $companyId, int $signalId): RadarSignal
    {
        $s = RadarSignal::with('topic')->where('company_id', $companyId)->whereKey($signalId)->first();
        if (! $s || $s->status !== RadarSignal::NEW) {
            throw ValidationException::withMessages(['signal_id' => ['Esta sugestão já não existe ou já foi tratada. Atualize a página.']]);
        }

        return $s;
    }

    /**
     * A ideia com prazo, sem texto (comum ao Radar e ao calendário).
     *
     * @param array{title: string, publish_date: string, networks: list<string>, format: string, media_formats?: array<string, ?string>} $data
     * @param array{brief: string, sources: list<array<string, mixed>>, valid_until: ?string, origin: string, origin_ref: string} $hook
     */
    public function createIdea(Company $company, User $user, array $data, array $hook): EditorialPost
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

        return DB::transaction(function () use ($company, $user, $data, $hook) {
            $networks = array_values(array_unique($data['networks']));
            $post = $this->posts->createPostRecord($company, [
                'title' => trim($data['title']), 'publish_date' => $data['publish_date'], 'channel' => EditorialPost::CHANNEL_SOCIAL,
                'networks' => $networks, 'format' => $data['format'], 'stage' => EditorialPost::STAGE_IDEA,
            ]);
            if (! empty($data['media_formats'])) {
                $map = [];
                foreach ($networks as $n) {
                    $map[$n] = $data['media_formats'][$n] ?? null;
                }
                app(EditorialWorkflowService::class)->setNetworks($post->fresh('networks'), $user, $map);
            }
            $post->forceFill([
                'brief' => mb_substr($hook['brief'], 0, 2000), 'sources' => $hook['sources'], 'valid_until' => $hook['valid_until'],
                'origin' => $hook['origin'], 'origin_ref' => mb_substr($hook['origin_ref'], 0, 160),
            ])->save();

            return $post;
        });
    }

    public function react(Company $company, User $user, RadarSignal $signal, array $data): EditorialPost
    {
        $lines = ['Está a subir: ' . $signal->topic->title . '.', $signal->why];
        foreach ((array) $signal->flags as $f) {
            if (isset(RadarPage::NOTICES[$f])) {
                $lines[] = RadarPage::NOTICES[$f];
            }
        }
        $post = $this->createIdea($company, $user, $data, [
            'brief' => implode("\n", $lines), 'sources' => (array) $signal->sources,
            'valid_until' => CarbonImmutable::parse($signal->window_until)->setTimezone('Europe/Lisbon')->toDateString(),
            'origin' => 'radar', 'origin_ref' => 'radar:' . $signal->topic_id,
        ]);
        $signal->update(['status' => RadarSignal::REACTED, 'editorial_post_id' => $post->id, 'acted_at' => now(), 'acted_by_user_id' => $user->id]);

        return $post;
    }

    /** Camada 2: "Reagir" a uma data do calendário (uma ideia por data e por empresa). */
    public function reactToDate(Company $company, User $user, string $key, string $occurrence, array $data): EditorialPost
    {
        $entry = OfficialDates::find($key, $occurrence);
        if ($entry === null) {
            throw ValidationException::withMessages(['calendar_key' => ['Esta data não está no calendário.']]);
        }
        if (EditorialPost::where('company_id', $company->id)->where('origin', 'calendario')->where('origin_ref', "{$key}@{$occurrence}")->exists()) {
            throw ValidationException::withMessages(['calendar_key' => ['Já existe uma ideia para esta data.']]);
        }
        $lines = array_filter([$entry['title'] . '.', $entry['note'], 'Fonte: ' . $entry['source']['name'] . ' (confirmada a ' . CarbonImmutable::parse($entry['confirmed_on'])->format('d/m/Y') . ').']);

        return $this->createIdea($company, $user, $data, [
            'brief' => implode("\n", $lines),
            'sources' => [['title' => $entry['title'], 'source_name' => $entry['source']['name'], 'url' => $entry['source']['url'], 'published_at' => null]],
            'valid_until' => $entry['ends_on'], 'origin' => 'calendario', 'origin_ref' => "{$key}@{$occurrence}",
        ]);
    }

    public function ignore(RadarSignal $signal, User $user): void
    {
        $signal->update(['status' => RadarSignal::IGNORED, 'acted_at' => now(), 'acted_by_user_id' => $user->id]);
    }

    public function mute(RadarSignal $signal, User $user): void
    {
        DB::transaction(function () use ($signal, $user) {
            $slug = \App\Models\Company::with('contentSector')->find($signal->company_id)?->contentSector?->slug;
            $theme = $slug ? $signal->topic->relevances()->where('sector_slug', $slug)->value('theme') : null;
            $row = RadarSetting::firstOrNew(['company_id' => $signal->company_id]);
            $muted = array_values((array) ($row->muted ?? []));
            $muted[] = ['theme' => $theme, 'term' => RadarText::normalize($signal->topic->title), 'title' => mb_substr($signal->topic->title, 0, 255),
                'muted_at' => now()->toIso8601String(), 'user_id' => $user->id];
            $row->fill(['muted' => array_slice($muted, -100), 'updated_by_user_id' => $user->id])->save();
            $signal->update(['status' => RadarSignal::MUTED, 'acted_at' => now(), 'acted_by_user_id' => $user->id]);
        });
    }

    public function unmute(int $companyId, int $index, User $user): void
    {
        $row = RadarSetting::where('company_id', $companyId)->first();
        $muted = array_values((array) ($row->muted ?? []));
        if (! $row || ! array_key_exists($index, $muted)) {
            throw ValidationException::withMessages(['index' => ['Este tema já não está silenciado.']]);
        }
        array_splice($muted, $index, 1);
        $row->update(['muted' => $muted, 'updated_by_user_id' => $user->id]);
    }
}
