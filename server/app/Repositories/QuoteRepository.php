<?php

namespace App\Repositories;

use App\Models\Quote;
use App\Repositories\Contracts\QuoteRepositoryInterface;

class QuoteRepository extends BaseRepository implements QuoteRepositoryInterface
{
    public function __construct(Quote $model)
    {
        parent::__construct($model);
    }
}
