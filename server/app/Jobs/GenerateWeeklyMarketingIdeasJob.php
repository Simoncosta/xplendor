<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\CarMarketingIdeaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateWeeklyMarketingIdeasJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?int $companyId = null,
        public ?int $carId = null
    ) {}

    public function handle(CarMarketingIdeaService $service): void
    {
        if ($this->companyId !== null) {
            $service->generateForCompany($this->companyId, $this->carId);
            return;
        }

        $companyIds = Company::query()->pluck('id');
        foreach ($companyIds as $companyId) {
            $service->generateForCompany($companyId);
        }
    }
}
