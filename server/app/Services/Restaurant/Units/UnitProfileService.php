<?php

declare(strict_types=1);

namespace App\Services\Restaurant\Units;

use App\Audit\AuditFindingStates;
use App\Audit\AuditRunInProgress;
use App\Audit\AuditRunService;
use App\Audit\Fmt;
use App\Jobs\ComputeMenuEngineeringJob;
use App\Jobs\WritePingwinUnitJob;
use App\Models\AuditFinding;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinUnitWrite;
use App\Models\ProductUnitConversion;
use App\Models\ProductUnitProfile;
use App\Models\ProductUnitProfileEvent;
use App\Models\User;
use App\Audit\Rules\Restaurant\RestaurantAuditData;
use App\Audit\Rules\Restaurant\Units\UnidadeGlobalIncoerenteRule;
use App\Services\AlertService;
use App\Services\PingwinService;
use App\Support\Storage\PrivateFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * XPLENDOR — Auditoria v2: o Guião de unidades. A fila pelo Pareto (€ comprado em 90 dias), a vista
 * de um artigo (o que vimos, as perguntas, a proposta, o que muda, os achados, a IA, o plano do
 * PingWin) e as decisões. Nada passa a confirmado sem uma pessoa carregar em "Confirmar"; o PingWin
 * só muda em "Aplicar no PingWin", uma alteração de cada vez, com releitura (e só com a prova feita).
 * As escolhas confirmadas viram a resposta por omissão dos artigos parecidos da mesma família
 * (sempre como sugestão).
 */
final class UnitProfileService
{
    /** Os achados que uma decisão do Guião corrige (as regras U e as de unidades/fatores). */
    public const RULE_PREFIXES = ['restauracao.unidade_', 'restauracao.transfer_fator'];

    private ?UnitEvidence $evidence = null;

    private ?array $learned = null;

    public function __construct(private int $companyId) {}

    public function evidence(): UnitEvidence
    {
        return $this->evidence ??= UnitEvidence::forCompany($this->companyId);
    }

    /** A confiança final: a das evidências, mais o que o classificador conseguiu (uso, descrição). */
    public static function confidenceOf(array $e, array $proposal): float
    {
        $s = (float) ($e['confidence']['score'] ?? 0);
        if (! $proposal['identified']) {
            return round(min($s, 0.45), 2);
        }
        $s += ($proposal['signals']['uso'] ?? null) ? 0.3 : 0.0;
        $s += ($proposal['signals']['descricao'] ?? null) ? 0.1 : 0.0;
        $s += ($proposal['signals']['faturado'] ?? null) ? 0.2 : 0.0;
        $s += $proposal['complete'] ? 0.1 : 0.0;
        $s -= ($e['confidence']['contradictions'] ?? []) ? 0.1 : 0.0;

        return round(max(0.0, min(1.0, $s)), 2);
    }

    /** O habitual nas confirmações da mesma família (base e tipo, com pelo menos 2 iguais). */
    public function learnedFor(array $e): ?array
    {
        if ($this->learned === null) {
            $this->learned = [];
            $items = PingwinCatalogItem::where('company_id', $this->companyId)->pluck('family', 'pingwin_id');
            foreach (ProductUnitProfile::where('company_id', $this->companyId)->whereIn('state', ['confirmado', 'aplicado_no_pingwin'])->get() as $p) {
                $fam = \App\Audit\Rules\Restaurant\RestaurantAuditData::familyPath($items[$p->product_pingwin_id] ?? null);
                if ($fam !== '' && $p->base_confirmed) {
                    $this->learned[$fam][$p->base_confirmed . '|' . $p->article_type] = ($this->learned[$fam][$p->base_confirmed . '|' . $p->article_type] ?? 0) + 1;
                }
            }
        }
        $counts = $this->learned[$e['family']] ?? [];
        arsort($counts);
        $top = array_key_first($counts);
        if ($top === null || $counts[$top] < 2) {
            return null;
        }
        [$base, $type] = explode('|', $top);

        return ['base' => $base, 'type' => $type, 'family' => $e['family_leaf'] ?: $e['family'], 'count' => $counts[$top]];
    }

    // ── A fila ──────────────────────────────────────────────────────────────────────────────

    /**
     * A fila do Guião pelo Pareto (€ comprado em 90 dias): os artigos de stock com achados de unidades
     * abertos ou por decidir. "fila": todos | por_identificar | administrador. Os saltados vão para o fim.
     */
    public function queue(string $filter = 'todos'): array
    {
        $open = $this->openFindingsByArticle();
        // Os artigos de agrupamento lançam-se sempre com quantidade 1: não entram no Guião.
        $grouping = \App\Models\ArticleGrouping::pingwinIdsFor($this->companyId);
        $items = [];
        foreach ($this->evidence()->all() as $pid => $e) {
            $pid = (string) $pid;
            if (! ($e['stock_relevant'] ?? true) || isset($grouping[$pid])) {
                continue;
            }
            $state = $e['profile']['state'] ?? null;
            if (in_array($state, ['confirmado', 'aplicado_no_pingwin', 'nao_se_aplica'], true) && $filter !== 'decididos') {
                continue;
            }
            $findings = $open[$pid] ?? [];
            if (! $findings && $e['spend_90d_eur'] <= 0) {
                continue;
            }
            $proposal = UnitProposal::build($e, (array) ($this->profileModel($pid)?->answers ?? []), $this->learnedFor($e));
            $conf = self::confidenceOf($e, $proposal);
            $unknown = ! $proposal['identified'] || $conf < ProductUnitProfile::MIN_CONFIDENCE;
            $assigned = (bool) array_filter($findings, fn ($f) => $f['assigned_to'] === 'administrador');
            if (($filter === 'por_identificar' && ! $unknown) || ($filter === 'administrador' && ! $assigned)) {
                continue;
            }
            $verdict = UnitVerdict::of($e, $proposal, UnitImpact::build($e, $proposal, null), $this->plan($e, $proposal, $this->profileModel($pid))['steps'], $unknown,
                (string) ($this->evidence()->data()->item($pid)?->default_purchase_unit_id ?? ''));
            $items[] = [
                'verdict' => $verdict['key'], 'verdict_text' => $verdict['text'],
                'product_id' => $pid, 'label' => $e['label'], 'family' => $e['family'], 'spend_90d_eur' => $e['spend_90d_eur'],
                'state' => $state ?? ($unknown ? 'por_identificar' : 'sugerido'), 'por_identificar' => $unknown, 'confidence' => $conf,
                'findings' => count($findings), 'impact_cents' => array_sum(array_column($findings, 'impact_cents')) ?: null,
                'pending_cents' => array_sum(array_column($findings, 'impact_pending_cents')) ?: null,
                'assigned' => $assigned, 'skipped' => (bool) $this->profileModel($pid)?->skipped_at,
            ];
        }
        // Por veredicto (precisa de mudança, por identificar, está certo) e, em cada grupo, pelo € comprado.
        usort($items, fn ($a, $b) => [UnitVerdict::ORDER[$a['verdict']], $a['skipped'], -$a['spend_90d_eur'], -($a['impact_cents'] ?? 0)]
            <=> [UnitVerdict::ORDER[$b['verdict']], $b['skipped'], -$b['spend_90d_eur'], -($b['impact_cents'] ?? 0)]);
        $this->syncProfiles($items);

        return [
            'items' => $items,
            'total' => count($items),
            'counts' => ['por_identificar' => count(array_filter($items, fn ($i) => $i['por_identificar'])), 'administrador' => count(array_filter($items, fn ($i) => $i['assigned'])),
                'mudar' => count(array_filter($items, fn ($i) => $i['verdict'] === 'mudar')), 'ajuda' => count(array_filter($items, fn ($i) => $i['verdict'] === 'ajuda')),
                'certo' => count(array_filter($items, fn ($i) => $i['verdict'] === 'certo'))],
        ];
    }

    /** Guarda o estado sugerido/por identificar dos artigos da fila (os decididos não mudam). */
    private function syncProfiles(array $items): void
    {
        foreach ($items as $i) {
            $p = $this->profileModel($i['product_id']);
            // "Por rever" (a unidade base mudou no PingWin) fica até alguém confirmar de novo.
            if ($p && ($p->isDecided() || $p->state === ProductUnitProfile::STATE_REVIEW)) {
                continue;
            }
            $state = $i['por_identificar'] ? 'por_identificar' : 'sugerido';
            if (! $p) {
                ProductUnitProfile::create(['company_id' => $this->companyId, 'product_pingwin_id' => $i['product_id'], 'state' => $state, 'confidence' => $i['confidence']]);
            } elseif ($p->state !== $state || abs((float) $p->confidence - $i['confidence']) > 0.001) {
                $p->update(['state' => $state, 'confidence' => $i['confidence']]);
            }
        }
        $this->profiles = null;
    }

    private ?array $profiles = null;

    private function profileModel(string $pid): ?ProductUnitProfile
    {
        if ($this->profiles === null) {
            $this->profiles = ProductUnitProfile::where('company_id', $this->companyId)->get()->keyBy(fn ($p) => (string) $p->product_pingwin_id)->all();
        }

        return $this->profiles[$pid] ?? null;
    }

    /** Os achados abertos das regras de unidades, por artigo (pela entidade e pelas referências). */
    private function openFindingsByArticle(?string $only = null): array
    {
        $q = AuditFinding::where('company_id', $this->companyId)->whereIn('state', AuditFinding::OPEN)
            ->where(function ($q) {
                foreach (self::RULE_PREFIXES as $prefix) {
                    $q->orWhere('rule', 'like', $prefix . '%');
                }
            });
        $out = [];
        foreach ($q->with('refs')->get() as $f) {
            $pids = [];
            if ($f->entity_type === 'artigo') {
                $pids[] = (string) $f->entity_id;
            } elseif (in_array($f->entity_type, ['artigo_unidade'], true)) {
                $pids[] = (string) preg_split('/[|:]/', (string) $f->entity_id)[0];
            }
            foreach ($f->refs as $r) {
                if ($r->entity_type === 'artigo' && $f->entity_type !== 'artigo') {
                    $pids[] = (string) $r->entity_id;
                }
            }
            foreach (array_unique($pids) as $pid) {
                if ($only !== null && $pid !== $only) {
                    continue;
                }
                $out[$pid][] = ['id' => $f->id, 'rule' => $f->rule, 'title' => $f->title, 'severity' => $f->severity, 'state' => $f->state,
                    'impact_cents' => $f->impact_cents, 'impact_pending_cents' => $f->impact_pending_cents, 'assigned_to' => $f->assigned_to];
            }
        }

        return $out;
    }

    // ── Um artigo ───────────────────────────────────────────────────────────────────────────

    /** A vista do Guião de um artigo (com as respostas dadas, guardadas ou novas). */
    public function view(string $pid, ?array $answers = null, ?User $user = null): array
    {
        $e = $this->evidence()->for($pid);
        if (! $e) {
            throw ValidationException::withMessages(['artigo' => ['Este artigo não tem compras nos últimos 12 meses nem entra em fichas.']]);
        }
        $profile = $this->profileModel($pid);
        $answers = array_merge((array) ($profile?->answers ?? []), $answers ?? []);
        $learned = $this->learnedFor($e);
        $proposal = UnitProposal::build($e, $answers, $learned);
        $findings = $this->openFindingsByArticle($pid)[$pid] ?? [];
        $oldPrice = $this->evidence()->cost()->purchaseOf($pid)['avg'] ?? null;
        $impact = UnitImpact::build($e, $proposal, $oldPrice, (int) array_sum(array_map(fn ($f) => (int) ($f['impact_cents'] ?? $f['impact_pending_cents'] ?? 0), $findings)));
        $conf = self::confidenceOf($e, $proposal);
        $code = $e['code'];
        $guard = PingwinService::unitWriteGuard($code, $this->companyId);
        $plan = $this->plan($e, $proposal, $profile);
        $unknown = ! $proposal['identified'] || $conf < ProductUnitProfile::MIN_CONFIDENCE;
        $currentPurchase = (string) PingwinCatalogItem::where('company_id', $this->companyId)->where('pingwin_id', $pid)->value('default_purchase_unit_id');
        $verdict = UnitVerdict::of($e, $proposal, $impact, $plan['steps'], $unknown, $currentPurchase);

        return [
            'article' => ['product_id' => $pid, 'code' => $code, 'label' => $e['label'], 'description' => $e['description'], 'family' => $e['family'],
                'base_unit' => $e['base_unit'], 'base_family' => $e['base_family'], 'url' => "/cadastros/artigos/{$pid}"],
            'profile' => $profile ? [
                'state' => $profile->state, 'base_confirmed' => $profile->base_confirmed, 'type' => $profile->article_type,
                'count_unit' => $profile->count_unit, 'confirmed_by' => $profile->confirmer?->name, 'confirmed_at' => $profile->confirmed_at?->toIso8601String(),
                'reason' => $profile->reason, 'info_text' => $profile->info_text, 'has_photo' => (bool) $profile->info_photo_path,
                'photo_url' => $profile->info_photo_path ? PrivateFiles::unitPhotoUrl($profile->id) : null,
                'skipped' => (bool) $profile->skipped_at,
            ] : null,
            'answers' => $answers,
            'por_identificar' => $unknown,
            'confidence' => $conf,
            // A conclusão antes das evidências (apresentação: não muda a lógica).
            'verdict' => $verdict,
            'confidence_label' => UnitVerdict::confidence($conf, $e, $proposal),
            'changes' => UnitVerdict::changes($e, $proposal, $impact, $plan['steps'], $currentPurchase),
            'seen' => $this->seen($e, $proposal, $conf),
            'evidence' => [
                'purchases' => array_map(fn ($p) => $p + ['document_url' => $p['last_doc'] ? '/restauracao/faturas?tab=pingwin&doc=' . rawurlencode((string) $p['last_doc']) : null],
                    $e['purchases']),
                'packaging' => $e['packaging'], 'recipes' => $e['recipes'], 'counts' => $e['counts'], 'transfers' => $e['transfers'],
                'sold_one_to_one' => $e['sold_one_to_one'], 'sold_qty_90d' => $e['sold_qty_90d'], 'price_per_base' => $e['price_per_base'],
                'family_median' => $e['family_median'], 'price_ratio' => $e['price_ratio'], 'units' => $e['units'], 'spend_90d_eur' => $e['spend_90d_eur'],
                'contradictions' => array_values(array_unique(array_merge($proposal['conflicts'], $e['confidence']['contradictions']))),
            ],
            'proposal' => $proposal,
            'impact' => $impact,
            'findings' => $findings,
            'ai' => UnitAdvisor::availability() + ['suggestion' => $profile?->ai_suggestion],
            'apply' => [
                'proven' => (bool) \App\Settings\Settings::platform('pingwin.proven.units'), 'guard' => $guard, 'steps' => $plan['steps'], 'manual' => $plan['manual'],
                'can_apply' => $guard === null && $plan['steps'] && in_array($profile?->state, ['confirmado', 'aplicado_no_pingwin'], true),
            ],
            'recipes_conversion' => $this->recipesConversion($e, $proposal, $impact, $profile, $answers),
            'history' => $profile ? $profile->events()->with('user:id,name')->limit(30)->get()->map(fn ($ev) => [
                'action' => $ev->action, 'user' => $ev->user?->name, 'reason' => $ev->reason, 'at' => $ev->created_at?->toIso8601String(),
            ])->all() : [],
        ];
    }

    /**
     * "O que vimos": as evidências em linguagem simples, cada uma com a fonte à vista (faturas, fichas,
     * contagens, PingWin), os números em pt-PT com a unidade e os períodos explícitos.
     *
     * @return list<array{source: string, text: string}>
     */
    private function seen(array $e, array $proposal, float $conf): array
    {
        $out = [];
        $add = function (string $source, string $text) use (&$out) {
            $out[] = ['source' => $source, 'text' => $text];
        };
        if ($e['purchases']) {
            $first = min(array_column($e['purchases'], 'first_date'));
            $add('faturas', sprintf('Comprado em 90 dias: %s. %s: %s.', self::eur($e['spend_90d_eur']), ucfirst(self::since($first, $e['today'] ?? null)), self::eur($e['spend_12m_eur'])));
            foreach (array_slice($e['purchases'], 0, 5) as $p) {
                $unit = self::unitShort($p['unit_label']);
                $add('faturas', sprintf('%s: %s a %s (%s) %s, na descrição «%s».', $p['supplier'] ?: 'Um fornecedor', self::qty($p['qnt'], $unit),
                    $p['price_per_unit'] !== null ? self::eur($p['price_per_unit'], 2) . (str_starts_with($unit, '«') ? ' cada' : '/' . $unit) : 'preço por saber',
                    self::eur($p['total_eur']), self::since($p['first_date'], $e['today'] ?? null), $p['descriptions'][0] ?? 'sem descrição'));
            }
        } else {
            $add('faturas', 'Não há compras nos últimos 12 meses.');
        }
        $byUnit = in_array($e['packaging']['source'] ?? null, array_column($e['purchases'], 'unit_label'), true);
        if ($e['packaging'] && ($e['packaging']['quantity'] > 0 || $e['packaging']['dose'])) {
            $add('faturas', ($byUnit ? 'A unidade faturada «' . $e['packaging']['source'] . '» diz ' : 'A descrição do fornecedor diz ')
                . mb_strtolower($e['packaging']['label']) . ($byUnit ? '.' : ' («' . $e['packaging']['text'] . '»).'));
        } elseif ($e['packaging']) {
            $add('faturas', 'A descrição diz «' . mb_strtolower($e['packaging']['label']) . '», sem dizer quanto leva.');
        }
        if ($e['recipes']) {
            $q = array_map(fn ($r) => $r['qnt'], $e['recipes']);
            $unit = self::unitShort((string) ($e['recipes'][0]['unit'] ?: 'Unidade'));
            $add('fichas', sprintf('Entra em %d %s, de %s a %s por porção.', count($e['recipes']), count($e['recipes']) === 1 ? 'ficha' : 'fichas',
                self::qty(min($q), $unit, 4), self::qty(max($q), $unit, 4)));
        } else {
            $add('fichas', 'Ainda não entra em nenhuma ficha: decidido só pelas faturas.');
        }
        foreach ($e['counts'] as $c) {
            $add('contagens', sprintf('Contou-se em «%s» %d %s.', $c['unit'], $c['times'], $c['times'] === 1 ? 'vez' : 'vezes'));
        }
        foreach ($e['transfers'] as $t) {
            $add('contagens', sprintf('Enviou-se em «%s» %d %s.', $t['unit'], $t['times'], $t['times'] === 1 ? 'vez' : 'vezes'));
        }
        if ($e['base_unit']) {
            $add('pingwin', sprintf('No PingWin, a unidade base é %s.', mb_strtolower($e['base_unit'])));
        }
        if ($e['sold_one_to_one']) {
            $add('pingwin', sprintf('Vende-se diretamente, sem ficha (%s nos últimos 90 dias).', self::qty($e['sold_qty_90d'], 'un', 0)));
        }
        if ($e['price_ratio'] !== null && $e['family_median']) {
            $unit = self::unitShort(UnitProposal::baseLabel($e['base_family']));
            $r = $e['price_ratio'];
            $times = $r >= 1 ? Fmt::qty($r, 1) . ' vezes mais' : Fmt::qty(1 / $r, 1) . ' vezes menos';
            $add('faturas', sprintf('A mediana da família %s é %s/%s: este artigo custa %s, %s.', mb_strtoupper((string) ($e['family_leaf'] ?: $e['family'])),
                self::eur($e['family_median'], 2), $unit, abs($r - 1) < 0.1 ? 'o mesmo' : $times,
                $r >= 0.25 && $r <= 4 ? 'dentro do esperado (a auditoria só assinala a partir de 4 vezes)' : 'fora do esperado: confira o fator das unidades de compra'));
        }

        return $out;
    }

    /** "kg", "L", "un" ou «Grade 24». */
    private static function unitShort(string $label): string
    {
        return ['quilograma' => 'kg', 'litro' => 'L', 'unidade' => 'un'][mb_strtolower(trim($label))] ?? "«{$label}»";
    }

    /** "7 026 kg", "0,007 un", "153 «Grade 24»". */
    private static function qty(float $v, string $unit, int $decimals = 3): string
    {
        $d = abs($v) >= 100 || abs($v - round($v)) < 1e-9 ? 0 : $decimals;
        $txt = number_format($v, $d, ',', ' ');

        return ($d > 0 ? rtrim(rtrim($txt, '0'), ',') : $txt) . ' ' . $unit;
    }

    /** "80 858 €" (sem cêntimos a partir de 100 €), "11,51 €". */
    private static function eur(?float $v, ?int $decimals = null): string
    {
        if ($v === null) {
            return '';
        }
        $d = $decimals ?? (abs($v) >= 100 ? 0 : 2);

        return number_format($v, $d, ',', ' ') . ' €';
    }

    /** "desde maio", "desde novembro de 2025". */
    private static function since(?string $date, ?string $today): string
    {
        if (! $date) {
            return '';
        }
        $m = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'][(int) substr($date, 5, 2) - 1] ?? '';
        $sameYear = $today && substr($date, 0, 4) === substr($today, 0, 4);

        return 'desde ' . $m . ($sameYear ? '' : ' de ' . substr($date, 0, 4));
    }

    // ── As decisões ─────────────────────────────────────────────────────────────────────────

    /** "Confirmar": o perfil e as conversões na XPLENDOR; os achados ficam corrigidos; recalcula. */
    public function confirm(string $pid, array $answers, User $user, ?string $reason = null, bool $recompute = true, ?string $batch = null): array
    {
        $view = $this->view($pid, $answers);
        $proposal = $view['proposal'];
        if (! $proposal['identified']) {
            throw ValidationException::withMessages(['proposta' => ['Ainda há perguntas por responder: o artigo está por identificar.']]);
        }
        if (! $proposal['complete']) {
            throw ValidationException::withMessages(['proposta' => ['Falta dizer quanto leva alguma unidade de compra.']]);
        }
        $profile = $this->profile($pid);
        $before = $this->snapshot($profile);
        $kStock = $view['impact']['stock_conversion'] ?: 1.0;
        $profile->fill([
            'base_proposed' => $proposal['base'], 'base_confirmed' => $proposal['base'], 'article_type' => $proposal['type'],
            'count_unit' => $proposal['count_unit']['label'] ?? null, 'count_factor' => $proposal['count_unit']['factor'] ?? null,
            'state' => 'confirmado', 'confirmed_by' => $user->id, 'confirmed_at' => now(), 'reason' => $reason ? mb_substr($reason, 0, 500) : null,
            'confidence' => $view['confidence'], 'answers' => $answers ?: $profile->answers, 'proposal' => $proposal, 'skipped_at' => null,
            'evidence' => ['seen' => $view['seen']],
        ])->save();

        // As conversões, na unidade base de hoje do PingWin (é nela que o custo e o stock contam).
        $baseRoot = $this->evidence()->cost()->componentBaseRoot($pid);
        $created = [];
        foreach ($proposal['purchase_units'] as $u) {
            if (! $u['factor']) {
                continue;
            }
            $f = round($u['factor'] / $kStock, 6);
            if (abs($f - 1) < 1e-9 && ($u['unit_id'] === $baseRoot || $u['unit_key'] === $baseRoot)) {
                continue;
            }
            $c = ProductUnitConversion::updateOrCreate(
                ['company_id' => $this->companyId, 'product_pingwin_id' => $pid, 'unit_key' => $u['unit_key'], 'supplier_nif' => (string) $u['nif']],
                ['unit_label' => $u['unit_label'], 'factor_to_base' => $f, 'source' => 'confirmado', 'purpose' => 'compra', 'profile_id' => $profile->id,
                    'reason' => 'Guião de unidades', 'confirmed_by' => $user->id, 'confirmed_at' => now()],
            );
            if ($c->wasRecentlyCreated) {
                $created[] = $c->id;
            }
        }
        $count = $proposal['count_unit'];
        if ($count && $count['factor'] && $count['label'] !== UnitProposal::baseLabel($proposal['base'])) {
            $cc = ProductUnitConversion::updateOrCreate(
                ['company_id' => $this->companyId, 'product_pingwin_id' => $pid, 'unit_key' => 'contagem:' . mb_substr($count['label'], 0, 60), 'supplier_nif' => ''],
                ['unit_label' => $count['label'], 'factor_to_base' => round($count['factor'] / $kStock, 6), 'source' => 'confirmado', 'purpose' => 'contagem',
                    'profile_id' => $profile->id, 'reason' => 'Guião de unidades (contagem)', 'confirmed_by' => $user->id, 'confirmed_at' => now()],
            );
        }
        if (isset($cc) && $cc->wasRecentlyCreated) {
            $created[] = $cc->id;
        }
        $fixed = $this->markFindings($pid, 'corrigido', $user, $batch ? 'Confirmado em bloco no Guião de unidades (estava certo).' : 'Confirmado no Guião de unidades.');
        // O histórico guarda o que se criou, para se poder desfazer (sobretudo a confirmação em bloco).
        $this->event($profile, 'confirmar', $user, $before, $this->snapshot($profile) + ['batch' => $batch, 'conversions' => $created, 'findings' => $fixed], $reason);
        if ($recompute) {
            $this->recompute($user);
        }

        return $this->view($pid);
    }

    /**
     * "Confirmar todos os que estão certos": só os artigos cujo veredicto é "Está certo" (os outros
     * ficam de fora, com o porquê). Só grava na XPLENDOR (nada no PingWin), fica no histórico com o
     * identificador do bloco e pode ser desfeito.
     *
     * @param  list<string>  $pids
     */
    public function confirmAll(array $pids, User $user): array
    {
        $batch = 'bloco-' . now()->format('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 6);
        $done = [];
        $skipped = [];
        foreach (array_values(array_unique(array_map('strval', $pids))) as $pid) {
            try {
                $v = $this->view($pid);
                if (($v['verdict']['key'] ?? null) !== 'certo') {
                    $skipped[] = ['product_id' => $pid, 'reason' => $v['verdict']['title'] ?? 'Não está certo.'];
                    continue;
                }
                $this->confirm($pid, (array) ($v['answers'] ?? []), $user, 'Confirmado em bloco: estava certo.', false, $batch);
                $done[] = $pid;
            } catch (ValidationException $e) {
                $skipped[] = ['product_id' => $pid, 'reason' => collect($e->errors())->flatten()->first() ?? 'Não foi possível confirmar.'];
            }
        }
        if ($done) {
            $this->recompute($user);
        }

        return ['batch' => $batch, 'confirmed' => $done, 'skipped' => $skipped];
    }

    /** Desfazer uma confirmação em bloco: o perfil volta ao que era, saem as conversões criadas e os achados reabrem. */
    public function undoBatch(string $batch, User $user): array
    {
        $events = ProductUnitProfileEvent::where('company_id', $this->companyId)->where('action', 'confirmar')->get()
            ->filter(fn ($ev) => ($ev->after['batch'] ?? null) === $batch);
        $undone = [];
        foreach ($events as $ev) {
            $profile = ProductUnitProfile::where('company_id', $this->companyId)->find($ev->profile_id);
            if (! $profile || ! in_array($profile->state, ['confirmado'], true)) {
                continue; // já mudou depois (aplicado no PingWin, outra decisão): fica
            }
            $before = (array) ($ev->before ?? []);
            $now = $this->snapshot($profile);
            $profile->forceFill([
                'state' => $before['state'] ?? 'sugerido', 'base_confirmed' => $before['base_confirmed'] ?? null, 'article_type' => $before['article_type'] ?? null,
                'count_unit' => $before['count_unit'] ?? null, 'count_factor' => $before['count_factor'] ?? null, 'reason' => $before['reason'] ?? null,
                'confirmed_by' => null, 'confirmed_at' => null,
            ])->save();
            ProductUnitConversion::where('company_id', $this->companyId)->whereIn('id', (array) ($ev->after['conversions'] ?? []))->delete();
            foreach ((array) ($ev->after['findings'] ?? []) as $id) {
                $f = AuditFinding::find($id);
                if ($f && $f->state === 'corrigido') {
                    AuditFindingStates::apply($f, 'reabrir', 'Desfeita a confirmação em bloco.', $user);
                }
            }
            $this->event($profile, 'desfazer', $user, $now, $this->snapshot($profile) + ['batch' => $batch], 'Desfeita a confirmação em bloco.');
            $undone[] = (string) $profile->product_pingwin_id;
        }
        $this->profiles = null;
        if ($undone) {
            $this->recompute($user);
        }

        return ['batch' => $batch, 'undone' => $undone];
    }

    /** "Ajustar": guarda as respostas (a proposta refaz-se); continua por confirmar. */
    public function adjust(string $pid, array $answers, User $user): array
    {
        $profile = $this->profile($pid);
        $before = $this->snapshot($profile);
        $profile->update(['answers' => array_merge((array) $profile->answers, $answers), 'state' => $profile->isDecided() ? 'sugerido' : $profile->state]);
        $this->event($profile, 'ajustar', $user, $before, $this->snapshot($profile));

        return $this->view($pid);
    }

    public function skip(string $pid, User $user): void
    {
        $profile = $this->profile($pid);
        $profile->update(['skipped_at' => now()]);
        $this->event($profile, 'saltar', $user);
    }

    /** "Não se aplica" (com motivo): o artigo sai das regras de unidades; os achados ficam ignorados. */
    public function notApplicable(string $pid, string $reason, User $user): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['Diga porque não se aplica.']]);
        }
        $profile = $this->profile($pid);
        $before = $this->snapshot($profile);
        $profile->update(['state' => 'nao_se_aplica', 'reason' => mb_substr($reason, 0, 500), 'confirmed_by' => $user->id, 'confirmed_at' => now()]);
        $this->event($profile, 'nao_se_aplica', $user, $before, $this->snapshot($profile), $reason);
        $this->markFindings($pid, 'ignorar', $user, 'Não se aplica (Guião de unidades): ' . $reason);

        return $this->view($pid);
    }

    /** "Dar mais informação": texto livre e foto opcional (ficheiro privado); reavalia logo. */
    public function moreInfo(string $pid, ?string $text, ?UploadedFile $photo, array $answers, User $user): array
    {
        $profile = $this->profile($pid);
        $before = $this->snapshot($profile);
        $fill = ['answers' => array_merge((array) $profile->answers, $answers)];
        if (($text = trim((string) $text)) !== '') {
            $fill['info_text'] = mb_substr(trim(($profile->info_text ? $profile->info_text . "\n" : '') . $text), 0, 4000);
        }
        if ($photo) {
            $binary = (new ImageManager(new Driver()))->read($photo->getRealPath())->scaleDown(1600, 1600)->toWebp(82)->toString();
            $path = "unit-profiles/company_{$this->companyId}/{$profile->id}/" . now()->format('YmdHisv') . '.webp';
            Storage::disk(PrivateFiles::disk())->put($path, $binary);
            if ($profile->info_photo_path) {
                Storage::disk(PrivateFiles::disk())->delete($profile->info_photo_path);
            }
            $fill['info_photo_path'] = $path;
        }
        $profile->update($fill);
        $this->event($profile, 'mais_info', $user, $before, $this->snapshot($profile), $text !== '' ? mb_substr($text, 0, 500) : null);
        // Reavaliar: as evidências voltam a ler o perfil (o texto conta como uma descrição).
        $this->evidence = null;
        $this->profiles = null;

        return $this->view($pid);
    }

    /** "Perguntar à IA": a sugestão validada, guardada (cache) e sempre "a confirmar". */
    public function askAi(string $pid, User $user): array
    {
        $a = UnitAdvisor::availability();
        if (! $a['available']) {
            throw ValidationException::withMessages(['ia' => [$a['reason']]]);
        }
        $e = $this->evidence()->for($pid) ?? throw ValidationException::withMessages(['artigo' => ['Artigo sem dados.']]);
        $profile = $this->profile($pid);
        $proposal = UnitProposal::build($e, (array) $profile->answers);
        $s = UnitAdvisor::advise($this->companyId, $user->id, $e, $proposal, $profile->ai_suggestion, $profile->ai_signature);
        if (! ($s['cached'] ?? false)) {
            $profile->update(['ai_suggestion' => $s, 'ai_suggested_at' => now(), 'ai_signature' => $s['signature']]);
            $this->event($profile, 'ia', $user, null, ['ai' => $s]);
        }

        return $this->view($pid);
    }

    /** "Enviar ao administrador": os achados ficam atribuídos ao perfil de administrador, com aviso na app. */
    public function sendToAdmin(string $pid, User $user, ?string $note = null): array
    {
        $profile = $this->profile($pid);
        $e = $this->evidence()->for($pid);
        $ids = array_column($this->openFindingsByArticle($pid)[$pid] ?? [], 'id');
        AuditFinding::whereIn('id', $ids)->update(['assigned_to' => 'administrador', 'assigned_by' => $user->id, 'assigned_at' => now()]);
        app(AlertService::class)->createSystemAlert($this->companyId, 'warning', 'Artigo por identificar: ' . mb_substr((string) ($e['description'] ?? $pid), 0, 80),
            sprintf('%s pediu ajuda para identificar as unidades deste artigo.%s', $user->name, $note ? ' Nota: ' . mb_substr(trim($note), 0, 300) : ''),
            'medium', '/restauracao/auditoria/guiao?fila=administrador&artigo=' . rawurlencode($pid));
        $this->event($profile, 'enviar_admin', $user, null, ['findings' => $ids], $note);

        return $this->view($pid);
    }

    // ── O PingWin ───────────────────────────────────────────────────────────────────────────

    /**
     * O plano de "Aplicar no PingWin": só o que o PASSO 0 provou. As unidades do PingWin são GLOBAIS e
     * a quantidade não muda: um fator errado corrige-se reutilizando (ou criando, pela regra 1) a
     * unidade certa e pondo-a como unidade de compra; a antiga fica e deixa de se usar (regra 2: nunca
     * se anula nem se altera). A unidade base nunca muda pela XPLENDOR. O resto vai para os passos à mão.
     */
    private function plan(array $e, array $proposal, ?ProductUnitProfile $profile): array
    {
        $steps = [];
        $manual = [];
        if (! $proposal['identified'] || ! $proposal['base']) {
            return ['steps' => [], 'manual' => []];
        }
        // A base muda: nada se grava antes de a base mudar no PingWin (à mão, por um caminho seguro); os
        // passos dependem dela. Ficam só os passos à mão, pela ordem certa.
        if ($proposal['base_change']) {
            $manual[] = 'Primeiro a unidade base: ' . mb_strtolower($proposal['base_change_warning']['title']) . '. Siga um dos caminhos seguros do aviso; a XPLENDOR não faz esta mudança.';
            foreach ($proposal['purchase_units'] as $u) {
                if ($u['factor'] && $u['proposed_unit'] !== $u['unit_label']) {
                    $manual[] = sprintf('Depois, em Unidades, crie «%s» com %s (filha da nova unidade base) e lance as faturas de %s nela.', $u['proposed_unit'],
                        UnitProposal::qty($u['factor'], $proposal['base'], $proposal['base_name']), $u['supplier'] ?: 'qualquer fornecedor');
                }
            }
            $manual[] = 'Por fim, converta as fichas para a unidade nova (botão «Converter as fichas»).';

            return ['steps' => [], 'manual' => $manual];
        }
        $cost = $this->evidence()->cost();
        $catalog = new UnitCatalog($cost->units());
        $baseId = (string) ($e['base_unit_id'] ?: $cost->componentBaseRoot($e['product_id']));
        $family = $e['base_family'] ?: $proposal['base'];
        $kStock = ($proposal['stock_conversion'] ?? null) ?: 1.0;
        $writes = PingwinUnitWrite::where('company_id', $this->companyId)->where('product_pingwin_id', $e['product_id'])->orderBy('id')->get();
        $main = null;
        foreach ($proposal['purchase_units'] as $u) {
            if (! $u['factor']) {
                continue;
            }
            $f = round($u['factor'] / $kStock, 6);
            $pwFactor = $u['unit_id'] ? $cost->unitFactor($u['unit_id']) : null;
            if ($pwFactor !== null && abs($pwFactor - $f) < 1e-6) {
                $main ??= ['unit_id' => $u['unit_id'], 'label' => $u['unit_label']];
                continue; // a unidade faturada já tem esta quantidade no PingWin
            }
            // Regra 1: reutilizar a unidade global com a mesma base e quantidade; só criar se não existir.
            $right = $catalog->resolve($baseId, $f, $family, $u['container'] ?? null);
            if (isset($right['reuse'])) {
                $main ??= ['unit_id' => (string) $right['reuse']->pingwin_id, 'label' => $right['name'], 'old' => $u['unit_label']];
                $manual[] = sprintf('Lance as faturas de %s em «%s» (já existe no PingWin, 1 = %s).', $u['supplier'] ?: 'qualquer fornecedor', $right['name'], UnitProposal::qty($f, $family));
                continue;
            }
            $steps[] = $this->step($writes, 'create', $right['create'], sprintf('Criar a unidade «%s» (1 = %s), de todos os artigos', $right['name'], UnitProposal::qty($f, $family)));
            $main ??= ['unit_id' => null, 'label' => $right['name'], 'create_step' => count($steps) - 1, 'old' => $u['unit_label']];
            $manual[] = sprintf('No PingWin, em Unidades, crie «%s» com %s %s (filha de %s).', $right['name'], Fmt::qty($f, 4), $e['base_unit'] ?: 'unidades base', $e['base_unit'] ?: 'a unidade base');
        }
        $current = (string) PingwinCatalogItem::where('company_id', $this->companyId)->where('pingwin_id', $e['product_id'])->value('default_purchase_unit_id');
        if ($main && (isset($main['create_step']) || $current !== (string) ($main['unit_id'] ?? ''))) {
            $steps[] = $this->step($writes, 'set_purchase', ['unit_id' => $main['unit_id'], 'create_step' => $main['create_step'] ?? null],
                sprintf('Pôr «%s» como unidade de compra por defeito', $main['label']));
            $manual[] = sprintf('No artigo, ponha «%s» como unidade de compra por defeito.', $main['label']);
            // Regra 2: a antiga fica no artigo; deixa de se usar (é de todos os artigos: não se anula).
            $old = $current !== '' ? ($cost->units()[$current]->description ?? null) : null;
            if ($old && $old !== $main['label']) {
                $manual[] = sprintf('Deixar de usar «%s» neste artigo: é uma unidade de todos os artigos, por isso não se anula nem se altera.', $old);
            }
        }
        foreach ($proposal['deactivate'] as $d) {
            $manual[] = sprintf('Deixar de usar «%s» neste artigo (sem uso há 12 meses; a unidade fica no PingWin).', $d);
        }

        return ['steps' => $steps, 'manual' => $manual];
    }

    private function step($writes, string $op, array $data, string $label): array
    {
        $w = $writes->first(fn ($w) => $w->op === $op && ($op !== 'create' || ($w->data['description'] ?? null) === $data['description'])
            && ($op !== 'set_purchase' || ($data['unit_id'] && ($w->data['unit_id'] ?? null) === $data['unit_id']) || ! $data['unit_id']));

        return ['op' => $op, 'data' => $data, 'label' => $label, 'status' => $w?->status, 'write_id' => $w?->id, 'unit_id' => $w?->unit_pingwin_id, 'error' => $w?->error];
    }

    /** "Aplicar no PingWin": um passo do plano, uma alteração de cada vez (com confirmação no ecrã). */
    public function applyStep(string $pid, int $index, User $user): PingwinUnitWrite
    {
        $view = $this->view($pid);
        if (! $view['apply']['can_apply']) {
            throw ValidationException::withMessages(['pingwin' => [$view['apply']['guard']['reason'] ?? 'Confirme primeiro a proposta no Guião.']]);
        }
        $step = $view['apply']['steps'][$index] ?? throw ValidationException::withMessages(['passo' => ['Passo desconhecido.']]);
        if ($step['status'] === 'ok') {
            throw ValidationException::withMessages(['passo' => ['Este passo já está feito.']]);
        }
        if (PingwinUnitWrite::where('company_id', $this->companyId)->where('product_pingwin_id', $pid)->whereIn('status', PingwinUnitWrite::ACTIVE)->exists()) {
            throw ValidationException::withMessages(['passo' => ['Há uma alteração deste artigo a gravar: espere que acabe.']]);
        }
        $data = $step['data'];
        if ($step['op'] === 'set_purchase' && ! $data['unit_id']) {
            $created = $view['apply']['steps'][$data['create_step']] ?? null;
            if (($created['status'] ?? null) !== 'ok' || ! $created['unit_id']) {
                throw ValidationException::withMessages(['passo' => ['Crie primeiro a unidade (o passo anterior).']]);
            }
            $data['unit_id'] = $created['unit_id'];
        }
        unset($data['create_step']);
        $w = PingwinUnitWrite::create([
            'company_id' => $this->companyId, 'product_pingwin_id' => $pid, 'product_code' => $view['article']['code'], 'user_id' => $user->id,
            'profile_id' => $this->profile($pid)->id, 'op' => $step['op'], 'data' => $data, 'status' => 'queued',
        ]);
        WritePingwinUnitJob::dispatch($w->id);

        return $w;
    }

    /** Depois de uma escrita certa: se o plano ficou todo feito, o perfil passa a "aplicado no PingWin". */
    public function afterPingwinWrite(PingwinUnitWrite $write): void
    {
        $this->evidence = null;
        $this->profiles = null;
        $view = $this->view($write->product_pingwin_id);
        $profile = $this->profile($write->product_pingwin_id);
        $this->event($profile, 'aplicado', $write->user, null, ['op' => $write->op, 'data' => $write->data, 'unit' => $write->unit_pingwin_id]);
        if ($view['apply']['steps'] && ! array_filter($view['apply']['steps'], fn ($s) => $s['status'] !== 'ok')) {
            $profile->update(['state' => 'aplicado_no_pingwin']);
        }
    }

    /**
     * "Converter as fichas para a unidade nova": o que vai para o editor das fichas (Fatia 4), ficha a
     * ficha, cada uma com confirmação e releitura. Só quando o artigo está confirmado e as fichas já
     * podem usar a unidade nova (a base no PingWin é a nova, ou as fichas é que estão erradas).
     */
    private function recipesConversion(array $e, array $proposal, array $impact, ?ProductUnitProfile $profile, array $answers): array
    {
        $confirmed = in_array($profile?->state, ['confirmado', 'aplicado_no_pingwin'], true);
        $ready = $confirmed && (($proposal['recipes_use_pack'] ?? false) || ! $proposal['base_change'] || ($answers['base_mudada'] ?? null) === 'sim');
        $baseRoot = $this->evidence()->cost()->componentBaseRoot($e['product_id']);
        $items = [];
        foreach ($impact['recipes'] as $r) {
            $source = collect($e['recipes'])->firstWhere('product_id', $r['product_id']);
            if ($r['after_qnt'] === null || ! $source || abs($r['after_qnt'] - $source['qnt_base']) < max(1e-6, 0.01 * $source['qnt_base'])) {
                continue;
            }
            $items[] = ['product_id' => $r['product_id'], 'label' => $r['label'], 'bom_id' => $r['bom_id'], 'component_id' => $e['product_id'],
                'unit_id' => $baseRoot, 'qnt' => $r['after_qnt'], 'before' => $r['before'], 'after' => $r['after']];
        }

        return ['ready' => $ready && (bool) $items, 'needs_base_change' => $confirmed && $proposal['base_change'] && ! ($proposal['recipes_use_pack'] ?? false)
            && ($answers['base_mudada'] ?? null) !== 'sim', 'items' => $items];
    }

    // ── U9: a unidade global incoerente, artigo a artigo ───────────────────────────────────

    /**
     * A correção guiada de uma unidade global incoerente com o nome: a unidade certa (reutilizar a que
     * já existe, ou criá-la uma vez pela regra 1) e os artigos que usam a antiga, "1 de N", cada um a
     * passar para a certa como unidade de compra (no Guião do artigo). A antiga nunca se anula.
     */
    public function globalUnitView(string $unitId): array
    {
        $data = $this->evidence()->data();
        $units = $data->cost()->units();
        $u = UnidadeGlobalIncoerenteRule::detect($units)[$unitId] ?? null;
        if (! $u) {
            throw ValidationException::withMessages(['unidade' => ['Esta unidade não está incoerente com o nome (ou já não existe).']]);
        }
        $catalog = new UnitCatalog($units);
        $right = $catalog->resolve($u['base_id'], $u['says'], $u['family'], $u['container']);
        $articles = UnidadeGlobalIncoerenteRule::articlesOf($data, $u, UnidadeGlobalIncoerenteRule::bomUses($this->companyId));
        $write = isset($right['create'])
            ? PingwinUnitWrite::where('company_id', $this->companyId)->where('op', 'create')->whereNull('profile_id')
                ->get()->first(fn ($w) => ($w->data['description'] ?? null) === $right['create']['description'] && ($w->data['parent_qnt'] ?? null) == $right['create']['parent_qnt'])
            : null;
        $rightId = isset($right['reuse']) ? (string) $right['reuse']->pingwin_id : ($write?->status === 'ok' ? $write->unit_pingwin_id : null);
        $items = PingwinCatalogItem::where('company_id', $this->companyId)->whereIn('pingwin_id', array_column($articles, 'product_id'))->get()->keyBy('pingwin_id');
        $context = $articles[0]['product_id'] ?? null;
        $code = $context ? ltrim((string) ($items[$context]->code ?? ''), "\u{FEFF}") : null;

        return [
            'unit' => ['id' => $u['id'], 'name' => $u['name'], 'now' => UnitProposal::qty($u['now'], $u['family'] ?: 'un'), 'says' => UnitProposal::qty($u['says'], $u['family'] ?: 'un')],
            'right' => ['name' => $right['name'], 'unit_id' => $rightId, 'reuse' => isset($right['reuse']), 'create' => $right['create'] ?? null,
                'status' => isset($right['reuse']) ? 'ok' : $write?->status, 'write_id' => $write?->id, 'error' => $write?->error],
            'articles' => array_map(fn ($a) => $a + ['done' => $rightId !== null && (string) ($items[$a['product_id']]->default_purchase_unit_id ?? '') === $rightId],
                $articles),
            'impact_cents' => (int) array_sum(array_column($articles, 'impact_cents')),
            'guard' => PingwinService::unitWriteGuard($code, $this->companyId),
            'context_product_id' => $context,
        ];
    }

    /** Criar a unidade certa de uma unidade incoerente, uma vez (o artigo serve só de contexto e de guarda). */
    public function applyGlobalUnit(string $unitId, User $user): PingwinUnitWrite
    {
        $v = $this->globalUnitView($unitId);
        if ($v['right']['reuse'] || $v['right']['status'] === 'ok') {
            throw ValidationException::withMessages(['unidade' => ["A unidade certa, «{$v['right']['name']}», já existe: passe os artigos para ela."]]);
        }
        if ($v['guard']) {
            throw ValidationException::withMessages(['pingwin' => [$v['guard']['reason']]]);
        }
        if (! $v['context_product_id'] || in_array($v['right']['status'], PingwinUnitWrite::ACTIVE, true)) {
            throw ValidationException::withMessages(['unidade' => ['Não há nenhum artigo que use esta unidade, ou a unidade certa já está a ser criada.']]);
        }
        $w = PingwinUnitWrite::create([
            'company_id' => $this->companyId, 'product_pingwin_id' => $v['context_product_id'], 'user_id' => $user->id,
            'op' => 'create', 'data' => $v['right']['create'], 'status' => 'queued',
        ]);
        WritePingwinUnitJob::dispatch($w->id);

        return $w;
    }

    // ── Auxiliares ──────────────────────────────────────────────────────────────────────────

    private function profile(string $pid): ProductUnitProfile
    {
        $p = $this->profileModel($pid);
        if (! $p) {
            $p = ProductUnitProfile::create(['company_id' => $this->companyId, 'product_pingwin_id' => $pid, 'state' => 'sugerido']);
            $this->profiles[$pid] = $p;
        }

        return $p;
    }

    private function snapshot(ProductUnitProfile $p): array
    {
        return array_intersect_key($p->fresh()?->toArray() ?? $p->toArray(), array_flip(['state', 'base_confirmed', 'article_type', 'count_unit', 'count_factor', 'answers', 'reason']));
    }

    private function event(ProductUnitProfile $p, string $action, ?User $user, ?array $before = null, ?array $after = null, ?string $reason = null): void
    {
        ProductUnitProfileEvent::create(['company_id' => $this->companyId, 'profile_id' => $p->id, 'user_id' => $user?->id, 'action' => $action,
            'before' => $before, 'after' => $after, 'reason' => $reason ? mb_substr($reason, 0, 500) : null, 'created_at' => now()]);
    }

    /** Os achados de unidades deste artigo passam a "Marcado como corrigido" (ou ignorados). Devolve os ids. */
    private function markFindings(string $pid, string $action, User $user, string $reason): array
    {
        $done = [];
        foreach (array_column($this->openFindingsByArticle($pid)[$pid] ?? [], 'id') as $id) {
            $f = AuditFinding::find($id);
            if ($f && in_array($f->state, AuditFindingStates::ACTIONS[$action][1], true)) {
                AuditFindingStates::apply($f, $action, $reason, $user);
                $done[] = $f->id;
            }
        }

        return $done;
    }

    /** Recalcula a engenharia de menu e os achados (a corrida seguinte confirma o que se corrigiu). */
    private function recompute(User $user): void
    {
        ComputeMenuEngineeringJob::forDefaults($this->companyId);
        try {
            app(AuditRunService::class)->start($this->companyId, 'restauracao', 'unidades', $user->id);
        } catch (AuditRunInProgress) {
            // Já há uma corrida: confirma na próxima.
        }
    }
}
