<?php

namespace App\Observers;

use App\Models\Car;
use App\Models\CarLead;
use App\Models\CarPerformanceMetric;
use Carbon\Carbon;

class LeadObserver
{
    public function created(CarLead $lead): void
    {
        if (! $lead->car_id) {
            return;
        }

        $this->fillTimeToFirstLead($lead);
    }

    private function fillTimeToFirstLead(CarLead $lead): void
    {
        // Verifica se já existe algum registo com time_to_first_lead_hours preenchido
        $alreadyFilled = CarPerformanceMetric::where('car_id', $lead->car_id)
            ->whereNotNull('time_to_first_lead_hours')
            ->exists();

        if ($alreadyFilled) {
            return; // Só registamos a PRIMEIRA lead
        }

        $car         = Car::find($lead->car_id);
        $publishedAt = Carbon::parse($car->created_at);
        $leadAt      = Carbon::parse($lead->created_at);
        $hours       = (int) $publishedAt->diffInHours($leadAt);

        // Atualiza todos os registos de performance deste carro
        CarPerformanceMetric::where('car_id', $lead->car_id)
            ->update(['time_to_first_lead_hours' => $hours]);
    }
}
