<?php

namespace App\Repositories\Contracts;

interface CarInteractionRepositoryInterface extends BaseRepositoryInterface
{
    public function countByCar(int $carId): int;

    public function groupByType(int $carId);

    /** Contactos por WhatsApp da viatura, pela regra única (App\Support\Tracking\WhatsappContact). */
    public function countWhatsappContacts(int $carId): int;

    public function getTimelineByCar(int $carId);
}
