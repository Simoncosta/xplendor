<?php

namespace App\Services;

use App\Mail\NewLeadMail;
use App\Repositories\Contracts\CarLeadRepositoryInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CarLeadService extends BaseService
{
    public function __construct(protected CarLeadRepositoryInterface $carLeadRepository)
    {
        parent::__construct($carLeadRepository);
    }

    public function store(array $data): mixed
    {
        // A lead grava-se PRIMEIRO e é o que importa (não perder leads do site).
        $carLead = $this->repository->store($data);

        // Notificação por email é BEST-EFFORT: nunca pode fazer falhar a entrada
        // da lead (site sem telefone, empresa sem email, SMTP em baixo…).
        $this->notifyNewLead($carLead);

        return $carLead;
    }

    private function notifyNewLead($carLead): void
    {
        try {
            $carLead->loadMissing(['company:id,email', 'car:id,version,car_brand_id,car_model_id', 'car.brand', 'car.model']);

            $to = $carLead->company?->email;
            if (! $to) {
                return; // sem email da empresa não há a quem notificar
            }

            $car = $carLead->car;
            $carName = $car
                ? trim(($car->brand->name ?? '') . ' ' . ($car->model->name ?? '') . ' - ' . ($car->version ?? ''))
                : '—';

            Mail::to($to)->send(new NewLeadMail(
                carName: $carName,
                customerName: (string) $carLead->name,
                customerPhone: $carLead->phone, // nullable (site pode não ter)
                customerEmail: (string) $carLead->email,
                message: $carLead->message,
            ));
        } catch (\Throwable $e) {
            Log::error('[Leads] Falha ao notificar lead nova (a lead entrou na mesma)', [
                'lead_id' => $carLead->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
