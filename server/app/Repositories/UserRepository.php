<?php

namespace App\Repositories;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;

class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    public function __construct(User $model)
    {
        parent::__construct($model);
    }

    public function findByEmail(string $email)
    {
        return User::where('email', $email)->first();
    }

    public function syncSocialLinks(User $user, array $socialLinks): void
    {
        $user->socialLinks()->delete();

        foreach ($socialLinks as $link) {
            $user->socialLinks()->create([
                'type' => $link['type'],
                'value' => $link['value'],
            ]);
        }
    }
}
