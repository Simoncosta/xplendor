<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\PingwinCountableProduct;
use App\Models\PingwinWarehouse;
use App\Models\StockCountZone;
use App\Models\StockCountZoneItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * XPLENDOR — Fatia 7b: as zonas de contagem de cada armazém (criar, renomear, ordenar, atribuir artigos
 * por pesquisa e ordená-los). Só na XPLENDOR; ver: restauracao.ver; mudar: restauracao.editar.
 */
class CompanyStockZoneController extends Controller
{
    public function index(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $wid = (string) $request->query('warehouse_id', '');
        $zones = StockCountZone::where('company_id', $companyId)->when($wid !== '', fn ($q) => $q->where('warehouse_id', $wid))
            ->orderBy('position')->orderBy('id')->with('items')->get();
        $names = PingwinCountableProduct::where('company_id', $companyId)->whereIn('product_id', $zones->flatMap(fn ($z) => $z->items->pluck('product_id'))->all())
            ->get()->keyBy(fn ($p) => (string) $p->product_id);

        return ApiResponse::success(['zones' => $zones->map(fn ($z) => [
            'id' => $z->id, 'warehouse_id' => $z->warehouse_id, 'name' => $z->name, 'position' => $z->position,
            'items' => $z->items->map(fn ($i) => ['product_id' => $i->product_id, 'code' => $names[$i->product_id]->code ?? null,
                'description' => $names[$i->product_id]->description ?? $i->product_id, 'position' => $i->position])->values(),
        ])->values(), 'can_manage' => $this->can($companyId, 'restauracao.editar')], 'Zonas carregadas.');
    }

    public function store(Request $request, int $companyId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $data = $request->validate(['warehouse_id' => ['required', 'string', 'max:32'], 'name' => ['required', 'string', 'max:80']],
            ['name.required' => 'Dê um nome à zona.']);
        if (! PingwinWarehouse::where('company_id', $companyId)->where('pingwin_id', $data['warehouse_id'])->where('deleted', false)->exists()) {
            return ApiResponse::error('Armazém não encontrado.', 404);
        }
        $pos = (int) StockCountZone::where('company_id', $companyId)->where('warehouse_id', $data['warehouse_id'])->max('position') + 1;
        $zone = StockCountZone::create(['company_id' => $companyId, 'warehouse_id' => $data['warehouse_id'], 'name' => trim($data['name']), 'position' => $pos]);

        return ApiResponse::success(['zone' => $zone], 'Zona criada.', 201);
    }

    /** Renomear, mudar a posição e (opcional) a lista ordenada de artigos. */
    public function update(Request $request, int $companyId, int $zoneId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $zone = StockCountZone::where('company_id', $companyId)->find($zoneId);
        if (! $zone) {
            return ApiResponse::error('Zona não encontrada.', 404);
        }
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'], 'position' => ['sometimes', 'integer', 'min:0'],
            'product_ids' => ['sometimes', 'array', 'max:500'], 'product_ids.*' => ['string', 'max:32'],
        ]);
        DB::transaction(function () use ($zone, $data, $companyId) {
            $zone->fill(array_intersect_key($data, array_flip(['name', 'position'])))->save();
            if (array_key_exists('product_ids', $data)) {
                $ids = array_values(array_unique(array_map('strval', $data['product_ids'])));
                $known = PingwinCountableProduct::where('company_id', $companyId)->whereIn('product_id', $ids)->pluck('product_id')->map(fn ($v) => (string) $v)->all();
                $ids = array_values(array_intersect($ids, $known));
                // Um artigo está numa só zona de cada armazém.
                StockCountZoneItem::whereIn('zone_id', StockCountZone::where('company_id', $companyId)->where('warehouse_id', $zone->warehouse_id)->where('id', '!=', $zone->id)->pluck('id'))
                    ->whereIn('product_id', $ids)->delete();
                StockCountZoneItem::where('zone_id', $zone->id)->delete();
                foreach ($ids as $i => $pid) {
                    StockCountZoneItem::create(['zone_id' => $zone->id, 'product_id' => $pid, 'position' => $i]);
                }
            }
        });

        return ApiResponse::success(['zone' => $zone->fresh('items')], 'Zona guardada.');
    }

    public function destroy(Request $request, int $companyId, int $zoneId)
    {
        if (! $this->authorizeCompany($companyId)) {
            return ApiResponse::error('Acesso negado: utilizador inválido.', 403);
        }
        $zone = StockCountZone::where('company_id', $companyId)->find($zoneId);
        if (! $zone) {
            return ApiResponse::error('Zona não encontrada.', 404);
        }
        $zone->delete();

        return ApiResponse::success([], 'Zona apagada.');
    }
}
