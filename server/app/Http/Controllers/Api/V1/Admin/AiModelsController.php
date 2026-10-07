<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Services\Ai\AiBlindTestService;
use App\Services\Ai\AiFunctionSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Administração › Modelos de IA (só o root, grupo /admin com ensure_super_admin): o fornecedor,
 * o modelo e o esforço de cada função, "Aplicar a todas as funções", o histórico, os custos do
 * mês e o teste às cegas. O OCR das faturas não aparece aqui.
 */
class AiModelsController extends Controller
{
    public function __construct(private readonly AiBlindTestService $blind) {}

    public function index()
    {
        return ApiResponse::success(AiFunctionSettings::overview() + ['usage' => $this->usage()], 'Modelos carregados.');
    }

    public function update(Request $request, string $function)
    {
        $data = $request->validate(['model' => ['required', 'string'], 'effort' => ['required', 'string']]);
        AiFunctionSettings::set($function, $data['model'], $data['effort'], $request->user());

        return ApiResponse::success(AiFunctionSettings::overview() + ['usage' => $this->usage()], 'Modelo da função guardado.');
    }

    public function applyAll(Request $request)
    {
        $data = $request->validate(['model' => ['required', 'string'], 'effort' => ['required', 'string']]);
        AiFunctionSettings::applyAll($data['model'], $data['effort'], $request->user());

        return ApiResponse::success(AiFunctionSettings::overview() + ['usage' => $this->usage()], 'Modelo aplicado a todas as funções.');
    }

    // ── teste às cegas ────────────────────────────────────────────────────────

    public function blindTests()
    {
        return ApiResponse::success(['tests' => $this->blind->list(), 'report' => $this->blind->report(),
            'models' => [AiBlindTestService::MODEL_A, AiBlindTestService::MODEL_B]], 'Testes carregados.');
    }

    public function createBlindTest(Request $request)
    {
        $data = $request->validate(['function' => ['required', 'string']]);
        $id = $this->blind->create($data['function'], $request->user());

        return ApiResponse::success($this->blind->show($id), 'Teste criado. Os casos estão a ser gerados.', 201);
    }

    public function blindTest(int $testId)
    {
        return ApiResponse::success($this->blind->show($testId), 'Teste carregado.');
    }

    public function chooseBlindCase(Request $request, int $testId, int $caseId)
    {
        $data = $request->validate(['choice' => ['required', 'in:left,right,tie']], ['choice.in' => 'Escolha a esquerda, a direita ou empate.']);
        $this->blind->choose($testId, $caseId, $data['choice'], $request->user());

        return ApiResponse::success($this->blind->show($testId), 'Escolha registada.');
    }

    /** Custos do mês por função (ai_requests): pedidos, erros com resposta do fornecedor, tokens e custo. */
    private function usage(): array
    {
        return DB::table('ai_requests')->where('created_at', '>=', now()->startOfMonth())->whereNotNull('provider')
            ->selectRaw('mode, count(*) as requests, sum(case when status = ? and provider_status is not null then 1 else 0 end) as provider_errors, '
                . 'coalesce(sum(input_tokens), 0) as input_tokens, coalesce(sum(output_tokens), 0) as output_tokens, '
                . 'coalesce(sum(reasoning_tokens), 0) as reasoning_tokens, coalesce(sum(cost_usd), 0) as cost_usd', [AiRequest::ERROR])
            ->groupBy('mode')->get()->map(fn ($r) => [
                'function' => $r->mode, 'requests' => (int) $r->requests, 'provider_errors' => (int) $r->provider_errors,
                'input_tokens' => (int) $r->input_tokens, 'output_tokens' => (int) $r->output_tokens, 'reasoning_tokens' => (int) $r->reasoning_tokens,
                'cost_usd' => round((float) $r->cost_usd, 4),
            ])->values()->all();
    }
}
