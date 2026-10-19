<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\FinanceDocumentMark;
use App\Services\Finance\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * XPLENDOR — Finanças: o envio à contabilidade (substitui a folha manual). Marcar como enviado, a
 * Situação e a nota, a folha em XLSX, o ZIP, o email ao contabilista (pré-visualização e envio) e
 * importar a folha antiga. Só na XPLENDOR.
 */
class CompanyFinanceAccountingController extends Controller
{
    public function __construct(private readonly AccountingService $acc) {}

    public function markSent(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $d = $request->validate(['docheader_ids' => ['required', 'array', 'min:1', 'max:500'], 'docheader_ids.*' => ['string', 'max:32'],
            'sent_on' => ['required', 'date_format:Y-m-d'], 'send_method' => ['required', Rule::in(array_keys(FinanceDocumentMark::SEND_METHODS))]]);
        $n = $this->acc->markSent($companyId, $d['docheader_ids'], $d['sent_on'], $d['send_method'], Auth::id());

        return ApiResponse::success(['count' => $n], $n === 1 ? 'Marcado como enviado à contabilidade.' : "{$n} documentos marcados como enviados à contabilidade.");
    }

    public function unmarkSent(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $d = $request->validate(['docheader_ids' => ['required', 'array', 'min:1', 'max:500'], 'docheader_ids.*' => ['string', 'max:32']]);

        return ApiResponse::success(['count' => $this->acc->unmarkSent($companyId, $d['docheader_ids'], Auth::id())], 'Envio desmarcado.');
    }

    public function edit(Request $request, int $companyId, string $docheaderId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $d = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:500'], 'situation' => ['sometimes', 'nullable', 'string', 'max:60']]);
        $m = $this->acc->edit($companyId, $docheaderId, $d, Auth::id());

        return ApiResponse::success(['mark' => $m->only(['docheader_id', 'note', 'situation'])], 'Guardado.');
    }

    public function sheet(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        [$month, $ids] = $this->scope($request);
        $bin = $this->acc->sheet($companyId, $month, $this->acc->rows($companyId, $month, $ids));

        return response($bin, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . AccountingService::sheetName($month) . '"',
        ]);
    }

    public function zip(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        [$month, $ids] = $this->scope($request);
        $z = $this->acc->zip($companyId, $month, $this->acc->rows($companyId, $month, $ids));

        return response()->download($z['path'], $z['name'], ['Content-Type' => 'application/zip',
            'X-Sem-Ficheiro' => (string) count($z['missing'])])->deleteFileAfterSend(true);
    }

    /** O que vai no ZIP e o que não tem ficheiro na XPLENDOR (antes de descarregar). */
    public function files(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        [$month, $ids] = $this->scope($request);
        $f = $this->acc->files($companyId, $this->acc->rows($companyId, $month, $ids));

        return ApiResponse::success(['files' => array_map(fn ($x) => array_intersect_key($x, array_flip(['docheader_id', 'name', 'bytes'])), $f['files']), 'missing' => $f['missing']], 'Ficheiros.');
    }

    public function emailPreview(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        [$month, $ids] = $this->scope($request);

        return ApiResponse::success($this->acc->emailPreview($companyId, $month, $this->acc->rows($companyId, $month, $ids)), 'Pré-visualização do email.');
    }

    public function emailSend(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        [$month, $ids] = $this->scope($request);
        $r = $this->acc->emailSend($companyId, $month, $this->acc->rows($companyId, $month, $ids), Auth::id(), Auth::user()?->email);

        return ApiResponse::success($r, sprintf('Enviado a %s: %d documentos marcados como enviados.', $r['sent_to'], $r['documents']));
    }

    public function import(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls']], ['file.mimes' => 'Escolha a folha em XLSX.']);
        $r = $this->acc->import($companyId, $request->file('file')->getRealPath(), Auth::id());

        return ApiResponse::success($r, sprintf('%d linhas emparelhadas; %d sem par.', $r['matched'], count($r['unmatched'])));
    }

    /** @return array{0:string, 1:?array} o mês e, se vierem, os documentos escolhidos */
    private function scope(Request $request): array
    {
        $d = $request->validate(['month' => ['required', 'date_format:Y-m'], 'docheader_ids' => ['sometimes', 'array', 'max:500'], 'docheader_ids.*' => ['string', 'max:32']]);

        return [$d['month'], $d['docheader_ids'] ?? null];
    }
}
