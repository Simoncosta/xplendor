<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\EditorialLineService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * XPLENDOR — Linha Editorial (Fatia B1): escolha de ramo + calendário herdado (leitura).
 * Gated por ensure_module:linha_editorial (rota) + tenancy (aqui). Só leitura + a
 * PRIMEIRA escolha de ramo (a troca destrutiva é fatia futura).
 */
class EditorialLineController extends Controller
{
    public function __construct(private readonly EditorialLineService $service) {}

    private function authorizeCompanyAccess(int $companyId): bool
    {
        $user = Auth::user();
        return $user && ((int) $user->company_id === $companyId || $user->role === 'root');
    }

    /** Folhas selecionáveis (para o ecrã de escolha de ramo). */
    public function sectors(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        return ApiResponse::success(['sectors' => $this->service->selectableSectors()], 'Ramos disponíveis.');
    }

    /** PRIMEIRA escolha do ramo da empresa. */
    public function setSector(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate(['sector_id' => ['required', 'integer']]);
        $company = Company::find($companyId);
        if (! $company) {
            return ApiResponse::error('Empresa não encontrada.', 404);
        }

        try {
            $sector = $this->service->setSector($company, (int) $data['sector_id']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ApiResponse::error($e->validator->errors()->first(), 422);
        }

        return ApiResponse::success(
            ['sector' => ['id' => $sector->id, 'name' => $sector->name, 'slug' => $sector->slug]],
            'Ramo definido.'
        );
    }

    /** Calendário herdado dos próximos 12 meses (ou has_sector=false se ainda não escolheu). */
    public function calendar(int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $company = Company::find($companyId);
        if (! $company) {
            return ApiResponse::error('Empresa não encontrada.', 404);
        }

        return ApiResponse::success($this->service->calendar($company), 'Calendário editorial.');
    }

    /** B2 — abre um mês (sequência estrita validada no service). */
    public function openMonth(int $companyId, int $year, int $month)
    {
        return $this->transition($companyId, $year, $month, 'open');
    }

    /** B2 — fecha um mês em cascata (fecha também os abertos seguintes). */
    public function closeMonth(int $companyId, int $year, int $month)
    {
        return $this->transition($companyId, $year, $month, 'close');
    }

    /** Tronco comum das transições de estado do mês (tenancy + validação + service). */
    private function transition(int $companyId, int $year, int $month, string $action)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        if ($month < 1 || $month > 12) {
            return ApiResponse::error('Mês inválido.', 422);
        }

        $company = Company::find($companyId);
        if (! $company) {
            return ApiResponse::error('Empresa não encontrada.', 404);
        }

        try {
            $months = $action === 'open'
                ? $this->service->openMonth($company, $year, $month)
                : $this->service->closeMonth($company, $year, $month);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ApiResponse::error($e->validator->errors()->first(), 422);
        }

        return ApiResponse::success(['months' => $months], 'Estado do mês atualizado.');
    }

    // ─────────────────────────── B3a: esconder herdadas + criar/apagar próprias ───────────────────────────

    /** Esconde uma âncora HERDADA (content_anchors) numa ocorrência (ano). */
    public function hideAnchor(Request $request, int $companyId, int $anchorId)
    {
        return $this->anchorAction($request, $companyId, fn ($company, $year) =>
            $this->service->hideAnchorOccurrence($company, $anchorId, $year));
    }

    /** Remostra uma âncora HERDADA numa ocorrência (ano). */
    public function showAnchor(Request $request, int $companyId, int $anchorId)
    {
        return $this->anchorAction($request, $companyId, fn ($company, $year) =>
            $this->service->showAnchorOccurrence($company, $anchorId, $year));
    }

    /** Cria uma âncora PRÓPRIA (editorial_own_anchors). */
    public function createOwnAnchor(Request $request, int $companyId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $company = Company::find($companyId);
        if (! $company) {
            return ApiResponse::error('Empresa não encontrada.', 404);
        }

        try {
            $result = $this->service->createOwnAnchor($company, $request->all());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ApiResponse::error($e->validator->errors()->first(), 422);
        }

        return ApiResponse::success($result, 'Âncora própria criada.');
    }

    /** Apaga uma âncora PRÓPRIA (id do espaço editorial_own_anchors, NÃO content_anchors). */
    public function deleteOwnAnchor(int $companyId, int $ownAnchorId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $company = Company::find($companyId);
        if (! $company) {
            return ApiResponse::error('Empresa não encontrada.', 404);
        }

        try {
            $result = $this->service->deleteOwnAnchor($company, $ownAnchorId);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ApiResponse::error($e->validator->errors()->first(), 422);
        }

        return ApiResponse::success($result, 'Âncora própria apagada.');
    }

    /** Tronco comum de hide/show: tenancy + valida {year} + delega no service. */
    private function anchorAction(Request $request, int $companyId, \Closure $run)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100']]);
        $company = Company::find($companyId);
        if (! $company) {
            return ApiResponse::error('Empresa não encontrada.', 404);
        }

        try {
            $result = $run($company, (int) $data['year']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ApiResponse::error($e->validator->errors()->first(), 422);
        }

        return ApiResponse::success($result, 'Calendário editorial.');
    }
}
