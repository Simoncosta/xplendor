<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\CalculateCarSalePotentialScoreJob;
use App\Models\Car;
use Illuminate\Console\Command;

class RecalculateCarScoresCommand extends Command
{
    protected $signature = 'cars:recalculate-scores
        {--companies= : IDs de empresas separados por vírgula (ex: 2,4). Omitir = todas.}
        {--status=active : Estado das viaturas a recalcular (ex: active).}';

    protected $description = 'Despacha o recálculo do IPS (CalculateCarSalePotentialScoreJob) para o stock filtrado.';

    public function handle(): int
    {
        $status = (string) $this->option('status');

        $query = Car::query()->select('id', 'company_id');

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        $companiesOption = $this->option('companies');
        if ($companiesOption !== null && $companiesOption !== '') {
            $companyIds = collect(explode(',', (string) $companiesOption))
                ->map(fn ($id) => (int) trim($id))
                ->filter()
                ->values()
                ->all();

            if (!empty($companyIds)) {
                $query->whereIn('company_id', $companyIds);
            }
        }

        $cars = $query->get();

        foreach ($cars as $car) {
            CalculateCarSalePotentialScoreJob::dispatch(
                carId: $car->id,
                companyId: $car->company_id,
                triggeredBy: 'manual',
            );
        }

        $this->info("IPS: {$cars->count()} jobs despachados (status='{$status}', companies=" . ($companiesOption ?: 'todas') . ').');

        return self::SUCCESS;
    }
}
