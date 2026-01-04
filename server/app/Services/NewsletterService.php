<?php

namespace App\Services;

use App\Repositories\Contracts\NewsletterRepositoryInterface;

class NewsletterService extends BaseService
{
    public function __construct(protected NewsletterRepositoryInterface $newsletterRepository)
    {
        parent::__construct($newsletterRepository);
    }
}
