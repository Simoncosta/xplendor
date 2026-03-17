<?php

namespace App\Services;

use App\Mail\NewLeadMail;
use App\Repositories\Contracts\CarLeadRepositoryInterface;
use Illuminate\Support\Facades\Mail;

class CarLeadService extends BaseService
{
    public function __construct(protected CarLeadRepositoryInterface $carLeadRepository)
    {
        parent::__construct($carLeadRepository);
    }

    public function store(array $data): mixed
    {
        $carLead = $this->repository->store($data);

        $carLead->load(['company:id,email', 'car:id,version,car_brand_id,car_model_id', 'car.brand', 'car.model']);

        Mail::to($carLead->company->email)->send(
            new NewLeadMail(
                carName: $carLead->car->brand->name . " " . $carLead->car->model->name . " - " . $carLead->car->version,
                customerName: $carLead->name,
                customerPhone: $carLead->phone,
                customerEmail: $carLead->email,
                message: $carLead->message
            )
        );

        return $carLead;
    }
}
