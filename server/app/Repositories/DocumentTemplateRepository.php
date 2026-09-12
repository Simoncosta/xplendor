<?php

namespace App\Repositories;

use App\Models\DocumentTemplate;
use App\Repositories\Contracts\DocumentTemplateRepositoryInterface;

class DocumentTemplateRepository extends BaseRepository implements DocumentTemplateRepositoryInterface
{
    public function __construct(DocumentTemplate $model)
    {
        parent::__construct($model);
    }
}
