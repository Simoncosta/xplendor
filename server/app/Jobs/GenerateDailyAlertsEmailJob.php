<?php

namespace App\Jobs;

use App\Mail\DailyAlertsSummaryMail;
use App\Models\Company;
use App\Services\AlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class GenerateDailyAlertsEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const RECIPIENTS = [
        'simoncosta@xplendor.tech',
        'rosianemunhoz@xplendor.tech',
    ];

    public function handle(AlertService $alertService): void
    {
        $companies = Company::query()
            ->whereHas('cars', fn ($query) => $query->where('status', 'active'))
            ->get();

        foreach ($companies as $company) {
            $alertService->generateForCompany($company);

            $alerts = $alertService->getAlertsForDailySummary($company);

            if ($alerts->isEmpty()) {
                continue;
            }

            $cacheKey = sprintf('daily-alerts-email:%d:%s', $company->id, now()->toDateString());

            if (Cache::has($cacheKey)) {
                continue;
            }

            Mail::to(self::RECIPIENTS)->send(new DailyAlertsSummaryMail($company, $alerts));

            Cache::put($cacheKey, true, now()->addDays(2));

            Log::info('[DailyAlerts] Summary email sent', [
                'company_id' => $company->id,
                'alerts_count' => $alerts->count(),
                'recipients' => self::RECIPIENTS,
            ]);
        }
    }
}
