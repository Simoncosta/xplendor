<?php

namespace App\Repositories\Contracts;

interface UserInviteRepositoryInterface extends BaseRepositoryInterface
{
    public function getExpiresAtNull(string $token): mixed;
}
