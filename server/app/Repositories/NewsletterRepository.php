<?php

namespace App\Repositories;

use App\Models\Newsletter;
use App\Repositories\Contracts\NewsletterRepositoryInterface;

class NewsletterRepository extends BaseRepository implements NewsletterRepositoryInterface
{
    public function __construct(Newsletter $model)
    {
        parent::__construct($model);
    }
}
