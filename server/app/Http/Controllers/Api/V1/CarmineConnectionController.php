<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\CarmineConnection;
use App\Services\Api\ApiCarmineService;
use App\Services\CarmineConnectionService;
use Illuminate\Http\Request;
use App\Services\CollaboratorService;
use Illuminate\Support\Facades\Auth;

class CarmineConnectionController extends Controller
{
    public function __construct(protected CarmineConnectionService $carmineService) {}

    public function show(int $companyId, int $id)
    {
        // Bloqueia caso o usuário não pertença à empresa da rota
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $connection = $this->carmineService->findOrFail(
            $companyId,
            'company_id',
            ['*'],
            []
        );

        if ($connection['message'] === "Não há dados com estes parâmetros.") {
            return ApiResponse::success(null, 'Connection Carmine fetched successfully.');
        }

        return ApiResponse::success($connection, 'Connection Carmine fetched successfully.');
    }

    public function store(Request $request, int $companyId)
    {
        // Bloqueia caso o usuário não pertença à empresa da rota
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        if (! CollaboratorService::agencyMayConfigureIntegrations(Auth::user(), $companyId)) {
            return ApiResponse::error('Pela agência, só os administradores ligam, alteram ou desligam integrações.', 403);
        }

        $data = $request->validate([
            'dealer_id' => 'required|string|max:50',
            'token' => 'required|string|max:100',
        ]);
        $data['company_id'] = $companyId;

        $connection = $this->carmineService->findOrFail(
            $companyId,
            'company_id',
            ['*'],
            []
        );

        if (isset($connection->id)) {
            return ApiResponse::success($connection, 'Connection Carmine already exists.');
        }

        $carmine = $this->carmineService->store($data);

        return ApiResponse::success($carmine, 'Connection Carmine created successfully.');
    }

    public function update(Request $request, int $companyId, int $id)
    {
        // Bloqueia caso o usuário não pertença à empresa da rota
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        if (! CollaboratorService::agencyMayConfigureIntegrations(Auth::user(), $companyId)) {
            return ApiResponse::error('Pela agência, só os administradores ligam, alteram ou desligam integrações.', 403);
        }

        if (! CarmineConnection::where('company_id', $companyId)->whereKey($id)->exists()) {
            return ApiResponse::error('Ligação Carmine não encontrada.', 404);
        }

        $data = $request->validate([
            'dealer_id' => 'required|string|max:50',
            'token' => 'required|string|max:100',
        ]);
        $data['company_id'] = $companyId;

        $connection = $this->carmineService->update($id, $data);

        return ApiResponse::success($connection, 'Connection Carmine updated successfully.');
    }

    public function destroy(int $companyId, int $id)
    {
        // Bloqueia caso o usuário não pertença à empresa da rota
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        if (! CollaboratorService::agencyMayConfigureIntegrations(Auth::user(), $companyId)) {
            return ApiResponse::error('Pela agência, só os administradores ligam, alteram ou desligam integrações.', 403);
        }

        if (! CarmineConnection::where('company_id', $companyId)->whereKey($id)->exists()) {
            return ApiResponse::error('Ligação Carmine não encontrada.', 404);
        }

        $this->carmineService->destroy($id);

        return ApiResponse::success(null, 'Connection Carmine deleted successfully.');
    }

    public function sync(Request $request, int $companyId)
    {
        $carmine = $this->carmineService->getListaDetalhesViatura($companyId);

        return ApiResponse::success($carmine, 'Connection Carmine sync successfully.');
    }
}
