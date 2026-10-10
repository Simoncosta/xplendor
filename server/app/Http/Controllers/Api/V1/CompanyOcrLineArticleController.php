<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SupplierCodeConflict;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\OcrInvoice;
use App\Models\OcrInvoiceLine;
use App\Models\PingwinFamily;
use App\Services\OcrArticleSearchService;
use App\Services\OcrLineArticleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * XPLENDOR — F2b: artigos nas linhas da fatura OCR. Tenancy PRIMEIRO. Associar / aceitar
 * sugestões / desfazer / criar artigo pela linha, pesquisa de artigos e os dados do formulário
 * "Criar artigo". As escritas no PingWin (criar artigo, código do fornecedor no artigo) são
 * assíncronas (jobs no worker) e confirmadas por releitura.
 */
class CompanyOcrLineArticleController extends Controller
{
    public function __construct(private readonly OcrLineArticleService $lines) {}

    public function search(Request $request, int $companyId, OcrArticleSearchService $search)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'nif' => ['nullable', 'string', 'max:20']]);

        return ApiResponse::success(['articles' => $search->search($companyId, (string) ($data['q'] ?? ''), $data['nif'] ?? null)], 'Artigos.');
    }

    /** Opções do "Criar artigo": famílias (caminho completo), IVA e unidades. */
    public function form(int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $fam = PingwinFamily::where('company_id', $companyId)->where('is_active', true)->get(['pingwin_id', 'description', 'parent_pingwin_id'])->keyBy('pingwin_id');
        $path = function ($f) use ($fam) {
            $parts = [];
            for ($i = 0; $f && $i < 10; $i++) {
                if ($f->parent_pingwin_id) { // a raiz ("Família") não entra no caminho
                    array_unshift($parts, $f->description);
                }
                $f = $f->parent_pingwin_id ? ($fam[$f->parent_pingwin_id] ?? null) : null;
            }

            return implode(' › ', $parts);
        };
        $families = $fam->filter(fn ($f) => $f->parent_pingwin_id)->map(fn ($f) => ['value' => (string) $f->pingwin_id, 'label' => $path($f)])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values();

        return ApiResponse::success([
            'families'  => $families,
            'taxgroups' => [
                ['value' => OcrLineArticleService::TAXGROUPS[23], 'label' => 'Normal (23%)', 'rate' => 23],
                ['value' => OcrLineArticleService::TAXGROUPS[13], 'label' => 'Intermédia (13%)', 'rate' => 13],
                ['value' => OcrLineArticleService::TAXGROUPS[6], 'label' => 'Reduzida (6%)', 'rate' => 6],
                ['value' => OcrLineArticleService::TAXGROUPS[0], 'label' => 'Isenta (0%)', 'rate' => 0],
            ],
            'units' => [
                ['value' => OcrLineArticleService::UNITS['UN'], 'label' => 'Unidade (UN)', 'code' => 'UN'],
                ['value' => OcrLineArticleService::UNITS['KG'], 'label' => 'Quilograma (KG)', 'code' => 'KG'],
                ['value' => OcrLineArticleService::UNITS['LT'], 'label' => 'Litro (LT)', 'code' => 'LT'],
            ],
        ], 'Opções do artigo.');
    }

    public function associate(Request $request, int $companyId, int $invoiceId, int $lineId)
    {
        [$inv, $line, $err] = $this->resolve($companyId, $invoiceId, $lineId);
        if ($err) {
            return $err;
        }
        $data = $request->validate([
            'article_id'   => ['required', 'integer'],
            'method'       => ['nullable', 'in:manual,sugestao'],
        ]);
        // O frontend envia multipart (axios por omissão): "true"/"false" em texto → $request->boolean().
        try {
            $this->lines->associate($inv, $line, (int) $data['article_id'], Auth::id(), $data['method'] ?? 'manual', $request->boolean('replace_code'));
        } catch (SupplierCodeConflict $e) {
            return ApiResponse::error($e->getMessage(), 409, ['code' => 'codigo_diferente', 'existing_code' => $e->existingCode, 'new_code' => $e->newCode]);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success($this->payload($inv), 'Linha ligada ao artigo.');
    }

    public function unlink(int $companyId, int $invoiceId, int $lineId)
    {
        [$inv, $line, $err] = $this->resolve($companyId, $invoiceId, $lineId);
        if ($err) {
            return $err;
        }
        if (! $line->isManual()) {
            return ApiResponse::error('Só se desfazem ligações feitas à mão.', 422);
        }
        $this->lines->unlink($inv, $line);

        return ApiResponse::success($this->payload($inv), 'Ligação desfeita.');
    }

    public function acceptSuggestions(Request $request, int $companyId, int $invoiceId)
    {
        [$inv, , $err] = $this->resolve($companyId, $invoiceId, null);
        if ($err) {
            return $err;
        }
        $data = $request->validate(['min_confidence' => ['required', 'numeric', 'min:0.6', 'max:1']]);
        $res = $this->lines->acceptSuggestions($inv, (float) $data['min_confidence'], Auth::id());

        return ApiResponse::success($this->payload($inv) + ['result' => $res], "{$res['accepted']} sugestões aceites.");
    }

    public function relink(int $companyId, int $invoiceId)
    {
        [$inv, , $err] = $this->resolve($companyId, $invoiceId, null);
        if ($err) {
            return $err;
        }
        $this->lines->linkInvoice($inv);

        return ApiResponse::success($this->payload($inv), 'Linhas verificadas.');
    }

    public function createArticle(Request $request, int $companyId, int $invoiceId, int $lineId)
    {
        [$inv, $line, $err] = $this->resolve($companyId, $invoiceId, $lineId);
        if ($err) {
            return $err;
        }
        if ($line->article_write_id) {
            return ApiResponse::error('Já está a ser criado um artigo para esta linha.', 409);
        }
        $data = $request->validate([
            'description'    => ['required', 'string', 'max:120', 'regex:/\S/'],
            'family_id'      => ['required', 'string', 'max:30', function ($a, $v, $fail) use ($companyId) {
                if (! PingwinFamily::where('company_id', $companyId)->where('pingwin_id', $v)->where('is_active', true)->exists()) {
                    $fail('Família desconhecida.');
                }
            }],
            'taxgroup_id'    => ['required', 'in:' . implode(',', OcrLineArticleService::TAXGROUPS)],
            'unit_id'        => ['required', 'in:' . implode(',', OcrLineArticleService::UNITS)],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
        ]);
        $write = $this->lines->createArticle($inv, $line, $data, Auth::id());

        return ApiResponse::success($this->payload($inv) + ['write_id' => $write->id], 'A criar o artigo no PingWin… a linha liga-se quando terminar.', 202);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{0: ?OcrInvoice, 1: ?OcrInvoiceLine, 2: mixed} */
    private function resolve(int $companyId, int $invoiceId, ?int $lineId): array
    {
        if (! $this->authorizeCompany($companyId)) {
            return [null, null, ApiResponse::error('Acesso negado: utilizador inválido.', 403)];
        }
        $inv = OcrInvoice::where('company_id', $companyId)->find($invoiceId);
        if (! $inv) {
            return [null, null, ApiResponse::error('Fatura não encontrada.', 404)];
        }
        if (! in_array($inv->status, ['por_validar', 'validada'], true)) {
            return [null, null, ApiResponse::error('Esta fatura não tem linhas para ligar.', 422)];
        }
        if ($lineId === null) {
            return [$inv, null, null];
        }
        $line = OcrInvoiceLine::where('company_id', $companyId)->where('ocr_invoice_id', $inv->id)->find($lineId);

        return $line ? [$inv, $line, null] : [null, null, ApiResponse::error('Linha não encontrada.', 404)];
    }

    /** Linhas (só a ligação) + resumo, para a UI atualizar sem recarregar o formulário. */
    private function payload(OcrInvoice $inv): array
    {
        $lines = $inv->lines()->orderBy('position')->get();
        $articles = OcrLineArticleService::articlesFor($lines);

        return [
            'articles_summary' => OcrLineArticleService::summary($lines),
            'line_links'       => $lines->map(fn ($l) => ['id' => $l->id] + $this->lines->presentLine($l, $articles))->values(),
        ];
    }
}
