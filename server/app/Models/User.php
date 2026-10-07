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

    public function assignedCars(): HasMany
    {
        return $this->hasMany(Car::class, 'seller_user_id');
    }
}
