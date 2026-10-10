<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected static function booted(): void
    {
        // ACL (F2): até haver a escolha do perfil no ecrã (F5), o perfil segue o papel. Um
        // utilizador novo fica com o perfil equivalente ao papel; ao mudar de papel, um perfil
        // de sistema acompanha a mudança (um perfil personalizado fica como está). O root não
        // tem perfil.
        static::saving(function (User $user) {
            \App\Access\ProfileAssignment::syncWithRole($user);
        });

        // Gestão por agências: uma empresa arquivada (sem admin nem agência) sai do arquivo
        // quando ganha um administrador ativo.
        static::saved(function (User $user) {
            if ($user->role === 'admin' && $user->deactivated_at === null && $user->company_id) {
                $company = Company::find($user->company_id);
                if ($company?->archived_at !== null) {
                    app(\App\Services\Agency\CompanyArchiveService::class)->release($company);
                }
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'avatar',
        'signature',
        'email',
        'password',
        'role',
        'gender',
        'birthdate',
        'mobile',
        'whatsapp',
        'company_id',
        'accepted_at',
        'deactivated_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'accepted_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'can_approve_content' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** O dono da plataforma (a equipa XPLENDOR). Num só sítio, em vez de comparar o papel. */
    public function isRoot(): bool
    {
        return $this->role === 'root';
    }

    /** Administrador da própria empresa (o papel; as permissões vêm do perfil, ACL). */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** ACL: o perfil na própria empresa. */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(PermissionProfile::class, 'profile_id');
    }

    /** ACL: o perfil dentro dos clientes, para quem trabalha numa agência. */
    public function agencyProfile(): BelongsTo
    {
        return $this->belongsTo(PermissionProfile::class, 'agency_profile_id');
    }

    public function assignedCars(): HasMany
    {
        return $this->hasMany(Car::class, 'seller_user_id');
    }
}
