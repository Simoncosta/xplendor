<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Models\Company;
use App\Models\EditorialMonth;
use App\Models\EditorialPost;
use App\Models\PingwinLocation;
use App\Models\RestaurantDataQuality;
use App\Models\RestaurantSignal;
use App\Models\RestaurantSignalAction;
use App\Models\User;
use App\Services\CompanyModuleService;
use App\Services\Editorial\EditorialWorkflowService;
use App\Services\EditorialPostService;
use App\Services\PingwinItemSalesService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * XPLENDOR — F3: o painel "O que publicar e quando" (documents/PINGWIN-F3-DESENHO.md §2).
 *  · lê os sinais calculados (recalcula se o cálculo tiver mais de 24 horas);
 *  · junta o estado de cada sugestão: ignorada (até quando) e publicação criada;
 *  · "Criar publicação": uma ideia na Linha Editorial (mesmo caminho das ideias da IA);
 *  · "Ignorar": esconde durante 4 semanas, com registo; "Voltar a mostrar" desfaz.
 * Ver: qualquer utilizador da empresa. Criar e ignorar: quem produz na Linha Editorial.
 */
class RestaurantSignalPanelService
{
    public const SUGGESTION_TYPES = ['weak_period', 'item_up', 'item_down', 'stale_item'];

    /** Dias especiais enviados ao modal (tipo "Sazonal" por omissão nessas datas). */
    public const SPECIAL_DAYS_AHEAD = 90;

    public function __construct(
        private readonly RestaurantSignalService $signals,
        private readonly EditorialPostService $posts,
        private readonly CompanyModuleService $modules,
        private readonly RestaurantSpecialDays $specialDays,
    ) {}

    /** Pode criar publicações e ignorar sugestões? Devolve [pode, motivo se não pode]. */
    public function canAct(User $user, int $companyId): array
    {
        if (! $this->modules->isEnabled($companyId, 'linha_editorial')) {
            return [false, 'A Linha Editorial não está ativa nesta empresa.'];
        }
        if (! EditorialWorkflowService::isProducer($user, $companyId)) {
            return [false, 'Só quem produz na Linha Editorial pode criar publicações ou ignorar sugestões.'];
        }

        return [true, null];
    }

    public function panel(int $companyId, User $user, ?int $locationId = null, bool $showIgnored = false): array
    {
        $enabled = PingwinItemSalesService::isEnabled($companyId);
        $locations = PingwinLocation::where('company_id', $companyId)->where('is_active', true)->orderBy('id')->get();
        [$canAct, $reason] = $this->canAct($user, $companyId);
        $base = [
            'enabled' => $enabled,
            'locations' => $locations->map(fn (PingwinLocation $l) => ['id' => $l->id, 'name' => $l->display_name ?: $l->winrest_name ?: (string) $l->winrest_store_id])->values()->all(),
            'location_id' => $locationId,
            'can_act' => $canAct,
            'can_act_reason' => $reason,
            'formats' => EditorialPost::FORMATS,
            'networks' => EditorialPost::NETWORKS,
            'ignore_days' => RestaurantSignalService::IGNORE_DAYS,
            'show_ignored' => $showIgnored,
            'special_days' => [],
        ];
        if (! $enabled) {
            return $base + ['computed_at' => null, 'suggestions' => [], 'changes' => [], 'top_items' => [], 'top_categories' => [],
                'lead_time' => [], 'delivery' => [], 'channels' => [], 'availability' => [], 'hidden_count' => 0, 'categories_pending' => 0];
        }

        $this->signals->ensureFresh($companyId);
        $quality = RestaurantDataQuality::where('company_id', $companyId)->first();
        $state = $this->actionState($companyId);
        $today = CarbonImmutable::now('Europe/Lisbon')->toDateString();

        $all = RestaurantSignal::where('company_id', $companyId)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->orderByDesc('priority')->orderBy('id')->get();
        $names = $base['locations'] ? array_column($base['locations'], 'name', 'id') : [];
        $hiddenCount = 0;
        $dto = function (RestaurantSignal $s) use ($state, $names, $today, &$hiddenCount) {
            $st = $state[$s->signal_key] ?? [];
            $hiddenUntil = $st['hidden_until'] ?? null;
            $hidden = $hiddenUntil !== null && $hiddenUntil >= $today;

            return [
                'key' => $s->signal_key,
                'type' => $s->type,
                'kind' => $s->kind,
                'location_id' => $s->location_id,
                'location' => $names[$s->location_id] ?? null,
                'confidence' => $s->confidence,
                'title' => $s->title,
                'sentence' => $s->sentence,
                'numbers' => $s->numbers,
                'sample' => $s->sample,
                'theme' => $s->theme,
                'suggested_date' => $s->suggested_date?->toDateString(),
                'priority' => $s->priority,
                'hidden' => $hidden,
                'hidden_until' => $hidden ? $hiddenUntil : null,
                'post' => $st['post'] ?? null,
            ];
        };
        $rows = $all->map($dto)->all();
        $suggestions = array_values(array_filter($rows, fn ($r) => in_array($r['type'], self::SUGGESTION_TYPES, true)));
        $hiddenCount = count(array_filter($suggestions, fn ($r) => $r['hidden']));
        if (! $showIgnored) {
            $suggestions = array_values(array_filter($suggestions, fn ($r) => ! $r['hidden']));
        }
        $byType = fn (array $types) => array_values(array_filter($rows, fn ($r) => in_array($r['type'], $types, true)));

        $pending = \App\Models\RestaurantFamilyCategory::where('company_id', $companyId)->whereNull('category')->count();

        $company = Company::find($companyId);
        $base['special_days'] = $company
            ? $this->specialDays->between($company, $today, CarbonImmutable::parse($today)->addDays(self::SPECIAL_DAYS_AHEAD)->toDateString())
            : [];

        return $base + [
            'computed_at' => $quality?->signals_computed_at?->toIso8601String(),
            'suggestions' => $suggestions,
            'changes' => array_values(array_filter($suggestions, fn ($r) => in_array($r['type'], ['item_up', 'item_down'], true))),
            'top_items' => $byType(['top_items']),
            'top_categories' => $byType(['top_categories']),
            'lead_time' => $byType(['lead_time']),
            'delivery' => $byType(['delivery_share']),
            'channels' => $byType(['channels']),
            'availability' => array_values(array_filter((array) ($quality?->signals_availability ?? []), fn ($a) => ! $locationId || (int) $a['location_id'] === $locationId)),
            'hidden_count' => $hiddenCount,
            'categories_pending' => $pending,
        ];
    }

    /** Estado atual de cada sinal, a partir do registo: ignorado até, e a publicação criada. */
    private function actionState(int $companyId): array
    {
        $state = [];
        $postIds = [];
        foreach (RestaurantSignalAction::where('company_id', $companyId)->orderBy('id')->get() as $a) {
            $k = $a->signal_key;
            match ($a->action) {
                RestaurantSignalAction::IGNORED => $state[$k]['hidden_until'] = $a->hidden_until?->toDateString(),
                RestaurantSignalAction::RESTORED => $state[$k]['hidden_until'] = null,
                RestaurantSignalAction::POST_CREATED => $state[$k]['post_id'] = $a->editorial_post_id,
                default => null,
            };
            if ($a->editorial_post_id) {
                $postIds[] = $a->editorial_post_id;
            }
        }
        $posts = EditorialPost::whereIn('id', array_unique($postIds))->get(['id', 'title', 'publish_date', 'stage'])->keyBy('id');
        foreach ($state as $k => $st) {
            $post = isset($st['post_id']) ? $posts->get($st['post_id']) : null;
            $state[$k]['post'] = $post ? ['id' => $post->id, 'title' => $post->title, 'publish_date' => $post->publish_date->toDateString(), 'stage' => $post->stage] : null;
        }

        return $state;
    }

    private function findSignal(int $companyId, string $key): RestaurantSignal
    {
        $signal = RestaurantSignal::where('company_id', $companyId)->where('signal_key', $key)->first();
        if (! $signal) {
            throw ValidationException::withMessages(['key' => ['Esta sugestão já não existe (os sinais foram recalculados). Atualize a página.']]);
        }

        return $signal;
    }

    /** Esconde a sugestão durante 4 semanas (com registo). */
    public function ignore(int $companyId, string $key, User $user): string
    {
        $this->findSignal($companyId, $key);
        $until = CarbonImmutable::now('Europe/Lisbon')->addDays(RestaurantSignalService::IGNORE_DAYS)->toDateString();
        RestaurantSignalAction::create(['company_id' => $companyId, 'signal_key' => $key, 'action' => RestaurantSignalAction::IGNORED,
            'hidden_until' => $until, 'user_id' => $user->id]);

        return $until;
    }

    /** Volta a mostrar uma sugestão ignorada (com registo). */
    public function restore(int $companyId, string $key, User $user): void
    {
        RestaurantSignalAction::create(['company_id' => $companyId, 'signal_key' => $key, 'action' => RestaurantSignalAction::RESTORED, 'user_id' => $user->id]);
    }

    /**
     * Cria uma ideia na Linha Editorial a partir de uma sugestão, ou de uma jogada da Bússola
     * (vários sinais; o formato de cada rede e, se vier, o texto da legenda escolhida). O tema
     * e a data vêm do modal, revistos pela pessoa. Nunca uma data passada. O mês tem de estar
     * aberto: nunca se abre sozinho.
     *
     * @param string[]|null $signalKeys os sinais da jogada (por omissão, o sinal de $data['key'])
     * @param array{media_formats?: array<string, string>, content?: array} $extra
     */
    public function createPost(Company $company, array $data, User $user, ?array $signalKeys = null, array $extra = []): EditorialPost
    {
        $signalKeys ??= [$data['key']];
        foreach ($signalKeys as $key) {
            $this->findSignal($company->id, $key);
        }
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

        return DB::transaction(function () use ($company, $data, $user, $signalKeys, $extra) {
            $post = $this->posts->createPostRecord($company, [
                'title' => trim($data['title']),
                'publish_date' => $data['publish_date'],
                'channel' => EditorialPost::CHANNEL_SOCIAL,
                'networks' => array_values(array_unique($data['networks'])),
                'format' => $data['format'],
                'stage' => EditorialPost::STAGE_IDEA, // entra em "Ideia", como as ideias aceites da IA
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
            foreach ($signalKeys as $key) {
                RestaurantSignalAction::create(['company_id' => $company->id, 'signal_key' => $key, 'action' => RestaurantSignalAction::POST_CREATED,
                    'editorial_post_id' => $post->id, 'user_id' => $user->id]);
            }

            return $post->fresh();
        });
    }
}
