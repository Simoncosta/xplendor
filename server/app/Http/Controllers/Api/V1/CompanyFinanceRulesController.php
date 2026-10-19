<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\FinanceRule;
use App\Models\FinanceRuleEvent;
use App\Services\Finance\FinanceClassifier;
use App\Services\Finance\FinanceRuleMatcher;
use App\Services\Finance\FinanceRuleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — Finanças › Configuração › Regras de classificação ("quando → grupo"), com histórico.
 * Só na XPLENDOR: a família no PingWin fica como está.
 */
class CompanyFinanceRulesController extends Controller
{
    public function __construct(private readonly FinanceRuleService $rules) {}

    public function index(int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $groups = (array) config('finance_month.groups');
        $rules = FinanceRule::where('company_id', $companyId)->orderByDesc('active')->orderBy('priority')->orderBy('id')->get();
        $events = FinanceRuleEvent::where('company_id', $companyId)->with('user:id,name')->orderByDesc('id')->limit(50)->get();
        $classifier = new FinanceClassifier($companyId, (array) config('finance_month'));

        return ApiResponse::success([
            'rules' => $rules->map(fn ($r) => $r->only(['id', 'match_type', 'match_values', 'match_label', 'entity_pingwin_id', 'entity_name', 'group', 'active', 'priority'])
                + ['label' => FinanceRuleMatcher::label($r, $groups), 'updated_at' => $r->updated_at?->toIso8601String()])->values(),
            'events' => $events->map(fn ($e) => ['id' => $e->id, 'rule_id' => $e->rule_id, 'action' => $e->action, 'before' => $e->before, 'after' => $e->after,
                'user' => $e->user?->name, 'reason' => $e->reason, 'at' => $e->created_at?->toIso8601String()])->values(),
            'options' => [
                'groups' => collect($groups)->map(fn ($g, $k) => ['value' => $k, 'label' => $g['label']])->values(),
                'entities' => DB::table('pingwin_supplier_documents')->where('company_id', $companyId)->whereNotNull('entity_pingwin_id')
                    ->select('entity_pingwin_id', DB::raw('MAX(entity_name) as name'))->groupBy('entity_pingwin_id')->orderBy('name')->get()
                    ->map(fn ($e) => ['value' => (string) $e->entity_pingwin_id, 'label' => (string) $e->name])->values(),
                'doc_types' => DB::table('pingwin_document_configs')->where('company_id', $companyId)->where('entitytype', 'Fornecedor')->where('doctype_id', '17001')
                    ->orderBy('description')->get(['external_id', 'description'])->map(fn ($d) => ['value' => (string) $d->external_id, 'label' => (string) $d->description])->values(),
                'families' => DB::table('pingwin_families')->where('company_id', $companyId)->pluck('pingwin_id')
                    ->map(fn ($id) => $classifier->path((string) $id))->filter()->unique()->sort()->values()->map(fn ($p) => ['value' => $p, 'label' => $p])->values(),
            ],
        ], 'Regras de classificação.');
    }

    public function store(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $r = $this->rules->create($companyId, $this->data($request), Auth::id());

        return ApiResponse::success(['rule' => $r], 'Regra criada.', 201);
    }

    public function update(Request $request, int $companyId, int $ruleId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $r = FinanceRule::where('company_id', $companyId)->find($ruleId);
        if (! $r) {
            return ApiResponse::error('Regra não encontrada.', 404);
        }
        $r = $this->rules->update($r, $this->data($request, true), Auth::id());

        return ApiResponse::success(['rule' => $r], $r->active ? 'Regra guardada.' : 'Regra desativada.');
    }

    public function destroy(int $companyId, int $ruleId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $r = FinanceRule::where('company_id', $companyId)->find($ruleId);
        if (! $r) {
            return ApiResponse::error('Regra não encontrada.', 404);
        }
        $this->rules->delete($r, Auth::id());

        return ApiResponse::success(null, 'Regra apagada.');
    }

    private function data(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'match_type' => [$req, 'string'], 'match_values' => [$req, 'array', 'max:50'], 'match_values.*' => ['string', 'max:200'],
            'match_label' => ['nullable', 'string', 'max:255'], 'entity_pingwin_id' => ['nullable', 'string', 'max:32'], 'entity_name' => ['nullable', 'string', 'max:160'],
            'group' => [$req, 'string'], 'active' => ['sometimes', 'boolean'], 'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);
    }
}
