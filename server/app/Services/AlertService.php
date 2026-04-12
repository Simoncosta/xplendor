<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Car;
use App\Models\Company;
use Illuminate\Support\Collection;

class AlertService
{
    public function __construct(
        protected CarDecisionService $carDecisionService,
    ) {}

    public function generateForCompany(Company $company, ?string $from = null, ?string $to = null): Collection
    {
        $cars = Car::query()
            ->with(['brand:id,name', 'model:id,name'])
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->orderByDesc('created_at')
            ->get();

        if ($cars->isEmpty()) {
            return collect();
        }

        $decisions = collect($this->carDecisionService->resolveForCars($cars, $from, $to));
        $createdAlerts = collect();

        foreach ($cars as $car) {
            $decision = $decisions->firstWhere('car_id', $car->id);
            $guardrails = collect($decision['guardrails'] ?? []);

            foreach ($guardrails as $guardrail) {
                $alert = $this->createFromGuardrail($company, $car, $guardrail);

                if ($alert) {
                    $createdAlerts->push($alert);
                }
            }
        }

        return $createdAlerts;
    }

    public function getRecentForCompany(int $companyId, bool $unreadOnly = false, int $limit = 20): Collection
    {
        $query = Alert::query()
            ->with(['car.brand:id,name', 'car.model:id,name'])
            ->where('company_id', $companyId)
            ->latest();

        if ($unreadOnly) {
            $query->where('is_read', false);
        }

        return $query->limit($limit)->get()->map(fn (Alert $alert) => $this->transformAlert($alert));
    }

    public function unreadCountForCompany(int $companyId): int
    {
        return Alert::query()
            ->where('company_id', $companyId)
            ->where('is_read', false)
            ->count();
    }

    public function markAsRead(int $companyId, array $ids = []): int
    {
        $query = Alert::query()
            ->where('company_id', $companyId)
            ->where('is_read', false);

        if (!empty($ids)) {
            $query->whereIn('id', $ids);
        }

        return $query->update(['is_read' => true]);
    }

    public function markOneAsRead(int $companyId, int $alertId): bool
    {
        return Alert::query()
            ->where('company_id', $companyId)
            ->where('id', $alertId)
            ->where('is_read', false)
            ->update(['is_read' => true]) > 0;
    }

    public function getAlertsForDailySummary(Company $company): Collection
    {
        return Alert::query()
            ->with(['car.brand:id,name', 'car.model:id,name'])
            ->where('company_id', $company->id)
            ->where('created_at', '>=', now()->subDay())
            ->latest()
            ->get()
            ->map(fn (Alert $alert) => $this->transformAlert($alert));
    }

    private function createFromGuardrail(Company $company, Car $car, array $guardrail): ?Alert
    {
        $type = $this->mapAlertType($guardrail['type'] ?? null);

        if (!$type) {
            return null;
        }

        $existsToday = Alert::query()
            ->where('company_id', $company->id)
            ->where('car_id', $car->id)
            ->where('type', $type)
            ->where('title', (string) ($guardrail['title'] ?? ''))
            ->whereDate('created_at', today())
            ->exists();

        if ($existsToday) {
            return null;
        }

        return Alert::create([
            'company_id' => $company->id,
            'car_id' => $car->id,
            'type' => $type,
            'title' => (string) ($guardrail['title'] ?? 'Alerta operacional'),
            'message' => (string) ($guardrail['message'] ?? ''),
            'severity' => in_array(($guardrail['severity'] ?? 'medium'), ['low', 'medium', 'high'], true)
                ? $guardrail['severity']
                : 'medium',
            'is_read' => false,
        ]);
    }

    private function mapAlertType(?string $guardrailType): ?string
    {
        return match ($guardrailType) {
            'spend_without_qualified_lead', 'unanswered_leads' => 'urgent',
            'creative_fatigue', 'high_spend_low_intent' => 'warning',
            default => null,
        };
    }

    private function transformAlert(Alert $alert): array
    {
        $car = $alert->car;
        $carName = trim(implode(' ', array_filter([
            $car?->brand?->name,
            $car?->model?->name,
            $car?->version,
        ])));

        return [
            'id' => $alert->id,
            'company_id' => $alert->company_id,
            'car_id' => $alert->car_id,
            'car_name' => $carName,
            'type' => $alert->type,
            'title' => $alert->title,
            'message' => $alert->message,
            'severity' => $alert->severity,
            'is_read' => $alert->is_read,
            'created_at' => optional($alert->created_at)?->toISOString(),
            'detail_path' => "/cars/{$alert->car_id}/ficha",
        ];
    }
}
