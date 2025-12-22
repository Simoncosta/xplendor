<?php

namespace App\Repositories;

use App\Models\UserInvite;
use App\Repositories\Contracts\UserInviteRepositoryInterface;

class UserInviteRepository extends BaseRepository implements UserInviteRepositoryInterface
{
    public function __construct(UserInvite $model)
    {
        parent::__construct($model);
    }

    public function getExpiresAtNull(string $token): UserInvite
    {
        return $this->model->where('token', $token)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();
    }
}
