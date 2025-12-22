<?php

namespace App\Services;

use App\Mail\InviteToRegisterMail;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\UserInviteRepositoryInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UserService extends BaseService
{
    public function __construct(
        protected UserRepositoryInterface $userRepository,
        protected UserInviteRepositoryInterface $userInviteRepository,
    ) {
        parent::__construct($userRepository);
    }

    public function find(int $id): ?User
    {
        return $this->userRepository->findOrFail($id, 'id');
    }

    public function store(array $data): mixed
    {
        $invite = $this->userInviteRepository->store([
            'email' => $data['email'],
            'name' => $data['name'],
            'company_id' => $data['company_id'],
            'gender' => $data['gender'] ?? null,
            'birthdate' => $data['birthdate'] ?? null,
            'role' => $data['role'],
            'token' => Str::uuid()->toString(),
            'expires_at' => Carbon::now()->addDays(7),
        ]);

        // Avatar (opcional)
        if (!empty($data['avatar']) && $data['avatar'] instanceof UploadedFile) {
            $avatarPath = $this->uploadAvatar($data['avatar'], $invite->company_id);

            $this->userInviteRepository->update($invite->id, [
                'avatar' => $avatarPath,
            ]);
        }

        // Gera URL de convite
        $inviteUrl = rtrim(config('app.frontend_url'), '/')
            . '/auth/register?token=' . $invite->token;

        // Envia email
        Mail::to($invite->email)->send(
            new InviteToRegisterMail(
                $data['fiscal_name'] ?? Auth::user()->company->fiscal_name,
                $inviteUrl
            )
        );

        return $invite->refresh();
    }

    public function update(int $id, array $data): mixed
    {
        $user = $this->userRepository->findOrFail($id, 'id');

        if (! $user) {
            throw new NotFoundHttpException('User not found.');
        }

        // Se nova logo for enviada
        if (isset($data['avatar']) && $data['avatar'] instanceof UploadedFile) {
            // Deleta a logo antiga, se existir
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }

            // Salva nova logo
            $data['avatar'] = $this->uploadAvatar($data['avatar'], $user->id);
        } else {
            // Mantém logo antiga
            $data['avatar'] = $user->avatar;
        }

        $this->userRepository->update($id, $data);

        return $user->refresh();
    }

    private function uploadAvatar(UploadedFile $file, int $companyId): string
    {
        $ext = $file->getClientOriginalExtension();

        if (!$ext) {
            // fallback baseado no mimeType
            $mime = $file->getMimeType(); // ex: image/png
            $ext = match ($mime) {
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/jpg' => 'jpg',
                default => 'bin', // última linha de defesa
            };
        }

        $filename = time() . '_' . uniqid() . '.' . $ext;
        $path = $file->storeAs("company_{$companyId}/users", $filename, "public");

        return $path;
    }

    public function register(array $data): mixed
    {
        $data['password'] = Hash::make($data['password']);
        $user = $this->userRepository->store($data);

        return $this->userRepository->findWithRelations($user->id, ['company']);
    }

    public function authenticate(string $email, string $password): mixed
    {
        $user = $this->userRepository->findByEmail($email);

        if (! $user || ! Hash::check($password, $user->password)) {
            return null;
        }

        return $this->userRepository->findWithRelations($user->id, ['company']);
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

    public function me(): mixed
    {
        $userId = request()->user()->id;
        return $this->userRepository->findWithRelations($userId, ['company']);
    }

    public function registerByInvite(array $data): mixed
    {
        $invite = $this->userInviteRepository->getExpiresAtNull($data['token']);

        if (! $invite) {
            throw new \Exception('Convite inválido ou expirado');
        }

        // evita duplicados
        if ($this->userRepository->findByEmail($invite->email)) {
            throw new \Exception('Este e-mail já está registado.');
        }

        /** @var \App\Models\User $user */
        $user = null;

        DB::transaction(function () use ($invite, $data, &$user) {
            // cria user
            $user = $this->userRepository->store([
                'name'          => $invite->name,
                'email'         => $invite->email,
                'avatar'        => $invite->avatar,
                'birthdate'     => $invite->birthdate,
                'gender'        => $invite->gender,
                'role'          => $invite->role,
                'company_id'    => $invite->company_id,
                'password'      => Hash::make($data['password']),
                'accepted_at'   => now(),
            ]);

            // marca convite como usado
            $this->userInviteRepository->update($invite->id, [
                'accepted_at' => now(),
            ]);
        });

        // login automático (Sanctum)
        $token = $user->createToken('auth')->plainTextToken;

        return array_merge(['token' => $token], $user->toArray());
    }
}
