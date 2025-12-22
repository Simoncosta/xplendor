<?php

namespace App\Services;

use App\Models\Plan;
use App\Repositories\Contracts\UserInviteRepositoryInterface;

class UserInviteService extends BaseService
{
    public function __construct(protected UserInviteRepositoryInterface $userInviteRepository)
    {
        parent::__construct($userInviteRepository);
    }
}
