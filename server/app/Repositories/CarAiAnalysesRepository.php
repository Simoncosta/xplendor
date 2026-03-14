<?php

namespace App\Repositories;

use App\Models\CarAiAnalysis;
use App\Repositories\Contracts\CarAiAnalysesRepositoryInterface;

class CarAiAnalysesRepository extends BaseRepository implements CarAiAnalysesRepositoryInterface
{
    public function __construct(CarAiAnalysis $model)
    {
        parent::__construct($model);
    }

    /**
     * Carros ordenados por score para o dashboard de prioridades.
     * Útil para mostrar "carros que precisam de atenção".
     */
    public function getByCompanyOrderedByScore(int $companyId, int $limit = 10): mixed
    {
        return $this->model->where('company_id', $companyId)
            ->whereNotNull('score_conversao')
            ->with('car.brand', 'car.model')
            ->orderBy('score_conversao', 'asc') // score baixo = mais crítico
            ->limit($limit)
            ->get();
    }

    /**
     * Carros com alerta de preço activo.
     */
    public function getPriceAlerts(int $companyId): mixed
    {
        return $this->model->where('company_id', $companyId)
            ->where('price_alert', true)
            ->with('car.brand', 'car.model')
            ->orderBy('updated_at', 'desc')
            ->get();
    }

    /**
     * Carros com urgência imediata ou alta.
     */
    public function getUrgent(int $companyId): mixed
    {
        return $this->model->where('company_id', $companyId)
            ->whereIn('urgency_level', ['Imediata', 'Alta'])
            ->with('car.brand', 'car.model')
            ->orderByRaw("FIELD(urgency_level, 'Imediata', 'Alta')")
            ->get();
    }
}
