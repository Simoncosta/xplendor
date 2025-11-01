<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UserService extends BaseService
{
    public function __construct(protected UserRepositoryInterface $userRepository) {}

    public function find(int $id): ?User
    {
        return $this->userRepository->find($id);
    }

    public function update(int $id, array $data): User
    {
        $user = $this->userRepository->find($id);

        if (! $user) {
            throw new NotFoundHttpException('User not found.');
        }

        $user = $this->userRepository->update($user, $data);

        if (isset($data['social_links'])) {
            $this->userRepository->syncSocialLinks($user, $data['social_links']);
        }

        return $user->load('socialLinks');
    }

    public function register(array $data): User
    {
        $data['password'] = Hash::make($data['password']);
        $user = $this->userRepository->create($data);

        return $this->userRepository->findWithRelations($user->id, ['company', 'socialLinks']);
    }

    public function authenticate(string $email, string $password): ?User
    {
        $user = $this->userRepository->findByEmail($email);

        if (! $user || ! Hash::check($password, $user->password)) {
            return null;
        }

        return $this->userRepository->findWithRelations($user->id, ['company', 'socialLinks']);
    }

    public function createToken(User $user, string $name): string
    {
        return $user->createToken($name)->plainTextToken;
    }

    public function logout(): void
    {
        request()->user()->currentAccessToken()->delete();
    }

    public function revokeToken(): void
    {
        request()->user()->tokens()->delete();
    }

    public function me(): User
    {
        $userId = request()->user()->id;
        return $this->userRepository->findWithRelations($userId, ['company', 'socialLinks']);
    }
}
