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
        "company_id",
    ];
}
