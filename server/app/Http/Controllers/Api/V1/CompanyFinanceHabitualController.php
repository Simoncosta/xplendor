<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\FinanceHabitualExclusion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * XPLENDOR — Finanças: tirar uma entidade dos "Documentos habituais em falta" ("Não vem este mês" ou
 * "Não é habitual") e repô-la, com o histórico (quem, quando, motivo). Só na XPLENDOR.
 */
class CompanyFinanceHabitualController extends Controller
{
    public function index(int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $rows = FinanceHabitualExclusion::where('company_id', $companyId)->with(['creator:id,name', 'remover:id,name'])->orderByDesc('id')->get();

        return ApiResponse::success([
            'exclusions' => $rows->map(fn ($e) => ['id' => $e->id, 'entity_pingwin_id' => $e->entity_pingwin_id, 'supplier' => $e->entity_name, 'scope' => $e->scope,
                'month' => $e->month, 'avg_cents' => $e->avg_cents, 'reason' => $e->reason, 'by' => $e->creator?->name, 'at' => $e->created_at?->toIso8601String(),
                'active' => $e->removed_at === null, 'removed_at' => $e->removed_at?->toIso8601String(), 'removed_by' => $e->remover?->name])->values(),
        ], 'Entidades excluídas dos habituais.');
    }

    public function store(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $d = $request->validate([
            'entity_pingwin_id' => ['required', 'string', 'max:32'],
            'scope' => ['required', Rule::in([FinanceHabitualExclusion::MONTH, FinanceHabitualExclusion::ALWAYS])],
            'month' => ['required', 'date_format:Y-m'],
            'avg_cents' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], ['scope.in' => 'Escolha "Não vem este mês" ou "Não é habitual".']);
        $name = DB::table('pingwin_supplier_documents')->where('company_id', $companyId)->where('entity_pingwin_id', $d['entity_pingwin_id'])->value('entity_name');
        if ($name === null) {
            return ApiResponse::error('Entidade não encontrada nos documentos de fornecedor.', 404);
        }
        if (FinanceHabitualExclusion::activeFor($companyId, $d['month'])->where('entity_pingwin_id', $d['entity_pingwin_id'])->exists()) {
            return ApiResponse::error("{$name} já está fora dos habituais neste mês.", 422);
        }
        $e = FinanceHabitualExclusion::create($d + ['company_id' => $companyId, 'entity_name' => $name, 'created_by' => Auth::id(),
            'reason' => isset($d['reason']) && trim($d['reason']) !== '' ? trim($d['reason']) : null]);

        return ApiResponse::success(['exclusion' => $e->only(['id', 'scope', 'month'])], $d['scope'] === FinanceHabitualExclusion::MONTH
            ? "{$name}: não vem este mês. O resultado estimado foi recalculado."
            : "{$name}: deixa de ser habitual a partir deste mês.", 201);
    }

    /** Repor: a entidade volta a contar como habitual (o registo fica no histórico). */
    public function destroy(int $companyId, int $exclusionId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $e = FinanceHabitualExclusion::where('company_id', $companyId)->whereNull('removed_at')->find($exclusionId);
        if (! $e) {
            return ApiResponse::error('Exclusão não encontrada.', 404);
        }
        $e->update(['removed_at' => now(), 'removed_by' => Auth::id()]);

        return ApiResponse::success(null, "{$e->entity_name} volta a contar como habitual.");
    }
}
