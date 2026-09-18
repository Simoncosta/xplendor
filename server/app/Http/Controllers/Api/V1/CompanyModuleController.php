<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\CompanyModuleService;
use Illuminate\Support\Facades\Auth;

/**
 * XPLENDOR — Módulos ATIVOS da empresa do utilizador (lado stand). Só LEITURA —
 * o frontend usa isto para esconder secções que a empresa não tem (Fase 2). A
 * gestão (ligar/desligar) é do super-admin (AdminCompanyController). A recusa de
 * acesso por rota/endpoint é a Fase 3 (aqui só se expõe a lista).
 */
class CompanyModuleController extends Controller
{
    public function __construct(private readonly CompanyModuleService $service) {}

    public function active(int $companyId)
    {
        $user = Auth::user();
        if ($user->company_id !== $companyId && $user->role !== 'root') {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        return ApiResponse::success(
            ['modules' => $this->service->enabledKeys($companyId)],
            'Módulos ativos.'
        );
    }
}
