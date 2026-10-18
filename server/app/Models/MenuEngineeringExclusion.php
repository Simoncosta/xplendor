<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MenuEngineeringExclusion extends Model
{
    protected $fillable = ['company_id', 'product_id', 'reason', 'created_by'];
}
