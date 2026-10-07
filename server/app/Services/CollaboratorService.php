<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Collaborator;
use App\Models\CompanyDepartment;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Services\Tenancy\CompanyAccess;
use App\Models\UserInvite;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * Colaboradores e departamentos de uma empresa.
 *  · Conteúdo (dados, foto, departamentos, site): admin da empresa ou a equipa XPLENDOR
 *    em sessão como cliente (impersonation).
 *  · Acessos à plataforma (convidar, reenviar, cancelar, retirar, repor): SÓ o admin da
 *    própria empresa, nunca em impersonation (o mesmo caminho validado no hotfix: perfil
 *    'user', empresa da rota).
 *  · RGPD: sem autorização de publicação não vai para o site; contacto pessoal só com
 *    autorização própria.
 */
class CollaboratorService
{
    public const PHOTO_SIZE = 400;

    public function __construct(private readonly UserService $users) {}

    // ── Permissões ───────────────────────────────────────────────────────────

    /** Conteúdo da empresa (perfil da marca, equipa, definições): admin, root, sessão como cliente ou agência gestora. */
    public static function canEditContent(User $actor, int $companyId): bool
    {
        return $actor->role === 'root'
            || ((int) $actor->company_id === $companyId && ($actor->role === 'admin' || ImpersonationSession::activeFor($actor)))
            || app(CompanyAccess::class)->viaAgency($actor, $companyId);
    }

    /**
     * Ligar e desligar integrações (redes sociais, anúncios, GA4) com o próprio login:
     * admin da própria empresa, o root (em qualquer empresa) ou um ADMIN da agência
     * gestora (os outros membros da agência produzem, mas não ligam integrações).
     * Nunca em impersonation (o login seria o do cliente).
     */
    public static function canConfigureIntegrations(User $actor, int $companyId): bool
    {
        if (ImpersonationSession::activeFor($actor)) {
            return false;
        }

        return ($actor->role === 'admin' && (int) $actor->company_id === $companyId)
            || $actor->role === 'root'
            || self::isAgencyAdmin($actor, $companyId);
    }

    /** Pela agência gestora, só os admins dela ligam integrações (os utilizadores do cliente seguem as regras da empresa). */
    public static function agencyMayConfigureIntegrations(User $actor, int $companyId): bool
    {
        return ! app(CompanyAccess::class)->viaAgency($actor, $companyId) || $actor->role === 'admin';
    }

    private static function isAgencyAdmin(User $actor, int $companyId): bool
    {
        return $actor->role === 'admin' && app(CompanyAccess::class)->viaAgency($actor, $companyId);
    }

    /**
     * Acessos do cliente (contas dos colaboradores, aprovadores, terminar a relação com a
     * agência): administrador da própria empresa; o root conta como admin da SUA empresa
     * (nunca de outras). Nunca em impersonation. A agência gestora nunca.
     */
    public static function canManageAccess(User $actor, int $companyId): bool
    {
        return in_array($actor->role, ['admin', 'root'], true)
            && (int) $actor->company_id === $companyId
            && ! ImpersonationSession::activeFor($actor);
    }

    // ── Conteúdo ─────────────────────────────────────────────────────────────

    /** Cria ou altera os dados de um colaborador (com as regras de RGPD). */
    public function save(?Collaborator $c, int $companyId, array $data, User $actor): Collaborator
    {
        $c ??= new Collaborator(['company_id' => $companyId, 'contact_mode' => 'department', 'show_on_site' => false, 'active' => true]);

        if (array_key_exists('department_id', $data) && $data['department_id']) {
            $belongs = CompanyDepartment::where('company_id', $companyId)->whereKey($data['department_id'])->exists();
            if (! $belongs) {
                throw ValidationException::withMessages(['department_id' => ['Departamento não encontrado nesta empresa.']]);
            }
        }

        $c->fill(collect($data)->only(['department_id', 'name', 'role_title', 'bio', 'whatsapp', 'phone', 'phone_type', 'email', 'contact_mode', 'show_on_site', 'sort'])->all());

        // Autorizações (RGPD): ficam registadas com a data e quem registou.
        if (array_key_exists('publish_consent', $data)) {
            if ($data['publish_consent'] && ! $c->publish_consent_at) {
                $c->publish_consent_at = now();
                $c->publish_consent_by_user_id = $actor->id;
            } elseif (! $data['publish_consent']) {
                $c->publish_consent_at = null;
                $c->publish_consent_by_user_id = null;
            }
        }
        if (array_key_exists('personal_contact_consent', $data)) {
            if ($data['personal_contact_consent'] && ! $c->personal_contact_consent_at) {
                $c->personal_contact_consent_at = now();
                $c->personal_contact_consent_by_user_id = $actor->id;
            } elseif (! $data['personal_contact_consent']) {
                $c->personal_contact_consent_at = null;
                $c->personal_contact_consent_by_user_id = null;
            }
        }

        // Sem autorização de publicação não se mostra no site; sem autorização do contacto
        // pessoal vale o do departamento.
        if ($c->show_on_site && ! $c->publish_consent_at) {
            throw ValidationException::withMessages(['show_on_site' => ['Para mostrar no site, registe primeiro que a pessoa autorizou a publicação.']]);
        }
        if ($c->contact_mode === 'personal' && ! $c->personal_contact_consent_at) {
            throw ValidationException::withMessages(['contact_mode' => ['Para mostrar o contacto pessoal, registe primeiro a autorização da pessoa.']]);
        }

        $c->save();

        return $c->fresh(['department', 'user', 'pendingInvite']);
    }

    /** Foto quadrada 400x400 em WebP, sem EXIF; o original nunca é guardado. */
    public function storePhoto(Collaborator $c, UploadedFile $file): Collaborator
    {
        $webp = (string) (new ImageManager(new Driver()))->read($file->getRealPath())
            ->cover(self::PHOTO_SIZE, self::PHOTO_SIZE)
            ->toWebp(82);
        $path = "company_{$c->company_id}/team/" . Str::uuid() . '.webp';
        Storage::disk('public')->put($path, $webp);

        $old = $c->photo_path;
        $c->update(['photo_path' => $path]);
        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return $c->fresh(['department', 'user', 'pendingInvite']);
    }

    public function deletePhoto(Collaborator $c): Collaborator
    {
        if ($c->photo_path) {
            Storage::disk('public')->delete($c->photo_path);
            $c->update(['photo_path' => null]);
        }

        return $c->fresh(['department', 'user', 'pendingInvite']);
    }

    /** Desativar: sai do site. Não mexe na conta (retirar o acesso é uma ação própria). */
    public function setActive(Collaborator $c, bool $active): Collaborator
    {
        $c->update(['active' => $active, 'deactivated_at' => $active ? null : now()]);

        return $c->fresh(['department', 'user', 'pendingInvite']);
    }

    /** Apagar só colaboradores sem conta ligada; a foto é apagada do disco. */
    public function delete(Collaborator $c): void
    {
        if ($c->user_id) {
            throw ValidationException::withMessages(['collaborator' => ['Este colaborador tem conta na plataforma: retire primeiro o acesso e desative-o.']]);
        }
        $this->deletePhoto($c);
        UserInvite::where('collaborator_id', $c->id)->whereNull('accepted_at')->delete();
        $c->delete();
    }

    // ── Acessos à plataforma (só admin da própria empresa, fora de impersonation) ─

    public function grantAccess(Collaborator $c, string $email, User $actor): Collaborator
    {
        if ($c->user_id) {
            throw ValidationException::withMessages(['email' => ['Este colaborador já tem conta na plataforma.']]);
        }
        if (User::withTrashed()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => ['Já existe uma conta com este email.']]);
        }

        $this->users->store([
            'email'           => $email,
            'name'            => $c->name,
            'company_id'      => $c->company_id,
            'collaborator_id' => $c->id,
            'whatsapp'        => $c->whatsapp,
            'mobile'          => $c->phone_type === 'mobile' ? $c->phone : null,
            'role'            => 'user',   // nunca escala perfil (igual ao convite de utilizadores)
        ]);

        return $c->fresh(['department', 'user', 'pendingInvite']);
    }

    public function resendInvite(Collaborator $c): Collaborator
    {
        $invite = UserInvite::where('collaborator_id', $c->id)->whereNull('accepted_at')->latest('id')->first();
        if (! $invite) {
            throw ValidationException::withMessages(['invite' => ['Não há nenhum convite por aceitar para este colaborador.']]);
        }
        $invite->update(['token' => Str::uuid()->toString(), 'expires_at' => now()->addDays(7)]);
        $this->users->sendInvite($invite->fresh('company'));

        return $c->fresh(['department', 'user', 'pendingInvite']);
    }

    public function cancelInvite(Collaborator $c): Collaborator
    {
        $deleted = UserInvite::where('collaborator_id', $c->id)->whereNull('accepted_at')->delete();
        if ($deleted === 0) {
            throw ValidationException::withMessages(['invite' => ['Não há nenhum convite por aceitar para este colaborador.']]);
        }

        return $c->fresh(['department', 'user', 'pendingInvite']);
    }

    /** Retirar o acesso: a conta deixa de entrar e as sessões abertas são revogadas. */
    public function revokeAccess(Collaborator $c, User $actor): Collaborator
    {
        $user = $c->user;
        if (! $user) {
            throw ValidationException::withMessages(['access' => ['Este colaborador não tem conta na plataforma.']]);
        }
        if ($user->id === $actor->id) {
            throw ValidationException::withMessages(['access' => ['Não pode retirar o seu próprio acesso.']]);
        }
        if ($user->role === 'root' || (int) $user->company_id !== (int) $c->company_id) {
            abort(403, 'Acesso negado.');
        }
        DB::transaction(function () use ($user) {
            $user->forceFill(['deactivated_at' => now()])->save();
            $user->tokens()->delete();
        });

        return $c->fresh(['department', 'user', 'pendingInvite']);
    }

    public function restoreAccess(Collaborator $c): Collaborator
    {
        $user = $c->user;
        if (! $user || $user->deactivated_at === null) {
            throw ValidationException::withMessages(['access' => ['Este colaborador não tem um acesso retirado para repor.']]);
        }
        $user->forceFill(['deactivated_at' => null])->save();

        return $c->fresh(['department', 'user', 'pendingInvite']);
    }

    // ── Departamentos ────────────────────────────────────────────────────────

    /** Cria os departamentos sugeridos que ainda não existam (ex.: Comercial, Oficina). */
    public function createSuggestedDepartments(int $companyId): int
    {
        $existing = CompanyDepartment::where('company_id', $companyId)->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
        $created = 0;
        foreach (CompanyDepartment::SUGGESTED as $i => $name) {
            if (! in_array(mb_strtolower($name), $existing, true)) {
                CompanyDepartment::create(['company_id' => $companyId, 'name' => $name, 'sort' => ($i + 1) * 10]);
                $created++;
            }
        }

        return $created;
    }
}
