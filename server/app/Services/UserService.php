<?php

namespace App\Services;

use App\Mail\InviteToRegisterMail;
use App\Models\Collaborator;
use App\Models\User;
use App\Models\UserInvite;
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
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UserService extends BaseService
{
    public const INVALID_INVITE = 'Este convite é inválido, já foi usado ou expirou. Peça um novo convite ao administrador da empresa.';

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

    /**
     * Convida uma pessoa para criar conta na empresa (o perfil e a empresa vêm do
     * chamador, já validados). Um convite ainda por aceitar para o mesmo email na mesma
     * empresa é reaproveitado (novo token e nova validade) em vez de rebentar no índice
     * único. Se vier de um colaborador, fica ligado a ele e a conta liga-se ao aceitar.
     * O email vai em fila, com o link para a app (/app/register).
     */
    public function store(array $data): mixed
    {
        $fields = [
            'name'            => $data['name'],
            'gender'          => $data['gender'] ?? null,
            'birthdate'       => $data['birthdate'] ?? null,
            'mobile'          => $data['mobile'] ?? null,
            'whatsapp'        => $data['whatsapp'] ?? null,
            'role'            => $data['role'],
            'collaborator_id' => $data['collaborator_id'] ?? null,
            'token'           => Str::uuid()->toString(),
            'expires_at'      => Carbon::now()->addDays(7),
        ];

        $invite = UserInvite::where('company_id', $data['company_id'])->where('email', $data['email'])->first();
        if ($invite && $invite->accepted_at !== null) {
            throw ValidationException::withMessages(['email' => ['Esta pessoa já aceitou um convite e tem conta criada.']]);
        }
        if ($invite) {
            $invite->update($fields);
        } else {
            $invite = $this->userInviteRepository->store($fields + ['email' => $data['email'], 'company_id' => $data['company_id']]);
        }

        // Avatar (opcional)
        if (!empty($data['avatar']) && $data['avatar'] instanceof UploadedFile) {
            $avatarPath = $this->uploadAvatar($data['avatar'], $invite->company_id);

            $this->userInviteRepository->update($invite->id, [
                'avatar' => $avatarPath,
            ]);
        }

        $this->sendInvite($invite->refresh(), $data['fiscal_name'] ?? null);

        return $invite->refresh();
    }

    /** Envia (ou reenvia) o email do convite, em fila. */
    public function sendInvite(UserInvite $invite, ?string $companyName = null): void
    {
        $companyName ??= $invite->company?->trade_name ?: $invite->company?->fiscal_name ?: (string) config('app.name');
        Mail::to($invite->email)->queue(new InviteToRegisterMail($companyName, self::inviteUrl($invite)));
    }

    /** Link do convite: a página de registo da app, em /app/register. */
    public static function inviteUrl(UserInvite $invite): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/register?token=' . $invite->token;
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

        // Password
        if (!empty($data['password'])) {
            $data['password'] = bcrypt($data['password']);
        } else {
            unset($data['password']);
        }

        unset($data['password_confirmation']);

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
        if ($user->deactivated_at !== null) {
            throw ValidationException::withMessages(['email' => ['O acesso desta conta foi retirado pela empresa. Fale com o administrador.']]);
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
            throw ValidationException::withMessages(['token' => [self::INVALID_INVITE]]);
        }

        // evita duplicados
        if ($this->userRepository->findByEmail($invite->email)) {
            throw ValidationException::withMessages(['token' => ['Já existe uma conta com este email. Entre com a sua password ou peça uma nova.']]);
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
                'mobile'        => $invite->mobile,
                'whatsapp'      => $invite->whatsapp,
                'role'          => $invite->role,
                'company_id'    => $invite->company_id,
                'password'      => Hash::make($data['password']),
                'accepted_at'   => now(),
            ]);

            // marca convite como usado
            $this->userInviteRepository->update($invite->id, [
                'accepted_at' => now(),
            ]);

            // Convite dado a um colaborador: a conta nova fica ligada a ele (mesma empresa).
            if ($invite->collaborator_id) {
                Collaborator::where('id', $invite->collaborator_id)->where('company_id', $invite->company_id)
                    ->whereNull('user_id')->update(['user_id' => $user->id]);
            }
        });

        // login automático (Sanctum)
        $token = $user->createToken('auth')->plainTextToken;

        return array_merge(['token' => $token], $user->toArray());
    }

    /**
     * Dados mínimos para a página de registo (nome, email, empresa e validade). Nunca
     * devolve o token, o perfil nem ids. Convite usado, expirado ou inexistente: 410/404.
     */
    public function getUserInviteByToken(string $token): array
    {
        $invite = UserInvite::with('company:id,fiscal_name,trade_name')->where('token', $token)->first();
        if (! $invite) {
            abort(404, self::INVALID_INVITE);
        }
        if ($invite->accepted_at !== null || $invite->expires_at === null || $invite->expires_at->isPast()) {
            abort(410, self::INVALID_INVITE);
        }

        return [
            'name'         => $invite->name,
            'email'        => $invite->email,
            'company_name' => $invite->company?->trade_name ?: $invite->company?->fiscal_name,
            'expires_at'   => $invite->expires_at->toIso8601String(),
        ];
    }
}
