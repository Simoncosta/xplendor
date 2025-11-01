<?php

namespace App\Services;

use App\Models\Company;
use App\Repositories\Contracts\CompanyRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CompanyService extends BaseService
{
    public function __construct(protected CompanyRepositoryInterface $companyRepository)
    {
        parent::__construct($companyRepository);
    }

    public function all(): Collection
    {
        return $this->companyRepository->allWithRelations(['plan', 'country', 'operations', 'socialLinks']);
    }

    public function store(array $data): Company
    {
        $company = $this->companyRepository->create($data);
        return $this->companyRepository->findWithRelations($company->id, ['plan', 'country']);
    }

    public function update(int $id, array $data): Company
    {
        $company = $this->companyRepository->find($id);

        if (! $company) {
            throw new NotFoundHttpException('Company not found.');
        }

        $company = $this->companyRepository->update($company, $data);

        if (isset($data['social_links'])) {
            $this->companyRepository->syncSocialLinks($company, $data['social_links']);
        }

        return $this->companyRepository->findWithRelations($company->id, ['plan', 'country', 'socialLinks', 'operations']);
    }
}
