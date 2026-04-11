<?php

namespace App\Services\ActionExecution\Contracts;

use App\Models\Car;

interface ActionExecutionHandlerInterface
{
    public function execute(Car $car, array $context = []): array;
}
