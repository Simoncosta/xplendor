<?php

namespace App\Repositories\Contracts;

use App\Models\User;

interface UserRepositoryInterface extends BaseRepositoryInterface
{
    public function findByEmail(string $email);
    public function syncSocialLinks(User $user, array $socialLinks): void;
}
