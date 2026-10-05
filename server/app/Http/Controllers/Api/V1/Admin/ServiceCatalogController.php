<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\QuoteLine;
use App\Models\ServiceCatalogItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Catálogo de serviços (tabela padrão dos orçamentos). Só a equipa XPLENDOR.
 * Não se apaga: desativa-se (os orçamentos antigos guardam a sua própria cópia).
 */
class ServiceCatalogController extends Controller
{
    private function ensureRoot(): void
    {
        abort_unless(Auth::user()?->role === 'root', 403);
    }

    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name'         => [$req, 'string', 'max:255'],
            'description'  => ['nullable', 'string', 'max:2000'],
            'unit_price'   => [$req, 'numeric', 'min:0', 'max:9999999'],
            'unit'         => [$req, Rule::in(QuoteLine::UNITS)],
            'billing_type' => [$req, Rule::in(QuoteLine::BILLING_TYPES)],
            'active'       => ['sometimes', 'boolean'],
            'sort'         => ['sometimes', 'integer', 'min:0', 'max:100000'],
            // Lista de arranque: tarefas copiadas para o ticket quando um orçamento com o serviço é aceite.
            'onboarding_checklist'   => ['sometimes', 'nullable', 'array', 'max:30'],
            'onboarding_checklist.*' => ['string', 'max:200'],
        ];
    }

    public function index(Request $request)
    {
        $this->ensureRoot();
        $items = ServiceCatalogItem::query()
            ->when($request->boolean('active_only'), fn ($q) => $q->where('active', true))
            ->orderBy('sort')->orderBy('name')->get();

        return ApiResponse::success($items, 'Service catalog fetched successfully.');
    }

    public function store(Request $request)
    {
        $this->ensureRoot();
        $this->normalizeActive($request);
        $data = $request->validate($this->rules(true));
        // Sem ordem indicada, vai para o fim da lista.
        $data['sort'] ??= ((int) ServiceCatalogItem::max('sort')) + 10;
        $item = ServiceCatalogItem::create($data);

        return ApiResponse::success($item, 'Serviço criado.', 201);
    }

    public function update(Request $request, int $id)
    {
        $this->ensureRoot();
        $item = ServiceCatalogItem::find($id);
        abort_unless($item, 404, 'Serviço não encontrado.');
        $this->normalizeActive($request);
        $item->update($request->validate($this->rules(false)));

        return ApiResponse::success($item->fresh(), 'Serviço guardado.');
    }

    /**
     * Pedidos multipart enviam os booleanos como "true"/"false": converte-os. Só quando o
     * campo vem no pedido (ausente nunca desativa o serviço).
     */
    private function normalizeActive(Request $request): void
    {
        if ($request->has('active') && $request->input('active') !== null) {
            $request->merge(['active' => \App\Http\Requests\Admin\QuoteRequest::toBool($request->input('active'))]);
        }
    }
}
