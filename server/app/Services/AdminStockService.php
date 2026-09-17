<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Car;
use App\Models\Company;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * XPLENDOR — Stock GLOBAL (transversal, só root, área /admin). Lista veículos de
 * TODAS as empresas ATIVAS (Company::scopeActive — exclui expiradas/canceladas/
 * trial-expirado e arquivadas). Primeira consola de DADOS transversais; o scope
 * de empresa ativa é reutilizável por futuras vistas (vendas, métricas globais).
 *
 * NÃO mexe nos endpoints de stand (/companies/{id}/cars) — esses continuam
 * scoped. Aqui o acesso "ver todas as empresas" é intencional e vive só no /admin.
 */
class AdminStockService
{
    private const STATUSES = ['draft', 'active', 'inactive', 'sold', 'available_soon', 'reserved'];

    /** Query base: veículos de empresas ATIVAS, com relações da listagem. */
    private function baseQuery(): Builder
    {
        return Car::query()
            ->whereHas('company', fn ($q) => $q->active())
            ->with([
                'company:id,fiscal_name,trade_name',
                'brand:id,name',
                'model:id,name',
                'images:id,car_id,image,is_primary',
            ]);
    }

    /** Aplica os filtros opcionais (todos defensivos) à query. */
    private function applyFilters(Builder $query, array $f): Builder
    {
        // Guarda defensiva: só filtra por empresa com um id numérico > 0. Evita
        // que um "company_id=undefined" (int→0) vindo do frontend zere a lista.
        if (! empty($f['company_id']) && is_numeric($f['company_id']) && (int) $f['company_id'] > 0) {
            $query->where('company_id', (int) $f['company_id']);
        }

        if (! empty($f['status'])) {
            $statuses = array_values(array_filter(
                array_map('trim', explode(',', (string) $f['status'])),
                fn ($s) => in_array($s, self::STATUSES, true)
            ));
            if ($statuses) {
                $query->whereIn('status', $statuses);
            }
        }

        if (! empty($f['car_brand_id'])) {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string) $f['car_brand_id']))));
            if ($ids) {
                $query->whereIn('car_brand_id', $ids);
            }
        }

        if (! empty($f['vehicle_type'])) {
            $query->where('vehicle_type', (string) $f['vehicle_type']);
        }

        if (! empty($f['search'])) {
            $term = '%' . $f['search'] . '%';
            $query->where(function ($q) use ($term) {
                $q->where('version', 'like', $term)
                    ->orWhere('license_plate', 'like', $term)
                    ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', $term))
                    ->orWhereHas('model', fn ($m) => $m->where('name', 'like', $term))
                    ->orWhereHas('company', fn ($c) => $c->where('fiscal_name', 'like', $term));
            });
        }

        return $query;
    }

    /** Página de veículos (transversal, empresas ativas) com filtros. */
    public function paginate(array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        return $this->applyFilters($this->baseQuery(), $filters)
            ->orderByDesc('id')
            ->paginate(perPage: $perPage, page: $page);
    }

    /** Números do topo: total de veículos, empresas ativas, repartição por status. */
    public function summary(): array
    {
        $byStatus = Car::query()
            ->whereHas('company', fn ($q) => $q->active())
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return [
            'total_vehicles'   => (int) $byStatus->sum(),
            'active_companies' => (int) Company::query()->active()->count(),
            'by_status'        => collect(self::STATUSES)
                ->mapWithKeys(fn ($s) => [$s => (int) ($byStatus[$s] ?? 0)])
                ->all(),
        ];
    }

    /** Empresas ativas COM stock (para o dropdown de filtro), com contagem. */
    public function companiesWithStock(): array
    {
        return Company::query()
            ->active()
            ->whereHas('cars')          // só empresas com ≥1 veículo (EXISTS, portável)
            ->withCount('cars')
            ->orderBy('fiscal_name')
            ->get(['id', 'fiscal_name', 'trade_name'])
            ->map(fn (Company $c) => [
                'id'    => $c->id,
                'name'  => $c->trade_name ?: $c->fiscal_name,
                'count' => (int) $c->cars_count,
            ])
            ->all();
    }
}
