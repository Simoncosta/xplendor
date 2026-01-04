<?php

namespace App\Repositories;

use App\Models\Blog;
use App\Repositories\Contracts\BlogRepositoryInterface;

class BlogRepository extends BaseRepository implements BlogRepositoryInterface
{
    public function __construct(Blog $model)
    {
        parent::__construct($model);
    }
}
