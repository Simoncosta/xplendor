<?php

namespace App\Services;

use App\Repositories\Contracts\CompanyViewRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CompanyViewService extends BaseService
{
    public function __construct(protected CompanyViewRepositoryInterface $companyViewRepository)
    {
        parent::__construct($companyViewRepository);
    }
}
