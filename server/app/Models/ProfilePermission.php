<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** ACL: uma permissão (área × ação) de um perfil (D3: tabela, não JSON). */
class ProfilePermission extends Model
{
    public $timestamps = false;

    protected $fillable = ['profile_id', 'area', 'action'];
}
