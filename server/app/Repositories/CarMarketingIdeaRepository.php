<?php

namespace App\Repositories;

use App\Models\Car;
use App\Models\CarMarketingIdea;
use App\Repositories\Contracts\CarMarketingIdeaRepositoryInterface;

class CarMarketingIdeaRepository extends BaseRepository implements CarMarketingIdeaRepositoryInterface
{
    public function __construct(CarMarketingIdea $model)
    {
        parent::__construct($model);
    }

    public function getActiveCarsForWeeklyIdeas(int $companyId)
    {
        $since = now()->subDays(7);

        return Car::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->with(['brand:id,name', 'model:id,name'])
            ->withCount([
                'views as views_count' => fn($q) => $q->where('created_at', '>=', $since),
                'leads as leads_count' => fn($q) => $q->where('created_at', '>=', $since),
                'interactions as interactions_count' => fn($q) => $q->where('created_at', '>=', $since),
            ])
            ->get()
            ->map(function ($car) {
                $car->days_in_stock = (int) $car->created_at->diffInDays(now());
                return $car;
            })
            ->filter(fn($car) => $car->views_count > 0 || $car->interactions_count > 0 || $car->leads_count > 0)
            ->sortByDesc(fn($car) => ($car->views_count * 1) + ($car->interactions_count * 3) + ($car->leads_count * 5))
            ->take(5)
            ->values();
    }

    public function getActiveCarForWeeklyIdeas(int $companyId, int $carId)
    {
        $since = now()->subDays(7);

        $car = Car::query()
            ->where('company_id', $companyId)
            ->where('id', $carId)
            ->where('status', 'active')
            ->with(['brand:id,name', 'model:id,name'])
            ->withCount([
                'views as views_count' => fn($q) => $q->where('created_at', '>=', $since),
                'leads as leads_count' => fn($q) => $q->where('created_at', '>=', $since),
                'interactions as interactions_count' => fn($q) => $q->where('created_at', '>=', $since),
            ])
            ->first();

        if (!$car) {
            return collect();
        }

        $car->days_in_stock = (int) $car->created_at->diffInDays(now());

        return collect([$car]);
    }

    public function upsertWeeklyIdea(int $companyId, int $carId, string $contentType, array $data)
    {
        return $this->model->updateOrCreate(
            [
                'company_id' => $companyId,
                'car_id' => $carId,
                'week_ref' => now()->startOfWeek()->toDateString(),
                'content_type' => $contentType,
            ],
            $data
        );
    }

    public function getWeeklyIdeas(int $companyId)
    {
        return $this->model->with(['car.brand:id,name', 'car.model:id,name'])
            ->where('company_id', $companyId)
            ->whereDate('week_ref', now()->startOfWeek())
            ->latest()
            ->get();
    }

    public function getIdeasForCar(int $companyId, int $carId)
    {
        return $this->model->query()
            ->where('company_id', $companyId)
            ->where('car_id', $carId)
            ->orderByDesc('week_ref')
            ->orderByDesc('id')
            ->get();
    }
}
