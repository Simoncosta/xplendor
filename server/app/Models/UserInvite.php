<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserInvite extends Model
{
    protected $fillable = [
        "name",
        "email",
        "token",
        "accepted_at",
        "expires_at",
        "avatar",
        "role",
        "gender",
        "birthdate",
        "mobile",
        "whatsapp",
        "company_id",
        "collaborator_id",
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
        'expires_at'  => 'datetime',
    ];

    public function company(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
