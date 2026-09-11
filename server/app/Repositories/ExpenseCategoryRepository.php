<?php

namespace App\Repositories;

use App\Models\ExpenseCategory;
use App\Repositories\Contracts\ExpenseCategoryRepositoryInterface;

class ExpenseCategoryRepository extends BaseRepository implements ExpenseCategoryRepositoryInterface
{
    public function __construct(ExpenseCategory $model)
    {
        parent::__construct($model);
    }
}
