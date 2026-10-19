<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\LaunchBlocked;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Jobs\ReadOpenDocumentLinesJob;
use App\Models\PingwinSupplierDocument;
use App\Models\SupplierDocumentClose;
use App\Services\Finance\SupplierDocumentCloseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * XPLENDOR — "Fechar no PingWin" um documento de fornecedor em Aberto, ou vários ("Fechar os
 * selecionados", no máximo 10), também os lançados diretamente no PingWin. ⚠️ ESCRITA no PingWin,
 * assíncrona (worker), um de cada vez e com releitura; a UI acompanha o lote pelo GET.
 */
class CompanySupplierDocumentCloseController extends Controller
{
    public function __construct(private readonly SupplierDocumentCloseService $closes) {}

    /** O que impede fechar cada documento em Aberto (lista vazia = pode). */
    public function checks(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $ids = $this->ids($request);
        $docs = PingwinSupplierDocument::where('company_id', $companyId)->whereIn('docheader_id', $ids)->get();

        return ApiResponse::success(['checks' => $this->closes->checks($companyId, $docs)], 'Conferência dos documentos.');
    }

    public function store(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        try {
            $res = $this->closes->request($companyId, $this->ids($request), Auth::id());
        } catch (LaunchBlocked $e) {
            $doc = $e->failed[0]['document'] ?? null;

            return ApiResponse::error(($doc ? "Não é possível fechar {$doc}: " : 'Não é possível fechar: ') . implode(' ', array_column($e->failed, 'message')),
                422, ['code' => 'conferencia', 'failed' => $e->failed]);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(['batch' => $res['batch'], 'closes' => $res['closes']->map(fn ($c) => $this->closes->present($c))->values()],
            count($res['closes']) === 1 ? 'A fechar no PingWin… aguarda o resultado.' : sprintf('A fechar %d documentos no PingWin, um de cada vez…', count($res['closes'])), 202);
    }

    public function batch(int $companyId, string $batch)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $closes = SupplierDocumentClose::where('company_id', $companyId)->where('batch', $batch)->with('user:id,name')->orderBy('position')->get();
        if ($closes->isEmpty()) {
            return ApiResponse::error('Lote não encontrado.', 404);
        }

        return ApiResponse::success(['batch' => $batch, 'done' => ! $closes->contains('status', SupplierDocumentClose::PENDING),
            'closes' => $closes->map(fn ($c) => $this->closes->present($c))->values()], 'Fecho dos documentos.');
    }

    /** "Ler as linhas do PingWin" (só leitura) dos documentos em Aberto, para a conferência. */
    public function readLines(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $ids = $this->ids($request);
        if ($ids === []) {
            return ApiResponse::error('Escolha pelo menos um documento.', 422);
        }
        ReadOpenDocumentLinesJob::dispatch($companyId, $ids);

        return ApiResponse::success(['docheader_ids' => $ids], 'A ler as linhas no PingWin (só leitura)…', 202);
    }

    /** @return list<string> */
    private function ids(Request $request): array
    {
        $data = $request->validate(['docheader_ids' => ['required', 'array', 'min:1', 'max:' . (int) config('finance_month.close_max_batch', 10)],
            'docheader_ids.*' => ['string', 'max:32']], ['docheader_ids.max' => 'No máximo :max documentos de cada vez.']);

        return array_values(array_unique($data['docheader_ids']));
    }
}
