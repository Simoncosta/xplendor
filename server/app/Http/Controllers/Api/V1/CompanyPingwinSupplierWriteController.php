<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SupplierNifConflict;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\PingwinPaymentCondition;
use App\Models\PingwinSupplier;
use App\Models\PingwinSupplierWrite;
use App\Services\PingwinSupplierWriteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * XPLENDOR — ⚠️ ESCRITA de fornecedores no PingWin (FN): criar, editar, anular e o estado
 * da escrita (polling). Tenancy PRIMEIRO. Validação de NIF português com opção de forçar
 * (NIFs estrangeiros) e guarda de NIF duplicado (409 com o fornecedor existente).
 */
class CompanyPingwinSupplierWriteController extends Controller
{
    public function __construct(private readonly PingwinSupplierWriteService $writes) {}

    public function store(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $this->validated($request, $companyId, true);
        if ($bad = $this->nifProblem($data)) {
            return $bad;
        }

        try {
            $write = $this->writes->requestCreate($companyId, Auth::id(), $this->fields($data), $request->boolean('allow_duplicate_nif'));
        } catch (SupplierNifConflict $e) {
            return $this->conflict($e);
        }

        return ApiResponse::success($this->writePayload($write), 'A criar o fornecedor no PingWin… aguarda o resultado.', 202);
    }

    public function update(Request $request, int $companyId, int $supplierId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $s = $this->supplier($companyId, $supplierId);
        if (! $s) {
            return ApiResponse::error('Fornecedor não encontrado.', 404);
        }
        if (! $s->is_active) {
            return ApiResponse::error('Só é possível editar fornecedores ativos.', 422);
        }
        $data = $this->validated($request, $companyId, false);
        if (array_key_exists('tax_number', $data) && (string) $data['tax_number'] !== (string) $s->tax_number && ($bad = $this->nifProblem($data))) {
            return $bad;
        }
        $fields = $this->fields($data);
        if ($fields === []) {
            return ApiResponse::error('Nada para alterar.', 422);
        }

        try {
            $write = $this->writes->requestUpdate($companyId, Auth::id(), $s, $fields, $request->boolean('allow_duplicate_nif'));
        } catch (SupplierNifConflict $e) {
            return $this->conflict($e);
        }

        return ApiResponse::success($this->writePayload($write), 'A atualizar o fornecedor no PingWin… aguarda o resultado.', 202);
    }

    public function destroy(int $companyId, int $supplierId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $s = $this->supplier($companyId, $supplierId);
        if (! $s) {
            return ApiResponse::error('Fornecedor não encontrado.', 404);
        }
        if (! $s->is_active) {
            return ApiResponse::error('O fornecedor já está anulado.', 422);
        }

        $write = $this->writes->requestVoid($companyId, Auth::id(), $s);

        return ApiResponse::success($this->writePayload($write), 'A anular o fornecedor no PingWin… aguarda o resultado.', 202);
    }

    public function write(int $companyId, int $writeId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $write = PingwinSupplierWrite::where('company_id', $companyId)->find($writeId);
        if (! $write) {
            return ApiResponse::error('Escrita não encontrada.', 404);
        }

        return ApiResponse::success($this->writePayload($write), 'Estado da escrita.');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function validated(Request $request, int $companyId, bool $creating): array
    {
        return $request->validate([
            'description'            => [$creating ? 'required' : 'sometimes', 'string', 'max:50', 'regex:/\S/'],
            'fiscalname'             => ['nullable', 'string', 'max:100'],
            'tax_number'             => ['nullable', 'string', 'max:21'],
            'paycond_id'             => ['nullable', 'string', 'max:30', function ($attr, $value, $fail) use ($companyId) {
                if ($value !== null && $value !== '' && ! PingwinPaymentCondition::where('company_id', $companyId)
                    ->where('pingwin_id', $value)->where('is_active', true)->exists()) {
                    $fail('Condição de pagamento desconhecida ou inativa.');
                }
            }],
            'address'                => ['nullable', 'string', 'max:150'],
            'postalcode'             => ['nullable', 'string', 'max:20'],
            'postalcode_description' => ['nullable', 'string', 'max:75'],
            'country_id'             => ['nullable', 'string', 'max:30'],
            'obs'                    => ['nullable', 'string', 'max:250'],
            'allow_invalid_nif'      => ['nullable', 'boolean'],
            'allow_duplicate_nif'    => ['nullable', 'boolean'],
        ]);
    }

    /** NIF português inválido → 422 com código próprio (a UI oferece "forçar" p/ estrangeiros). */
    private function nifProblem(array $data)
    {
        $nif = trim((string) ($data['tax_number'] ?? ''));
        if ($nif === '' || ! empty($data['allow_invalid_nif']) || PingwinSupplierWriteService::isValidPortugueseNif($nif)) {
            return null;
        }

        return ApiResponse::error('O NIF não é um NIF português válido. Se for estrangeiro, confirma para gravar na mesma.', 422, ['code' => 'nif_invalido']);
    }

    private function conflict(SupplierNifConflict $e)
    {
        $x = $e->existing;

        return ApiResponse::error($e->getMessage(), 409, ['code' => 'nif_duplicado', 'existing' => [
            'id' => $x->id, 'pingwin_id' => $x->pingwin_id, 'code' => $x->code, 'name' => $x->name, 'tax_number' => $x->tax_number,
        ]]);
    }

    private function fields(array $data): array
    {
        return array_intersect_key($data, array_flip(PingwinSupplierWriteService::FIELDS));
    }

    private function supplier(int $companyId, int $supplierId): ?PingwinSupplier
    {
        return PingwinSupplier::where('company_id', $companyId)->whereKey($supplierId)->whereNotNull('pingwin_id')->first();
    }

    private function writePayload(PingwinSupplierWrite $w): array
    {
        return [
            'write_id'      => $w->id,
            'action'        => $w->action,
            'status'        => $w->status,
            'error_message' => $w->error_message,
            'supplier_id'   => $w->supplier_id,
            'pingwin_id'    => $w->pingwin_id,
            'existing'      => $w->status === PingwinSupplierWrite::DUPLICATE ? ($w->result['existing'] ?? null) : null,
            'finished_at'   => $w->finished_at?->toIso8601String(),
        ];
    }
}
