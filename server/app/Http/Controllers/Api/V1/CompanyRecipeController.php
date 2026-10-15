<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\PingwinCatalogItem;
use App\Models\PingwinProductBom;
use App\Models\PingwinProductBomLine;
use App\Models\PingwinUnit;
use App\Models\ProductBomYield;
use App\Models\ProductUnitConversion;
use App\Services\SupplierArticleMapService;
use Illuminate\Support\Facades\DB;
use App\Services\Restaurant\CompassFinancials;
use App\Services\Restaurant\RecipeReportService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * XPLENDOR — Fatia 1: fichas técnicas (só leitura). Lista por Pareto das vendas e detalhe de um
 * artigo, sobre o espelho das fichas do PingWin e o custo XPLENDOR. Tenancy primeiro. Os euros só
 * vão para quem vê as Finanças (como na Bússola): os outros recebem os campos *_eur a null.
 * Fatia 1b: confirmar o rendimento de um semi-produto e o fator de uma unidade de compra. Escrevem
 * só na XPLENDOR (restauracao.editar); nada vai para a PingWin.
 */
class CompanyRecipeController extends Controller
{
    /** Ordenações da lista: chave pública → campo da linha. As de euros só com Finanças. */
    private const SORTS = [
        'pareto' => 'pareto', 'description' => 'description', 'family' => 'family', 'qty' => 'qty',
        'share' => 'pareto', 'state' => 'state_label', 'components' => 'components', 'food_cost' => 'food_cost_pct',
        'valid_from' => 'valid_from',
    ];

    private const EURO_SORTS = ['cost' => 'cost_eur', 'price' => 'price_eur', 'margin' => 'margin_eur'];

    public function index(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $finance = CompassFinancials::visibleTo($request->user(), $companyId);
        $sorts = $finance ? self::SORTS + self::EURO_SORTS : self::SORTS;

        $data = Validator::make($request->all(), [
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:200'],
            'search' => ['nullable', 'string', 'max:120'],
            'family' => ['nullable', 'string', 'max:255'],
            'location_id' => ['nullable', 'integer'],
            'state' => ['nullable', 'string', Rule::in(RecipeReportService::STATES)],
            'sort' => ['nullable', 'string', Rule::in(array_keys($sorts))],
            'dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ], [
            'sort.in' => 'Ordenação inválida: use ' . implode(', ', array_keys($sorts)) . '.',
        ])->validate();

        $report = new RecipeReportService($companyId);
        $locations = $report->locations();
        if (isset($data['location_id']) && ! in_array((int) $data['location_id'], array_column($locations, 'id'), true)) {
            return ApiResponse::error('Loja não encontrada.', 404);
        }
        $list = $report->list([
            'family' => $data['family'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'state' => $data['state'] ?? null,
        ]);

        $rows = $list['rows'];
        if ($s = trim((string) ($data['search'] ?? ''))) {
            $needle = mb_strtolower($s);
            $rows = array_values(array_filter($rows, fn ($r) => str_contains(mb_strtolower((string) $r['description']), $needle)
                || str_contains(mb_strtolower((string) $r['code']), $needle)));
        }

        // Ordenação pedida (por omissão, Pareto); vazios no fim; desempate pela ordem de Pareto.
        $sort = $data['sort'] ?? 'pareto';
        $dir = $data['dir'] ?? 'asc';
        $field = $sorts[$sort];
        usort($rows, function ($a, $b) use ($field, $dir) {
            $va = $a[$field];
            $vb = $b[$field];
            if ($va === null || $vb === null) {
                return ($va === null) <=> ($vb === null) ?: $a['pareto'] <=> $b['pareto'];
            }
            $c = is_string($va) ? strcoll(mb_strtolower($va), mb_strtolower((string) $vb)) : $va <=> $vb;

            return ($dir === 'desc' ? -$c : $c) ?: $a['pareto'] <=> $b['pareto'];
        });

        $perPage = (int) ($data['perPage'] ?? 25);
        $page = (int) ($data['page'] ?? 1);
        $rows = array_map(function ($r) {
            unset($r['_net']);

            return $r;
        }, $rows);
        $paginator = new LengthAwarePaginator(array_slice($rows, ($page - 1) * $perPage, $perPage), count($rows), $perPage, $page);

        $payload = [
            'recipes' => $paginator->toArray(),
            'summary' => $list['summary'],
            'families' => $list['families'],
            'locations' => array_map(fn ($l) => ['id' => $l['id'], 'name' => $l['name']], $locations),
            'states' => collect(RecipeReportService::STATE_LABELS)->map(fn ($label, $id) => ['id' => $id, 'label' => $label])->values(),
            'sort' => ['sort' => $sort, 'dir' => $dir],
        ];

        return ApiResponse::success($this->forUser($payload, $finance), 'Fichas técnicas carregadas.');
    }

    public function show(Request $request, int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $detail = (new RecipeReportService($companyId))->detail($productId);
        if ($detail === null) {
            return ApiResponse::error('Artigo não encontrado.', 404);
        }

        return ApiResponse::success($this->forUser($detail, CompassFinancials::visibleTo($request->user(), $companyId)), 'Ficha técnica carregada.');
    }

    /** Confirma o rendimento de uma versão da ficha de um semi-produto (ex.: o lote rende 30 L). */
    public function confirmYield(Request $request, int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate([
            'bom_id' => ['required', 'string', 'max:32'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $bom = PingwinProductBom::where('company_id', $companyId)->where('product_pingwin_id', $productId)
            ->where('bom_pingwin_id', $data['bom_id'])->first();
        if (! $bom) {
            return ApiResponse::error('Versão da ficha não encontrada.', 404);
        }
        $baseId = PingwinCatalogItem::where('company_id', $companyId)->where('pingwin_id', $productId)->value('base_unit_id');
        $yield = ProductBomYield::updateOrCreate(
            ['company_id' => $companyId, 'bom_pingwin_id' => $bom->bom_pingwin_id],
            [
                'product_pingwin_id' => $productId, 'quantity' => (float) $data['quantity'],
                'unit_pingwin_id' => $baseId, 'unit_label' => $baseId ? PingwinUnit::where('company_id', $companyId)->where('pingwin_id', $baseId)->value('description') : null,
                'source' => 'confirmado', 'reason' => $data['reason'] ?? null,
                'confirmed_by' => $request->user()?->id, 'confirmed_at' => now(),
            ],
        );

        return ApiResponse::success(['yield' => $yield->only(['bom_pingwin_id', 'quantity', 'unit_label', 'source', 'reason', 'confirmed_at'])], 'Rendimento confirmado.');
    }

    /** Confirma quantas unidades base vale uma unidade de compra de um artigo (ex.: "Unidade" = 20 L). */
    public function confirmConversion(Request $request, int $companyId, string $productId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate([
            'unit_key' => ['required', 'string', 'max:64'],
            'unit_label' => ['nullable', 'string', 'max:60'],
            'factor' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'supplier_nif' => ['nullable', 'string', 'max:20'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        // O artigo tem de ser desta empresa: no catálogo, num componente de ficha ou numa compra.
        $known = PingwinCatalogItem::where('company_id', $companyId)->where('pingwin_id', $productId)->exists()
            || PingwinProductBomLine::where('company_id', $companyId)->where('component_pingwin_id', $productId)->exists()
            || DB::table('pingwin_supplier_document_lines')->where('company_id', $companyId)->where('product_pingwin_id', $productId)->exists();
        if (! $known) {
            return ApiResponse::error('Artigo não encontrado.', 404);
        }
        $conv = ProductUnitConversion::updateOrCreate(
            ['company_id' => $companyId, 'product_pingwin_id' => $productId, 'unit_key' => $data['unit_key'],
                'supplier_nif' => SupplierArticleMapService::nif($data['supplier_nif'] ?? '')],
            [
                'unit_label' => $data['unit_label'] ?? null, 'factor_to_base' => (float) $data['factor'],
                'source' => 'confirmado', 'reason' => $data['reason'] ?? null,
                'confirmed_by' => $request->user()?->id, 'confirmed_at' => now(),
            ],
        );

        return ApiResponse::success(['conversion' => $conv->only(['product_pingwin_id', 'unit_key', 'unit_label', 'supplier_nif', 'factor_to_base', 'source', 'confirmed_at'])], 'Fator confirmado.');
    }

    private function forUser(array $payload, bool $finance): array
    {
        if ($finance) {
            return $payload + ['financial' => ['visible' => true, 'note' => null]];
        }

        return RecipeReportService::redact($payload) + ['financial' => ['visible' => false, 'note' => CompassFinancials::NOTE]];
    }
}
