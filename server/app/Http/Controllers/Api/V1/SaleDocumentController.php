<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\SaleDocumentDataResource;
use App\Models\Car;
use App\Models\Company;
use App\Models\Municipality;
use Illuminate\Support\Facades\Auth;

/**
 * DMS Fase 3 — dados para os DOCUMENTOS DE VENDA.
 *
 * Um único endpoint que reúne o "saco" de dados de que qualquer documento
 * precisa: empresa emitente + viatura + cliente da venda. Os documentos (TVDE,
 * contrato, RGPD, ...) resolvem os seus placeholders a partir daqui — não há
 * endpoint por documento. Read-only, tenant-scoped, sem migration.
 */
class SaleDocumentController extends Controller
{
    private function authorizeCompanyAccess(int $companyId): bool
    {
        $user = Auth::user();

        return $user->company_id === $companyId || $user->role === 'root';
    }

    public function data(int $companyId, int $carId)
    {
        if (! $this->authorizeCompanyAccess($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        $car = Car::with(['brand', 'model', 'sale.customer.municipality'])
            ->where('company_id', $companyId)
            ->find($carId);

        if (! $car) {
            return ApiResponse::error('Viatura não encontrada.', 404);
        }

        $company = Company::find($companyId);

        // "Localidade" da empresa via a tabela lookup (Company não tem relação
        // municipality declarada — resolve-se por lookup directo).
        $companyLocality = $company && $company->municipality_id
            ? Municipality::find($company->municipality_id)?->name
            : null;

        return ApiResponse::success(
            (new SaleDocumentDataResource([
                'company'          => $company,
                'company_locality' => $companyLocality,
                'car'              => $car,
                'customer'         => $car->sale?->customer,
                'sale'             => $car->sale,
            ]))->resolve(),
            'Sale document data fetched successfully.'
        );
    }
}
