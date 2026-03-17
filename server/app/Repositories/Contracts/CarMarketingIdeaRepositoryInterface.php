<?php

namespace App\Repositories\Contracts;

interface CarMarketingIdeaRepositoryInterface extends BaseRepositoryInterface
{
    public function getActiveCarsForWeeklyIdeas(int $companyId);
    public function upsertWeeklyIdea(int $companyId, int $carId, string $contentType, array $data);
    public function getWeeklyIdeas(int $companyId);
}
