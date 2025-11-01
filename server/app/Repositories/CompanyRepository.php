<?php

namespace App\Repositories;

use App\Models\Company;
use App\Repositories\Contracts\CompanyRepositoryInterface;

class CompanyRepository extends BaseRepository implements CompanyRepositoryInterface
{
    public function __construct(Company $model)
    {
        parent::__construct($model);
    }

    public function syncSocialLinks(Company $company, array $socialLinks): void
    {
        $company->socialLinks()->delete();

        foreach ($socialLinks as $link) {
            $company->socialLinks()->create([
                'type' => $link['type'],
                'value' => $link['value'],
            ]);
        }
    }
}
