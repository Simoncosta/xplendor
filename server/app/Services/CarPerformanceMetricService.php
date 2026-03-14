<?php

namespace App\Services;

use App\Models\Car;
use App\Models\CarPerformanceMetric;
use App\Repositories\Contracts\CarPerformanceMetricRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CarPerformanceMetricService
{
    public function __construct(
        private CarPerformanceMetricRepositoryInterface $performanceRepository
    ) {}

    // ── Leitura ───────────────────────────────────────────────────────────────

    public function getForCar(
        int $carId,
        int $companyId,
        ?string $channel = null,
        ?string $from    = null,
        ?string $to      = null
    ): Collection {
        $this->ensureCarBelongsToCompany($carId, $companyId);

        return $this->performanceRepository->getForCar($carId, $companyId, $channel, $from, $to);
    }

    public function getSummary(int $carId, int $companyId): array
    {
        $this->ensureCarBelongsToCompany($carId, $companyId);

        return [
            'totals'     => $this->performanceRepository->getSummary($carId, $companyId),
            'by_channel' => $this->performanceRepository->getSummaryByChannel($carId, $companyId),
        ];
    }

    // ── Escrita ───────────────────────────────────────────────────────────────

    public function create(int $carId, int $companyId, array $data): CarPerformanceMetric
    {
        $this->ensureCarBelongsToCompany($carId, $companyId);

        return $this->performanceRepository->create([
            ...$data,
            'car_id'     => $carId,
            'company_id' => $companyId,
        ]);
    }

    public function update(int $metricId, int $carId, int $companyId, array $data): CarPerformanceMetric
    {
        $metric = $this->resolveMetric($metricId, $carId, $companyId);

        return $this->performanceRepository->update($metric, $data);
    }

    // ── Guardrails ────────────────────────────────────────────────────────────

    private function ensureCarBelongsToCompany(int $carId, int $companyId): void
    {
        $exists = Car::where('id', $carId)
            ->where('company_id', $companyId)
            ->exists();

        if (! $exists) {
            throw new AccessDeniedHttpException('Acesso negado a esta viatura.');
        }
    }

    private function resolveMetric(int $metricId, int $carId, int $companyId): CarPerformanceMetric
    {
        $metric = $this->performanceRepository->findForCompany($metricId, $companyId);

        if (! $metric || $metric->car_id !== $carId) {
            throw new NotFoundHttpException('Registo não encontrado.');
        }

        return $metric;
    }
}
