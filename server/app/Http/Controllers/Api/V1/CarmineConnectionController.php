<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Api\ApiCarmineService;
use App\Services\CarmineConnectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CarmineConnectionController extends Controller
{
    public function __construct(protected CarmineConnectionService $carmineService) {}

    public function show(int $companyId, int $id)
    {
        $user = Auth::user();

        // Bloqueia caso o usuário não pertença à empresa da rota
        if ($user->company_id !== $companyId && $user->role !== 'root') {
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
        $user = Auth::user();

        // Bloqueia caso o usuário não pertença à empresa da rota
        if ($user->company_id !== $companyId && $user->role !== 'root') {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
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
        $user = Auth::user();

        // Bloqueia caso o usuário não pertença à empresa da rota
        if ($user->company_id !== $companyId && $user->role !== 'root') {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
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
        $user = Auth::user();

        // Bloqueia caso o usuário não pertença à empresa da rota
        if ($user->company_id !== $companyId && $user->role !== 'root') {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $this->carmineService->destroy($id);

        return ApiResponse::success(null, 'Connection Carmine deleted successfully.');
    }

    public function sync(Request $request, int $companyId)
    {
        $carmine = $this->carmineService->getListaDetalhesViatura($companyId);

        return ApiResponse::success($carmine, 'Connection Carmine deleted successfully.');
    }
}
