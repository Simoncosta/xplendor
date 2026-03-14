<?php

namespace App\Observers;

use App\Models\CarPerformanceMetric;

class CarPerformanceMetricObserver
{

    /**
     * Depois de criar ou atualizar — sempre recalcula campos computados.
     */
    public function saved(CarPerformanceMetric $metric): void
    {
        // Evita loop: saveQuietly() dentro de recalculate() não dispara observers
        $metric->recalculate();
    }

    /**
     * Antes de criar — aplica regras de negócio e flags automáticas.
     */
    public function creating(CarPerformanceMetric $metric): void
    {
        $this->applyBusinessRules($metric);
    }

    /**
     * Antes de atualizar — re-aplica regras se campos críticos mudaram.
     */
    public function updating(CarPerformanceMetric $metric): void
    {
        if ($metric->isDirty(['spend_amount', 'channel', 'impressions', 'clicks'])) {
            $this->applyBusinessRules($metric);
        }
    }

    private function applyBusinessRules(CarPerformanceMetric $metric): void
    {
        // Regra: paid sem spend → marcar para revisão
        if ($metric->channel === 'paid' && $metric->spend_amount == 0) {
            $metric->data_source    = 'manual';
            $metric->requires_review = true;
        }
    }
}
