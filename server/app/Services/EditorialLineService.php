<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\ContentAnchor;
use App\Models\ContentSector;
use App\Models\EditorialHiddenAnchor;
use App\Models\EditorialMonth;
use App\Models\EditorialOwnAnchor;
use App\Models\EditorialPost;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * XPLENDOR — Linha Editorial (B1: escolha de ramo + calendário HERDADO; B2: máquina de
 * estados dos meses — abrir/fechar em sequência estrita com cascata). A empresa herda o
 * caminho da árvore desde Universal até à sua folha + país PT; o EditorialAnchorResolver
 * calcula as datas concretas para os próximos 12 meses.
 */
class EditorialLineService
{
    /**
     * Fuso de REFERÊNCIA do "mês corrente". O servidor corre em UTC (config/app.php),
     * mas a janela deslizante tem de seguir Portugal: senão, à meia-noite de fim de mês,
     * UTC desalinha o mês corrente por um dia face a Lisboa.
     */
    private const REF_TZ = 'Europe/Lisbon';

    /** Nº de meses da janela ativa (corrente + 11). */
    private const WINDOW = 12;

    public function __construct(private readonly EditorialAnchorResolver $resolver) {}

    /** Folhas selecionáveis (Restauração, Carros, Autocaravanas, Domótica). */
    public function selectableSectors(): array
    {
        return ContentSector::where('is_selectable', true)
            ->orderBy('sort')->orderBy('name')
            ->get(['id', 'name', 'slug'])->all();
    }

    /**
     * PRIMEIRA escolha do ramo (B1): só quando a empresa AINDA não tem ramo. A troca
     * destrutiva (já com ramo) é fatia futura → aqui recusa-se para não apagar trabalho.
     */
    public function setSector(Company $company, int $sectorId): ContentSector
    {
        if ($company->content_sector_id) {
            throw ValidationException::withMessages([
                'sector' => ['Esta empresa já tem um ramo. A troca de ramo é uma fase futura.'],
            ]);
        }

        $sector = ContentSector::find($sectorId);
        if (! $sector || ! $sector->is_selectable) {
            throw ValidationException::withMessages(['sector' => ['Ramo inválido (só folhas são selecionáveis).']]);
        }

        $company->update(['content_sector_id' => $sector->id]);

        return $sector;
    }

    /**
     * TROCA de ramo (B3b) — método DISTINTO de setSector. EXIGE já haver ramo (é troca, não
     * primeira escolha). É destrutiva: em transação, reseta o planeamento do ramo velho
     * (apaga editorial_months + editorial_hidden_anchors desta empresa) e muda a herança
     * (content_sector_id). PRESERVA SEMPRE editorial_own_anchors (são da empresa, não do
     * ramo). Devolve o calendar() novo. O aviso consciente é do frontend; aqui é a rede
     * de segurança (folha selecionável + diferente do atual + tenancy no controller).
     */
    public function changeSector(Company $company, int $newSectorId): array
    {
        if (! $company->content_sector_id) {
            throw ValidationException::withMessages([
                'sector' => ['Esta empresa ainda não tem ramo. Usa a primeira escolha.'],
            ]);
        }

        $new = ContentSector::find($newSectorId);
        if (! $new || ! $new->is_selectable) {
            throw ValidationException::withMessages(['sector' => ['Ramo inválido (só folhas são selecionáveis).']]);
        }

        if ((int) $company->content_sector_id === $new->id) {
            throw ValidationException::withMessages(['sector' => ['Já estás nesse ramo.']]);
        }

        DB::transaction(function () use ($company, $new) {
            EditorialMonth::where('company_id', $company->id)->delete();        // meses → resetam (fechados)
            EditorialHiddenAnchor::where('company_id', $company->id)->delete(); // escondidas do ramo velho → fora
            $company->update(['content_sector_id' => $new->id]);               // herança passa ao ramo novo
            // editorial_own_anchors → NÃO se toca (são da empresa).
        });

        $company->refresh();

        return $this->calendar($company);
    }

    /**
     * Calendário herdado dos próximos 12 meses (a partir do mês atual, atravessa a
     * viragem do ano). Devolve o estado (has_sector) + as ocorrências com datas concretas.
     */
    public function calendar(Company $company): array
    {
        $company->loadMissing('contentSector');
        $sector = $company->contentSector;
        if (! $sector) {
            return ['has_sector' => false];
        }

        // Janela: 1.º dia do mês corrente → último dia do mês +11 (12 meses), em Lisboa.
        [$start, $end] = $this->windowBounds();

        // Herança: âncoras nos nós do caminho raiz→folha, país universal(null) ou 'PT'.
        $pathIds = array_map(static fn (ContentSector $s) => $s->id, $sector->pathFromRoot());
        $anchors = ContentAnchor::whereIn('sector_id', $pathIds)
            ->where(function ($q) {
                $q->whereNull('country')->orWhere('country', 'PT');
            })
            ->orderBy('title')
            ->get();

        // Próprias da empresa (tabela dedicada; não passam pela árvore).
        $own = EditorialOwnAnchor::where('company_id', $company->id)->orderBy('title')->get();

        // Escondidas desta empresa: mapa 'anchorId-year' => true (só afeta HERDADAS).
        $hidden = EditorialHiddenAnchor::where('company_id', $company->id)
            ->get(['anchor_id', 'occurrence_year'])
            ->mapWithKeys(static fn ($h) => ["{$h->anchor_id}-{$h->occurrence_year}" => true])
            ->all();

        // Anos que a janela atravessa (1 ou 2).
        $years = range((int) $start->year, (int) $end->year);

        $items = [];

        // Herdadas (content_anchors): marcam hidden conforme editorial_hidden_anchors.
        foreach ($anchors as $anchor) {
            foreach ($years as $year) {
                $r = $this->resolver->resolve($anchor, $year);
                $occ = $this->occurrenceInWindow($r, $start, $end);
                if ($occ === null) {
                    continue;
                }
                $items[] = array_merge([
                    'anchor_id'  => $anchor->id,
                    'title'      => $anchor->title,
                    'origin'     => $anchor->origin,
                    'rule_type'  => $anchor->rule_type,
                    'sector_id'  => $anchor->sector_id,
                    'suggestion' => $anchor->suggestion,
                    'owned'      => false,
                    'occ_year'   => $year,
                    'hidden'     => isset($hidden["{$anchor->id}-{$year}"]),
                ], $occ);
            }
        }

        // Próprias (editorial_own_anchors): owned=true, nunca hidden.
        foreach ($own as $anchor) {
            foreach ($years as $year) {
                $r = $this->resolver->resolve($anchor, $year);
                $occ = $this->occurrenceInWindow($r, $start, $end);
                if ($occ === null) {
                    continue;
                }
                $items[] = array_merge([
                    'anchor_id'  => $anchor->id,   // id do ESPAÇO das próprias (editorial_own_anchors)
                    'title'      => $anchor->title,
                    'origin'     => 'variavel',
                    'rule_type'  => $anchor->rule_type,
                    'sector_id'  => null,
                    'suggestion' => $anchor->suggestion,
                    'owned'      => true,
                    'occ_year'   => $year,
                    'hidden'     => false,
                ], $occ);
            }
        }

        // Ordenar por data (as ranges pela data de início).
        usort($items, static fn ($a, $b) => strcmp($a['sort_date'], $b['sort_date']));

        return [
            'has_sector' => true,
            'sector'     => ['id' => $sector->id, 'name' => $sector->name, 'slug' => $sector->slug],
            'from'       => $start->toDateString(),
            'to'         => $end->toDateString(),
            'items'      => $items,
            'months'     => $this->monthsState($company),
            'posts'      => $this->postsInWindow($company, $start, $end),
        ];
    }

    /** Publicações (P1) da empresa dentro da janela — a par das âncoras (items). */
    private function postsInWindow(Company $company, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return EditorialPost::where('company_id', $company->id)
            ->whereBetween('publish_date', [$start->toDateString(), $end->toDateString()])
            ->with(['anchor:id,title', 'ownAnchor:id,title'])
            ->orderBy('publish_date')
            ->get()
            ->map(static fn (EditorialPost $p) => [
                'id'            => $p->id,
                'publish_date'  => $p->publish_date->toDateString(),
                'month_key'     => $p->publish_date->format('Y-m'),
                'title'         => $p->title,
                'format'        => $p->format,
                'status'        => $p->status,
                'channel'       => $p->channel,
                'keyword'       => $p->keyword,
                'anchor_id'     => $p->anchor_id,
                'own_anchor_id' => $p->own_anchor_id,
                'linked_title'  => $p->anchor?->title ?? $p->ownAnchor?->title,
            ])
            ->all();
    }

    // ─────────────────────────── B2: máquina de estados dos meses ───────────────────────────

    /**
     * Estado dos 12 meses da janela ativa + flags para a UI. A verdade da sequência vive
     * aqui (o frontend só reflete): can_open marca EXATAMENTE o 1.º mês fechado a seguir ao
     * prefixo aberto; closes_also lista os meses abertos DEPOIS (para o aviso de cascata).
     */
    public function monthsState(Company $company): array
    {
        $window = $this->windowMonths(); // [ [year,month], ... x12 ] a partir do corrente

        // Estados persistidos que caem na janela ativa (mapa 'Y-m' => state).
        $rows = EditorialMonth::where('company_id', $company->id)
            ->get(['year', 'month', 'state']);
        $stateByKey = [];
        foreach ($rows as $r) {
            $stateByKey[sprintf('%04d-%02d', $r->year, $r->month)] = $r->state;
        }

        // Prefixo contíguo aberto a começar no mês corrente (índice 0).
        $prefixLen = 0;
        foreach ($window as [$y, $m]) {
            if (($stateByKey[sprintf('%04d-%02d', $y, $m)] ?? null) === EditorialMonth::OPEN) {
                $prefixLen++;
            } else {
                break;
            }
        }

        $out = [];
        foreach ($window as $i => [$y, $m]) {
            $key = sprintf('%04d-%02d', $y, $m);
            $isOpen = ($stateByKey[$key] ?? null) === EditorialMonth::OPEN;

            // closes_also: meses abertos à FRENTE deste (só relevante se este estiver aberto).
            $closesAlso = [];
            if ($isOpen) {
                for ($j = $i + 1; $j < count($window); $j++) {
                    [$yj, $mj] = $window[$j];
                    if (($stateByKey[sprintf('%04d-%02d', $yj, $mj)] ?? null) === EditorialMonth::OPEN) {
                        $closesAlso[] = sprintf('%04d-%02d', $yj, $mj);
                    } else {
                        break; // por invariante os abertos são contíguos; para no 1.º fechado
                    }
                }
            }

            $out[] = [
                'year'        => $y,
                'month'       => $m,
                'month_key'   => $key,
                'state'       => $isOpen ? EditorialMonth::OPEN : EditorialMonth::CLOSED,
                'is_current'  => $i === 0,
                'can_open'    => ! $isOpen && $i === $prefixLen, // só o 1.º fechado a seguir aos abertos
                'can_close'   => $isOpen,
                'closes_also' => $closesAlso,
            ];
        }

        return $out;
    }

    /**
     * Abre um mês. Regra estrita validada NO BACKEND: o corrente é o 1.º abrível; qualquer
     * outro só se o anterior estiver aberto (índice == prefixo aberto). Passado/futuro fora
     * da janela → recusa (janela deslizante = read-only fora dela). Devolve monthsState.
     */
    public function openMonth(Company $company, int $year, int $month): array
    {
        $window = $this->windowMonths();
        $idx = $this->indexInWindow($window, $year, $month); // ValidationException se fora

        // Prefixo aberto atual (reutiliza a leitura via monthsState seria redundante — calcula aqui).
        $prefixLen = 0;
        foreach ($window as [$y, $m]) {
            $isOpen = EditorialMonth::where('company_id', $company->id)
                ->where('year', $y)->where('month', $m)->where('state', EditorialMonth::OPEN)->exists();
            if ($isOpen) {
                $prefixLen++;
            } else {
                break;
            }
        }

        // Idempotente: já aberto → nada a fazer.
        $already = EditorialMonth::where('company_id', $company->id)
            ->where('year', $year)->where('month', $month)->where('state', EditorialMonth::OPEN)->exists();

        if (! $already && $idx > $prefixLen) {
            throw ValidationException::withMessages([
                'month' => ['Sequência estrita: abre primeiro o mês anterior.'],
            ]);
        }

        EditorialMonth::updateOrCreate(
            ['company_id' => $company->id, 'year' => $year, 'month' => $month],
            ['state' => EditorialMonth::OPEN],
        );

        return $this->monthsState($company);
    }

    /**
     * Fecha um mês EM CASCATA: fecha-o e a todos os meses abertos POSTERIORES da janela
     * (mantém a continuidade — nunca há aberto com anterior fechado). Não apaga linhas →
     * o trabalho da B3 persiste e reaparece ao reabrir. Devolve monthsState.
     */
    public function closeMonth(Company $company, int $year, int $month): array
    {
        $window = $this->windowMonths();
        $idx = $this->indexInWindow($window, $year, $month); // ValidationException se fora

        DB::transaction(function () use ($company, $window, $idx) {
            // Deste índice até ao fim da janela: os que estiverem abertos passam a fechados.
            for ($j = $idx; $j < count($window); $j++) {
                [$y, $m] = $window[$j];
                EditorialMonth::where('company_id', $company->id)
                    ->where('year', $y)->where('month', $m)->where('state', EditorialMonth::OPEN)
                    ->update(['state' => EditorialMonth::CLOSED]);
            }
        });

        return $this->monthsState($company);
    }

    // ─────────────────────────── B3a: esconder herdadas + criar/apagar próprias ───────────────────────────

    /**
     * Esconde uma âncora HERDADA numa OCORRÊNCIA (ano). Valida: é herdada por esta empresa
     * (id do espaço content_anchors) + a ocorrência cai na janela + esse mês está aberto.
     * Idempotente. Devolve o calendar() atualizado.
     */
    public function hideAnchorOccurrence(Company $company, int $anchorId, int $year): array
    {
        $anchor = $this->inheritedAnchorOrFail($company, $anchorId);
        $this->assertOccurrenceMonthOpen($company, $anchor, $year);

        EditorialHiddenAnchor::updateOrCreate([
            'company_id' => $company->id, 'anchor_id' => $anchor->id, 'occurrence_year' => $year,
        ]);

        return $this->calendar($company);
    }

    /** Remostra (remove o registo de escondida). Mesmas validações (mês aberto). */
    public function showAnchorOccurrence(Company $company, int $anchorId, int $year): array
    {
        $anchor = $this->inheritedAnchorOrFail($company, $anchorId);
        $this->assertOccurrenceMonthOpen($company, $anchor, $year);

        EditorialHiddenAnchor::where('company_id', $company->id)
            ->where('anchor_id', $anchor->id)->where('occurrence_year', $year)->delete();

        return $this->calendar($company);
    }

    /**
     * Cria uma âncora PRÓPRIA (editorial_own_anchors). Valida os campos por rule_type, e que
     * a ocorrência resultante cai num mês ABERTO da janela. Devolve o calendar() + o id novo.
     */
    public function createOwnAnchor(Company $company, array $data): array
    {
        $clean = $this->validateOwnAnchorInput($data);

        // Constrói transitória (sem gravar) para descobrir em que mês da janela cai.
        $transient = new EditorialOwnAnchor(array_merge($clean, ['company_id' => $company->id]));
        $this->assertOccurrenceMonthOpen($company, $transient, null);

        $own = EditorialOwnAnchor::create(array_merge($clean, ['company_id' => $company->id]));

        return array_merge($this->calendar($company), ['own_anchor_id' => $own->id]);
    }

    /**
     * Apaga uma âncora PRÓPRIA (id do espaço editorial_own_anchors). Valida tenancy (é da
     * empresa) + mês da ocorrência aberto. NÃO aceita ids de herdadas (espaço diferente).
     */
    public function deleteOwnAnchor(Company $company, int $ownAnchorId): array
    {
        $own = EditorialOwnAnchor::where('company_id', $company->id)->find($ownAnchorId);
        if (! $own) {
            throw ValidationException::withMessages(['own_anchor' => ['Âncora própria não encontrada.']]);
        }

        $this->assertOccurrenceMonthOpen($company, $own, null);
        $own->delete();

        return $this->calendar($company);
    }

    // ── helpers da B3a ──

    /** Carrega uma âncora de content_anchors e garante que é HERDADA por esta empresa. */
    public function inheritedAnchorOrFail(Company $company, int $anchorId): ContentAnchor
    {
        $company->loadMissing('contentSector');
        $sector = $company->contentSector;
        if (! $sector) {
            throw ValidationException::withMessages(['sector' => ['Empresa sem ramo definido.']]);
        }

        $anchor = ContentAnchor::find($anchorId);
        if (! $anchor) {
            throw ValidationException::withMessages(['anchor' => ['Âncora herdada não encontrada.']]);
        }

        $pathIds = array_map(static fn (ContentSector $s) => $s->id, $sector->pathFromRoot());
        $countryOk = $anchor->country === null || $anchor->country === 'PT';
        if (! in_array($anchor->sector_id, $pathIds, true) || ! $countryOk) {
            throw ValidationException::withMessages(['anchor' => ['Esta âncora não é herdada por esta empresa.']]);
        }

        return $anchor;
    }

    /**
     * Resolve a ocorrência da âncora na janela e exige que esse mês esteja ABERTO.
     * $year != null → força esse ano (hide/show por ocorrência); null → procura a ocorrência
     * na janela (própria: cai uma vez nos 12 meses). 422 se fora da janela ou mês fechado.
     */
    private function assertOccurrenceMonthOpen(Company $company, ContentAnchor|EditorialOwnAnchor $anchor, ?int $year): void
    {
        [$start, $end] = $this->windowBounds();
        $years = $year !== null ? [$year] : range((int) $start->year, (int) $end->year);

        $monthKey = null;
        foreach ($years as $y) {
            $occ = $this->occurrenceInWindow($this->resolver->resolve($anchor, $y), $start, $end);
            if ($occ !== null) {
                $monthKey = $occ['month_key'];
                break;
            }
        }

        if ($monthKey === null) {
            throw ValidationException::withMessages(['month' => ['A ocorrência não cai na janela de 12 meses.']]);
        }

        [$my, $mm] = array_map('intval', explode('-', $monthKey));
        $isOpen = EditorialMonth::where('company_id', $company->id)
            ->where('year', $my)->where('month', $mm)->where('state', EditorialMonth::OPEN)->exists();
        if (! $isOpen) {
            throw ValidationException::withMessages(['month' => ['Só podes editar num mês aberto.']]);
        }
    }

    /** Valida a entrada de uma âncora própria por rule_type; devolve só os campos limpos. */
    private function validateOwnAnchorInput(array $data): array
    {
        $type = $data['rule_type'] ?? null;

        $rules = [
            'title'      => ['required', 'string', 'max:255'],
            'rule_type'  => ['required', 'in:fixa,nth_weekday,periodo,relativa_pascoa'],
            'suggestion' => ['nullable', 'string', 'max:2000'],
        ];

        switch ($type) {
            case 'fixa':
                $rules += [
                    'month' => ['required', 'integer', 'between:1,12'],
                    'day'   => ['required', 'integer', 'between:1,31'],
                ];
                break;
            case 'nth_weekday':
                $rules += [
                    'month'   => ['required', 'integer', 'between:1,12'],
                    'ordinal' => ['required', 'integer', 'in:-1,1,2,3,4,5'],
                    'weekday' => ['required', 'integer', 'between:0,6'],
                ];
                break;
            case 'periodo':
                $rules += [
                    'start_month' => ['required', 'integer', 'between:1,12'],
                    'start_day'   => ['required', 'integer', 'between:1,31'],
                    'end_month'   => ['required', 'integer', 'between:1,12'],
                    'end_day'     => ['required', 'integer', 'between:1,31'],
                ];
                break;
            case 'relativa_pascoa':
                $rules += ['easter_offset' => ['required', 'integer', 'between:-200,200']];
                break;
        }

        $validated = Validator::make($data, $rules)->validate();

        // Mantém só as chaves relevantes ao tipo (evita lixo de outros tipos).
        $keep = ['title', 'rule_type', 'suggestion'];
        $keep = array_merge($keep, match ($type) {
            'fixa'            => ['month', 'day'],
            'nth_weekday'     => ['month', 'ordinal', 'weekday'],
            'periodo'         => ['start_month', 'start_day', 'end_month', 'end_day'],
            'relativa_pascoa' => ['easter_offset'],
            default           => [],
        });

        return array_intersect_key($validated, array_flip($keep));
    }

    /**
     * FONTE ÚNICA da regra "esta data é editável": cai na janela dos 12 meses E o mês está
     * aberto. Usada pelas publicações (P1) e reutilizável por qualquer trabalho datado.
     * 422 se fora da janela ou mês fechado (mesmas mensagens da restante linha editorial).
     */
    public function assertDateEditable(Company $company, string $date): void
    {
        [$start, $end] = $this->windowBounds();
        $d = CarbonImmutable::parse($date);

        if ($d->lt($start) || $d->gt($end)) {
            throw ValidationException::withMessages(['publish_date' => ['A data está fora da janela de 12 meses.']]);
        }

        $isOpen = EditorialMonth::where('company_id', $company->id)
            ->where('year', (int) $d->year)->where('month', (int) $d->month)
            ->where('state', EditorialMonth::OPEN)->exists();
        if (! $isOpen) {
            throw ValidationException::withMessages(['publish_date' => ['Só podes editar num mês aberto.']]);
        }
    }

    // ─────────────────────────── janela deslizante (Europe/Lisbon) ───────────────────────────

    /** [start, end] da janela: 1.º dia do mês corrente → último dia do mês +11 (em Lisboa). */
    private function windowBounds(): array
    {
        $start = CarbonImmutable::now(self::REF_TZ)->startOfMonth();
        $end = $start->addMonths(self::WINDOW - 1)->endOfMonth();

        return [$start, $end];
    }

    /** Os 12 pares [ano, mês] da janela ativa, a começar no mês corrente (Lisboa). */
    private function windowMonths(): array
    {
        $cursor = CarbonImmutable::now(self::REF_TZ)->startOfMonth();
        $months = [];
        for ($i = 0; $i < self::WINDOW; $i++) {
            $months[] = [(int) $cursor->year, (int) $cursor->month];
            $cursor = $cursor->addMonth();
        }

        return $months;
    }

    /**
     * Índice (0..11) de (year,month) na janela ativa; recusa se estiver fora — cobre o
     * passado read-only E a corrida "a janela mudou entre carregar e agir" (mensagem que o
     * frontend traduz em "recarrega a página").
     */
    private function indexInWindow(array $window, int $year, int $month): int
    {
        foreach ($window as $i => [$y, $m]) {
            if ($y === $year && $m === $month) {
                return $i;
            }
        }

        throw ValidationException::withMessages([
            'month' => ['A janela de meses mudou. Recarrega a página.'],
        ]);
    }

    /**
     * Se a ocorrência resolvida cai na janela, devolve os campos para a UI (com
     * month_key p/ agrupar e sort_date p/ ordenar). Senão, null. As ranges entram se
     * intersetarem a janela.
     */
    private function occurrenceInWindow(array $r, CarbonImmutable $start, CarbonImmutable $end): ?array
    {
        if ($r['type'] === 'day') {
            $d = CarbonImmutable::parse($r['date']);
            if ($d->lt($start) || $d->gt($end)) {
                return null;
            }

            return ['type' => 'day', 'date' => $r['date'], 'month_key' => $d->format('Y-m'), 'sort_date' => $r['date']];
        }

        // range
        $rs = CarbonImmutable::parse($r['start']);
        $re = CarbonImmutable::parse($r['end']);
        if ($re->lt($start) || $rs->gt($end)) {
            return null; // não interseta a janela
        }

        return ['type' => 'range', 'start' => $r['start'], 'end' => $r['end'], 'month_key' => $rs->format('Y-m'), 'sort_date' => $r['start']];
    }
}
