<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Colaborador de uma empresa (pessoa da equipa), separado do utilizador: pode ter, ou
 * não, acesso à plataforma (user_id). Aparece na secção Equipa do site só se estiver
 * ativo, marcado para o site e com autorização de publicação registada (RGPD). O
 * contacto pessoal só aparece com autorização própria; senão vale o do departamento.
 */
class Collaborator extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    public const CONTACT_MODES = ['department', 'personal'];
    public const PHONE_TYPES = ['fixed', 'mobile'];

    protected $fillable = [
        'company_id', 'department_id', 'user_id', 'name', 'role_title', 'bio', 'photo_path',
        'whatsapp', 'phone', 'phone_type', 'email', 'contact_mode', 'show_on_site',
        'publish_consent_at', 'publish_consent_by_user_id',
        'personal_contact_consent_at', 'personal_contact_consent_by_user_id',
        'sort', 'active', 'deactivated_at',
    ];

    protected $casts = [
        'show_on_site'                => 'boolean',
        'active'                      => 'boolean',
        'sort'                        => 'integer',
        'publish_consent_at'          => 'datetime',
        'personal_contact_consent_at' => 'datetime',
        'deactivated_at'              => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(CompanyDepartment::class, 'department_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Convite ainda por aceitar (para dar acesso à plataforma). */
    public function pendingInvite(): HasOne
    {
        return $this->hasOne(UserInvite::class)->whereNull('accepted_at')->latestOfMany();
    }

    /** Os que podem aparecer no site: ativos, marcados e com autorização de publicação. */
    public function scopePublishable(Builder $query): Builder
    {
        return $query->where('active', true)->where('show_on_site', true)->whereNotNull('publish_consent_at');
    }

    /** Estado do acesso à plataforma: none | invited | active | revoked. */
    public function accessStatus(): string
    {
        if ($this->user_id && $this->user) {
            return $this->user->deactivated_at ? 'revoked' : 'active';
        }

        return $this->pendingInvite && $this->pendingInvite->expires_at?->isFuture() ? 'invited' : 'none';
    }
}
