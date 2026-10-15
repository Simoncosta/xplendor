<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditAreas;
use App\Audit\AuditFindings;
use App\Audit\AuditFindingStates;
use App\Audit\AuditRegistry;
use App\Audit\AuditRunInProgress;
use App\Audit\AuditRunService;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\AuditFinding;
use App\Models\AuditRun;
use App\Services\Restaurant\CompassFinancials;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * XPLENDOR — Auditoria (plataforma): a página central (lista com Pareto e filtros, detalhe), a coluna
 * "Auditoria" das listas (contagens por entidade), as corridas (botão "Auditoria" e pipeline) e as
 * mudanças de estado. Cada pessoa só vê as áreas cuja permissão de ver tem (restauracao.ver,
 * automovel.ver, resultados.ver) e só muda o estado com a de editar; os euros só com financas.ver.
 * As rotas de correr e de mudar o estado são por área: a ACL das rotas faz a verificação.
 */
class CompanyAuditController extends Controller
{
    public function __construct(private readonly AuditFindings $findings) {}

    /** As áreas que a pessoa vê, as regras de cada uma e o que pode fazer. */
    public function meta(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $user = $request->user();
        $areas = AuditAreas::visible($user, $companyId);
        $last = AuditRun::where('company_id', $companyId)->whereIn('area', $areas ?: ['__nenhuma__'])
            ->whereIn('id', AuditRun::where('company_id', $companyId)->selectRaw('MAX(id)')->groupBy('scope_key'))
            ->get()->map(fn ($r) => $r->toApi())->values();

        return ApiResponse::success([
            'areas' => array_map(fn ($a) => [
                'key' => $a, 'label' => AuditAreas::label($a), 'can_edit' => AuditAreas::canEdit($user, $companyId, $a),
                'rules' => array_map(fn ($r) => [
                    'code' => $r->code(), 'title' => $r->title(), 'description' => $r->description(), 'severity' => $r->severity(),
                    'screens' => $r->screens(), 'applies' => $r->appliesTo($companyId),
                ], AuditRegistry::forScope($a, 'central')),
            ], $areas),
            'last_runs' => $last,
            'financial' => $this->financial($request, $companyId),
        ], 'Auditoria.');
    }

    public function index(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $finance = CompassFinancials::visibleTo($request->user(), $companyId);
        $sorts = $finance ? AuditFindings::SORTS : array_values(array_diff(AuditFindings::SORTS, ['impact']));
        $v = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:200'],
            'area' => ['nullable', 'string', 'max:32'],
            'rule' => ['nullable', 'string', 'max:2000'],
            'severity' => ['nullable', 'string', 'max:40'],
            'state' => ['nullable', 'string', 'max:80'],
            'entity_type' => ['nullable', 'string', 'max:40'],
            'entity_id' => ['nullable', 'string', 'max:64'],
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', Rule::in($sorts)],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $areas = AuditAreas::visible($request->user(), $companyId);
        $payload = $this->findings->list($companyId, $areas, $v, $v['sort'] ?? 'pareto', $v['dir'] ?? 'desc',
            (int) ($v['page'] ?? 1), (int) ($v['perPage'] ?? 50), $finance);

        return ApiResponse::success($payload + ['financial' => $this->financial($request, $companyId)], 'Achados da auditoria.');
    }

    /** "Agrupar por regra": a contagem por regra (com os mesmos filtros da lista). */
    public function groups(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $v = $request->validate([
            'area' => ['nullable', 'string', 'max:32'],
            'rule' => ['nullable', 'string', 'max:2000'],
            'severity' => ['nullable', 'string', 'max:40'],
            'state' => ['nullable', 'string', 'max:80'],
            'entity_type' => ['nullable', 'string', 'max:40'],
            'entity_id' => ['nullable', 'string', 'max:64'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);
        $finance = CompassFinancials::visibleTo($request->user(), $companyId);

        return ApiResponse::success([
            'groups' => $this->findings->groups($companyId, AuditAreas::visible($request->user(), $companyId), $v, $finance),
            'financial' => $this->financial($request, $companyId),
        ], 'Achados por regra.');
    }

    public function show(Request $request, int $companyId, int $findingId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $f = $this->visibleFinding($request, $companyId, $findingId);
        if (! $f) {
            return ApiResponse::error('Achado não encontrado.', 404);
        }
        $finance = CompassFinancials::visibleTo($request->user(), $companyId);

        return ApiResponse::success([
            'finding' => $this->findings->shape($f, $finance, null, true) + ['can_edit' => AuditAreas::canEdit($request->user(), $companyId, $f->area)],
            'financial' => $this->financial($request, $companyId),
        ], 'Achado.');
    }

    /** A coluna "Auditoria" das listas: por entidade, os achados abertos e a gravidade mais alta. */
    public function counts(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $v = $request->validate([
            'entity_type' => ['required', 'string', 'max:40'],
            'ids' => ['nullable', 'string', 'max:20000'],
        ]);
        $areas = AuditAreas::visible($request->user(), $companyId);
        $ids = array_values(array_filter(array_map('trim', explode(',', (string) ($v['ids'] ?? '')))));
        $rows = DB::table('audit_finding_refs as r')->join('audit_findings as f', 'f.id', '=', 'r.finding_id')
            ->where('r.company_id', $companyId)->where('r.entity_type', $v['entity_type'])
            ->when($ids, fn ($q) => $q->whereIn('r.entity_id', $ids))
            ->whereIn('f.area', $areas ?: ['__nenhuma__'])->whereIn('f.state', AuditFinding::OPEN)
            ->groupBy('r.entity_id')
            ->get(['r.entity_id', DB::raw('COUNT(DISTINCT f.id) as n'),
                DB::raw("MAX(CASE f.severity WHEN 'alta' THEN 3 WHEN 'media' THEN 2 WHEN 'baixa' THEN 1 ELSE 0 END) as rank")]);
        $names = array_flip(AuditFinding::SEVERITY_RANK);
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->entity_id] = ['open' => (int) $r->n, 'severity' => $names[(int) $r->rank] ?? 'info'];
        }

        return ApiResponse::success(['counts' => (object) $out], 'Contagens.');
    }

    /** POST {id}/audit/{área}/runs: o botão "Auditoria" de um ecrã (ou da página central). */
    public function startRun(Request $request, int $companyId)
    {
        $area = (string) $request->route('area');
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $v = $request->validate(['origin' => ['required', Rule::in(array_merge(['central'], AuditRegistry::SCREENS))]]);
        try {
            $run = app(AuditRunService::class)->start($companyId, $area, $v['origin'], $request->user()?->id);
        } catch (AuditRunInProgress $e) {
            return ApiResponse::error($e->getMessage(), 409, ['run' => $e->run->toApi()]);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(['run' => $run->fresh()->toApi()], 'A auditoria vai começar.', 202);
    }

    public function run(Request $request, int $companyId, int $runId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        app(AuditRunService::class)->expireStale($companyId);
        $run = AuditRun::where('company_id', $companyId)->whereKey($runId)->first();
        if (! $run || ! AuditAreas::canSee($request->user(), $companyId, $run->area)) {
            return ApiResponse::error('Corrida não encontrada.', 404);
        }

        return ApiResponse::success(['run' => $run->toApi()], 'Corrida.');
    }

    /** POST {id}/audit/{área}/findings/{finding}/state: Aceitar, Ignorar (com motivo), Marcar como corrigido, Reabrir. */
    public function state(Request $request, int $companyId, int $findingId)
    {
        $area = (string) $request->route('area');
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $v = $request->validate([
            'action' => ['required', Rule::in(array_keys(AuditFindingStates::ACTIONS))],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $f = AuditFinding::where('company_id', $companyId)->where('area', $area)->whereKey($findingId)->first();
        if (! $f) {
            return ApiResponse::error('Achado não encontrado.', 404);
        }
        AuditFindingStates::apply($f, $v['action'], $v['reason'] ?? null, $request->user());
        $finance = CompassFinancials::visibleTo($request->user(), $companyId);

        return ApiResponse::success([
            'finding' => $this->findings->shape($f->fresh(), $finance, null, true) + ['can_edit' => true],
        ], 'Estado atualizado.');
    }

    private function visibleFinding(Request $request, int $companyId, int $findingId): ?AuditFinding
    {
        $f = AuditFinding::where('company_id', $companyId)->whereKey($findingId)->first();

        return $f && AuditAreas::canSee($request->user(), $companyId, $f->area) ? $f : null;
    }

    private function financial(Request $request, int $companyId): array
    {
        $visible = CompassFinancials::visibleTo($request->user(), $companyId);

        return ['visible' => $visible, 'note' => $visible ? null : CompassFinancials::NOTE];
    }
}
