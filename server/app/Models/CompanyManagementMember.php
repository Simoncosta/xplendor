<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pessoa da agência atribuída a um cliente (usada quando o âmbito da relação é "assigned"). */
class CompanyManagementMember extends Model
{
    public $timestamps = false;

    protected $fillable = ['management_id', 'user_id', 'assigned_by_user_id', 'assigned_at'];

    protected $casts = ['assigned_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
