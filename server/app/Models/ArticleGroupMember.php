<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArticleGroupMember extends Model
{
    protected $fillable = ['group_id', 'company_id', 'product_pingwin_id'];
}
