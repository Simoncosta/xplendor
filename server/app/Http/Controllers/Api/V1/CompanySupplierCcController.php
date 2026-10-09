<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\PingwinSupplier;
use App\Models\PingwinSupplierCcBalance;
use App\Services\SupplierCcService;
use Illuminate\Http\Request;

/**
 * XPLENDOR — Conta Corrente de Fornecedor (S2): visão geral, fornecedor ("por liquidar"),
 * extrato e atualização de um fornecedor. Valores em CÊNTIMOS (a formatação é do frontend).
 * Sinal: positivo = em dívida ao fornecedor. Tenancy pela empresa da rota; o fornecedor
 * tem de ser um fornecedor PingWin dessa empresa (senão 404).
 */
class CompanySupplierCcController extends Controller
{
    public function __construct(private readonly SupplierCcService $cc) {}

    public function index(int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }

        return ApiResponse::success($this->cc->overview($companyId), 'Conta corrente dos fornecedores.');
    }

    public function show(int $companyId, int $supplierId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        if (! $this->owns($companyId, $supplierId)) {
            return ApiResponse::error('Fornecedor não encontrado.', 404);
        }

        return ApiResponse::success(
            $this->cc->supplierDetail($companyId, $supplierId) + ['stores' => $this->cc->stores($companyId, $supplierId)],
            'Conta corrente do fornecedor.'
        );
    }

    public function statement(Request $request, int $companyId, int $supplierId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        if (! $this->owns($companyId, $supplierId)) {
            return ApiResponse::error('Fornecedor não encontrado.', 404);
        }

        $data = $request->validate([
            'from'  => ['nullable', 'date_format:Y-m-d'],
            'to'    => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'store' => ['nullable', 'string', 'max:30'],
        ]);

        return ApiResponse::success([
            'from'      => $data['from'] ?? null,
            'to'        => $data['to'] ?? null,
            'store'     => $data['store'] ?? null,
            'statement' => $this->cc->statement($companyId, $supplierId, $data['from'] ?? null, $data['to'] ?? null, $data['store'] ?? null),
        ], 'Extrato do fornecedor.');
    }

    public function refresh(int $companyId, int $supplierId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        if (! $this->owns($companyId, $supplierId)) {
            return ApiResponse::error('Fornecedor não encontrado.', 404);
        }

        $bal = $this->cc->requestRefresh($companyId, $supplierId);

        return ApiResponse::success($this->statusPayload($bal), 'Atualização pedida.', 202);
    }

    public function status(int $companyId, int $supplierId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        if (! $this->owns($companyId, $supplierId)) {
            return ApiResponse::error('Fornecedor não encontrado.', 404);
        }

        $bal = PingwinSupplierCcBalance::where('company_id', $companyId)->where('supplier_id', $supplierId)->first();

        return ApiResponse::success($this->statusPayload($bal), 'Estado da conta corrente.');
    }

    private function owns(int $companyId, int $supplierId): bool
    {
        return PingwinSupplier::where('company_id', $companyId)->whereKey($supplierId)
            ->whereNotNull('pingwin_id')->exists();
    }

    private function statusPayload(?PingwinSupplierCcBalance $bal): array
    {
        return [
            'sync_status' => $bal?->sync_status,
            'reconciled'  => (bool) $bal?->reconciled,
            'last_error'  => $bal?->last_error,
            'synced_at'   => $bal?->synced_at?->toIso8601String(),
        ];
    }
}
