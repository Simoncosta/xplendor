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

    public int $tries = 3;
    public int $timeout = 900;

    public function __construct(
        public int $companyId
    ) {}

    public function handle(CarMarketingIdeaService $service): void
    {
        $service->generateForCompany($this->companyId);
    }

    // public function handle(CarMarketingIdeaService $service): void
    // {
    //     $companyIds = Company::query()->pluck('id');

    //     foreach ($companyIds as $companyId) {
    //         $service->generateForCompany($companyId);
    //     }
    // }
}
