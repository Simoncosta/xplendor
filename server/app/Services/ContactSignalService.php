<?php

namespace App\Services;

use App\Models\Car;

class ContactSignalService
{
    public function __construct(
        protected ContactProbabilityService $contactProbabilityService,
    ) {}

    public function calculate(
        Car $car,
        array $funnelAnalysis = [],
        array $intentAnalysis = [],
        array $leadRealityGap = [],
        array $attributionSummary = []
    ): array {
        $signal = $this->contactProbabilityService->calculate(
            $car,
            $funnelAnalysis,
            $intentAnalysis,
            $leadRealityGap,
            $attributionSummary
        );

        return [
            'score' => $signal['score'],
            'level' => $signal['level'],
            'state' => $signal['state'],
            'state_label' => $signal['state_label'],
            'summary' => $signal['summary'],
            'diagnosis' => $signal['diagnosis'],
            'inputs' => $signal['inputs'] ?? [],
        ];
    }

    public function wrap(array $contactSignal): array
    {
        return ['contact_signal' => $contactSignal];
    }
}
